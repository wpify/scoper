# Consumer impact — group C: the `plugin-update-checker` cluster

Projects: `mawis`, `wpify-woo-dognet`, `wpify-woo-filters`.
All three set `extra.wpify-scoper.globals = ["wordpress","woocommerce","plugin-update-checker"]`
and all three ship a `scoper.custom.php`.

**Method.** Read-only. Nothing in the three project trees was modified, and no
`composer` command was run against them. Scratch scripts live in
`…/scratchpad/{h1check,h1h16,h1regress,h1regress2,verify,final}.php`.

**Two pieces of luck made this verifiable rather than speculative:**

1. `mawis` has a **committed, built `deps/`** at `/Users/wpify/projects/mawis/deps`
   (42 scoped packages, built 2026-07-17).
2. Built `deps/` for **both** `wpify-woo-dognet` and `wpify-woo-filters` exist as
   installed plugins inside an unrelated project's worktree:
   - `/Users/wpify/projects/delife/.worktrees/redesign-2026/web/app/plugins/wpify-woo-dognet/deps`
   - `/Users/wpify/projects/delife/.worktrees/redesign-2026/web/app/plugins/wpify-woo-filters/deps`

   These carry the `WpifyWooDognetDeps` / `WpifyWooFiltersDeps` prefixes, i.e. they are
   genuine CI output for these two plugins. Every claim below about "what the tool
   actually produces" is read off those trees, not inferred.

**Which version of the tool built them.** All three projects rely on a **global**
install: `/Users/wpify/.composer/vendor/wpify/scoper`, version **3.2.21**
(`/Users/wpify/.composer/vendor/composer/installed.json`). None has `wpify/scoper` in
its own `require`/`require-dev`, and no `vendor/wpify/scoper` exists in any of them.
`src/Plugin.php` and `scripts/postinstall.php` in that global install are **byte-identical**
to the audited `master` checkout, so findings transfer directly.

---

## 1. The plugin-update-checker question (H14 / H15) — **the headline**

### 1.1 What each project actually requires

| project | PUC in `composer-deps.json`? | PUC in `composer-deps.lock`? | resolved | how it arrives |
|---|---|---|---|---|
| `mawis` | no | **no — absent entirely** | — | — |
| `wpify-woo-dognet` | no (transitive) | **yes** | `dev-master` @ `288f270d` | `wpify/updates ^1` → `yahnis-elsts/plugin-update-checker: dev-master` |
| `wpify-woo-filters` | no (transitive) | **yes** | `dev-master` @ `299a8698` | same |

Evidence: `wpify-woo-dognet/composer-deps.lock` and `wpify-woo-filters/composer-deps.lock`,
package `wpify/updates` 1.0.2 — `"require": {"yahnis-elsts/plugin-update-checker": "dev-master"}`.

Both locked PUC entries autoload **`load-v5p6.php`** — i.e. **PUC v5p6**, fully namespaced
(`YahnisElsts\PluginUpdateChecker\v5p6\…`). Not v4, not v5p7.

**No project anywhere references `Puc_v4p11_*` or `Puc_v4_*`.** The only PUC-adjacent
first-party code is:

- `wpify-woo-dognet/src/Plugin.php:5` — `use WpifyWooDognetDeps\Wpify\Updates\Updates;`
- `wpify-woo-filters/src/Plugin.php:5` — `use WpifyWooFiltersDeps\Wpify\Updates\Updates;`

i.e. they never touch PUC directly; they go through `wpify/updates`, whose entire body is:

```php
// deps/wpify/updates/src/Updates.php  (scoped)
use WpifyWooFiltersDeps\YahnisElsts\PluginUpdateChecker\v5\PucFactory;
…
$url = sprintf('https://wpify.io/?update_action=get_metadata&update_slug=%s&site_url=%s', …);
PucFactory::buildUpdateChecker($url, $this->plugin_file, $this->plugin_slug);
```

The metadata URL is a plain JSON endpoint on `wpify.io` — **not** GitHub/GitLab/BitBucket.
That detail decides everything below.

### 1.2 Is PUC working today? **Yes — and the audit's F3 is wrong about why**

Finding F3 (`03-symbols-and-scoper-config.md:687-744`) says the patcher at
`config/scoper.inc.php:75-77` "is actively fatal on v5": it rewrites
`$checkerClass = $type` to `$checkerClass = "Prefix\\".$type`, producing
`Prefix\Plugin\UpdateChecker`, which F3 claims is *not* a key in
`PucFactory::$classVersions` → `getCompatibleClassVersion()` returns `null` →
`trigger_error(…, E_USER_ERROR)`.

**That reasoning omits one step.** php-scoper *also* prefixes the string-literal keys in
`load-v5pX.php`. Upstream, unscoped
(`/Users/wpify/projects/scoper/vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php:16-27`):

```php
foreach (array(
    'Plugin\\UpdateChecker' => Plugin\UpdateChecker::class,
    'Theme\\UpdateChecker'  => Theme\UpdateChecker::class,
    'Vcs\\PluginUpdateChecker' => Vcs\PluginUpdateChecker::class,
    'Vcs\\ThemeUpdateChecker'  => Vcs\ThemeUpdateChecker::class,
    'GitHubApi'    => Vcs\GitHubApi::class,
    …
) as $pucGeneralClass => $pucVersionedClass) {
    MajorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.7');
```

In the real scoped output
(`…/wpify-woo-filters/deps/yahnis-elsts/plugin-update-checker/load-v5p6.php:12`):

```php
foreach (array('WpifyWooFiltersDeps\Plugin\UpdateChecker' => Plugin\UpdateChecker::class,
               'WpifyWooFiltersDeps\Theme\UpdateChecker'  => Theme\UpdateChecker::class,
               'WpifyWooFiltersDeps\Vcs\PluginUpdateChecker' => …,
               'WpifyWooFiltersDeps\Vcs\ThemeUpdateChecker'  => …,
               'GitHubApi' => …, 'BitBucketApi' => …, 'GitLabApi' => …) as …)
```

php-scoper's `StringScalarPrefixer` prefixed every key containing a namespace separator
and left the single-segment ones (`GitHubApi`, …) alone.

And in the same build,
`…/deps/yahnis-elsts/plugin-update-checker/Puc/v5p6/PucFactory.php:81`:

```php
$checkerClass = "WpifyWooFiltersDeps\\".$type . '\UpdateChecker';   // → WpifyWooFiltersDeps\Plugin\UpdateChecker
```

**The registry key and the lookup key match exactly.** The `:75-77` patcher is not a bug —
it is the *compensation* for php-scoper's string-literal prefixing of `load-v5pX.php`.
Remove the patcher and the lookup becomes `Plugin\UpdateChecker` while the registry key
stays `Prefix\Plugin\UpdateChecker` → `null` → **that** is when `E_USER_ERROR` fires.

Reproduced identically in **three independent builds**:

| build | `PucFactory.php:81` | `load-v5p6.php:12` first key |
|---|---|---|
| `wpify-woo-filters/deps` | `"WpifyWooFiltersDeps\\".$type . '\UpdateChecker'` | `'WpifyWooFiltersDeps\Plugin\UpdateChecker'` |
| `wpify-woo-dognet/deps` | `"WpifyWooDognetDeps\\".$type . '\UpdateChecker'` | `'WpifyWooDognetDeps\Plugin\UpdateChecker'` |
| `mawis/web/app/plugins/rosettapress/vendor/rosettapress-deps` | `"RosettaPressDeps\\".$type . '\UpdateChecker'` | `'RosettaPressDeps\Plugin\UpdateChecker'` |

The same mechanism applies to v5p7 — `PucFactory.php:99` and `load-v5p7.php` have the same
shape as v5p6.

**One real half-bug that F3 did not catch.** The patcher only matches the literal
`$checkerClass = $type`, so the VCS branch two lines down is left alone:

```php
// PucFactory.php:84 (scoped, unchanged)
$checkerClass = 'Vcs\\' . $type . 'UpdateChecker';    // → 'Vcs\PluginUpdateChecker'
// registry key is 'WpifyWooFiltersDeps\Vcs\PluginUpdateChecker'  → MISS → E_USER_ERROR
```

So **GitHub/GitLab/BitBucket-hosted update checking is genuinely broken in every scoped
build**, while JSON-metadata checking works. Neither of these projects uses the VCS path
(`wpify/updates` always passes a `wpify.io` JSON URL), so it does not bite them.

### 1.3 What `symbols/plugin-update-checker.php` contributes today

Nothing. It supplies 33 `Puc_v4p11_*` names under `expose-classes`. Verified on the built output:

- `grep -c "Puc_" deps/scoper-autoload.php` → **0** — no exposure aliases were emitted
  (and `postinstall.php` comments out `humbug_phpscoper_expose_*` anyway: 86 lines are
  commented in that file).
- No `Puc_v4*` class exists in any locked package.

Because `wordpress` + `woocommerce` are also in `globals`, `exclude-classes` and
`exclude-namespaces` are non-empty, so **F5's `TypeError` is not reachable** for any of
these three. Merged config: `exclude-classes=1064`, `exclude-namespaces=200`.

### 1.4 Verdict on dropping built-in PUC support

The proposal has two separable halves. They have **opposite** risk profiles.

| sub-change | mawis | dognet | filters |
|---|---|---|---|
| Delete `symbols/plugin-update-checker.php` + the `globals` branch at `src/Plugin.php:230-235` | **SAFE** | **SAFE** | **SAFE** |
| Delete the dead v4p11 patcher `scoper.inc.php:48-50` | **SAFE** | **SAFE** | **SAFE** |
| Delete the `$checkerClass = $type` patcher `scoper.inc.php:75-77` | **SAFE** (no PUC in deps) | **BREAKING** | **BREAKING** |

**Deleting `scoper.inc.php:75-77` breaks `wpify-woo-dognet` and `wpify-woo-filters`.**
Exact failure: on every admin page load, `Wpify\Updates\Updates::init_udates_check()`
(hooked to `init`) calls `PucFactory::buildUpdateChecker()`; `getCompatibleClassVersion('Plugin\UpdateChecker')`
returns `null`; `PucFactory.php:89-90` fires `trigger_error(…, E_USER_ERROR)` — a **fatal**,
white-screening the site. Every WPify plugin that depends on `wpify/updates` is affected,
which from the evidence on disk is most of the fleet (`wpify-woo-gopay`, `rosettapress`,
`wpify-woo-conditional-shipping`, `wpify-woo-phone-validation`, `wpify-woo-zbozi-conversions`,
`wpify-woo-feeds`, `wpify-woo-fakturoid` all ship the same `load-v5p6.php`).

**Recommendation.** Drop the symbol file and the `globals` key (dead weight, and the
`expose-classes` key is genuinely wrong). **Keep the `$checkerClass` patcher**, and fix it
properly rather than deleting it — the right change is to also handle the VCS branch:

```php
if ( strpos( $filePath, 'yahnis-elsts/plugin-update-checker' ) !== false ) {
    $content = str_replace( '$checkerClass = $type',      '$checkerClass = "' . $prefix . '\\\\".$type',      $content );
    $content = str_replace( "\$checkerClass = 'Vcs\\\\'", "\$checkerClass = '" . $prefix . "\\\\Vcs\\\\'",    $content );
}
```

That turns a currently-half-working integration into a fully working one and costs nothing.
If the maintainer still wants PUC out of the core tool, it must move to the F11
user-patcher mechanism **in the same release**, and `wpify/updates` (or every consumer's
`scoper.custom.php`) must carry the patcher — otherwise this is a fleet-wide fatal.

---

## 2. `scoper.custom.php` and H18

### 2.1 H18 as stated does not reproduce — the custom file **is** being applied

`Plugin::createPath()` (`src/Plugin.php:268-276`) gates on:

```php
$vendor = strpos( dirname( __DIR__ ), 'vendor' . DIRECTORY_SEPARATOR . 'wpify' . DIRECTORY_SEPARATOR . 'scoper' );
```

For the global install `dirname(__DIR__)` is `/Users/wpify/.composer/vendor/wpify/scoper`,
which **does** contain the literal `vendor/wpify/scoper`. Composer's global install still
lays packages out under `$COMPOSER_HOME/vendor/`, so the substring matches and
`createPath(['scoper.custom.php'], true)` correctly returns `getcwd().'/scoper.custom.php'`.

**Empirically confirmed** with an unambiguous marker. `mawis/scoper.custom.php:24-33`
flips `protected $client` → `public $client` in exactly four Raynet SDK classes:

| class | `mawis/deps/wpify/raynet-api-php-sdk/lib/Api/…` line 52 | named in `scoper.custom.php`? |
|---|---|---|
| `KlientiApi` | `public $client;` | yes |
| `KontaktnOsobyApi` | `public $client;` | yes |
| `SelnkyApi` | `public $client;` | yes |
| `ObchodnPpadyApi` | `public $client;` | yes |
| `ProduktApi` | `protected $client;` | **no (control)** |
| `AktivityApi` | `protected $client;` | **no (control)** |
| `WebhookApi` | `protected $client;` | **no (control)** |
| `DiskuzeApi` | `protected $client;` | **no (control)** |

A perfect split along the list in `scoper.custom.php`. The customization is live.

Corroborating: `mawis/deps/league/oauth2-client/src/Grant/GrantFactory.php:64` reads
`$class = '\MawisDeps\League\OAuth2\Client\Grant\\' . $class;` — the leading-backslash form
that only the custom patcher (`mawis/scoper.custom.php:5-11`) produces; the built-in
patcher at `scoper.inc.php:71-73` emits it without the leading backslash.

**Verdict: H18 is a real code smell but not a live defect for these three.** It would bite
only an install layout where the package path lacks `vendor/wpify/scoper` (a `path`
repository, a git clone symlinked in, a phar). Fixing it is **SAFE** here — the behaviour
it would "start" is already happening.

### 2.2 What each custom file patches, and whether it does anything

**`mawis/scoper.custom.php`** — 4 patches, **3 live, 1 dead**:

| lines | patch | status |
|---|---|---|
| `:5-11` | `'League\OAuth2\Client\Grant\\'` → `'\MawisDeps\…'` | **live** (see `GrantFactory.php:64`) |
| `:13-16` | `'\\RaynetApiClient\\Model` → `'\\MawisDeps\\RaynetApiClient\\Model`; ` \RaynetApiClient` → ` \MawisDeps\RaynetApiClient` | **live** (raynet SDK is in `deps/wpify/raynet-api-php-sdk`) |
| `:18-29` | `protected $client` → `public $client` ×4 | **live** (table above) |

Note `:5-11` and the built-in `scoper.inc.php:71-73` target the same file. The built-in runs
first (patchers array order), rewriting `League\\OAuth2\\Client\\Grant` →
`MawisDeps\\League\\…`, after which the custom needle `'League\OAuth2\Client\Grant\\'`
(quote-anchored) no longer matches. The observed leading-backslash form comes from
php-scoper's own string-literal prefixing. **Redundant but harmless** — flag for the owner,
do not "fix" as part of this work.

**`wpify-woo-dognet/scoper.custom.php`** — 1 patch, **currently a no-op**:
strips `WpifyWooDognetDeps\ICL_LANGUAGE_CODE` in files under `woo-core`. Grep of the built
`deps/`: **0** prefixed occurrences; all 4 sites
(`deps/wpify/woo-core/src/Admin/Settings.php:246,248,249`,
`deps/wpify/woo-core/src/Abstracts/AbstractModule.php:25,27`) are already bare, because
`expose-global-constants => false` means php-scoper never prefixes an undeclared global
constant. Harmless insurance.

**`wpify-woo-filters/scoper.custom.php`** — 2 blocks, **both no-ops**:

- `:5-9` guards on `strpos($filePath, 'wpify/core')`. The package is `wpify/**woo**-core`;
  `'wpify/core'` is **not** a substring of `'wpify/woo-core'`, and no package named
  `wpify/core` exists in `composer-deps.lock`. **This branch has never fired.** Its three
  replacements (un-prefixing `array_merge`, `wpml_object_id_filter`, `WP_Post`) are
  therefore untested; note `array_merge` and `WP_Post` are already handled globally
  (`expose-global-functions => false`; `WP_Post` ∈ `exclude-classes`).
- `:11-12` — same `ICL_LANGUAGE_CODE` no-op as dognet.

### 2.3 Would any custom patcher conflict with the H1 anchored rewrite?

**No.** H1 replaces the generic un-prefixing block (`scoper.inc.php:79-103`) which lives in
the built-in patcher; `scoper.custom.php` appends *additional* entries to
`$config['patchers']` that run afterwards and are untouched. Checked individually:

- mawis `RaynetApiClient` — *adds* a prefix; `RaynetApiClient` is not an excluded symbol, so
  anchored matching neither strips it before nor after. No interaction.
- mawis `protected $client` — not namespace-related.
- dognet/filters `ICL_LANGUAGE_CODE` — needles are the full constant name, already exact-match
  anchored. No interaction.
- filters `wpify/core` block — dead; cannot conflict.

---

## 3. H1 — the one place anchoring costs something

`mawis`: **SAFE.** A full scan of all 37 534 PHP files in `mawis/deps` for tokens where a
short excluded symbol is a strict prefix of a referenced name found **zero** hits. Its
scoped namespaces (`GuzzleHttp`, `Microsoft`, `OpenTelemetry`, `Ramsey`, `Symfony`, `Brick`,
`Doctrine`, `League`, `Nyholm`, `Psr`, `Http`, `StdUriTemplate`, `RaynetApiClient`, …) collide
with nothing in the 1 264-entry class+namespace exclusion list. Anchoring changes nothing.

`wpify-woo-dognet` / `wpify-woo-filters`: **NEEDS-MIGRATION.** Both bundle
`woocommerce/action-scheduler` 3.9.3, which has a WP-CLI integration — and **both projects
override `globals` and drop `wp-cli`** (and `action-scheduler`) from the tool's defaults at
`src/Plugin.php:60`. So `WP_CLI` is **not** in the exclusion lists, php-scoper prefixes it,
and the *unanchored* needle `\{prefix}\WP` (from `WP` ∈ `exclude-classes`) strips it back by
accident. Anchoring stops that. Affected sites in `wpify-woo-filters/deps` (dognet identical):

| currently de-prefixed token | refs | example |
|---|---|---|
| `\WP_CLI` (static calls) | 36 | `woocommerce/action-scheduler/classes/WP_CLI/Action/Get_Command.php:23` |
| `\WP_CLI\Utils\get_flag_value` | 13 | `…/WP_CLI/ActionScheduler_WPCLI_Clean_Command.php:39` |
| `\WP_CLI\ExitException` | 8 | `…/WP_CLI/ActionScheduler_WPCLI_Clean_Command.php:32` (docblock) |
| `\WP_CLI\Formatter` | 7 | `…/WP_CLI/Action/Get_Command.php:33` |
| `\WP_CLI\Utils\make_progress_bar` | 4 | `…/WP_CLI/ProgressBar.php:125` |
| `\WP_CLI_Command` | 2 | `…/WP_CLI/Action_Command.php:8` (`extends`) |
| `\WP_CLI\CommandWithDBObject` | 1 | `…/abstracts/ActionScheduler_WPCLI_Command.php:63` (docblock) |

Proof php-scoper really did prefix `WP_CLI`: **28 references survive prefixed** in the same
tree, in the two forms the `str_replace` needles cannot reach —
`use function WpifyWooFiltersDeps\WP_CLI\Utils\get_flag_value;`
(`…/Action/Cancel_Command.php:5`, `…/Action/Generate_Command.php:5`, `…/System_Command.php:8`, …)
and `defined('WpifyWooFiltersDeps\WP_CLI')` (`…/ProgressBar.php:60`, `…/Migration_Command.php:33`,
`…/migration/Controller.php:146`, `…/abstracts/ActionScheduler.php:231`).

**Severity is low in practice, and the reason matters.**
`classes/abstracts/ActionScheduler.php:231` is the command-registration gate:

```php
if (\defined('WpifyWooFiltersDeps\WP_CLI') && \WP_CLI) {
    WP_CLI::add_command('action-scheduler', 'ActionScheduler_WPCLI_Scheduler_command');
    …
}
```

`defined('WpifyWooFiltersDeps\WP_CLI')` can never be true, so **Action Scheduler's WP-CLI
commands are already never registered** in these scoped builds. The code H1 would re-prefix
is already unreachable. So H1 does not *newly* break a working feature — it converts a
latent accident into a consistent (still-broken) state.

**Migration, one line, no tool change:** add `"wp-cli"` to `globals` in
`wpify-woo-dognet/composer.json:20-24` and `wpify-woo-filters/composer.json:36-42`. That puts
`WP_CLI` in `exclude-namespaces`/`exclude-classes`, php-scoper stops prefixing it everywhere
(including `use function` and `defined()` literals), and the integration works for the first
time. Worth doing **before** H1 lands.

**Recommendation for the tool:** since all three projects drop `wp-cli` and `action-scheduler`
by overriding `globals` wholesale, consider making `globals` *additive* to the defaults, or
warn when a bundled package (action-scheduler) is present but its symbol file is not selected.
That is F11/M15 territory.

---

## 4. H2 — narrow the `autoload_static.php` rewrite to `$files`

**SAFE for all three.** `postinstall.php:38-44` applies
`/'([[:alnum:]]+)'\s*=>\s*([a-zA-Z0-9 .'"\/\-_]+),/` to the whole file. Measured on the built
output — it only ever hit the `$files` array:

| project | rewritten keys | all in `$files`? | classmap keys with no `\` |
|---|---|---|---|
| `mawis` | 16 | yes (`mawisdeps5897ea0a…` → `symfony/polyfill-php82/bootstrap.php`, …) | **0** |
| `wpify-woo-dognet` | 3 | yes (incl. `…f6d4f6bc…` → `yahnis-elsts/plugin-update-checker/load-v5p6.php`) | **0** |
| `wpify-woo-filters` | 3 | yes | **0** |

`$prefixLengthsPsr4`/`$prefixDirsPsr4` entries are safe because their values start with
`array (`, and `(` is outside the value character class; classmap keys all contain `\`, which
is not `[:alnum:]`. Narrowing the regex to `$files` is behaviour-preserving here and removes a
real latent hazard (a scoped package declaring a single-segment global class with no
underscore would today get a corrupted classmap key).

---

## 5. Remaining checklist items

### C4 — bump `require.php` to `^8.2`

Keep the two PHP versions apart:

- **PHP the tool runs on.** `mawis/.gitlab-ci.yml:38` runs the composer job in image
  `composer:2.8.2`; verified by pulling it: **PHP 8.3.13**. It does
  `composer global require wpify/scoper --with-all-dependencies` (`.gitlab-ci.yml:64`), so
  `^8.2` resolves. Local dev is `.ddev/config.yaml:4` → `php_version: "8.3"`.
  `wpify-woo-dognet` and `wpify-woo-filters` delegate to
  `wpify/gitlab-ci-templates` `/pipelines/wpify-plugin.yml` (`.gitlab-ci.yml:4-7`), which is
  **not checked out locally** — see UNKNOWN below.
- **`config.platform.php`.** `wpify-woo-filters/composer.json:9` pins `8.0.30`, but that
  governs only the *outer* project's own `vendor/` (which is dev-only: phpunit, wp-phpunit,
  polyfills). The scoped resolution is governed by `wpify-woo-filters/composer-deps.json:12-14`
  → `"platform": {"php": "8.1"}` with `"require": {"php": ">=8.1.0"}`. Confirmed on the built
  output: `deps/composer/platform_check.php:7` asserts `PHP_VERSION_ID >= 80100`. The `8.0.30`
  pin is **irrelevant to C4**.
  (mawis: `composer-deps.json` platform `8.3`, `deps/composer/platform_check.php` asserts
  `>= 80200`. dognet: platform `8.1.0`, asserts `>= 80100`.)

| project | verdict |
|---|---|
| mawis | **SAFE** (PHP 8.3.13 in CI, 8.3 in DDEV) |
| dognet | **UNKNOWN** — shared CI template not available locally |
| filters | **UNKNOWN** — same; the `8.0.30` platform pin is a red herring, not a blocker |

### C5 — fail fast on missing/invalid prefix

**SAFE ×3.** `MawisDeps` (`mawis/composer.json:104`), `WpifyWooDognetDeps`
(`wpify-woo-dognet/composer.json:19`), `WpifyWooFiltersDeps`
(`wpify-woo-filters/composer.json:38`) are all present and legal PHP namespace identifiers.
Nothing relies on the silent no-op at `src/Plugin.php:127`.

### C3 — atomic `.bak` swap

**SAFE ×3.** `folder` is the default `deps` at the **project root** in every case — not inside
`vendor/`. A `deps.bak` sibling would land at the project root.

- `mawis/.gitignore:33` has `/deps`; dognet `.gitignore:5` and filters `.gitignore:5` have
  `/deps/`. **None of these patterns match `deps.bak`**, so a crash mid-swap would leave an
  untracked directory visible in `git status`. Cosmetic; worth `.gitignore`-ing `deps.bak` or
  using a dot-prefixed name.
- `mawis/.gitlab-ci.yml:47` archives `$CI_PROJECT_DIR/deps` as an artifact and
  `.gitlab-ci.yml:96` deploys `deps/` explicitly — neither would pick up `deps.bak`.
- Same filesystem in all cases (project root ↔ `deps/`); no Docker/DDEV mount crosses that
  boundary.

### C2 — `is_link()` guard in `remove()`

**SAFE ×3.** `mawis/deps` is not a symlink and contains **0** symlinks (`find -type l`).
No `path` repositories in any `composer-deps.json`. mawis' `composer.json:21-24` has a `path`
repo (`lib/MawisApi`) but that is the *outer* project, which `remove()` never touches.

### C1 + M4 — subprocess, exit code, `--no-plugins`

| project | composer-plugin packages in scoped deps | currently running? | `--no-plugins` verdict |
|---|---|---|---|
| mawis | `php-http/discovery`, `tbachert/spi` | **no** | **SAFE** |
| dognet | none | — | **SAFE** |
| filters | none | — | **SAFE** |

`mawis/composer-deps.json` has no `allow-plugins`, and the built output proves neither plugin
ran: `mawis/deps/composer/` contains no `GeneratedDiscoveryStrategy.php` and no
`GeneratedServiceProviderData.php`. So `--no-plugins` is a no-op today.
`woocommerce/action-scheduler` is `type: wordpress-plugin` in dognet/filters but no
`composer/installers` is present; it lands at `deps/woocommerce/action-scheduler` via the
default fallback, which `--no-plugins` does not change.

**Exit-code propagation (C1) is a real improvement, not a risk**, for `mawis`: the CI job at
`.gitlab-ci.yml:65` is a bare `composer install` whose artifacts (`.gitlab-ci.yml:47`) include
`deps/`. Today a failed scoping run still exits 0 and publishes a stale or partial `deps/`.
Nothing in any of the three pipelines *depends* on `composer install` masking a scoper failure.

### H7 — make `--no-dev` reachable

**Not applicable ×3.** None of the three `composer-deps.json` files has a `require-dev`
section (verified by grep). `--no-dev` on the nested install changes nothing.
Note `mawis/.gitlab-ci.yml:65` already passes `--no-dev` to the *outer* install.

### H16 — regenerated symbol lists

**SAFE ×3.** Scanned all built `deps/` for declarations of the ~18 function-body symbols the
new `SymbolCollector` would add (`WP_Block_Cloner`, `wxr_cdata`, `lowercase_octets`,
`wp_handle_upload_error`, `_sort_priority_callback`, `filter_created_pages`) and for any
`SODIUM_*` reference (the 97 new constants): **zero hits in all three projects**.
No scoped package declares or consumes a newly-excluded symbol.

### M3 — stop writing generated `scripts` into `composer-deps.json`

**SAFE ×3**, mildly beneficial for mawis. Only `mawis/composer-deps.json:14` has a `scripts`
block and it is the **empty** `"scripts": {}` — a leftover, not hand-written; the generated
absolute-path/`tmp-XXXX` entries are written to the *temp* copy
(`src/Plugin.php:169-175` writes to `$source/composer.json`), not back to the user's file.
dognet and filters have no `scripts` key at all. All three track `composer-deps.json`,
`composer-deps.lock` and `scoper.custom.php` in git (verified via `git ls-files`), so no churn
is being introduced today, and none would be removed.

### M15 — validate `globals`

**SAFE ×3.** All three use `["wordpress","woocommerce","plugin-update-checker"]`; every entry
maps to an existing file in `symbols/`. No typos.
Worth recording under M15 that validation would **not** catch the real problem here, which is
the *omission* of `wp-cli`/`action-scheduler` — see §3.

---

## Verdict table

| item | mawis | wpify-woo-dognet | wpify-woo-filters |
|---|---|---|---|
| **H14/H15** drop `symbols/plugin-update-checker.php` + `globals` branch | SAFE | SAFE | SAFE |
| **H14/H15** drop dead v4p11 patcher (`scoper.inc.php:48-50`) | SAFE | SAFE | SAFE |
| **F3/H15** drop `$checkerClass` patcher (`scoper.inc.php:75-77`) | SAFE | **BREAKING** | **BREAKING** |
| **H18** fix `scoper.custom.php` discovery | SAFE (already applied) | SAFE (already applied) | SAFE (already applied) |
| custom patchers vs **H1** | SAFE (no conflict) | SAFE (no conflict) | SAFE (no conflict) |
| **H1** anchored prefix-stripping | SAFE | **NEEDS-MIGRATION** (add `wp-cli` to `globals`) | **NEEDS-MIGRATION** (add `wp-cli` to `globals`) |
| **H2** narrow `autoload_static` rewrite to `$files` | SAFE | SAFE | SAFE |
| **C1+M4** subprocess / exit code / `--no-plugins` | SAFE | SAFE | SAFE |
| **C2** `is_link()` guard | SAFE | SAFE | SAFE |
| **C3** atomic `.bak` swap | SAFE (`.gitignore` misses `deps.bak`) | SAFE (same) | SAFE (same) |
| **C4** require PHP `^8.2` | SAFE (CI PHP 8.3.13) | UNKNOWN (shared CI template) | UNKNOWN (shared CI template); `platform 8.0.30` is a red herring |
| **C5** fail fast on bad prefix | SAFE | SAFE | SAFE |
| **H7** reachable `--no-dev` | n/a (no `require-dev`) | n/a | n/a |
| **H16** regenerated symbol lists | SAFE | SAFE | SAFE |
| **M3** stop writing `scripts` | SAFE | SAFE | SAFE |
| **M15** validate `globals` | SAFE | SAFE | SAFE |

### UNKNOWNs

- **C4 for dognet and filters.** Both `.gitlab-ci.yml` files consist solely of
  `include: project: 'wpify/gitlab-ci-templates', file: '/pipelines/wpify-plugin.yml'`.
  That repository is not checked out under `/Users/wpify/projects`, so the PHP version of the
  image that runs `composer global require wpify/scoper` cannot be read. **This single file
  gates the C4 answer for the entire WPify plugin fleet, not just these two** — it should be
  checked before the bump ships.
- Whether `wpify/updates` is ever invoked with a VCS metadata URL by any *other* consumer. In
  these three it is always the `wpify.io` JSON endpoint, so the broken VCS branch (§1.2) is
  latent. A fleet-wide grep for `buildUpdateChecker` with a github.com/gitlab.com URL would
  settle it.
