# 03 — Symbol extraction & php-scoper configuration

Audit of `scripts/extract-symbols.php`, `symbols/*.php`, `config/scoper.inc.php`,
`config/scoper.config.php` and `Wpify\Scoper\Plugin::createScoperConfig()`.

All numbers below were measured on this checkout (PHP 8.4.20, nikic/php-parser 5.x)
with throwaway scripts in the scratchpad. Nothing under `symbols/` was modified.

**Versions under audit** (from `vendor/composer/installed.json`):

| package | installed |
|---|---|
| `johnpbloch/wordpress` | 7.0.2 |
| `wpackagist-plugin/woocommerce` | 10.9.4 |
| `woocommerce/action-scheduler` | 4.0.0 |
| `wp-cli/wp-cli` | v2.12.0 |
| `yahnis-elsts/plugin-update-checker` | **v5.7** |
| `wpify/php-scoper` | 0.18.19 |

**Current symbol inventory:**

| file | bytes | total | functions | classes | namespaces | constants |
|---|---|---|---|---|---|---|
| `symbols/wordpress.php` | 201 746 | 5 332 | 4 190 | 524 | 78 | 540 |
| `symbols/woocommerce.php` | 94 509 | 1 994 | 1 018 | 595 | 374 | 7 |
| `symbols/action-scheduler.php` | 4 078 | 93 | 19 | 71 | 3 | 0 |
| `symbols/wp-cli.php` | 2 638 | 75 | 6 | 29 | 22 | 18 |
| `symbols/plugin-update-checker.php` | 1 025 | 33 | — | — | — | — (33 under `expose-classes`) |

---

## Summary of findings

| # | Finding | Severity | Effort |
|---|---|---|---|
| F1 | Patcher `str_replace` un-prefixes unrelated symbols (unanchored prefix match) | **High** | S |
| F2 | `resolve()` never descends into function bodies — 18 real symbols missed | **High** | S |
| F3 | PUC patchers are stale *and* actively break PUC v5 (`E_USER_ERROR` fatal) | **High** | S |
| F4 | `symbols/plugin-update-checker.php` is stale, uses the wrong config key, and is neutered by `postinstall.php` | **High** | S |
| F5 | `globals: ["plugin-update-checker"]` crashes with a `TypeError` | Medium | S |
| F6 | Top-level `const` never collected — 97 WP constants missing | Medium | S |
| F7 | Patcher rebuilds + re-sorts a 3 392-needle table for every scoped file (~1.33 ms/file) | Medium | S |
| F8 | No provenance/version metadata in `symbols/*.php`; unpinned sources; FS-ordered output | Medium | M |
| F9 | Twig `twig_*` patchers target functions removed from Twig 3.x | Medium | S |
| F10 | `If_` ignores `else`/`elseif`; `Try_`/`Switch_`/`Foreach_`/`Declare_` never walked | Medium | S |
| F11 | Only 5 hardcoded `globals`; no way to supply your own symbol list | Medium | M |
| F12 | `class_alias()` targets not collected (46 across WP/Woo/wp-cli) | Low | S |
| F13 | Dynamic `define()` not collected (6 occurrences) | Low | S |
| F14 | `namespace { }` (null name) would fatal the extractor | Low | S |
| F15 | Guzzle patcher is a dead no-op | Low | S |
| F16 | `config/scoper.config.php` ships three always-overwritten defaults | Low | S |
| F17 | `require_once` in `createScoperConfig()` → silent bare `exit` on second call | Low | S |
| F18 | wp-cli test-suite symbols (28) leak into the exclusion list | Low | S |
| F19 | `PhpVersion::fromString("8.1.0")` pin (currently harmless) | Low | S |
| F20 | 1.6 % duplication from `array_merge_recursive`; 370/374 Woo namespaces redundant | Low | S |

---

## 1. Correctness of extraction

### F2 — `resolve()` never descends into function bodies (High, S)

`scripts/extract-symbols.php:51-78` dispatches on six node types and recurses only
into `Node\Stmt\If_`. It never walks a `Function_` body, so any symbol declared
inside a function is lost.

I reimplemented `resolve()` verbatim and diffed it against a full-AST ground truth
(every global-namespace `Class_`/`Interface_`/`Trait_`/`Enum_`/`Function_`) over the
same file set the script uses:

| source | files | classes found / truth | functions found / truth | **missed** |
|---|---|---|---|---|
| wordpress | 1 295 | 524 / 525 | 4 190 / 4 205 | **16** |
| woocommerce | 3 529 | 595 / 595 | 1 018 / 1 020 | **2** |
| action-scheduler | 95 | 71 / 71 | 19 / 19 | 0 |
| wp-cli | 182 | 29 / 29 | 6 / 6 | 0 |

Namespaces: 0 missed in every source.

The 18 misses, with their exact AST enclosing chain:

```
WP_Block_Cloner          Function_(render_block_core_block) > If_ > Else_ > If_ > Class_
                         sources/wordpress/wp-includes/blocks/block.php:93
wxr_cdata (+13 wxr_*)    Function_(export_wp) > Function_(wxr_cdata)
                         sources/wordpress/wp-admin/includes/export.php:245
lowercase_octets         Function_(redirect_canonical) > If_ > If_ > Function_
                         sources/wordpress/wp-includes/canonical.php:789
wp_handle_upload_error   Function_(_wp_handle_upload) > If_ > Function_
                         sources/wordpress/wp-admin/includes/file.php:807
_sort_priority_callback  If_ > Function_(woocommerce_sort_product_tabs) > If_ > Function_
                         sources/plugin-woocommerce/includes/wc-template-functions.php:2398
filter_created_pages     Function_(wc_update_560_create_refund_returns_page) > Function_
                         sources/plugin-woocommerce/includes/wc-update-functions.php:2382
```

Every single miss has a `Function_` in its chain. This is exactly the bug that
commit `a59d577` ("extract constants defined inside function bodies") fixed for
constants — via a `NodeVisitor` that walks the whole AST — but the fix was never
extended to classes and functions.

**Impact:** a scoped dependency that calls e.g. `wxr_cdata()` or
`function_exists('wp_handle_upload_error')` gets the call prefixed to
`Prefix\wxr_cdata()` and fatals at runtime. Narrow in practice (these are obscure
internal helpers) but the extraction is simply incorrect, and the same defect will
silently swallow more important symbols as WP/Woo evolve.

**Fix:** replace `resolve()` entirely with a `NodeVisitorAbstract` that tracks the
current namespace and emits on `enterNode`, exactly like `ConstantCollector`:

```php
class SymbolCollector extends NodeVisitorAbstract {
    public array $symbols = [
        'exclude-namespaces' => [], 'exclude-classes' => [],
        'exclude-functions' => [], 'exclude-constants' => [],
    ];
    private ?string $namespace = null;

    public function enterNode( Node $node ) {
        if ( $node instanceof Node\Stmt\Namespace_ ) {
            // A `namespace { }` block has a null name — that's the global namespace.
            $this->namespace = $node->name?->toString();
            if ( null !== $this->namespace ) {
                $this->symbols['exclude-namespaces'][] = $this->namespace;
            }
            return null;
        }
        // Symbols inside a namespace are already covered by exclude-namespaces.
        if ( null !== $this->namespace ) {
            return null;
        }
        if ( $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_ ) {
            if ( null !== $node->name ) { // skip anonymous classes
                $this->symbols['exclude-classes'][] = $node->name->name;
            }
        } elseif ( $node instanceof Node\Stmt\Function_ ) {
            $this->symbols['exclude-functions'][] = $node->name->name;
        } elseif ( $node instanceof Node\Stmt\Const_ ) {
            foreach ( $node->consts as $const ) {
                $this->symbols['exclude-constants'][] = $const->name->name;
            }
        }
        return null;
    }
}
```

This one class subsumes F2, F6, F10 and F14 and deletes ~30 lines.

**Benefit:** correct, and structurally immune to new nesting shapes.
**Downside:** an `Enum_` in the global namespace now lands in `exclude-classes`
(correct, but a new entry); anonymous classes must be guarded (`$node->name` is
`null`) — the current top-level-only code never hit that case.

### Namespaced declarations *are* covered — verified against php-scoper

The extractor deliberately records only the namespace name and not the classes
inside it. That is correct. `php-scoper.phar`'s
`src/Symbol/NamespaceRegistry.php` matches with:

```php
if ( '' === $excludedNamespaceName || str_contains( $normalizedNamespaceName, $excludedNamespaceName ) ) {
    return true;
}
```

It is a **case-insensitive substring** match, not even a prefix match — so
`exclude-namespaces: ['Automattic\WooCommerce']` covers every sub-namespace.

Two consequences worth recording:

- **F20a (Low):** 65/78 WordPress and **370/374** WooCommerce namespace entries are
  descendants of another entry in the same list and are pure noise. Collapsing to
  roots would cut `symbols/woocommerce.php` substantially with zero behaviour change.
- **Over-exclusion hazard (Low–Medium):** because the match is *substring*, short
  entries are greedy. The shortest entries currently shipped are `Sodium` (6),
  `WP_CLI` (6), `Avifinfo` (8), `SimplePie` (9). A scoped dependency whose namespace
  merely *contains* `Sodium` anywhere (e.g. `Acme\SodiumBridge`) will silently not be
  prefixed. Nothing to fix on our side — it is php-scoper's semantics — but it argues
  for keeping the namespace list as short and specific as possible, which the
  collapse above also achieves.

### F6 — top-level `const` is never collected (Medium, S)

`Node\Stmt\Const_` appears in no branch of `resolve()`, and `ConstantCollector`
(`scripts/extract-symbols.php:21-39`) only matches `define()` calls.

Measured: **97 global constants** in 2 WordPress files are missing:

- `wp-includes/sodium_compat/lib/php72compat_const.php` — 89 consts
  (`SODIUM_LIBRARY_VERSION`, `SODIUM_BASE64_VARIANT_ORIGINAL`,
  `SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES`, …)
- `wp-includes/sodium_compat/lib/php84compat_const.php` — 8 consts
  (`SODIUM_CRYPTO_AEAD_AEGIS128L_*`, `SODIUM_CRYPTO_AEAD_AEGIS256_*`)

Verified absent from the shipped list:

```
SODIUM_LIBRARY_VERSION                 in exclude-constants? NO
SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES  in exclude-constants? NO
SODIUM_BASE64_VARIANT_ORIGINAL         in exclude-constants? NO
ABSPATH                                in exclude-constants? YES   (define(), so caught)
```

WooCommerce, action-scheduler and wp-cli have zero top-level `const`.

**Impact:** a scoped dependency that references `SODIUM_*` (any libsodium-using
crypto library that supports the polyfill) gets the constant prefixed and fatals.
Real, if narrow.
**Fix:** covered by the `SymbolCollector` above.

### F10 — `If_` ignores `else`/`elseif`; other block statements never walked (Medium, S)

`scripts/extract-symbols.php:60-69` iterates `$node->stmts` only. `$node->else`
(`Node\Stmt\Else_`) and `$node->elseifs` are dropped, and `Try_`, `Catch_`,
`Switch_`, `While_`, `Do_`, `For_`, `Foreach_`, `Declare_` and `Block` have no
branch at all.

**Measured impact today: 0 additional symbols.** Every one of the 18 misses in F2 is
inside a function body, so fixing `else` handling alone recovers nothing on WP 7.0.2 /
Woo 10.9.4. This is a latent gap, not a live one — but `WP_Block_Cloner`'s chain
(`… > If_ > Else_ > If_ > Class_`) shows WordPress does write this shape, and a
top-level occurrence would be silently dropped. `Declare_` matters specifically:
`declare(strict_types=1) { … }` block form would hide an entire file.

**Fix:** the visitor in F2 makes all of these irrelevant.

### F14 — `namespace { }` would fatal the extractor (Low, S)

`scripts/extract-symbols.php:53` does `join( '\\', $node->name->getParts() )`.
For a braced global-namespace block (`namespace { ... }`) `$node->name` is `null`
→ `Error: Call to a member function getParts() on null`, and since the `try/catch`
at `:112-132` only catches `PhpParser\Error`, the whole extraction run dies.

Measured: **0 occurrences** in all five sources today. Latent only.
**Fix:** `$node->name?->toString()` (in the F2 visitor).

### F13 — dynamic `define()` not collected (Low, S)

`ConstantCollector` requires `$node->args[0]->value instanceof Node\Scalar\String_`.
Measured non-literal first arguments:

| source | count | locations |
|---|---|---|
| wordpress | 3 | `wp-includes/ID3/module.audio-video.asf.php:51` (`Variable`), `sodium_compat/lib/php84compat.php:24` (`InterpolatedString`), `sodium_compat/lib/php72compat.php:107` (`InterpolatedString`) |
| woocommerce | 3 | `includes/class-woocommerce.php:594`, `includes/wc-core-functions.php:77`, `src/Internal/Admin/FeaturePlugin.php:234` (all `Variable`) |

All six are loop/variable-driven; two of the WP ones are `define("SODIUM_$name", …)`
inside a `foreach` over the `ParagonIE_Sodium_Compat` constants — i.e. they mint the
*same* `SODIUM_*` names as F6, so fixing F6 covers them incidentally.

No `@define` usage exists in any source (checked). The remaining four are genuinely
un-analysable statically.

**Fix:** low value. If desired, resolve `Node\Scalar\InterpolatedString` whose parts
are all literal, and log the rest to stderr so regressions are visible. Otherwise
document the limitation.

### F12 — `class_alias()` targets not collected (Low, S)

Measured `class_alias()` calls: **37 in WordPress** (e.g. `wp-includes/class-phpmailer.php:18-19`,
`SimplePie/src/Category.php:126`, `SimplePie/src/SimplePie.php:3465`), **8 in
WooCommerce** (`src/Api/Infrastructure/Schema/aliases.php:18,23`,
`src/StoreApi/deprecated.php:73`, …), **1 in wp-cli**.

Most alias *to* an already-excluded class (`PHPMailer\PHPMailer\PHPMailer` →
`PHPMailer`), so the alias name is usually already covered by the namespace or class
list. But the aliases in `deprecated.php` / `aliases.php` create global class names
that exist only at runtime and appear nowhere as a `class` declaration.

**Fix:** in the visitor, also match `class_alias` `FuncCall`s and push
`$args[1]` (the alias name) into `exclude-classes` when it is a literal string.
**Benefit:** closes a class of runtime-only symbols. **Downside:** none material.

### F18 — wp-cli test-suite symbols leak in (Low, S)

`get_files()` (`scripts/extract-symbols.php:80-105`) filters only `/vendor/` and
`/wp-content/`. Measured symbols that come **exclusively** from test-ish directories:

| source | test-ish files | test-only symbols |
|---|---|---|
| wordpress | 0 | 0 |
| woocommerce | 0 | 0 |
| action-scheduler | 0 | 0 |
| **wp-cli** | 23 | **28** |

Examples: `exclude-namespaces: WP_CLI\Tests\Traverser`, `WP_CLI\Tests\CSV`,
`exclude-classes: WpOrgApiTest, InflectorTest, SynopsisParserTest, ProcessTest,
UtilsTest, Mock_Requests_Transport, FileCacheTest, MockRegularLogger`.

These are never loaded in a WordPress request, so excluding them is pure
over-exclusion — and `WP_CLI\Tests\CSV` is a short substring entry (see the
substring-matching hazard above).

**Fix:** add `tests?/`, `spec/`, `features/`, `.github/` to the skip regex.
**Downside:** none.

### F19 — parser pinned to PHP 8.1 (Low, S)

`scripts/extract-symbols.php:45`:

```php
$parser = ( new ParserFactory() )->createForVersion( \PhpParser\PhpVersion::fromString("8.1.0") );
```

I ran every source file through both an 8.1.0 and an 8.4.0 parser:

```
=== parse @ PHP 8.1.0 ===        === parse @ PHP 8.4.0 ===
wordpress             1295  0    wordpress             1295  0
woocommerce           3529  0    woocommerce           3529  0
action-scheduler        95  0    action-scheduler        95  0
wp-cli                 182  0    wp-cli                 182  0
plugin-update-checker   39  0    plugin-update-checker   39  0
```

**Zero parse failures at either version.** The pin is currently harmless. It is
still wrong in principle: the package requires `php: ^8.1` but the extractor is a
dev-only script run by maintainers, and WordPress/WooCommerce will eventually ship
syntax (property hooks, asymmetric visibility) that an 8.1 parser rejects — and
`extract_symbols()` swallows `PhpParser\Error` with a printed message
(`:130-132`), so a future failure will scroll past in a wall of output and produce a
silently truncated symbol list.

**Fix:** use `PhpVersion::getNewestSupported()`, and make parse failures fatal
(collect them and `exit(1)` at the end with a count). **Benefit:** fail-fast at the
boundary. **Downside:** none — this script is maintainer-only.

---

## 2. Reproducibility & provenance (F8, Medium, M)

Current state:

- `symbols/*.php` open with `<?php return array (` — **no header, no version, no
  date, no source URL, no "generated, do not edit" marker.** Nothing in the repo
  records that `symbols/wordpress.php` came from WP 7.0.2.
- `composer.json:37-43` pins nothing: `"johnpbloch/wordpress": "*"`,
  `"wpackagist-plugin/woocommerce": "*"`, `"woocommerce/action-scheduler": "*"`,
  `"wp-cli/wp-cli": "*"`, `"yahnis-elsts/plugin-update-checker": "*"`.
- `.gitignore` excludes `/composer.lock` and `/sources/`.

So the exact inputs that produced the committed lists are unrecoverable. Two
maintainers running `composer extract` a week apart produce different output with no
way to tell what changed vs. what drifted.

**Diff quality is worse than it looks.** `var_export()` emits explicit integer keys
and preserves the holes `array_unique()` leaves behind:

```php
<?php return array (
  'exclude-functions' =>
  array (
    0 => 'trackback_response',
    1 => '_get_cron_lock',
    2 => 'do_activate_header',
```

The order is `RecursiveDirectoryIterator` order — filesystem-dependent, not sorted
(verified: `exclude-functions sorted? NO`). Two things follow:

1. Regenerating on a different filesystem reshuffles thousands of lines for no
   semantic change.
2. Inserting one symbol early renumbers every subsequent key, so a one-symbol change
   can rewrite 4 000+ lines. The file is one-entry-per-line (5 345 lines for 5 332
   symbols), so it *could* diff beautifully — the explicit keys are what ruin it.

### Proposal

1. **Emit a provenance header.** Have `extract_symbols()` take the package name and
   read the installed version from `vendor/composer/installed.json` (all five sources
   are composer packages, including `johnpbloch/wordpress`):

   ```php
   <?php
   /**
    * GENERATED FILE — DO NOT EDIT.
    * Regenerate with: composer extract
    *
    * source:    wpackagist-plugin/woocommerce
    * version:   10.9.4
    * generated: 2026-07-27
    * extractor: scripts/extract-symbols.php @ <git short sha>
    */
   return array( ... );
   ```

   Cost: ~15 lines. Makes every future PR reviewable.

2. **Sort and reindex before export.** Replace `:137-144` with:

   ```php
   foreach ( $symbols as $exclusion => $values ) {
       $values = array_values( array_unique( $values ) );
       sort( $values, SORT_STRING );
       $symbols[ $exclusion ] = $values;
   }
   ksort( $symbols );
   ```

   and export list-style (one quoted string per line, no `N =>`) with a tiny custom
   printer rather than `var_export()`. Measured: deduping + sorting alone shrinks the
   generated temp config from 303 980 → 299 019 bytes (1.6 %); dropping the integer
   keys is worth far more on disk and turns regeneration diffs into pure
   additions/removals. **This is the single highest-value change in this section.**

3. **Pin the sources.** Replace the `"*"` constraints with exact versions
   (`"johnpbloch/wordpress": "7.0.2"`) and **commit `composer.lock`** (remove
   `/composer.lock` from `.gitignore`). This is a `composer-plugin`, not a library —
   its lock file is not imposed on consumers, and it is the only record of what the
   symbols were built from. `/sources/` should stay ignored (it is 4 800 files of
   third-party code that composer can restore).

4. **CI regeneration job.** A scheduled GitHub Actions workflow that:
   `composer update johnpbloch/wordpress wpackagist-plugin/woocommerce … --no-interaction`
   → `composer extract` → if `git diff --quiet symbols/` fails, open a PR titled
   "Update symbols for WP x.y.z / WC a.b.c" with the version deltas in the body.
   With (2) in place the PR diff is readable; with (1) the header change states the
   versions. **Effort: M.** **Benefit:** symbol lists stop silently rotting between
   manual "Upgrade" commits (the git log shows these happen ad hoc: `b00d523`,
   `4abe3a6`, `0255f59`).

5. **Add a smoke test** asserting a handful of canary symbols are present
   (`wpdb`, `WP_Query`, `ABSPATH`, `WC_Product`, `Automattic\WooCommerce`) so a
   catastrophically truncated regeneration cannot be merged.

---

## 3. Output format & load cost (F7)

**Measured — the load cost is a non-issue.** Requiring all four default symbol files
and `array_merge_recursive`-ing them, in a fresh PHP process with opcache:

```
1.15 ms | peak 2.00 MB     (3 consecutive runs: 1.15, 1.15, 1.10 ms)
var_export of merged config: 0.55 ms, 303 937 bytes
merged array real memory: ~0.4 MB, peak (real) 4.00 MB
```

Alternatives, measured:

| format | load+merge | on-disk |
|---|---|---|
| PHP `require` (current, opcached) | **1.15 ms** | 302 971 B |
| `json_decode(file_get_contents())` | 0.62 ms | 254 011 B |

JSON is ~0.5 ms faster and 16 % smaller, but this runs **once per scoping run** — a
run that then shells out to `php-scoper.phar` and a full `composer install`, i.e.
tens of seconds. Saving 0.5 ms is noise, and PHP files get opcached while JSON does
not (relevant if this ever runs in a warm process).

**Recommendation: do not change the storage format.** Keep `require`d PHP arrays.
The one format change worth making is the sort/reindex in F8.2, and that is for diff
quality, not speed. Splitting into one file per symbol type or per package adds file
count and `require` calls for no measurable gain.

### F7 — the real cost is the patcher, not the load (Medium, S)

`config/scoper.inc.php:79-103` runs **inside the patcher closure**, i.e. once for
**every file php-scoper processes**:

```php
usort( $config['exclude-classes'], function ( $a, $b ) {   // :79  — 1 219 elements
    return strlen( $b ) - strlen( $a );
} );

$count        = 0;
$searches     = array();
$replacements = array();

foreach ( $config['exclude-classes'] as $symbol ) {        // :87  — builds 2 438 needles
    ...
}
foreach ( $config['exclude-namespaces'] as $symbol ) {     // :95  — builds 954 more
    ...
}

$content = str_replace( $searches, $replacements, $content, $count );   // :103 — 3 392 needles
```

`$config` is captured by value, so the array is copied and re-sorted on every
invocation, and the 3 392-entry needle/replacement tables are rebuilt from scratch
every time — even though they are identical for every file.

Measured (full loop: `usort` + build + `str_replace` on a ~4 KB file):

```
exclude-classes entries=1219  exclude-namespaces=477
build needles: 0.45 ms, needle count=3392
str_replace with 3392 needles on 4KB file: 0.99 ms
full per-file cost: 1.33 ms  =>  for 5 000 vendor files: 6.7 s
```

**Fix:** hoist the sort and table construction out of the closure — build
`$searches`/`$replacements` once at the top of `scoper.inc.php` and `use` them:

```php
$exclude_classes = $config['exclude-classes'] ?? array();
usort( $exclude_classes, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

$searches = $replacements = array();
foreach ( array_merge( $exclude_classes, $config['exclude-namespaces'] ?? array() ) as $symbol ) {
    $searches[]     = "\\$prefix\\$symbol";
    $replacements[] = "\\$symbol";
    $searches[]     = "use $prefix\\$symbol";
    $replacements[] = "use $symbol";
}

'patchers' => array(
    function ( string $filePath, string $prefix, string $content ) use ( $searches, $replacements ): string {
        ...
        return str_replace( $searches, $replacements, $content );
    },
),
```

**Benefit:** ~0.34 ms/file saved (≈25 %), ~1.7 s on a 5 000-file vendor tree, and the
code reads better. **Downside:** none. **Note:** `$count` at `:83`/`:103` is written
and never read — dead.

### F1 — the patcher un-prefixes unrelated symbols (**High**, S)

`config/scoper.inc.php:87-103` builds needles like `\{$prefix}\WP` and
`use {$prefix}\WP` and feeds them to `str_replace`, which matches **anywhere in the
string with no right-hand boundary**. The `usort` at `:79` only guarantees that a
longer *excluded* name wins over a shorter one — it does nothing for symbols that
are not in the list at all.

The shortest shipped `exclude-classes` entries are `WP` (2), `PO` (2), `MO` (2),
`ftp` (3), `wpdb` (4), `POP3` (4), `PclZip` (6), `getID3` (6), `Walker` (6),
`Snoopy` (6), `WC_Tax` (6), `WC_CLI` (6).

Reproduced with the real merged config and `$prefix = 'MyPrefix'`:

```
new \MyPrefix\WPSEO_Utils();        =>  new \WPSEO_Utils();      ← WRONG
use MyPrefix\WPBakery\Thing;        =>  use WPBakery\Thing;      ← WRONG
new \MyPrefix\POStuff();            =>  new \POStuff();          ← WRONG
new \MyPrefix\ftp_client();         =>  new \ftp_client();       ← WRONG
new \MyPrefix\MOnolog();            =>  new \MOnolog();          ← WRONG
new \MyPrefix\WP_Post();            =>  new \WP_Post();          ← correct
use MyPrefix\Monolog\Logger;        =>  use MyPrefix\Monolog\Logger;  ← correct
```

Any scoped dependency with a class or namespace whose fully-qualified name *starts
with* one of the 1 219 class names or 477 namespace names gets silently
de-prefixed. The result is a `Class "WPSEO_Utils" not found` fatal at runtime, in
the user's plugin, with no hint that the scoper caused it.

**Fix:** anchor the replacement. Since the needles are already sorted longest-first,
the cheapest correct form is a single `preg_replace_callback` over
`(?:use\s+|\\)?{$prefix}\\([A-Za-z_][A-Za-z0-9_\\]*)` that looks the captured symbol
up in a hash set (`array_flip` of the exclusion lists, case-insensitively — php-scoper
itself matches case-insensitively, see `SymbolRegistry::normalizeNames()`), and only
strips the prefix on an exact hit. That is also *faster* than 3 392 `str_replace`
needles.

**Benefit:** removes an entire class of silent, hard-to-diagnose runtime fatals.
**Downside:** a regex pass is a rewrite of the hottest part of the patcher and needs
tests. **Severity: High** — it is a correctness bug that scales with how many
dependencies the user scopes.

**Design note.** This whole block exists to undo prefixing that php-scoper already
declined to do via `exclude-classes`/`exclude-namespaces`. Before rewriting it, it is
worth establishing *why* it is needed at all — php-scoper 0.18 handles exclusions
natively, and this may be compensating for something long since fixed upstream. If it
can be deleted, F1 and F7 both vanish.

---

## 4. Duplication between the lists (F20, Low, S)

`Plugin::createScoperConfig()` (`src/Plugin.php:223-256`) merges with
`array_merge_recursive` and **never de-duplicates**, so the generated
`scoper.config.php` ships duplicates:

| key | entries | unique | dupes |
|---|---|---|---|
| `exclude-functions` | 5 233 | 5 213 | 20 |
| `exclude-classes` | 1 219 | 1 147 | **72** |
| `exclude-constants` | 568 | 554 | 14 |
| `exclude-namespaces` | 477 | 464 | 13 |
| **total** | **7 497** | **7 378** | **119 (1.6 %)** |

Pairwise overlaps:

```
action-scheduler ^ woocommerce   exclude-classes     = 71   (ActionScheduler_*)
action-scheduler ^ woocommerce   exclude-functions   = 17   (as_schedule_*, as_enqueue_*)
action-scheduler ^ woocommerce   exclude-namespaces  = 3    (Action_Scheduler\*)
wordpress        ^ wp-cli        exclude-namespaces  = 10   (WpOrg\Requests\*)
wordpress        ^ wp-cli        exclude-constants   = 13   (ABSPATH, WP_DEBUG, …)
wordpress        ^ wp-cli        exclude-classes     = 1    (Requests)
woocommerce      ^ wordpress     exclude-functions   = 3    (str_contains, str_starts_with, str_ends_with)
woocommerce      ^ wordpress     exclude-constants   = 1    (WP_POST_REVISIONS)
```

The action-scheduler/woocommerce overlap is complete (all 91 action-scheduler symbols
are in the Woo list) because WooCommerce bundles it at
`sources/plugin-woocommerce/packages/action-scheduler` — confirmed. So the default
`globals` list ships `action-scheduler` redundantly whenever `woocommerce` is on.

**Impact is small**: 1.6 % of a 300 KB file, and it costs ~0.02 ms in the merge. But
it inflates the F7 needle table by 119 entries × 2, and duplicates in
`exclude-functions` are noise for anyone reading the generated config.

**Fix:** one line in `createScoperConfig()` after the merges:

```php
foreach ( $config as $key => $value ) {
    if ( is_array( $value ) && str_starts_with( $key, 'exclude-' ) ) {
        $config[ $key ] = array_values( array_unique( $value ) );
    }
}
```

**Benefit:** clean generated config, smaller needle table. **Downside:** none.
(The `sort`+`array_values` in F8.2 makes this redundant at the source, but keeping it
here also protects user-supplied lists once F11 lands.)

---

## 5. `plugin-update-checker` (F3, F4, F5 — High)

This is the most broken corner of the subsystem. Four independent problems:

### F4 — `symbols/plugin-update-checker.php` is stale *and* uses the wrong key (High, S)

The file lists 33 class names, all of the form `Puc_v4_Factory`, `Puc_v4p11_Factory`,
`Puc_v4p11_UpdateChecker`, `Puc_v4p11_Vcs_GitHubApi`, … — the flat, underscore-named
PUC **v4** API.

The installed package is **v5.7**, which is fully namespaced:

```
$ grep -rh "^namespace" vendor/yahnis-elsts/plugin-update-checker --include='*.php' | sort -u
namespace YahnisElsts\PluginUpdateChecker\v5;
namespace YahnisElsts\PluginUpdateChecker\v5p7;
namespace YahnisElsts\PluginUpdateChecker\v5p7\DebugBar;
namespace YahnisElsts\PluginUpdateChecker\v5p7\Plugin;
namespace YahnisElsts\PluginUpdateChecker\v5p7\Theme;
namespace YahnisElsts\PluginUpdateChecker\v5p7\Vcs;

$ ls vendor/yahnis-elsts/plugin-update-checker/Puc/
v5  v5p7
```

Running my ground-truth extractor over the installed package: **0 global classes,
0 global functions, 6 namespaces**. Every one of the 33 shipped names is dead.

Worse, the file uses `'expose-classes'`, while every other symbol file uses
`'exclude-classes'`. These are opposite operations in php-scoper: *expose* means
"prefix it, then register a global alias"; *exclude* means "don't prefix it at all".
Nothing else in this repo reads `expose-classes` (grep: only this file).

And it would not work even if the names were right: `scripts/postinstall.php`
explicitly strips the exposure aliases from the generated autoloader —

```php
// fix scoper autoload - comment exposed classes and functions as we don't want to expose anything
$scoper_autoload = preg_replace('/^humbug_phpscoper_expose_.*;$/m', '// $0 // commented by WPify Scoper', $scoper_autoload );
```

So `expose-*` is a no-op by design in this tool.

Finally, the regeneration line is commented out at `scripts/extract-symbols.php:153`:

```php
//extract_symbols( __DIR__ . '/../vendor/yahnis-elsts/plugin-update-checker', 'vendor', … );
```

which is why it never picked up the v4 → v5 migration.

**Fix:** either (a) uncomment `:153`, switch the emitted key to `exclude-namespaces`
(which is what the v5 extraction naturally produces), and regenerate; or (b) delete
`symbols/plugin-update-checker.php`, the branch at `src/Plugin.php:230-235`, and the
two patchers, and let users add PUC through the extensibility mechanism in F11.

I'd recommend **(b)**. PUC is one vendor library among thousands; special-casing it
in a general-purpose tool is exactly the coupling F11 exists to remove. Note also
that excluding PUC entirely is usually *wrong* — two plugins shipping unscoped PUC v5
is the collision PUC's own `v5p7` namespace versioning already solves.

### F3 — the PUC patchers are stale and one is actively fatal (High, S)

`config/scoper.inc.php:48-50`:

```php
if ( strpos( $filePath, 'yahnis-elsts/plugin-update-checker/Puc/v4p11/UpdateChecker.php' ) !== false ) {
    $content = str_replace( "namespace $prefix;", "namespace $prefix;\n\nuse WP_Error;", $content );
}
```

`Puc/v4p11/` **does not exist** (`ls` → `No such file or directory`). Dead no-op.
It is also no longer needed: `Puc/v5p7/UpdateChecker.php:5` already declares
`use WP_Error;`.

`config/scoper.inc.php:75-77` is worse:

```php
if ( strpos( $filePath, 'yahnis-elsts/plugin-update-checker' ) !== false ) {
    $content = str_replace( '$checkerClass = $type', '$checkerClass = "'. $prefix . '\\\\".$type', $content );
}
```

The file guard is version-agnostic, so it **still fires on v5.7**. In
`Puc/v5p7/PucFactory.php:99` the source is:

```php
$checkerClass = $type . '\\UpdateChecker';
```

`'$checkerClass = $type'` is a prefix of that line, so `str_replace` rewrites it to:

```php
$checkerClass = "MyPrefix\\".$type . '\\UpdateChecker';   //  "MyPrefix\Plugin\UpdateChecker"
```

Two lines later (`:106`) the value is fed to `getCompatibleClassVersion()`:

```php
protected static function getCompatibleClassVersion($class) {          // :286
    if ( isset(self::$classVersions[$class][self::$latestCompatibleVersion]) ) {
        return self::$classVersions[$class][self::$latestCompatibleVersion];
    }
    return null;
}
```

`self::$classVersions` is keyed by the **unprefixed, unversioned** general class name
registered from `load-v5p7.php:29` via `PucFactory::addVersion($pucGeneralClass, …)`
(i.e. `Plugin\UpdateChecker`). `MyPrefix\Plugin\UpdateChecker` is not a key →
`getCompatibleClassVersion()` returns `null` → `:107-118` fires
`trigger_error( …, E_USER_ERROR )`, which is **fatal**.

So a user who scopes PUC v5 today gets a hard fatal from `PucFactory::buildUpdateChecker()`.

**Fix:** delete both PUC patchers (`:48-50` and `:75-77`). The v4 one is dead; the v5
one is harmful. **Benefit:** removes a fatal. **Downside:** anyone still on PUC v4
loses the `$checkerClass` fix — acceptable, v4 is years EOL and the current lists
already don't work for them.

### F5 — `globals: ["plugin-update-checker"]` crashes (Medium, S)

`src/Plugin.php:230-235` merges `plugin-update-checker.php` into `$config`. That file
contributes only `expose-classes`, so if it is the *only* selected global, `$config`
has no `exclude-classes` key. `config/scoper.inc.php:79` then does:

```php
usort( $config['exclude-classes'], … );
```

Reproduced:

```
keys: prefix, exclude-constants, expose-classes
FATAL at scoper.inc.php:79 => TypeError: usort(): Argument #1 ($array) must be of type array, null given
```

`:95` (`$config['exclude-namespaces']`) has the same problem.

Note this combination is not reachable from the documented config: `plugin-update-checker`
is **not** in the default `globals` at `src/Plugin.php:60`
(`array( 'wordpress', 'woocommerce', 'action-scheduler', 'wp-cli' )`) and is not
listed in the README's example — yet `createScoperConfig()` handles it. So the only
way to reach it is to guess the name, and doing so fatals.

**Fix:** default the keys at the top of `scoper.inc.php`:

```php
$config += array(
    'exclude-classes'    => array(),
    'exclude-namespaces' => array(),
    'exclude-functions'  => array(),
    'exclude-constants'  => array(),
);
```

**Benefit:** fail-safe for any `globals` subset, and required once F11 lets users
supply arbitrary lists. **Downside:** none.

---

## 6. `config/scoper.inc.php` patchers — status of each (F9, F15)

Verified against the current upstream source of each package:

| # | line | target | status |
|---|---|---|---|
| 1 | `:40-42` | `guzzlehttp/guzzle/.../CurlFactory.php` — `stream_for($sink)` → `Utils::streamFor()` | **Dead no-op.** Guzzle 7 already has `$sink = \GuzzleHttp\Psr7\Utils::streamFor($sink);`. The literal `stream_for($sink)` no longer exists. Also note the replacement **drops the `$sink` argument** — if it ever did match, it would produce broken code. |
| 2 | `:44-46` | `php-di/php-di/src/Compiler/Template.php` — strip `namespace $prefix;` | **Still required.** The upstream file is a PHP *template* with no namespace of its own; php-scoper injects one and breaks compiled-container generation. Keep. |
| 3 | `:48-50` | PUC `Puc/v4p11/UpdateChecker.php` — add `use WP_Error;` | **Dead no-op** (path gone; v5p7 already imports it). Delete — see F3. |
| 4 | `:52-55` | `twig/src/Node/ModuleNode.php` | **Half live.** Line `:53` (`write("use Twig` → `write("use {$prefix}\Twig`) is **still needed** — upstream `ModuleNode::compileClassHeader()` emits `->write("use Twig\Environment;\n")` etc. as *string literals* php-scoper cannot see into. Line `:54` injects `use function {$prefix}\twig_escape_filter;` — **dead and wrong**, see below. |
| 5 | `:57-65` | `/vendor/twig/twig/` — `twig_escape_filter_is_safe`, `twig_get_attribute(`, `twig_ensure_traversable(`, `new TwigFilter('x','twig_…')`, `$compiler->raw('twig_…(` | **Dead.** See F9. |
| 6 | `:67-69` | `giggsey/libphonenumber-for-php` — un-prefix `array_merge` | Plausible no-op; `expose-global-functions => false` means php-scoper shouldn't prefix internal functions anyway. Not verified against current upstream — flag for follow-up. |
| 7 | `:71-73` | `league/oauth2-client` — prefix the literal `League\OAuth2\Client\Grant` | **Still required.** `GrantFactory::registerDefaultGrant()` builds `$class = 'League\\OAuth2\\Client\\Grant\\' . $class;` as a string literal. Keep. |
| 8 | `:75-77` | PUC — `$checkerClass = $type` | **Actively fatal on v5.** Delete — see F3. |

### F9 — the Twig `twig_*` patchers target removed functions (Medium, S)

Twig 3.x moved every `twig_*` global function to static methods and registers filters
with array callables. Verified against `twigphp/Twig` `3.x`:

- `src/Node/ModuleNode.php` contains **no** reference to `twig_escape_filter`,
  `twig_escape_filter_is_safe`, `twig_get_attribute` or `twig_ensure_traversable`.
- `src/Extension/CoreExtension.php` contains **none of those functions** either, and
  registers filters as `new TwigFilter('format', [self::class, 'sprintf'])` /
  `new TwigFilter('url_encode', [self::class, 'urlencode'])` — array callables, never
  `new TwigFilter('x', 'twig_…')` string callbacks.

So:

- `:54` injects `use function {$prefix}\twig_escape_filter;` into every compiled
  template header for a function that does not exist. Harmless only because a
  `use function` for a missing function is not itself an error — but it is noise
  written into generated code, and the `'Template;\n\n'` anchor no longer matches
  upstream's `use Twig\Template;\n` / `use Twig\TemplateWrapper;\n` sequence anyway.
- `:58`, `:59`, `:60`, `:61`, `:62` are all no-ops on Twig 3.x.
- `:63-64` (`'\\Twig\\` → `'\\{$prefix}\\Twig\\`) remains relevant for string-literal
  class references and should be kept.

**Fix:** delete `:54` and `:58-62`; keep `:53` and `:63-64`. **Benefit:** removes
six regex/`str_replace` passes per Twig file and stops injecting a bogus import.
**Downside:** breaks Twig 2.x / Twig <3.9 users — acceptable; Twig 2 is EOL.

### Design: should a general-purpose tool hardcode a per-package patcher list?

No. `config/scoper.inc.php:39-106` is a single 68-line closure with eight hardcoded
`strpos($filePath, 'vendor/foo/bar')` branches for packages that have nothing to do
with WordPress. Every one of them is dead weight for a user who doesn't use that
package, and — as F3 shows — a stale branch can go from "harmless" to "fatal" when
the target package changes shape, with nobody noticing because there is no test and
no version guard.

There *is* an extension point (`config/scoper.custom.php` via
`customize_php_scoper_config()`, `:7-17`), but it is all-or-nothing: a user who wants
to add one patcher receives the whole config array and must merge by hand.

**Proposal (Effort: M):** a patcher registry keyed by package name, with an optional
version constraint:

```
config/patchers/
    php-di.php
    twig.php
    league-oauth2-client.php
    libphonenumber.php
```

Each returns:

```php
return array(
    'package'    => 'php-di/php-di',
    'constraint' => '^7.0',                       // checked against composer.lock
    'patch'      => function ( string $filePath, string $prefix, string $content ): string { … },
);
```

`scoper.inc.php` globs `config/patchers/*.php` plus a user-supplied
`extra.wpify-scoper.patchers` directory, resolves each `package` against the scoped
`composer.lock`, and only registers patchers whose package is actually installed and
whose constraint matches. **Benefits:** dead patchers cost nothing at runtime and
become visible (a constraint mismatch can warn); users add their own without forking;
each patcher is independently testable. **Downside:** more moving parts than a single
closure; needs a lock-file read. **Note:** F1 and F7 apply to the generic
un-prefixing block, which stays where it is — it is not a per-package patcher.

---

## 7. `config/scoper.config.php` (F16, Low, S)

```php
<?php

return array(
	'prefix' => 'WordPressDeps',
	'source' => getcwd() . '/vendor-source/',
	'destination' => getcwd() . '/vendor-scoped/',
);
```

All three keys are unconditionally overwritten in
`Plugin::createScoperConfig()` (`src/Plugin.php:218-220`) before the file is
re-emitted to the temp dir at `:263`. The shipped values are never used:
`'WordPressDeps'` is not the documented default prefix (there is none —
`src/Plugin.php:59` sets `'prefix' => null` and `execute()` no-ops when it's empty),
and `vendor-source/` / `vendor-scoped/` appear nowhere else in the repo or README.

So the file's only real function is to be a valid array that `require_once` returns
at `:212`. It is misleading dead configuration: a reader reasonably concludes
`WordPressDeps` is the fallback prefix, and it isn't.

**Fix:** either delete the file and inline
`$config = array( 'exclude-constants' => array( 'NULL', 'TRUE', 'FALSE' ) );` at
`src/Plugin.php:212`, or keep it as the single place where **genuine** php-scoper
defaults live (the `expose-global-*` flags currently hardcoded at
`config/scoper.inc.php:108-110` are a better fit) and drop the three phantom keys.
I'd keep the file for the second purpose — it gives users one obvious place to look.

**Benefit:** removes a false lead. **Downside:** none.

### F17 — `require_once` returns `true` on a second call → silent `exit` (Low, S)

`src/Plugin.php:212-216`:

```php
$config = require_once $config_path;

if ( ! is_array( $config ) ) {
    exit;
}
```

`require_once` returns `true` (not the array) if the file was already included in
this process. `execute()` is subscribed to both `POST_INSTALL_CMD` and
`POST_UPDATE_CMD` (`:44-49`), so a second invocation in one process would hit the
bare `exit` — no message, no exit code, no diagnostics. Same pattern at
`config/scoper.inc.php:5`.

**Fix:** use `require` (not `require_once`), and replace the bare `exit` with a
thrown exception or `$this->io->writeError()` + `exit(1)`.

---

## 8. Extensibility (F11, Medium, M)

Today the only lever is `extra.wpify-scoper.globals`, and its values must come from a
hardcoded set of five, matched by an if-chain in `Plugin::createScoperConfig()`
(`src/Plugin.php:223-256`). There is:

- no way to supply your own symbol file;
- no way to add symbols for other WP-ecosystem packages a project depends on
  (ACF, Elementor, Yoast, Gravity Forms, EDD, Polylang, WPML …);
- no validation — an unknown value in `globals` is silently ignored, so a typo like
  `"woo-commerce"` disables WooCommerce exclusions with no warning and produces a
  build that fatals at runtime;
- an undocumented sixth value (`plugin-update-checker`) that crashes if used alone
  (F5).

The if-chain is also five near-identical blocks — a textbook case for a map.

**Proposal:**

**Step 1 (S) — replace the if-chain with a provider map.** In `Plugin`:

```php
private const BUNDLED_SYMBOLS = array(
    'action-scheduler'      => 'action-scheduler.php',
    'plugin-update-checker' => 'plugin-update-checker.php',
    'woocommerce'           => 'woocommerce.php',
    'wordpress'             => 'wordpress.php',
    'wp-cli'                => 'wp-cli.php',
);
```

and iterate `$this->globals`, warning via `$this->io` on an unknown key. Immediately
fixes the silent-typo failure mode and deletes 30 lines.

**Step 2 (S) — accept user-supplied symbol files.** A new key:

```json
{
  "extra": {
    "wpify-scoper": {
      "globals": ["wordpress", "woocommerce"],
      "symbols": [
        "symbols/acf.php",
        "vendor/acme/wp-symbols/elementor.php"
      ]
    }
  }
}
```

Each path is resolved relative to `getcwd()`, `require`d, validated to be an array
whose keys are all `exclude-*`/`expose-*`, and merged with the same
`array_merge_recursive` + de-dupe as the bundled ones. ~20 lines.

**Step 3 (M) — make the extractor reusable.** `scripts/extract-symbols.php` is
currently a script with four hardcoded `extract_symbols(...)` calls at `:151-155`.
Extract the collector into `src/SymbolExtractor.php` and expose a composer command
(the plugin already implements `CommandProvider`):

```
composer wpify-scoper extract-symbols --from=wp-content/plugins/advanced-custom-fields --to=symbols/acf.php
```

Now a user with a proprietary or niche must-not-scope plugin generates their own list
with the same tool, no fork required — and the maintainers' own regeneration becomes
four invocations of a supported command rather than a script nobody else can use.

**Benefit:** the tool stops being WordPress-plus-four-hardcoded-plugins and becomes a
WordPress scoper with a symbol-list mechanism. **Downside:** a public command and
config key to keep stable; user-supplied files are `require`d, so document that they
must be trusted (no worse than `scoper.custom.php`, which is also `require`d).

---

## Recommended order

1. **F1** — anchor the patcher replacement (silent runtime fatals, scales with usage).
2. **F3 + F4** — delete the PUC patchers and the stale symbol file (fatal today).
3. **F2 + F6 + F10 + F14** — one `SymbolCollector` visitor; regenerate.
4. **F5** — default the `exclude-*` keys in `scoper.inc.php`.
5. **F8.2** — sort + reindex the export (unlocks reviewable diffs for everything after).
6. **F7 + F20** — hoist the needle table; de-dupe the merge.
7. **F9 + F15** — prune the dead Twig and Guzzle patchers.
8. **F8.1/8.3/8.4** — provenance header, pinned sources, CI regeneration.
9. **F11** — provider map → user symbol files → `extract-symbols` command.
10. **F16 + F17 + F18 + F19** — cleanups.

Steps 1–4 are all severity-High or their direct prerequisites and are each S effort.

---

## Reproducing the measurements

Scripts written to
`/private/tmp/claude-503/-Users-wpify-projects-scoper/a8a9361a-6f4f-45a7-9ff3-d48b90efa403/scratchpad/`:

| script | what it measures |
|---|---|
| `analyze.php` | parse failures at PHP 8.1.0 vs 8.4.0 (F19) |
| `missed.php` | production `resolve()` vs full-AST ground truth (F2, F6, F12, F13, F14) |
| `chain.php` | AST enclosing chain for each missed symbol (F2) |
| `tests.php` | test-directory symbol contamination (F18) |
| `overlap.php` | cross-list duplication, redundant namespaces, merged config size (F20) |
| `bench.php` | require/merge/`var_export` timings, patcher cost (F7) |
| `formats.php` | PHP vs JSON load cost and on-disk size (§3) |

Nothing under `symbols/`, `src/`, `config/` or `scripts/` was modified.
