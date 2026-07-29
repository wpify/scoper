# Consumer impact — cluster E ("legacy PHP" group)

Read-only verification of the proposed `wpify/scoper` changes against five real
consumer projects. No file in any consumer project was modified; all evidence is
`file:line` from the checkouts as they stand on 2026-07-27.

| Project | Prefix | `folder` | `globals` | root `require.php` | `config.platform.php` | scoper install |
|---|---|---|---|---|---|---|
| `heureka-scope-review` | `HeurekaDeps` | `vendor/heureka` | `wordpress`, `woocommerce` | `>=8.1.0` | `8.1` | global |
| `rosettapress` | `RosettaPressDeps` | `vendor/rosettapress-deps` | *(default)* | `>=8.1` | `8.1.27` | global |
| `epicwash-dev` | `EpicwashDeps` | `vendor/epicwash` | *(default)* | `^8.3` | `8.3` | global |
| `edu.constructiocrm` | `ConstructioEduDeps` | `web/app/deps` | *(default)* | `>=8.3` | `8.3` | **local `require-dev` `^3.2`** + global |
| `environmentalbadge` | `EnvironmentalBadgeDeps` | `web/app/deps` | *(default)* | `>=8.3` | *(none)* | global |

Reference points used throughout:

* Global install: `/Users/wpify/.composer/vendor/wpify/scoper` @ **3.2.21** (= commit
  `b00d523`), with `wpify/php-scoper` **0.18.18**.
* Dev host PHP: **8.4.20** (`php -v`).

---

## 0. Is `heureka-scope-review` a product or a test bed?

**It is an active client product.** The directory name is a local checkout label only.

* `git -C heureka-scope-review remote -v` → `git@gitlab.wpify.io:client-projects/heureka.git`
* Last commit `6323efe` "update deps, release", **2026-07-08**.
* It ships to **wordpress.org**: `heureka-scope-review/.gitlab-ci/deploy-wporg.yml:20`
  (`wporg_plugin_deploy ... vendor/ ...`), plus `assets-wporg/` and `readme.txt`.

Weight it as a **production consumer with a public release channel**. This raises the
stakes on C3 specifically (see §3): a leftover `.bak` inside `vendor/` would be
published to the wordpress.org SVN trunk.

---

## 1. C4 — bump `require.php` from `^8.1` to `^8.2`

### The two constraints, kept apart

`config.platform.php` in `composer.json` / `composer-deps.json` constrains **resolution
of the scoped dependency graph**. It does not constrain the PHP that executes the
plugin. Proof, per project:

* `heureka-scope-review/composer.lock` and `rosettapress/composer.lock` contain **no
  `wpify/scoper` entry at all** — it is not a project dependency in either, so their
  `platform-overrides` (`{"php": "8.1"}` / `{"php": "8.1.27"}`) are irrelevant to it.
  Same for `epicwash-dev` and `environmentalbadge`.
* The only project where `wpify/scoper` participates in resolution is
  `edu.constructiocrm` (`composer.json:57`, `require-dev` → `"wpify/scoper": "^3.2"`),
  and its `config.platform.php` is **`8.3`** (`composer.json:71`).

### The premise in the audit card needs one correction

The C4 card says consumers on 8.1 "cannot install today either". That is not quite
right, and it matters for judging the bump. `wpify/scoper` requires
`wpify/php-scoper: ^0.18` (`composer.json:34`), and not every 0.18.x forbids 8.1:

```
0.18.4  -> "php": "^8.1"
0.18.7  -> "php": "^8.1"
0.18.9  -> "php": "^8.2"     <- and every release after
...
0.18.19 -> "php": "^8.2"
```

(verified from tags in the local `/Users/wpify/projects/php-scoper` clone). A PHP-8.1
host today therefore **does** install `wpify/scoper`, pinned back to php-scoper 0.18.7.
So the bump is a genuine tightening, not a no-op — which is exactly why the per-project
runtime check below had to be done rather than waved away.

### What PHP actually runs the tool, per project

| Project | Local dev | CI | Verdict |
|---|---|---|---|
| `heureka-scope-review` | no DDEV, no `.tool-versions`/`.php-version` → host PHP **8.4.20** | `.gitlab-ci/generate-zip.yml:8` `extends: .composer_install` from private `wpify/gitlab-ci-templates` — **image not readable** (clone denied: `Permission denied (publickey)`) | **SAFE** |
| `rosettapress` | `.ddev/config.yaml:4` `php_version: "8.3"` | `.gitlab-ci.yml:5` includes private `pipelines/wpify-plugin.yml` — **image not readable** | **SAFE** |
| `epicwash-dev` | no DDEV → host PHP **8.4.20** | `.gitlab-ci.yml:7` **`image: composer:2.8.2`**, `.gitlab-ci.yml:12` `composer global require wpify/scoper` | **SAFE** |
| `edu.constructiocrm` | `.ddev/config.yaml:4` `php_version: "8.4"` | no CI config in the repo | **SAFE** |
| `environmentalbadge` | `.ddev/config.yaml:3` `php_version: "8.3"` (`.ddev/config.local.yaml` sets only `name`/`project_tld`/hostnames — no PHP override) | `.gitlab-ci.yml:29` **`image: composer:2.8.2`**, `.gitlab-ci.yml:35` `composer global require wpify/scoper` | **SAFE** |

On the two unreadable templates: the house convention on this machine is unambiguous —
14 sibling `.gitlab-ci.yml` files pin `image: composer:2.8.2` and 4 pin `image: composer:2`
(`alfamarka`, `dluhopisy`, `mawis`, `delife`, `sdcentral`, `marieolivie`, `stavbadesign`,
`feed.szn`, `sales-booster-kit`, `ckait`, `constructiocrm`, `helpdesk`, `tmp/wpify-woo`,
`environmentalbadge`, `epicwash-dev`, …). Both tags are PHP ≥ 8.3. I mark the template
image itself **UNKNOWN-but-strongly-inferred** and say so rather than asserting it.

There is also a decisive independent argument for `heureka`/`rosettapress` that does not
depend on the template: both projects' current scoped output was produced by a
`wpify/scoper` install whose php-scoper is 0.18.x. If either environment were on PHP 8.1
it would be frozen on php-scoper **0.18.7** — a release from before the current symbol
lists — and `rosettapress`'s scoped tree (rebuilt 2026-07-21) is demonstrably current.

### Verdict

> **The `php: ^8.2` bump breaks nobody in this cluster.** Every environment that
> executes `wpify/scoper` here is on PHP 8.3 or 8.4. The three projects pinning
> `config.platform.php` to 8.1.x are pinning their *scoped dependency graph*, not their
> toolchain, and two of the three do not even have `wpify/scoper` in their lock file.

One follow-on note, not a blocker: `heureka` and `rosettapress` still resolve their
*scoped* deps against platform 8.1, and `rosettapress/vendor/rosettapress-deps/composer/platform_check.php:7`
emits `PHP_VERSION_ID >= 80100`. That is their own choice and is untouched by C4.

---

## 2. C5 — fail fast on a missing/invalid `extra.wpify-scoper.prefix`

All five prefixes are present and are legal PHP namespace identifiers:
`HeurekaDeps` (`composer.json:36`), `RosettaPressDeps` (`composer.json:39`),
`EpicwashDeps` (`composer.json:37`), `ConstructioEduDeps` (`composer.json:88`),
`EnvironmentalBadgeDeps` (`composer.json:117`).

Nothing in the cluster relies on the silent no-op **as a configured project**. But there
is a design constraint that this cluster proves, and it is the most important C5 finding:

> **`wpify/scoper` is installed *globally* in 4 of 5 projects** — so it activates on
> **every** `composer install`/`update` the developer or CI runs, in **any** repository
> on that machine, whether or not that repository has anything to do with scoping.

Evidence: `~/.composer/composer.json` requires `wpify/scoper: ^3.2` with
`allow-plugins.wpify/scoper: true`; CI reproduces this with
`composer global config --no-plugins allow-plugins.wpify/scoper true` +
`composer global require wpify/scoper` (`epicwash-dev/.gitlab-ci.yml:11-12`,
`environmentalbadge/.gitlab-ci.yml:34-35`).

A naïve "no prefix → throw" would therefore break `composer install` in every unrelated
project on the machine, including `wpify/scoper`'s own repo. The gate must be
**"`extra.wpify-scoper` is present but `prefix` is missing/empty/invalid"**, never
"`prefix` is absent". Scanning these five trees for sub-packages that could trip a naïve
check turned up `epicwash-dev/libs/Nayax/composer.json` and
`epicwash-dev/libs/EmailKampane/composer.json` (generated OpenAPI SDKs, no `name`, no
prefix) — neither has a `vendor/`, so nobody runs Composer in them today; the risk is
latent, not live.

**Verdict: SAFE** for all five as configured, **conditional on the check being scoped to
the presence of the `wpify-scoper` key**. Implemented as an unconditional requirement it
is **BREAKING** for every project on a machine with the global install.

---

## 3. C3 — atomic swap via a `.bak` sibling

`folder` is inside the project root in all five, and `temp` defaults to
`getcwd() . '/tmp-XXXXXXXXXX'` (`Plugin.php:58`) — **same filesystem in every case**,
including the DDEV projects, where the whole project root is one bind mount. No `EXDEV`
risk anywhere in this cluster.

Does a later `composer install` disturb a `.bak` in `vendor/`? **No.** Composer does not
prune directories it does not manage, and this cluster proves it: the scoped directories
themselves already live in `vendor/` and survive every install —
`rosettapress/vendor/composer/installed.json` lists 11 packages, **none** matching
`rosettapress-deps`; `epicwash-dev`'s lists 17, **none** matching `epicwash`.

Per-project, for a leftover `<folder>.bak` (only possible if the run dies mid-swap):

| Project | `.bak` path | gitignored? | CI artifact / deploy exposure |
|---|---|---|---|
| `heureka-scope-review` | `vendor/heureka.bak` | **yes** — `.gitignore:8` `/vendor/` | `.gitlab-ci/generate-zip.yml:12` artifacts `$CI_PROJECT_DIR/vendor`, and `plugin_archive ... vendor/` (line 30) → **would be published to wordpress.org** |
| `rosettapress` | `vendor/rosettapress-deps.bak` | **yes** — `.gitignore:5` `/vendor` | ZIP built from private template; `vendor/` is shipped → would be bundled |
| `epicwash-dev` | `vendor/epicwash.bak` | **yes** — `.gitignore:2` `vendor` | `.gitlab-ci.yml:10` artifacts `$CI_PROJECT_DIR/vendor`; `.gitlab-ci.yml:39` `cp -r assets build libs src vendor epicwash.php export/epicwash/` → bundled into `epicwash.zip`. The lftp mirror (`.gitlab-ci.yml:66-82`) excludes `deps/` but **not** `vendor/*.bak` → uploaded to FTP |
| `edu.constructiocrm` | `web/app/deps.bak` | **NO** — `.gitignore:13` is `web/app/deps/*`, which ignores the *contents of* `deps/`, not a sibling `deps.bak` | no CI |
| `environmentalbadge` | `web/app/deps.bak` | **NO** — `.gitignore:16` `web/app/deps/*`, same gap | `.gitlab-ci.yml:24` artifacts `$CI_PROJECT_DIR/web/app/deps` (the dir, not the sibling); `server_deploy ... web/app/deps/ ...` (`.gitlab-ci.yml:57`) → **not** deployed |

Why C3 matters most in this cluster — the deletion window is not "a plugin fails to
load", it is "the site is down", because four of five `require` the scoped autoloader
unguarded and one does it from `wp-config.php`:

* `environmentalbadge/web/wp-config.php:10` — `require_once .../web/app/deps/scoper-autoload.php`, **before WordPress boots**. A missing `deps/` is a fatal on every request, front end and admin.
* `edu.constructiocrm/bootstrap.php:12` — same pattern, loaded from `web/app/mu-plugins/constructio-edu/constructio-edu.php:17`.
* `rosettapress/rosettapress.php:25` and `epicwash-dev/epicwash.php:22` — unguarded `require_once` at plugin load.
* `heureka-scope-review/heureka.php:103-105` is the only one that guards with `file_exists()`; it degrades to a silent "plugin does nothing" instead of a fatal.

**Verdict:**
* `heureka-scope-review`, `rosettapress`, `epicwash-dev` — **SAFE** (`.bak` lands inside an already-ignored `vendor/`; Composer will not touch it).
* `edu.constructiocrm`, `environmentalbadge` — **NEEDS-MIGRATION**: add `web/app/*.bak` (or `web/app/deps.bak`) to `.gitignore`, otherwise a failed run leaves an untracked directory in `git status`.
* `epicwash-dev` — **NEEDS-MIGRATION** on the packaging side: add `--exclude "*.bak"` to the lftp mirror and filter the `cp -r … vendor …` in the `package` job, or a leftover backup doubles the shipped plugin.
* `heureka-scope-review` — **NEEDS-MIGRATION** on the release side: `plugin_archive … vendor/` would carry a leftover `.bak` into the wordpress.org release. Highest-consequence instance in the cluster.

If the plugin can instead keep the backup under its own `temp` directory (same
filesystem, already ignored everywhere) the whole migration row disappears. Worth
considering before landing C3.

---

## 4. C2 — `is_link()` guard in `remove()`

* No `path` repositories: **none** of the five `composer-deps.json` files declares a
  `repositories` key at all.
* No symlinks inside any built scoped tree: `find <folder> -type l` returns **0** for
  `rosettapress/vendor/rosettapress-deps`, `epicwash-dev/vendor/epicwash`,
  `edu.constructiocrm/web/app/deps`, `environmentalbadge/web/app/deps`.
* None of the scoped folders is itself a symlink.
* The only symlinks near these project roots are unrelated:
  `rosettapress/.ddev/custom_certs/*`, `environmentalbadge/.ddev/custom_certs/*`,
  `edu.constructiocrm/.claude/skills/*`.
* DDEV mounts the project root as a normal bind mount; `folder` and `temp` are both
  inside it.

**Verdict: SAFE — pure hardening, zero behaviour change** for all five. Not applicable in
the sense that no consumer here currently triggers the bug, but nothing here can regress
from the guard either.

---

## 5. C1 + M4 — subprocess Composer, exit-code propagation, re-entrancy guard, `--no-plugins`

### `--no-plugins` on the nested install

No scoped dependency in this cluster is, or needs, a Composer plugin:

| Project | scoped pkgs | composer-plugin / installer among them | pkgs requiring `composer/installers` |
|---|---|---|---|
| `heureka-scope-review` | 13 | none | none |
| `rosettapress` | 40 | `woocommerce/action-scheduler` is type `wordpress-plugin` (**not** a `composer-plugin`) | none |
| `epicwash-dev` | 19 | none | none |
| `edu.constructiocrm` | 13 | none | none |
| `environmentalbadge` | 16 | none | none |

`woocommerce/action-scheduler`'s `wordpress-plugin` type is handled by Composer's default
`LibraryInstaller` when no installer plugin is present — confirmed by its actual location
in the built tree, `rosettapress/vendor/rosettapress-deps/woocommerce/action-scheduler/`,
i.e. the plain vendor layout.

`environmentalbadge/composer-deps.json:5-10` declares `allow-plugins` for
`composer/installers`, `dealerdirect/phpcodesniffer-composer-installer`,
`roots/wordpress-core-installer` and `mnsami/composer-custom-directory-installer` — none
of which appears in `composer-deps.lock`. That block is vestigial copy-paste from the
root `composer.json` and `--no-plugins` would change nothing.

**Verdict on `--no-plugins`: SAFE for all five.**

### Exit codes and script ordering

No project in this cluster registers a root `post-install-cmd` / `post-update-cmd`
script. Their `scripts` blocks are all manual commands (`phpcs`, `make-pot`, `lint`,
`test`, `generate-*-sdk`), so nothing user-authored is currently being skipped by the
`exit()`.

What **is** being skipped: `dealerdirect/phpcodesniffer-composer-installer` subscribes to
`POST_INSTALL_CMD` and `POST_UPDATE_CMD`
(`rosettapress/vendor/dealerdirect/phpcodesniffer-composer-installer/src/Plugin.php:161-171`)
and is a `require-dev` of `heureka-scope-review`, `rosettapress`, `epicwash-dev` and
`environmentalbadge`. Global plugins are registered **before** local ones
(`PluginManager.php:109-110`), so on a dev `composer install` the scoper listener runs
first and `exit()`s, and dealerdirect's `installed_paths` write never happens. Fixing C1
makes it start working — a benign but real behaviour change ("phpcs suddenly finds WPCS").
CI is unaffected: every CI job uses `--no-dev`.

**Verdict on exit-code/ordering: NEEDS-MIGRATION (informational)** for
`heureka-scope-review`, `rosettapress`, `epicwash-dev`, `environmentalbadge`;
**SAFE** for `edu.constructiocrm` (no dealerdirect).

### M4 re-entrancy — an extra data point from `edu.constructiocrm`

`edu.constructiocrm` has `wpify/scoper` **both** globally and in `require-dev`. Only one
runs: `PluginManager::registerPackage()` returns early when the package name is already
registered (`PluginManager.php:216-218`), and the global repository is loaded first
(`PluginManager.php:109-110`). So the **global 3.2.21 executes** and the local
`vendor/wpify/scoper` copy is inert. The local requirement still governs *resolution*
(and therefore C4), but never the running code. Any re-entrancy guard must be keyed on
something process-global (env var), not on plugin instance state, or the two copies would
not see each other in a subprocess world.

---

## 6. H1 — anchored prefix-stripping

The hazard is the unanchored `str_replace` at `config/scoper.inc.php:87-103`: for every
`exclude-classes` / `exclude-namespaces` entry it replaces `\<Prefix>\<Symbol>` with
`\<Symbol>`, so a scoped symbol that merely *starts with* an excluded symbol gets
truncated.

Checked mechanically: for each project, every key of `composer/autoload_classmap.php`
plus every PSR-4 root of `composer/autoload_psr4.php`, stripped of the prefix, against
the full excluded class+namespace set for that project's `globals` (1,609 symbols for the
four default-`globals` projects; 1,570 for `heureka`, which sets
`globals: ["wordpress","woocommerce"]`).

```
RosettaPressDeps        1453 scoped symbols  -> NO prefix-collisions
EpicwashDeps             360 scoped symbols  -> NO prefix-collisions
ConstructioEduDeps       480 scoped symbols  -> NO prefix-collisions
EnvironmentalBadgeDeps   504 scoped symbols  -> NO prefix-collisions
HeurekaDeps (from composer-deps.lock roots)  -> NO prefix-collisions
```

The only excluded class/namespace symbols short enough to be plausible false-prefixes are
`MO`, `PO`, `POP3`, `WP`, `ftp`, `wpdb`. No scoped root in this cluster begins with any of
them — note `Wpify\` ≠ `WP` (case-sensitive), which is the near-miss worth flagging.
`heureka`'s scoped roots are `Heureka`, `Hcapi`, `Wpify\{CustomFields,Log,Model,PluginUtils}`,
`DI`, `Invoker`, `PhpDocReader`, `Psr\{Container,Log}`, `Spatie\ArrayToXml`,
`Laravel\SerializableClosure` (from `composer-deps.lock`).

A textual sweep of the four built trees for un-prefixed `use` roots found only legitimate
cases: intentionally excluded WP/Woo namespaces (`Automattic\WooCommerce\…`,
`Action_Scheduler\…`, `PHPMailer\PHPMailer\PHPMailer`), PHP-native constants
(`STR_PAD_LEFT`, `PREG_*`, `PHP_INT_SIZE`) and optional-extension classes
(`Redis`, `COM`, `MongoDB\*`, `Grpc\*`, `AMQPExchange`, `uuid_*`). Nothing carrying the
H1 signature.

**Verdict: SAFE for all five.** No project is currently corrupted, and none regresses.
Cross-cutting note: **H1 should land before or with H16** — every symbol H16 adds is
another unanchored needle, so the anchoring fix is what keeps H16's blast radius at zero.

---

## 7. H2 — narrow the `autoload_static.php` rewrite to `$files`

The regex at `scripts/postinstall.php:41-45` prepends the lowercased prefix to any
`'alnum' => value,` pair. Checked all four built `composer/autoload_static.php` files:

* **Zero** unqualified classmap keys in any of them (`0` matches for
  `^\s*'[A-Za-z0-9_]+' =>` inside the `$classMap` block).
* The only rewritten keys are the intended `$files` md5 hashes — e.g.
  `rosettapress/vendor/rosettapress-deps/composer/autoload_static.php:13-16`,
  `edu.constructiocrm/web/app/deps/composer/autoload_static.php:14-18`.
* `'Composer\\InstalledVersions'` survives intact (it contains a backslash, so the regex
  misses it), and `$prefixLengthsPsr4` keys like `'R' => array (` are not matched because
  `array (` fails the value character class.

There is **no live H2 corruption** in this cluster: php-scoper namespaces every scoped
class, so no unqualified classmap key exists to corrupt.

**Verdict: SAFE for all five** — narrowing the rewrite to `$files` is byte-for-byte
identical output for these projects. (`heureka-scope-review` has no built tree on disk;
its scoped set is a strict subset of the others' package types, so the same conclusion
holds by construction.)

---

## 8. H7 — make `--no-dev` reachable

**Not applicable to any of the five.** None of the five `composer-deps.json` files
declares `require-dev`, and every `composer-deps.lock` reports `packages-dev: 0`
(heureka 13/0, rosettapress 40/0, epicwash 19/0, edu 13/0, environmentalbadge 16/0). No
scoped dev dependency exists to disappear, and no project source references one.

**Verdict: SAFE / not applicable, all five.**

---

## 9. H14 / H15 — `plugin-update-checker`

Flagged by the lead as the highest-risk item. **For this cluster the risk is zero.**

* `plugin-update-checker` is **not** in any project's `globals`. `heureka` sets
  `["wordpress","woocommerce"]` explicitly (`composer.json:38-41`); the other four omit
  `globals` and get the default `['wordpress','woocommerce','action-scheduler','wp-cli']`
  (`Plugin.php:60`) — which does not include it. So
  `symbols/plugin-update-checker.php` is never loaded (`Plugin.php:230-235`) for any of
  them.
* Only `rosettapress` scopes PUC at all, transitively via `wpify/updates: ^1`, and it is
  **v5**: `rosettapress/vendor/rosettapress-deps/composer/autoload_static.php:14` →
  `yahnis-elsts/plugin-update-checker/load-v5p6.php`. It is fully prefixed, which is the
  correct treatment for the namespaced v5.
* No project's source references `Puc_v4p11_*`, `PluginUpdateChecker`, or
  `YahnisElsts\*` (grep across all five `src/` trees and root plugin files: **0 hits**).
* The v4-specific patchers at `config/scoper.inc.php:48-50` and `:75-77` are dead code
  for this cluster.

**Verdict: SAFE / not applicable, all five** — regenerating for v5 *or* dropping the
built-in list entirely costs this cluster nothing.

---

## 10. H16 — full-AST symbol extraction, regenerated lists

Approximated by diffing the symbol files between `af1b752` (before the two symbol
commits) and `HEAD`, then collision-testing every added class/namespace symbol against
each project's scoped symbol set:

```
exclude-classes:     +66  / -4
exclude-namespaces:  +292 / -12
exclude-functions:   +266 / -24
exclude-constants:   +102 / -1

358 newly-added class/namespace symbols vs.
  RosettaPressDeps        -> no collisions
  EpicwashDeps            -> no collisions
  ConstructioEduDeps      -> no collisions
  EnvironmentalBadgeDeps  -> no collisions
```

The interesting additions are the short, generic ones now in `exclude-classes`:
`Attribute`, `Stringable`, `ValueError`, `PhpToken`, `UnhandledMatchError` (WordPress /
WooCommerce polyfill classes). Grepping the four built trees for
`\<Prefix>\{Attribute|Stringable|ValueError|PhpToken|UnhandledMatchError}` returns **0
hits** — `RosettaPressDeps\DI\Attribute\Inject` does *not* match, because the needle
requires the symbol to sit directly after the prefix.

Timing check: `b00d523` ("add new symbols") is dated **2025-05-07**, and every built tree
in this cluster post-dates it (epicwash 2026-01-08, environmentalbadge 2026-06-22,
edu 2026-07-14, rosettapress 2026-07-21) — so those exclusions are already reflected in
the shipped output. Only `a59d577` (today) is newer, and it touches `exclude-constants`
only, which the `scoper.inc.php` patcher loop does not consume (it iterates
`exclude-classes` and `exclude-namespaces` only, `config/scoper.inc.php:87-101`).

**Verdict: SAFE for all five**, with the residual caveat that a *future* regeneration's
`class_alias` targets are unknown names. That residual is exactly what H1's anchoring
neutralises — hence the sequencing note in §6.

---

## 11. H18 — `scoper.custom.php` discovery

**None of the five projects has a `scoper.custom.php`** (checked at each project root).
So no customisation is currently being applied *or* silently ignored anywhere here, and
the fix is a no-op for all five.

The lead's hypothesis that `edu.constructiocrm` behaves differently because of its local
install is **not borne out**. `createPath()` tests
`strpos( dirname(__DIR__), 'vendor/wpify/scoper' )` (`Plugin.php:269`), and both
topologies contain that substring:

* global: `/Users/wpify/.composer/vendor/wpify/scoper` → substring found
* edu local: `/Users/wpify/projects/edu.constructiocrm/vendor/wpify/scoper` → substring found

Both resolve `scoper.custom.php` to `getcwd()`, which is correct. The substring test only
fails for a non-standard `vendor-dir` or a `path`-repository symlink — neither of which
occurs in this cluster.

The genuinely distinctive thing about `edu.constructiocrm` is different, and it is worth
recording: its local `require-dev` copy **never executes**. Composer dedupes plugin
registration by package name (`PluginManager.php:216-218`) and loads the global
repository first (`PluginManager.php:109-110`), so the global 3.2.21 wins. Its own lock
confirms the version is identical anyway — `composer.lock` `packages-dev` has
`wpify/scoper 3.2.21` and `wpify/php-scoper 0.18.18`.

**Verdict: SAFE / not applicable, all five.**

---

## 12. M3 — stop writing generated `scripts` into `composer-deps.json`

* **No** `composer-deps.json` in this cluster contains a `scripts` block — hand-written
  or generated. Nothing would be lost.
* The generated scripts are written to the **temp copy**, not the user's file:
  `Plugin.php:169-175` writes `$composerJsonPath`, which is
  `path( $source, 'composer.json' )` = `tmp-XXXXXXXXXX/source/composer.json`
  (`Plugin.php:128,131`). The user's `composer-deps.json` is only ever written when it is
  **absent** (`Plugin.php:141`).
* Both files are committed in all five. What actually churns is
  **`composer-deps.lock`**, which `scripts/postinstall.php:57-58` deletes and overwrites
  on every run: heureka 26 commits vs 10 for the `.json`, rosettapress 29 vs 7,
  environmentalbadge 14 vs 3, epicwash 2 vs 2, edu 1 vs 1. All five working trees are
  currently clean for both files.

**Verdict: SAFE / not applicable, all five.** M3's "clobbers user scripts" concern does
not materialise here; the lock-file churn is a separate (and expected) behaviour.

---

## 13. M15 — validate `globals` against available symbol files

Only `heureka-scope-review` sets `globals` at all:
`["wordpress","woocommerce"]` (`composer.json:38-41`). Both map to real files
(`symbols/wordpress.php`, `symbols/woocommerce.php`). The other four inherit the default
(`Plugin.php:60`), which is valid by construction.

Worth noting as a side effect rather than a risk: heureka's explicit list **narrows** the
default — it drops `action-scheduler` and `wp-cli`. Its scoped set contains neither, so
this is harmless today, but a validator should not flag it.

**Verdict: SAFE, all five.**

---

## Verdict table

Legend: **S** = SAFE · **NM** = NEEDS-MIGRATION · **B** = BREAKING · **n/a** = not applicable · **?** = UNKNOWN

| Item | heureka | rosettapress | epicwash-dev | edu.constructiocrm | environmentalbadge |
|---|---|---|---|---|---|
| **C4** PHP `^8.2` | **S** (CI image inferred, not read) | **S** (CI image inferred, not read) | **S** | **S** | **S** |
| **C5** prefix fail-fast | **S**¹ | **S**¹ | **S**¹ | **S**¹ | **S**¹ |
| **C3** atomic `.bak` swap | **NM** (wp.org release ships `vendor/`) | **S** | **NM** (ZIP + FTP mirror ship `vendor/`) | **NM** (`.gitignore` misses `web/app/deps.bak`) | **NM** (same `.gitignore` gap) |
| **C2** `is_link()` guard | **S** | **S** | **S** | **S** | **S** |
| **C1** `--no-plugins` | **S** | **S** | **S** | **S** | **S** |
| **C1** exit code / ordering | **NM**² | **NM**² | **NM**² | **S** | **NM**² |
| **M4** re-entrancy guard | **S** | **S** | **S** | **S**³ | **S** |
| **H1** anchored stripping | **S** | **S** | **S** | **S** | **S** |
| **H2** narrow `$files` rewrite | **S** | **S** | **S** | **S** | **S** |
| **H7** `--no-dev` | n/a | n/a | n/a | n/a | n/a |
| **H14/H15** plugin-update-checker | n/a | n/a (scopes PUC **v5**, not opted in) | n/a | n/a | n/a |
| **H16** regenerated symbols | **S** | **S** | **S** | **S** | **S** |
| **H18** `scoper.custom.php` | n/a | n/a | n/a | n/a⁴ | n/a |
| **M3** generated `scripts` | n/a | n/a | n/a | n/a | n/a |
| **M15** `globals` validation | **S** | **S** | **S** | **S** | **S** |

1. Conditional: the check must fire only when `extra.wpify-scoper` is present. An
   unconditional "prefix required" is **BREAKING** for all five, because the plugin is
   installed globally and activates in every repository on the machine.
2. Benign behaviour change: `dealerdirect/phpcodesniffer-composer-installer`'s
   `POST_INSTALL_CMD` listener currently never runs on a dev install and would start
   running. CI is `--no-dev`, so unaffected.
3. The local `require-dev` copy never executes (global wins the registration race); a
   re-entrancy guard must be process-global, not instance state.
4. The local install path still satisfies the `vendor/wpify/scoper` substring test, so
   H18 behaves identically to the global case. No `scoper.custom.php` exists anyway.

### Residual unknowns

* The image used by `.composer_install` in the private `wpify/gitlab-ci-templates` repo
  (affects `heureka-scope-review`, and `wpify-plugin.yml` for `rosettapress`). Clone
  denied — `git@gitlab.wpify.io: Permission denied (publickey)`. Strong inference from 18
  sibling pipelines that it is `composer:2.8.2` / `composer:2`, both PHP ≥ 8.3. Confirm
  by reading one line of that repo before landing C4 if you want certainty.
* `heureka-scope-review` has no built scoped tree on disk, so H1/H2 were verified against
  its `composer-deps.lock` package/namespace set rather than generated output.
