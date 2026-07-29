# Consumer impact — cluster B (Bedrock / delife group)

Projects verified: `delife`, `stavbadesign`, `wpify-website`, `sdcentral` (all under
`/Users/wpify/projects/`). All four are Roots/Bedrock WordPress projects, all four
consume `wpify/scoper` **as a global Composer plugin**, never as a project dependency.

Everything below was inspected read-only. No file in any of the four projects was
modified and no `composer install/update` was run inside them. Scratch scripts live in
`/private/tmp/claude-503/.../scratchpad/` (`h1scan.php`, `h1forward.php`, `h1ci.php`,
`woo.php`, `B_delta.php`, `B_h16impact.php`).

---

## 0. Topology — the fact that drives half the verdicts

**`wpify/scoper` is installed globally, and therefore executes on every Composer run on
the machine and in CI, including runs for projects that have no scoper config at all.**

Evidence:

- `composer global config home` → `/Users/wpify/.composer`; global manifest
  `/Users/wpify/.composer/composer.json` requires `wpify/scoper: ^3.2` with
  `allow-plugins.wpify/scoper: true`.
- Installed global version: **3.2.21**, source ref `b00d523`
  (`/Users/wpify/.composer/vendor/composer/installed.json`).
- `src/Plugin.php`, `scripts/postinstall.php` and `config/scoper.inc.php` in the global
  install are **byte-identical** to this repo's HEAD (`diff -q`, no output). Only
  `symbols/*.php` differ (global lags commit `a59d577`). So the audited code is the code
  consumers are running.
- None of the four `composer.json` files require `wpify/scoper` in `require` or
  `require-dev`.
- All four `.gitlab-ci.yml` files install it globally at build time:
  `delife/.gitlab-ci.yml:55-56`, `stavbadesign/.gitlab-ci.yml:59-60`,
  `wpify-website/.gitlab-ci.yml:64,68`, `sdcentral/.gitlab-ci.yml:33-34` and again at
  `sdcentral/.gitlab-ci.yml:66-67` for the `test:php` job.

Empirically confirmed in a throwaway scratch project with an empty `composer.json`:

```
Loading plugin Wpify\Scoper\Plugin (from wpify/scoper, installed globally)
> post-install-cmd: Wpify\Scoper\Plugin->execute
EXIT=0        # silent no-op, nothing created
```

That silent no-op (`src/Plugin.php:127`) is currently **load-bearing** for the global
Composer project itself and for every unrelated project on the machine. See C5.

---

## 1. `delife/scoper.custom.php` — the priority-1 artifact

### (a) What it customizes

`/Users/wpify/projects/delife/scoper.custom.php` (13 lines, tracked in git —
`git ls-files` confirms). It defines `customize_php_scoper_config()` and appends exactly
one patcher:

```php
if ( strpos( $filePath, 'wpify/custom-fields/src/Integrations/OrderMetabox.php' ) !== false ) {
    $content = str_replace( 'function_exists(\'DelifeDeps\\\\', 'function_exists(\'', $content );
}
```

i.e. "in that one file, strip the prefix out of `function_exists('DelifeDeps\…')`
string literals."

The same file exists, byte-identical (409 bytes), in all three worktrees
(`.worktrees/b2b-api-integration`, `.worktrees/openrouter-migration`,
`.worktrees/redesign-2026`).

### (b) Is it currently being applied? — **YES. Proven.**

Finding H18 says `Plugin::createPath(['scoper.custom.php'], true)`
(`src/Plugin.php:268-276`) only resolves to the project root when
`dirname(__DIR__)` contains the literal `vendor/wpify/scoper`. For a **global** install
that path is `/Users/wpify/.composer/vendor/wpify/scoper` — which *does* contain the
substring:

```
dirname(__DIR__) = "/Users/wpify/.composer/vendor/wpify/scoper"
strpos(..., "vendor/wpify/scoper") = int(23)   → is_int() === true
```

So the `$in_root` branch is taken, `getcwd() . '/scoper.custom.php'` resolves to
`/Users/wpify/projects/delife/scoper.custom.php`, `src/Plugin.php:258-260` copies it into
the temp dir, and `config/scoper.inc.php:7-10` requires it. **The customization is live
today. H18 does not change delife's behaviour.**

(Contrast: a *development* checkout at `/Users/wpify/projects/scoper` does **not** match,
which is presumably where the finding came from. No consumer in this cluster is in that
topology.)

### (c) …but the customization is a **no-op in the output**

Natural experiment across the four builds, same package (`wpify/custom-fields` 4.x),
same file, line 165:

| project | `globals` | custom file | built output |
|---|---|---|---|
| delife | default (incl. `woocommerce`) | **yes** | `function_exists('wc_get_container')` |
| stavbadesign | default (incl. `woocommerce`) | no | `function_exists('wc_get_container')` |
| wpify-website | default (incl. `woocommerce`) | no | `function_exists('wc_get_container')` |
| sdcentral | `["wordpress"]` only | no | `function_exists('SDCentralDeps\wc_get_container')` |

`delife/web/app/deps/wpify/custom-fields/src/Integrations/OrderMetabox.php:165,205` is
identical to stavbadesign's and wpify-website's. Since `woocommerce` is in delife's
globals, php-scoper never prefixes `wc_*` in the first place, so the custom patcher finds
nothing to replace. It is dead weight, not a functional dependency.

**Verdict: SAFE, no behaviour change from H18 — but the "silently ignored today, would
suddenly apply" scenario the brief worried about does not occur here.** The one caveat
worth stating loudly is the inverse: **if H18's fix is implemented as
`dirname(Factory::getComposerFile())`, delife keeps working; if it is implemented in a way
that stops resolving to the project root for a global install, delife silently loses its
patcher.** The patcher is currently inert, so even that would be invisible — which is
exactly why it should be covered by a test rather than by observation.

---

## 2. Live corruption hunt in the built `deps/` (H1 / H2)

All four projects have a built `deps/` at `web/app/deps` (delife 36 MB / 2 939 PHP files,
wpify-website 2 247, sdcentral 518, stavbadesign 506).

### H2 — `autoload_static.php` classmap corruption: **not triggered, latent only**

`scripts/postinstall.php:41-45` applies
`/'([[:alnum:]]+)'\s*=>\s*([a-zA-Z0-9 .'"\/\-_]+),/` to the whole file. Measured, per
project, by walking `autoload_static.php` section by section:

| project | prefixed keys in `$files` (intended) | prefixed keys **outside** `$files` | classMap entries | non-namespaced classMap keys |
|---|---|---|---|---|
| delife | 12 | **0** | 2 405 | **0** |
| stavbadesign | 9 | **0** | 486 | **0** |
| wpify-website | 10 | **0** | 709 | **0** |
| sdcentral | 9 | **0** | 498 | **0** |

Reason: php-scoper puts every scoped class under the prefix namespace, so every classmap
key contains a `\\` and cannot match `[[:alnum:]]+`. The bug is real but unreachable for
these four dependency sets. **Narrowing the rewrite to `$files` is a pure no-op here →
SAFE.**

### H1 — unanchored prefix-stripping: **not triggered, and the fix must be boundary-anchored, not exact-match**

Two independent scans:

1. *Retrospective* (`h1scan.php`): every `\Ident…` / `use Ident…` occurrence in all
   6 210 scoped PHP files, flagged when it starts with an excluded class/namespace and
   continues with an identifier character. **0 mangles in all four projects.**
2. *Forward* (`h1forward.php`): every classmap FQN vs. the project's excluded symbol set.
   **0 `MANGLE` candidates in all four.**

The only forward hits are correct de-prefixings (delife):
`DelifeDeps\Attribute`, `DelifeDeps\PhpToken`, `DelifeDeps\Stringable`,
`DelifeDeps\UnhandledMatchError`, `DelifeDeps\ValueError` — the symfony/polyfill-php80
stubs (`web/app/deps/symfony/polyfill-php80/Resources/stubs/Attribute.php:3,13`), which
are declared under the prefix but referenced as `\Attribute` and resolve to the native
PHP 8.3 classes. Exact-symbol matching preserves this.

**Two implementation constraints the fix must respect, or it becomes BREAKING:**

- **Sub-namespace continuation must still be stripped.** `delife/…/OrderMetabox.php:10`
  is `use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;`
  and `…/snippets/src/CustomSMTP.php:5` is `use PHPMailer\PHPMailer\PHPMailer;`. The
  excluded entries are `Automattic\WooCommerce` and `PHPMailer\PHPMailer`. The proposed
  hash-set lookup on the *whole* captured `[A-Za-z_][\w\\]*` would miss both. The lookup
  must be longest-namespace-prefix, with `\` as a legal continuation. (php-scoper's own
  `exclude-namespaces` already handles these, so the patcher is belt-and-braces here —
  but that is an assumption worth testing rather than relying on.)
- **Token-boundary, not string-end.** `\DelifeDeps\Attribute::TARGET_CLASS` must still
  become `\Attribute::TARGET_CLASS` (polyfill stub, line 13).

The proposed switch to **case-insensitive** matching was measured separately
(`h1ci.php`): **0 new de-prefixings** in all four projects.

**Verdict H1: SAFE for all four, conditional on the two constraints above.**

---

## 3. sdcentral's `globals: ["wordpress"]` — pre-existing, live breakage

`sdcentral/composer.json` `extra.wpify-scoper.globals = ["wordpress"]` — no
`woocommerce`, no `action-scheduler`, no `wp-cli`. Its scoped set (`wpify/custom-fields`,
`wpify/model`, `wpify/snippets`, `wpify/log`) references all three anyway.

Measured (`woo.php`) — **14 distinct symbols wrongly prefixed** in
`sdcentral/web/app/deps`:

| symbol | kind | sites (excerpt) |
|---|---|---|
| `WP_CLI` | constant | 15 — `wpify/snippets/src/HTTPAuth.php:9`, `wpify/custom-fields/src/Integrations/Taxonomy.php:94`, `…/Metabox.php:121` |
| `WC_Product` | class | 6 — `wpify/model/src/Product.php:6`, `…/ProductRepository.php:6` |
| `WC_Order` | class | 4 — `wpify/model/src/Order.php:7`, `…/OrderRepository.php:6` |
| `WC_Abstract_Order` | class | 3 — `wpify/model/src/Order.php:6` |
| `WC_Order_Item`, `WC_Order_Refund`, `WC_Tax`, `WC_Coupon`, `WC_Admin_Settings` | classes | 8 total |
| `wc_get_container`, `wc_get_page_screen_id`, `wc_get_order`, `wc_get_logger` | functions | 7 total |
| `ActionScheduler` | class | 2 — `wpify/snippets/src/DisableDefaultAsRunners.php:18-19` |

Concrete dead code produced:

- `HTTPAuth.php:9` — `if (defined('SDCentralDeps\WP_CLI') && WP_CLI)` — always false.
  sdcentral **does** register WP-CLI commands (`src/CLI.php:64-66`
  `WP_CLI::add_command('sd create-processes', …)`), so the WP-CLI guards inside
  custom-fields (`Taxonomy.php:94`, `Metabox.php:121`, `SubscriptionMetabox.php:161`) are
  live-wrong: admin form-field hooks are registered during CLI runs.
- `wpify/log/src/Log.php:56` — `function_exists('SDCentralDeps\wc_get_logger')` — always
  false; the WooCommerce logger path is unreachable.
- `DisableDefaultAsRunners.php:18` — `class_exists('SDCentralDeps\ActionScheduler')` —
  always false; the snippet is inert (and `\SDCentralDeps\ActionScheduler::runner()` on
  line 19 would fatal if it ever were reached).
- `wpify/model/src/Order.php:6-7` — `use SDCentralDeps\WC_Abstract_Order;` /
  `use SDCentralDeps\WC_Order;`. Dormant: sdcentral's `src/` never touches
  `Wpify\Model\Order`/`Product` (verified by grep over `src/`), so no fatal today.

**None of this is caused by the proposed changes** — it is the status quo. Adding
`"woocommerce"`, `"action-scheduler"` and `"wp-cli"` to sdcentral's `globals` (a
one-line project-side change) fixes all 14. It is worth reporting to the sdcentral owner
independently of this audit.

**Would H16's regenerated lists change sdcentral's output?** Only via `wordpress.php`.
See §4 — measured impact zero.

---

## 4. H16 — regenerated symbol lists

The documented H16 delta (docs `03-symbols-and-scoper-config.md:61-160, 186-285`) adds:
18 function-body symbols (`WP_Block_Cloner`, `wxr_cdata` + 13 `wxr_*`,
`lowercase_octets`, `wp_handle_upload_error`, `_sort_priority_callback`,
`filter_created_pages`), ~97 `SODIUM_*` top-level constants, and the `class_alias`
targets. I extracted the alias targets from the checked-in sources: the only
**global-namespace** ones are `PHPMailer`, `phpmailerException`, `SMTP` and 33
`SimplePie*` names; every WooCommerce alias is namespaced under
`Automattic\WooCommerce\…` and every global enum is namespaced, so both are already
covered by `exclude-namespaces`.

Measured against all four builds:

- **Exact classmap collisions with the newly-excluded classes: 0** in every project.
- **H1-mangle risk from the new short names: 0** in every project. Notably
  `wpify/snippets/src/SMTP.php` declares `…\Wpify\Snippets\SMTP`
  (`autoload_classmap.php:1267` → `'DelifeDeps\\Wpify\\Snippets\\SMTP'`), i.e. it is
  namespaced, so the search needle `\Prefix\SMTP` cannot reach it. Same for
  `CustomSMTP` and for `use PHPMailer\PHPMailer\PHPMailer` in `CustomSMTP.php:5`.
- **No `SODIUM_*` reference anywhere** in any of the four scoped trees.
- **No reference to any of the 18 function-body symbols.**

**Ordering constraint (flagged, not blocking):** `SMTP`, `SimplePie` and `PHPMailer` are
short, generic, root-level class names. Landing H16 **before** H1 widens the unanchored
str_replace surface. Land H1 first, or together.

`F18` (dropping 28 wp-cli test-suite symbols such as `Requests`, `UtilsTest`,
`ProcessTest`, `WP_CLI\Tests\CSV`) — none is referenced in any of the four scoped trees.

**Verdict H16: SAFE for all four.** Caveat: a sibling agent's `scratchpad/new/` symbol
snapshot turned out to be a byte-identical copy of the repo's shipped `symbols/*.php`
(verified with `cmp`), so it could not be used as a regenerated ground truth. My H16
analysis is therefore driven by the deltas documented in `03-symbols-and-scoper-config.md`
plus my own extraction from `sources/`, not by a real regenerated list. **If an actual
regenerated list lands, re-run `scratchpad/B_h16impact.php` against it** — that is the
one item here I could not verify end-to-end.

---

## 5. Item-by-item

### C4 — bump `require.php` to `^8.2` → **SAFE ×4**

The PHP that runs the *tool* (not `config.platform.php`, which only constrains scoped
resolution):

| context | PHP | evidence |
|---|---|---|
| CI, all four | **8.3.13** | `image: composer:2.8.2`; `docker run --entrypoint php composer:2.8.2 -v` |
| sdcentral `test:php` | 8.3 | `sdcentral/.gitlab-ci.yml:43` `php:8.3-cli-bookworm` |
| local host | 8.4.20 | `php -v` (this is where `~/.composer` lives) |
| DDEV web container | 8.3 | `php_version: "8.3"` in all four `.ddev/config.yaml` |

Nothing runs on 8.1. `config.platform.php` is `8.3` (delife, stavbadesign, wpify-website,
sdcentral) — distinct from the above and unaffected.

### C5 — fail fast on missing/invalid prefix → **BREAKING ×4 unless scoped**

Prefixes are all present and legal PHP namespace segments: `DelifeDeps`,
`StavbadesignDeps`, `WpifyDeps`, `SDCentralDeps`. Extra keys used across the cluster —
`prefix`, `folder`, `globals`, `composerjson`, `composerlock`, `autorun`
(`sdcentral/composer.json` `extra.wpify-scoper.autorun: true`) — are all recognised keys,
so a strict unknown-key rejection is fine **provided `autorun` and `temp` are on the
allow-list**.

**The breakage is elsewhere.** Because the plugin is global, `execute()` runs for projects
that have *no* `extra.wpify-scoper` at all, and the silent return at `src/Plugin.php:127`
is what keeps them working. Confirmed empirically (§0). If a missing prefix becomes a hard
error:

1. `composer global require wpify/scoper` — present in **all four** CI pipelines
   (`delife:56`, `stavbadesign:60`, `wpify-website:68`, `sdcentral:34` and `:67`) —
   dispatches `post-update-cmd` in `COMPOSER_HOME`, whose `composer.json` has no
   `extra`. The CI job fails on the line that installs the tool.
2. Every other Composer project on a developer machine with the global install starts
   erroring.
3. The **nested** install (temp `source/composer.json`, no `extra`) fails too — which
   breaks scoping itself.

**Required scoping: error only when the `extra.wpify-scoper` key is present but `prefix`
is missing/empty/invalid. Absence of the whole key must remain a silent no-op.**
`autorun: false` is not a substitute — you cannot put it in `COMPOSER_HOME/composer.json`
for every project on the machine.

### C3 — atomic swap via `.bak` sibling → **SAFE ×4** (one housekeeping note)

`folder` is `web/app/deps` in all four — **not** inside `vendor/`. Backup would land at
`web/app/deps.bak`, inside the Bedrock content dir but outside `plugins/`, `mu-plugins/`
and `themes/`, so WordPress never scans it.

- Same filesystem as the temp dir: temp defaults to `getcwd()/tmp-XXXX`
  (`src/Plugin.php:58`), no project overrides `temp`. Both under the project root → no
  cross-device `rename()`. DDEV mounts the whole project as one volume; Composer here runs
  on the host anyway (`~/.composer`).
- CI artifacts list `web/app/deps` explicitly (`delife/.gitlab-ci.yml:44-48`); deploy
  rsyncs an explicit path list (`delife/.gitlab-ci.yml:81-96`) — `deps.bak` is in neither.
- **Note:** `deps.bak` is *not* covered by any of the four `.gitignore` files —
  delife ignores `web/app/deps/*` (`.gitignore:13`), the others ignore `web/app/deps`
  (stavbadesign:11, wpify-website:16, sdcentral:8). `git check-ignore -v web/app/deps.bak`
  returns nothing in all four. A crash mid-swap leaves an untracked ~36 MB tree in
  `git status`. Cosmetic; either name it `.deps.bak` (dot-prefixed) or clean it in a
  `finally`.

### C2 — `is_link()` guard in `remove()` → **SAFE ×4**

- `find web/app/deps -type l` → **0 symlinks** in all four, full depth.
- `web/app/deps` is itself a real directory in all four (`ls -ld`).
- **No `repositories` block at all** in any of the four `composer-deps.json` — so no
  `path` repository and no Composer-created symlink inside the scoped tree.
- delife's *outer* `composer.json:32-35` does have a `path` repository
  (`lib/graphql-php`), but that produces a symlink under `vendor/`, which
  `scripts/postinstall.php` never touches (it only removes `$deps`, `$temp` and the
  `composer-deps.lock` file).
- No leftover `tmp-*` directories in any project (delife's `tmp/` and `tmp-tests/` are
  hand-made scratch dirs with unrelated content, not scoper temp dirs).

### C1 + M4 — subprocess, exit-code propagation, re-entrancy guard, `--no-plugins` → **SAFE ×4, with one ordering constraint**

- **No Composer plugin in any scoped set.** Parsed all four `composer-deps.lock`: zero
  packages of type `composer-plugin`/`composer-installer`. Package lists: delife 35,
  wpify-website 21, sdcentral 16, stavbadesign 14 — all plain libraries
  (php-di, twig, guzzle, phpspreadsheet, libphonenumber, ramsey/uuid, wpify/*).
  delife's `composer-deps.json` `config.allow-plugins` names four installers, but none is
  actually required. **`--no-plugins` on the nested install is safe.**
- **`--no-scripts` must NOT be added** — the nested `post-install-cmd` is what runs
  php-scoper, `dump-autoload` and `postinstall.php` (`src/Plugin.php:169-173`).
- **Exit codes:** none of the four has a `post-install-cmd`/`post-update-cmd` in its own
  `scripts` that would be skipped by the current `exit()`. delife's
  `post-autoload-dump: php composer.postinstall.php` fires *before* `post-install-cmd`,
  so it already runs. No CI step depends on `composer install` exiting 0 while scoping
  fails — the current `Application::run()` already propagates the nested code via
  `autoExit`.
- **Ordering constraint (repeat of C5):** if C5 lands without `--no-plugins` **or** the
  M4 re-entrancy guard, the nested install (no `extra`) hard-fails and scoping breaks for
  all four. Same for H17: with a re-entrancy path into `createScoperConfig()`,
  `require_once` returns `true` and `src/Plugin.php:215` `exit;` fires silently.

### H7 — make `--no-dev` reachable → **SAFE ×4 (no-op)**

`packages-dev` is **empty** in all four `composer-deps.lock`, and none of the four
`composer-deps.json` has a `require-dev` block. Nothing disappears. Honouring the outer
run's dev mode is likewise a no-op even though all four CI pipelines pass `--no-dev` to
the outer install.

### H14 / H15 — `plugin-update-checker` → **not applicable ×4**

- `plugin-update-checker` is not in any project's `globals` (three use the default
  `[wordpress, woocommerce, action-scheduler, wp-cli]`; sdcentral uses `["wordpress"]`).
- `yahnis-elsts/plugin-update-checker` appears in **none** of the four
  `composer-deps.lock` files.
- No occurrence of `Puc_v4`, `YahnisElsts` or `plugin-update-checker` in any project's
  `src/`, `config/` or `mu-plugins/` (grep, excluding `deps/`).

Dropping the built-in list entirely is invisible to this cluster.

### M3 — stop writing generated `scripts` into `composer-deps.json` → **SAFE ×4**

None of the four `composer-deps.json` contains a `scripts` block. Reading
`src/Plugin.php:169-175`, the generated scripts are written to
`path($source, 'composer.json')` — the *temp* copy — not back to the project file, so
there is nothing to lose. Confirmed empirically: `git status --porcelain
composer-deps.json composer-deps.lock scoper.custom.php` is **clean** in all four despite
recent builds, and no `composer-deps.lock` contains a host path or a `tmp-` fragment
(`grep -c '/Users/\|/builds/\|tmp-'` → 0 ×4). `composer-deps.json` is committed in all
four and does not churn.

### M15 — validate `globals` entries → **SAFE ×4**

sdcentral's `["wordpress"]` maps to `symbols/wordpress.php`, which exists. The other three
do not set `globals` and take the default. No invalid names anywhere. (See §3 for the
separate observation that sdcentral's list is *valid but incomplete*.)

### delife's three worktrees → **SAFE**

`.worktrees/{b2b-api-integration,openrouter-migration,redesign-2026}` each hold a full
checkout with its own `composer.json` (all three: `prefix: DelifeDeps`,
`folder: web/app/deps`), its own `composer-deps.json`/`.lock`, its own built
`web/app/deps`, and its own byte-identical `scoper.custom.php`.

- Temp dir is `getcwd()/tmp-<10 random chars>`, so a build in a worktree stays inside that
  worktree; no shared temp path, no cross-worktree collision, same filesystem as its own
  `deps/`.
- `getcwd()` inside a worktree is the worktree root, so H18's project-root resolution
  (and the current `getcwd()` behaviour) finds the worktree's own `scoper.custom.php`.
- `.worktrees/` is gitignored in the main repo (`delife/.gitignore:60`), so a stray
  `deps.bak` under a worktree is invisible to the main checkout.
- The php-scoper `Finder` only scans `$source/vendor` (`config/scoper.inc.php:25-33`), so
  the presence of `.worktrees/` inside the project root does not enlarge any scan.

---

## 6. Verdict table

| Item | delife | stavbadesign | wpify-website | sdcentral |
|---|---|---|---|---|
| **C4** bump `require.php` to `^8.2` | SAFE | SAFE | SAFE | SAFE |
| **C5** fail fast on missing/invalid prefix | **BREAKING** ¹ | **BREAKING** ¹ | **BREAKING** ¹ | **BREAKING** ¹ |
| **C3** atomic `.bak` swap | SAFE ² | SAFE ² | SAFE ² | SAFE ² |
| **C2** `is_link()` guard in `remove()` | SAFE | SAFE | SAFE | SAFE |
| **C1+M4** subprocess / exit code / re-entrancy / `--no-plugins` | SAFE ³ | SAFE ³ | SAFE ³ | SAFE ³ |
| **H1** anchored prefix-stripping | SAFE ⁴ | SAFE ⁴ | SAFE ⁴ | SAFE ⁴ |
| **H2** narrow `autoload_static` rewrite to `$files` | SAFE | SAFE | SAFE | SAFE |
| **H7** reachable `--no-dev` | SAFE (no-op) | SAFE (no-op) | SAFE (no-op) | SAFE (no-op) |
| **H14/H15** plugin-update-checker | n/a | n/a | n/a | n/a |
| **H16** regenerated symbol lists | SAFE ⁵ | SAFE ⁵ | SAFE ⁵ | SAFE ⁵ |
| **H18** `scoper.custom.php` discovery | SAFE ⁶ | n/a (no custom file) | n/a | n/a |
| **M3** stop writing generated `scripts` | SAFE | SAFE | SAFE | SAFE |
| **M15** validate `globals` | SAFE | SAFE | SAFE | SAFE ⁷ |
| *(pre-existing, not a proposed change)* incomplete `globals` | — | — | — | **BROKEN TODAY** ⁸ |

¹ Breaks **only if** the error also fires when `extra.wpify-scoper` is entirely absent.
  The plugin is global and runs for every project, including `COMPOSER_HOME` during
  `composer global require wpify/scoper` (present in all four CI pipelines) and the nested
  scoped install. Scope the validation to "key present but `prefix` bad" → then SAFE ×4.
² Leftover `web/app/deps.bak` is not gitignored in any of the four; cosmetic only.
³ Conditional on landing with C5's scoping (or the M4 guard / `--no-plugins`), and on not
  adding `--no-scripts`.
⁴ Conditional on boundary-anchoring (allow `\` and `::` continuation; longest-namespace-
  prefix lookup), not whole-string equality. Case-insensitivity measured: 0 change.
⁵ Land H1 first or together — H16 introduces the short root-level names `SMTP`,
  `SimplePie`, `PHPMailer`. Verified 0 collisions and 0 mangle risk in all four builds.
  Not verified against an actual regenerated list (none available); see §4.
⁶ Currently applied (proven by path arithmetic), and inert in the output (proven by
  cross-project diff). No change either way.
⁷ `["wordpress"]` is a *valid* name, so M15 passes it. M15 would not catch ⁸.
⁸ 14 WooCommerce / ActionScheduler / WP-CLI symbols wrongly prefixed in sdcentral's built
  `deps/`. Pre-existing; fix is a project-side `globals` change.
