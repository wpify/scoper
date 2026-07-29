# Consumer impact — Group F: monorepo cluster

**Projects:** `sales-booster-kit` (root + 6 sub-modules), `feed.szn`
**Audited against:** `wpify/scoper` 3.2.x working tree at `/Users/wpify/projects/scoper`
**Method:** read-only. No edits, no `composer` runs, no writes inside consumer projects.

---

## 0. Headline answers

### 0.1 Path repositories / symlinks (finding C2) — **SAFE, not applicable**

The six sub-modules are **git submodules, not Composer `path` repositories.**

- `sales-booster-kit/.gitmodules:1-18` declares all six under `src/Modules/`, each pointing at
  `git@gitlab.wpify.io:commercial-plugins/*.git`.
- There is **no `repositories` block in any of the 8 composer.json / composer-deps.json files** in this
  cluster (verified by grep across all of them).
- The root `composer.json` does not `require` any module. The root and the modules are installed
  completely independently; Composer never links them.
- `find -type l` across both projects (excluding `node_modules`) returns **zero symlinks** in
  `sales-booster-kit`, and in `feed.szn` only `.ddev/custom_certs/*` and two unrelated files inside
  `vendor/humbug/php-scoper` and `vendor/fidry/console` — none of which is inside any `deps` target.
- Every `deps` target is a real directory (`ls -ld` confirms `drwxr-xr-x`, not `lrwx`).

So the C2 scenario — `remove()` following a symlink into a developer's real module source — **cannot
occur here.** Adding the `is_link()` guard is a pure no-op for this cluster.

For the record, the underlying hazard in `scripts/postinstall.php:3-10` is real: `remove()` tests
`is_dir($full)`, which returns `true` for a symlink-to-directory, so it would recurse into and empty
the link target. This cluster simply never presents that input.

### 0.2 Recursion (finding M4) — **SAFE**

**No `composer-deps.json` anywhere in this cluster contains an `extra` key, let alone
`extra.wpify-scoper`.** All eight were grepped individually:

```
sales-booster-kit/composer-deps.json
sales-booster-kit/src/Modules/sales-booster-extras/composer-deps.json
sales-booster-kit/src/Modules/wpify-woo-conditional-shipping/composer-deps.json
sales-booster-kit/src/Modules/wpify-woo-discount-rules/composer-deps.json
sales-booster-kit/src/Modules/wpify-woo-feeds/composer-deps.json
sales-booster-kit/src/Modules/wpify-woo-phone-validation/composer-deps.json
sales-booster-kit/src/Modules/wpify-woo-product-vouchers/composer-deps.json
feed.szn/composer-deps.json
```

`find` confirms these are the only `composer-deps.json` files in either project. The unbounded-recursion
configuration described in M4 does not exist here, and fixing C1 will not introduce it.

---

## 1. Monorepo map

`sales-booster-kit` performs **seven fully independent scoping runs** — the root plus each of the six
submodules — producing seven separate scoped trees, seven `composer-deps.lock` files and seven
`tmp-XXXXXXXXXX` working dirs. Confirmed by the presence of a distinct built tree under each module.

The default `globals` is `array('wordpress','woocommerce','action-scheduler','wp-cli')`
(`src/Plugin.php:60`). This matters: the five modules that declare `globals` **override** the default
and thereby *lose* `action-scheduler` and `wp-cli`.

| # | Unit | `prefix` | `folder` | `globals` | `scoper.custom.php` |
|---|------|----------|----------|-----------|---------------------|
| 1 | root `sales-booster-kit` | `WpifySalesBoosterDeps` | *(absent → `deps/`)* | *(absent → default 4)* | no |
| 2 | `sales-booster-extras` | `SalesBoosterExtrasDeps` | *(absent → `deps/`)* | *(absent → default 4)* | no |
| 3 | `wpify-woo-conditional-shipping` | `WpifyWooConditionalShippingDeps` | `vendor/wpify-woo-conditional-shipping` | wordpress, woocommerce, plugin-update-checker | **yes** |
| 4 | `wpify-woo-discount-rules` | `WpifyWooDiscountRuleDeps` | `vendor/wpify-woo-discount-rules` | wordpress, woocommerce, plugin-update-checker | **yes** |
| 5 | `wpify-woo-feeds` | `WpifyWooFeedsDeps` | `vendor/wpify-woo-feeds` | wordpress, woocommerce, plugin-update-checker | no |
| 6 | `wpify-woo-phone-validation` | `WpifyWooPhoneValidationDeps` | `vendor/wpify-woo-phone-validation` | wordpress, woocommerce, plugin-update-checker | **yes** |
| 7 | `wpify-woo-product-vouchers` | `WpifyWooProductVouchersDeps` | `vendor/wpify-woo-product-vouchers` | wordpress, woocommerce, plugin-update-checker | **yes** |
| 8 | `feed.szn` | `ExpresFeedsDeps` | *(absent → `deps/`)* | *(absent → default 4)* | no |

Note that five of the seven `folder` values point **inside `vendor/`** — relevant to C3.

### How scoper is actually invoked

Neither `sales-booster-kit` nor any sub-module requires `wpify/scoper` at all. CI installs it globally:

- `sales-booster-kit/.gitlab-ci.yml:66-67` and `:102-103` — `composer global require wpify/scoper`
- `feed.szn/.gitlab-ci.yml` (`composer` job `before_script`) — same, plus
  `PATH=$(composer global config bin-dir --absolute --quiet):$PATH`

Modules are built in a loop at `sales-booster-kit/.gitlab-ci.yml:73-83`, each with
`(cd "$MODULE_PATH" && composer install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-reqs)`.

---

## 2. `feed.szn` and the `wpify/scoper: ^2` constraint

**It does not effectively pin to 2.x. The audited 3.2.x line is what runs in CI.**

- `feed.szn/composer.json` declares `wpify/scoper: ^2` in **`require-dev`**, and
  `feed.szn/composer.lock` resolves it to `wpify/scoper 2.5.4` (with `humbug/php-scoper 0.17.2`).
  That version *is* physically installed at `feed.szn/vendor/wpify/scoper`.
- But CI runs `composer install --no-dev`, which **never installs `require-dev`**. So 2.5.4 is absent
  from the CI container entirely.
- CI instead does `composer global require wpify/scoper` (unconstrained) and puts the global bin-dir on
  `PATH`. The globally installed version on this machine is **`wpify/scoper 3.2.21`** with
  `wpify/php-scoper 0.18.18` (from `~/.composer/vendor/composer/installed.json`).

**Conclusion:** in CI, `feed.szn` is scoped by 3.2.x, so the audit's findings **do apply to it**. Only a
local developer install (`composer install` *with* dev) gets 2.5.4 — and even then the global 3.2.21
plugin is also present, so which one handles the event is ambiguous. The `^2` constraint is
misleading and should be treated as stale metadata rather than a real pin.

This is worth fixing on the consumer side independently of any scoper change: either drop the
`require-dev` entry or move CI to a pinned `composer global require wpify/scoper:^3`.

---

## 3. Findings verified against built output

### 3.1 H1 — anchored prefix-stripping: **one real regression**

`config/scoper.inc.php:88-105` builds an **unanchored** `str_replace` over `\$prefix\$symbol`, so a
listed symbol strips any longer symbol that starts with it. I scanned all eight built trees for
genuinely global (single-segment) `\Name` references where `Name` is *not* an exact list member but
*does* have one as a strict prefix.

**Root, `sales-booster-extras`, `feed.szn` (default globals): clean.** The only hits are
`WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, `WP_MAX_MEMORY_LIMIT`, `MONTH_IN_SECONDS` — all of which are in
`symbols/wordpress.php` under **`exclude-constants`**, so php-scoper leaves them global natively and the
patcher plays no part. Anchoring changes nothing.

**The five modules with explicit `globals`: `\WP_CLI` and `\WP_CLI_Command` regress.** Because those
modules override `globals` and drop `wp-cli`, these two symbols are *only* reaching the global namespace
via the unanchored `WP` match (`WP` is in `symbols/wordpress.php` → `exclude-classes`). Under H1 they
would become `<Prefix>\WP_CLI`. Affected files (identical set in all five trees):

- `woocommerce/action-scheduler/classes/WP_CLI/…` — 12 files referencing `\WP_CLI`
- `woocommerce/action-scheduler/classes/WP_CLI/Action_Command.php` and
  `classes/abstracts/ActionScheduler_WPCLI_Command.php` — `\WP_CLI_Command`

Blast radius is small in practice: these classes only load under WP-CLI, and the scoped Action Scheduler
copy is already inert (see 3.2). But it is a genuine behaviour change.

**Recommendation:** ship H1 together with either (a) adding `wp-cli` back to these modules' `globals`,
or (b) H16 regenerating the lists such that `WP_CLI`/`WP_CLI_Command` are covered without needing the
`wp-cli` opt-in. Do not ship H1 alone.

### 3.2 Pre-existing: scoped Action Scheduler is inert (not caused by, and not fixed by, any finding)

Worth recording because it shapes the H1 risk assessment. In every scoped tree, Action Scheduler class
*declarations* land inside the prefix namespace while all *references* are de-prefixed to global:

`…/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Store.php`
```php
namespace WpifyWooFeedsDeps;

abstract class ActionScheduler_Store extends \ActionScheduler_Store_Deprecated
```

All 60 `ActionScheduler_*` symbols are in `symbols/woocommerce.php` → `exclude-classes`, so the patcher
rewrites every reference to global, but nothing rewrites the `namespace` line php-scoper added. The
scoped copy is therefore unreachable; the code works only because WooCommerce provides Action Scheduler
globally at runtime. Unchanged by the proposed work — flagging it as a separate issue.

### 3.3 H2 — narrowing the `autoload_static.php` rewrite: **SAFE, no live corruption**

`scripts/postinstall.php:41-45` applies `'/'([[:alnum:]]+)'\s*=>\s*([a-zA-Z0-9 .'\"\/\-_]+),/'` to the
whole file. I checked all eight `autoload_static.php` files for keys carrying the lowercased prefix.
**Every hit is a 32-char md5 in the `$files` array — i.e. exactly the intended target.** Example
(`sales-booster-kit/deps/composer/autoload_static.php`):

```php
public static $files = array (
    'wpifysalesboosterdepscdf08174348db7aba2f2aa1537fac4b1' => __DIR__ . '/..' . '/wpify/custom-fields/custom-fields.php',
    'wpifysalesboosterdepsbc0af1337b39f0d750e835f5263eb646' => __DIR__ . '/..' . '/yahnis-elsts/plugin-update-checker/load-v5p7.php',
    'wpifysalesboosterdepsb33e3d135e5d9e47d845c576147bda89' => __DIR__ . '/..' . '/php-di/php-di/src/functions.php',
);
```

No `$classMap` or `$prefixLengthsPsr4` key is corrupted. The natural candidate — `tecnickcom/tcpdf` in
`wpify-woo-product-vouchers`, whose classes are bare global names — is safe because php-scoper moved them
into the prefix namespace first, so the keys contain backslashes and the `[[:alnum:]]+` key pattern
cannot match:

```php
'WpifyWooProductVouchersDeps\\TCPDF' => __DIR__ . '/..' . '/tecnickcom/tcpdf/tcpdf.php',
'WpifyWooProductVouchersDeps\\TCPDF_FONTS' => …
'WpifyWooProductVouchersDeps\\QRcode' => …
```

H2 is a correctness hardening with no observable effect here.

### 3.4 H14/H15 — `plugin-update-checker`: **SAFE; the config is already dead**

`symbols/plugin-update-checker.php` contains **33 symbols, all `Puc_v4*`** — zero `Puc_v5*`, zero
`YahnisElsts\…` entries. Every consumer in this cluster resolves
`yahnis-elsts/plugin-update-checker` to **`dev-master` (v5, `Puc/v5p7`)**.

So for the five modules that list `plugin-update-checker` in `globals`, the exclusion list **matches
nothing** and PUC is scoped anyway — identical to the root, which does not list it at all. Proof, from
`wpify-woo-feeds/vendor/wpify-woo-feeds/yahnis-elsts/plugin-update-checker/load-v5p7.php:3`:

```php
namespace WpifyWooFeedsDeps\YahnisElsts\PluginUpdateChecker\v5p7;
```

…despite `plugin-update-checker` being in that module's `globals`
(`src/Modules/wpify-woo-feeds/composer.json`, `extra.wpify-scoper.globals`).

No project code references PUC directly. Every consumer goes through `Wpify\Updates\Updates`:

- `sales-booster-kit/src/Features/LicenseFeature.php:8,42`
- `src/Modules/{wpify-woo-feeds,wpify-woo-conditional-shipping,wpify-woo-discount-rules,wpify-woo-phone-validation,wpify-woo-product-vouchers}/src/Plugin.php` (each `use …Deps\Wpify\Updates\Updates;`)

which in turn calls the scoped `…Deps\YahnisElsts\PluginUpdateChecker\v5\PucFactory`
(`wpify/updates/src/Updates.php:5,26`). `feed.szn` has no PUC at all.

**Either proposal (regenerate for v5, or drop the built-in list) is SAFE.** Dropping it is a literal
no-op. Regenerating it for v5 would be a *behaviour change* — PUC would stop being scoped and start
resolving globally, which risks colliding with other plugins' PUC copies. Given every consumer here
relies on the scoped copy today, **dropping the list is the safer option for this cluster**; the five
`globals: [… plugin-update-checker]` entries should be removed as dead config either way.

Separately, a cosmetic artefact of the current path, `load-v5p7.php:12`: the registration keys are
inconsistently rewritten — `'WpifyWooFeedsDeps\Plugin\UpdateChecker'` (string patched) alongside
`'GitHubApi'`, `'BitBucketApi'`, `'GitLabApi'` (left bare, no backslash to trigger the patcher). Both
registration and lookup happen inside the scoped tree so it is self-consistent, but it is fragile.

### 3.5 H18 — `scoper.custom.php` discovery: **SAFE (currently working), and it exposes a live WPML bug**

`src/Plugin.php:268-276`: `createPath(['scoper.custom.php'], true)` returns `getcwd()/scoper.custom.php`
only when `dirname(__DIR__)` contains the literal `vendor/wpify/scoper`. Because CI installs scoper via
`composer global require`, the path is `$COMPOSER_HOME/vendor/wpify/scoper` — which **does** contain that
substring. **Discovery works today in this cluster; the H18 fix is a no-op here.**

Verified empirically, and the result is unusually clean. `ICL_LANGUAGE_CODE` is in **no** symbol list, so
its treatment depends entirely on the custom patcher:

| Tree | has `scoper.custom.php` | `wpify/woo-core/src/Abstracts/AbstractModule.php:28` |
|------|------------------------|------------------------------------------------------|
| `wpify-woo-conditional-shipping` | yes | `defined('ICL_LANGUAGE_CODE')` ✅ |
| `wpify-woo-discount-rules` | yes | bare ✅ |
| `wpify-woo-phone-validation` | yes | bare ✅ |
| `wpify-woo-product-vouchers` | yes | bare ✅ |
| **`wpify-woo-feeds`** | **no** | `defined('WpifyWooFeedsDeps\ICL_LANGUAGE_CODE')` ❌ |
| **`sales-booster-extras`** | **no** | `defined('SalesBoosterExtrasDeps\ICL_LANGUAGE_CODE')` ❌ |
| **root `sales-booster-kit`** | **no** | `defined('WpifySalesBoosterDeps\ICL_LANGUAGE_CODE')` ❌ |

The four modules carrying the file are correct. **The three units without it have a live WPML bug**: the
`defined()` guard can never be true, so WPML language handling in `wpify/woo-core` is silently dead in
`sales-booster-kit` (root), `sales-booster-extras` and `wpify-woo-feeds` — at
`AbstractModule.php:28,155,179` and `Admin/Settings.php`.

This is a consumer-side gap, not a scoper defect, but the cleanest fix is scoper-side: add
`ICL_LANGUAGE_CODE` to `symbols/wordpress.php` → `exclude-constants` and retire four copies of the
workaround. Worth folding into H16.

### 3.6 C1 + `--no-plugins`: **SAFE**

No package in any of the eight `composer-deps.lock` files has a non-library `type` — **zero
`composer-plugin` packages**. The only installer-ish requirements are transitive **`require-dev`** entries
of dependencies, which Composer never installs:

- `wpify/custom-fields` → `require-dev: dealerdirect/phpcodesniffer-composer-installer` (all 7 sbk locks)
- `giggsey/locale`, `phpstan/phpdoc-parser` → `require-dev: phpstan/extension-installer`

`--no-plugins` on the nested install is safe everywhere here. Note `wpify/plugin-composer-scripts` and
`dealerdirect/phpcodesniffer-composer-installer` *are* real plugins, but they live in the modules'
outer `composer.json` `require-dev` — untouched by the nested install, and skipped in CI anyway
because CI uses `--no-dev`.

**Exit-code propagation is a clear improvement here.** The module loop
(`.gitlab-ci.yml:71-83`) runs under `set -e`, so a propagated failure would correctly fail the job.
Today the only safety net is a root-only artefact check at `.gitlab-ci.yml:139-140`:

```
test -f "$EXPORT_DIR/$PLUGIN_SLUG/deps/scoper-autoload.php"
test -f "$EXPORT_DIR/$PLUGIN_SLUG/deps/autoload.php"
```

There is **no equivalent check for any of the six modules**, so a module whose scoping silently failed
would ship a broken `vendor/<name>/` today. C1 fixes that.

### 3.7 C3 — atomic swap with a `.bak` sibling: **SAFE for root/feed.szn, minor caveat for 5 modules**

`tempDir` is `getcwd()/tmp-XXXXXXXXXX` (`src/Plugin.php:58`) — same filesystem as the deps target in
every case, so `rename()` (and a `.bak` swap) is sound. No Docker/DDEV cross-mount issue: `feed.szn` uses
DDEV but scoping runs in CI on `composer:2.8.2`, and locally the project dir is a single mount.

- **Root / `sales-booster-extras` / `feed.szn`** — `deps.bak` would sit at the project root. Not shipped:
  root packaging copies only `jq -r '.files[]' package.json` (`.gitlab-ci.yml:130-136`) and `files` lists
  `deps`, not `deps.bak`; `feed.szn` deploys via `rsync --include-from=./.rsyncinclude --exclude="*"`,
  which lists `/deps/***` only. **SAFE.**
- **Five modules with `folder: vendor/<name>`** — the backup lands at `vendor/<name>.bak`, **inside
  `vendor/`**. Each module's `package.json` `files` includes `vendor` wholesale, and the artefact copier
  (`.gitlab-ci.yml:82`) copies whole listed directories. So a backup left behind by an interrupted or
  failed swap **would be packaged into the shipped zip**, roughly doubling module size. Normal operation
  deletes it, so this is a failure-path concern only.

  **Recommendation:** put the backup in the existing `tmp-*` working dir rather than as a sibling of
  `folder`, or `register_shutdown_function` its cleanup.

Also note neither `.gitignore` covers `tmp-*` (`sales-booster-kit/.gitignore`, `feed.szn/.gitignore` both
list `/deps/` and `/vendor/` only). No leftovers exist right now, and packaging/rsync both exclude them,
so this is cosmetic.

### 3.8 C4 — bump `require.php` to `^8.2`: **SAFE, with one CI hardening suggested**

Keeping the distinction the checklist asks for: `config.platform.php` = `8.1` in the root and every
module constrains **scoped dependency resolution only**. The PHP the *tool* runs on is the CI image:

- `sales-booster-kit` `composer` job — `image: composer:2.8.2` (`.gitlab-ci.yml:99`)
- `sales-booster-kit` `modules_build` job — `image: node:20-alpine` + `apk add … php …` (`:45,51`)
- `feed.szn` `composer` job — `image: composer:2.8.2`
- `feed.szn` dev — `.ddev/config.yaml:3` `php_version: "8.3"`

The `composer:2.8.x` images ship PHP 8.3, and Alpine's `php` package on the release matching
`node:20-alpine` is 8.3. Both satisfy `^8.2`. *(Inferred from image tags — not executable in this
read-only environment, so flagged rather than proven.)*

**One real hazard, independent of the bump:** `composer global require wpify/scoper` is unconstrained
and is *not* run with `--ignore-platform-reqs` (that flag is on the project install only). If a runner
image ever drops to PHP 8.1, Composer will **silently resolve an older scoper** instead of failing —
producing a subtly different build with no error. Recommend pinning `composer global require
wpify/scoper:^3` in both `.gitlab-ci.yml` files.

### 3.9 C5 — fail fast on missing/invalid `prefix`: **SAFE**

All eight units declare a `prefix`, all are legal PHP namespace identifiers, and **all eight are
distinct** — no two scoped trees share a namespace. Nothing relies on the current silent no-op: there is
no `extra.wpify-scoper` block anywhere without a `prefix`. Note the near-miss naming
`WpifyWooDiscountRuleDeps` (singular "Rule") vs. the package `wpify-woo-discount-rules` — correct and
unique, just inconsistent.

### 3.10 H7 — make `--no-dev` reachable: **SAFE, not applicable**

**No `composer-deps.json` in this cluster has a `require-dev` section** (all eight grepped). Nothing
would disappear from any scoped tree. Note also that CI's `composer install --no-dev` fires
`POST_INSTALL_CMD`, which hard-codes `$useDevDependencies = true` (`src/Plugin.php:191`), so the nested
install runs *with* dev today regardless — a no-op here precisely because there are no dev requires.

### 3.11 H16 — regenerated symbol lists: **SAFE, with two requests**

Scoped packages across the cluster: `php-di`, `invoker`, `psr/*`, `laravel/serializable-closure`,
`woocommerce/action-scheduler`, `wpify/*`, `twig`, `symfony/polyfill-*`, `spatie/array-to-xml`,
`giggsey/libphonenumber-for-php`, `giggsey/locale`, `tecnickcom/tcpdf`, `setasign/fpdi`,
`ghostff/php-text-to-image`, `yahnis-elsts/plugin-update-checker`, `phpstan/*` (feed.szn).

None declares a global function, class or constant that collides with WP/Woo naming — every one is
namespaced, and the two that ship bare global classes (`tcpdf`, PUC) are already handled. Newly-excluded
symbols would only *widen* what stays global, which for these packages is a no-op.

Two asks while the lists are being regenerated:

1. **Add `ICL_LANGUAGE_CODE` to `symbols/wordpress.php` → `exclude-constants`** (see 3.5). It fixes a
   live bug in three units and retires four copies of a hand-written patcher.
2. **Ensure `WP_CLI` / `WP_CLI_Command` survive H1** for consumers that override `globals` without
   `wp-cli` (see 3.1).

### 3.12 M3 — stop writing generated `scripts` into `composer-deps.json`: **SAFE**

**No `composer-deps.json` in this cluster contains a `scripts` block.** Both projects track
`composer-deps.json` and `composer-deps.lock` in git (`git ls-files`), so churn would have been visible —
there is none. Nothing hand-written would be lost. Note the generated `scripts` are written to
`tmp-*/source/composer.json` (`src/Plugin.php:169-175`), not to the user's file, so this is already
mostly true in practice for these consumers.

### 3.13 M15 — validate `globals` entries: **SAFE**

Available symbol files: `action-scheduler`, `plugin-update-checker`, `woocommerce`, `wordpress`,
`wp-cli`. Every declared entry (`wordpress`, `woocommerce`, `plugin-update-checker` × 5 modules) is
valid. Validation would emit no errors — though it would be a good place to *warn* that
`plugin-update-checker` is currently ineffective against PUC v5 (3.4).

---

## 4. Verdict table

Legend: **S** = SAFE · **NM** = NEEDS-MIGRATION · **B** = BREAKING · **n/a** = not applicable

| Unit | C1 | C2 | C3 | C4 | C5 | M4 | H1 | H2 | H7 | H14/15 | H16 | H18 | M3 | M15 |
|------|----|----|----|----|----|----|----|----|----|--------|-----|-----|----|-----|
| root `sales-booster-kit` | S | S | S | S | S | S | S | S | n/a | S | S | S | S | S |
| `sales-booster-extras` | S | S | S | S | S | S | S | S | n/a | S | S | S | S | S |
| `wpify-woo-conditional-shipping` | S | S | **NM** | S | S | S | **NM** | S | n/a | S | S | S | S | S |
| `wpify-woo-discount-rules` | S | S | **NM** | S | S | S | **NM** | S | n/a | S | S | S | S | S |
| `wpify-woo-feeds` | S | S | **NM** | S | S | S | **NM** | S | n/a | S | S | S | S | S |
| `wpify-woo-phone-validation` | S | S | **NM** | S | S | S | **NM** | S | n/a | S | S | S | S | S |
| `wpify-woo-product-vouchers` | S | S | **NM** | S | S | S | **NM** | S | n/a | S | S | S | S | S |
| `feed.szn` | S | S | S | S | S | S | S | S | n/a | S | S | S | S | S |

**Nothing in this cluster is BREAKING.** The two NEEDS-MIGRATION items are both confined to the five
modules that override `globals`:

- **H1** — `\WP_CLI` / `\WP_CLI_Command` regress in 14 Action Scheduler files per module unless H1 ships
  with `wp-cli` coverage. Low runtime impact, but a real change.
- **C3** — the `.bak` sibling lands inside `vendor/`, which those modules package wholesale. Failure-path
  only; avoided by putting the backup in `tmp-*`.

## 5. Consumer-side issues found (independent of the proposed changes)

1. **Live WPML bug** in root `sales-booster-kit`, `sales-booster-extras`, `wpify-woo-feeds` —
   `ICL_LANGUAGE_CODE` is prefixed, so `defined()` always returns false (3.5).
2. **`feed.szn`'s `wpify/scoper: ^2` is not honoured** in CI; 3.2.x does the work (§2).
3. **`globals: [… plugin-update-checker]` in five modules is dead config** against PUC v5 (3.4).
4. **No module-level build verification in CI** — only the root's `deps/` is checked for existence (3.6).
5. **Unconstrained `composer global require wpify/scoper`** risks a silent version downgrade (3.8).
