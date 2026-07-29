# Consumer impact — cluster D: projects that scope INTO `vendor/`

Scope: `wpify-woo-feeds`, `wpify-woo-gopay`, `wpify-woo-paczkomaty`, `tmp/wpify-woo`.
All inspection was read-only (`git status` clean where it started clean; no writes, no composer runs).

## 0. Ground facts established first

| | feeds | gopay | paczkomaty | tmp/wpify-woo |
|---|---|---|---|---|
| `extra.wpify-scoper.prefix` | `WpifyWooFeedsDeps` (composer.json:25) | `WpifyWooGopayDeps` (:21) | `WpifyWooPaczkomatyDeps` (:42) | `WpifyWooDeps` (:35) |
| `folder` | `vendor/wpify-woo-feeds` (:26) | `vendor/wpify-woo-gopay` (:22) | **absent** → default `deps` (Plugin.php:57) | `vendor/wpify-woo` (:36) |
| `globals` | wordpress, woocommerce, plugin-update-checker (:27–30) | same (:23–26) | wordpress, woocommerce (:43–45) | wordpress, woocommerce (:37–39) |
| `config.platform.php` | `8.1.0` (:10) | `8.1` (:7) | `8.1` (:12) | `8.1` + `ext-soap` (:11) |
| scoped tree currently built on disk | **yes** | no | no | no |
| `scoper.custom.php` | no | no | no | **yes** |
| wpify/scoper installed | **globally** (`/Users/wpify/.composer/vendor/wpify/scoper`, global composer.json requires `^3.2`) | globally | globally | globally |

None of the four has `wpify/scoper` in its own `composer.lock` (checked: zero matches in all four). The
global install path `/Users/wpify/.composer/vendor/wpify/scoper` **does** contain the literal
`vendor/wpify/scoper`, which matters for H18 below.

**Bootstrap paths all match `folder`** (ordering-hazard item 2, first half):

- `wpify-woo-feeds/wpify-woo-feeds.php:148` → `__DIR__ . '/vendor/wpify-woo-feeds/scoper-autoload.php'`, then `vendor/autoload.php` at :160
- `wpify-woo-gopay/wpify-woo-gopay.php:157` → `__DIR__ . '/vendor/wpify-woo-gopay/scoper-autoload.php'`, then `vendor/autoload.php` at :166
- `wpify-woo-paczkomaty/wpify-woo-paczkomaty.php:134` → `__DIR__ . '/deps/scoper-autoload.php'` + `deps/autoload.php`
- `tmp/wpify-woo/wpify-woo.php:175–177` → `__DIR__ . '/vendor/wpify-woo/scoper-autoload.php'` then `vendor/autoload.php`

No mismatch. The scoped tree is **not** registered in any project's own `autoload` block — it is loaded
by an explicit `include_once` of `scoper-autoload.php`, which chains to the scoped tree's own
`autoload.php`. Composer's root autoloader has no knowledge of it.

### Is `tmp/wpify-woo` a real project or a scratch copy? — **REAL, and it is the only copy on this machine.**

`git -C /Users/wpify/projects/tmp/wpify-woo`:
- remote `git@gitlab.com:wpify/wpify-woo.git`, branch `master` tracking `origin/master`
- `git describe --tags` → `5.4.16`
- HEAD `9aca08c "Fix IC/DIC Login conflict, Withdrawal & Claim order id normalization"`
- **uncommitted work present**: `M readme.txt`, `M src/Api/SettingsApi.php`, `M wpify-woo.php`, untracked `tests/`

There is no `/Users/wpify/projects/wpify-woo`. This is an active working copy that merely lives under a
directory named `tmp`. Its findings are weighted the same as the other three, and it is the only project
in this cluster with a `scoper.custom.php` and the only one with a hand-written `scripts` block in
`composer-deps.json` — so it is the highest-signal project here, not the lowest.

---

## 1. Does the scoped directory being inside `vendor/` break C3 (atomic swap via `.bak` sibling)?

Current behaviour (`scripts/postinstall.php:62–63`):

```php
remove( $deps );
rename( path( $destination, 'vendor' ), $deps );
```

Under C3 this becomes: rename `$deps` → `$deps.bak-<pid>`, rename new tree into `$deps`, delete the backup.
For three of four projects `$deps` is inside `vendor/`, so the backup is
`vendor/wpify-woo-feeds.bak-<pid>` / `vendor/wpify-woo-gopay.bak-<pid>` / `vendor/wpify-woo.bak-<pid>`.

**(a) Would a later `composer install` remove or complain about it?** No — and that is the problem.
Composer only removes package directories recorded in `vendor/composer/installed.json`; it does not sweep
`vendor/` for strays. The scoped directory itself is proof: `wpify-woo-feeds/vendor/` currently holds
`autoload.php`, `composer/` and `wpify-woo-feeds/` side by side (`composer/` mtime Jun 22 18:37,
scoped dir Jun 22 20:20) with no Composer complaint. A leaked `.bak-<pid>` therefore **persists
indefinitely and is never cleaned up by anything.**

**(b) Is the `.bak` visible in git?** No, in any of the four. `.gitignore` ignores `vendor/` wholesale
(`wpify-woo-feeds/.gitignore:7`, `wpify-woo-gopay/.gitignore:7`, `wpify-woo-paczkomaty/.gitignore:7`,
`tmp/wpify-woo/.gitignore:8`) and `deps` too (`:4` in the first three, `:4` in wpify-woo). So a leaked
backup is **invisible to `git status`** — nobody will notice it accumulating.

**(c) Deploy / artifact rules that would now pick it up — this is the real hit.**
`tmp/wpify-woo/.rsyncinclude:6` is `+ /vendor/***`. That is a recursive wildcard over the whole vendor
directory, used by the `.deploy_wporg_rsyncinclude` job (`.gitlab-ci.yml`, `wporg:` job, tags only).
A leaked `vendor/wpify-woo.bak-<pid>` would be **rsynced into the WordPress.org SVN release**, roughly
doubling the shipped plugin size and publishing a duplicate copy of every scoped dependency.
Additionally `tmp/wpify-woo/.gitlab-ci.yml:27–30` declares `artifacts.paths: ./deps, ./vendor`, so the
backup would also ride along in the CI artifact consumed by the `create_zip` / deploy jobs.
`.gitattributes` is absent for wpify-woo and only `export-ignore`s lock files in the other three, so
`git archive` is unaffected (vendor is untracked anyway).

feeds / gopay / paczkomaty have **no** `.rsyncinclude`; they delegate to
`wpify/gitlab-ci-templates :: /pipelines/wpify-plugin.yml`, which is **not checked out locally**, so I
cannot read its rsync/artifact rules. Given wpify-woo's inlined equivalent uses `+ /vendor/***`, the
shared template very likely does the same — but I am marking that UNKNOWN rather than asserting it.

**(d) Crash window / stale-backup confusion.** The proposed name carries `-<pid>`, so a later run will
never mistake a leftover for the real tree — the plugin only ever looks at `$deps` itself, never at
siblings. That specific hazard is closed. What is *not* closed is that leftovers are silent, permanent,
and shippable (points a–c).

**(e) Same filesystem?** Yes for all four. Temp dir is `getcwd() . '/tmp-<10 chars>'` (Plugin.php:58) and
no project overrides `extra.wpify-scoper.temp` (verified: zero matches in all four composer.json files).
Source, destination, `$deps` and the `.bak` sibling are all under the project root, so `rename()` cannot
hit `EXDEV`. No `.ddev/`, `Dockerfile`, `docker-compose.yml`, `.tool-versions` or `.php-version` in any of
the four — no bind-mount split to worry about.

**Verdict C3:** SAFE for paczkomaty (`deps` is a top-level, `.gitignore`d, self-contained directory).
**NEEDS-MIGRATION for feeds, gopay and wpify-woo** — the backup must be created *outside* `vendor/`
(alongside the temp tree, i.e. `getcwd()/tmp-<rand>/old-deps`), or the run must sweep
`dirname($deps) . '/' . basename($deps) . '.bak-*'` at startup. Placing it inside `vendor/` converts a
crash into a permanently invisible, deployable artifact — and for wpify-woo specifically into a
wordpress.org release payload via `.rsyncinclude:6`.

---

## 2. Ordering hazard — does Composer ever wipe the scoped directory?

**No.** Three separate reasons, all checked:

1. `composer install` / `composer update` only remove packages tracked in `vendor/composer/installed.json`.
   The scoped tree is not a Composer package and is not in the project's own `autoload` config
   (verified in all four `composer.json`), so nothing prunes it. `--no-dev` prunes *dev packages*, not
   unknown directories.
2. `composer dump-autoload` fires `pre-`/`post-autoload-dump`, **not** `POST_INSTALL_CMD` /
   `POST_UPDATE_CMD` — the only two events the plugin subscribes to (`src/Plugin.php:44–49`). So
   `dump-autoload` neither rebuilds nor deletes the scoped tree; it leaves it untouched.
3. The plugin runs *after* Composer has finished writing `vendor/` (POST_INSTALL_CMD), so
   `rename(temp/destination/vendor, vendor/<name>)` always lands into an already-existing `vendor/`.

Empirical corroboration: `wpify-woo-feeds/vendor/` currently contains the composer-managed
`autoload.php` + `composer/` (mtime 18:37) and the scoped `wpify-woo-feeds/` (mtime 20:20) coexisting.

One residual, low-probability naming collision worth stating: `vendor/wpify-woo-feeds` occupies what
Composer treats as a *vendor-namespace* slot. If a package `wpify-woo-feeds/<anything>` were ever
required, Composer would install into that same directory and the two would fight. No such package is
required today in any of the four.

**Verdict:** SAFE for all four.

---

## 3. C2 — `is_link()` guard in `remove()`

- `wpify-woo-feeds`: `test -L vendor` → not a symlink; `test -L vendor/wpify-woo-feeds` → not a symlink;
  `find vendor -type l` → **zero** symlinks anywhere in the tree.
- gopay / paczkomaty / wpify-woo: no built tree exists, so nothing to test; `find -maxdepth 2 -type l`
  over each project root (excluding `.git`, `node_modules`) → zero symlinks.
- No `repositories` key at all in any of the four `composer-deps.json`; no `"type": "path"` anywhere in
  any `composer.json` or `composer-deps.json`. So Composer never symlinks a path repository into the
  scoped tree.
- No DDEV/Docker mounts (see 1e).

**Verdict C2:** SAFE for all four. Purely additive hardening here; nothing in this cluster relies on
`remove()` following a link.

---

## 4. H14 / H15 — plugin-update-checker. **This is the BREAKING finding in this cluster.**

### What is actually installed

`plugin-update-checker` is in `globals` for **feeds** (composer.json:30) and **gopay** (:26) only.
Neither paczkomaty nor wpify-woo lists it, and neither has PUC in its `composer-deps.lock` — for those
two the whole topic is **not applicable**.

feeds and gopay both lock `yahnis-elsts/plugin-update-checker dev-master`, pulled transitively by
`wpify/updates ^1`. The built tree shows it is **v5.7**, not v4:
`vendor/wpify-woo-feeds/yahnis-elsts/plugin-update-checker/load-v5p7.php`, tree `Puc/v5`, `Puc/v5p7`,
namespaces `YahnisElsts\PluginUpdateChecker\v5` / `v5p7`.

### Does any project code reference PUC?

**No.** Grepping `src/` and the main plugin file of all four for `Puc_v4`, `PluginUpdateChecker`,
`PucFactory`, `Puc\` → zero hits. The only consumer is the scoped package `wpify/updates`:

`vendor/wpify-woo-feeds/wpify/updates/src/Updates.php:5,26`
```php
use WpifyWooFeedsDeps\YahnisElsts\PluginUpdateChecker\v5\PucFactory;
...
$url = sprintf('https://wpify.io/?update_action=get_metadata&update_slug=%s&site_url=%s', ...);
PucFactory::buildUpdateChecker($url, $this->plugin_file, $this->plugin_slug);
```

### H15 — dropping the built-in symbol list: SAFE

`symbols/plugin-update-checker.php` contains **only** `expose-classes` naming 33 `Puc_v4p11_*` /
`Puc_v4_Factory` classes (lines 1–37). None of those class names exists anywhere in the installed v5.7
tree. The list is a dead no-op for feeds and gopay today. Deleting the file — and with it the
`in_array('plugin-update-checker', ...)` branch at `src/Plugin.php:230–235` — changes nothing observable.
The two projects would then need `plugin-update-checker` removed from `globals`, or `globals` validation
(M15) would reject it: **that is the only migration step**, and it is a one-line edit per project.

### H14 — regenerating for v5, or removing the v5 patchers: BREAKING for feeds and gopay

The `globals` list is not the thing PUC actually depends on. What PUC depends on is a **patcher in
`config/scoper.inc.php:75–77`**:

```php
if ( strpos( $filePath, 'yahnis-elsts/plugin-update-checker' ) !== false ) {
    $content = str_replace( '$checkerClass = $type', '$checkerClass = "'. $prefix . '\\\\".$type', $content );
}
```

Its effect in the built tree, `yahnis-elsts/plugin-update-checker/Puc/v5p7/PucFactory.php:81`:

```php
$checkerClass = "WpifyWooFeedsDeps\\".$type . '\UpdateChecker';   // $type === 'Plugin'
```

and the lookup table it must match, `load-v5p7.php:11` (php-scoper prefixed the *string literal* keys):

```php
foreach (array('WpifyWooFeedsDeps\Plugin\UpdateChecker' => Plugin\UpdateChecker::class, ...) as $pucGeneralClass => $pucVersionedClass) {
    MajorFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.7');
```

The two only line up **because** the patcher fires. Remove or neutralise it and
`getCompatibleClassVersion('Plugin\UpdateChecker')` returns `null`, which reaches
`PucFactory.php:88–90`:

```php
if ($checkerClass === null) {
    trigger_error(esc_html(sprintf('PUC %s does not support updates for %ss %s', ...)), \E_USER_ERROR);
}
```

`E_USER_ERROR` is fatal. `Updates::init_udates_check()` runs on every `init`
(`Updates.php:16–20`), so this would be a **white screen on every request** for wpify-woo-feeds and
wpify-woo-gopay, not a degraded update check.

Corroborating that the URL takes the fatal branch and not the VCS branch: the metadata URL is
`https://wpify.io/?update_action=...`, so `getVcsService()` returns empty and control goes to the
`empty($service)` branch at `PucFactory.php:81` — the one the patcher rewrites.

**Already broken today, worth recording:** the *other* branch, `PucFactory.php:84`
`$checkerClass = 'Vcs\\' . $type . 'UpdateChecker';`, is **not** patched, so it can never match the
registered key `'WpifyWooFeedsDeps\Vcs\PluginUpdateChecker'`. GitHub/GitLab/BitBucket-hosted update
checking is dead in every scoped build in this cluster. Nothing here uses it, so it is latent.

**Verdict H14/H15:**
- H15 (drop the built-in `Puc_v4p11_*` list): **SAFE** for feeds and gopay, **not applicable** for
  paczkomaty and wpify-woo. Requires removing `plugin-update-checker` from two `globals` arrays.
- H14 (regenerate for v5 / rework the patchers): **BREAKING for feeds and gopay unless the
  `$checkerClass = $type` patcher at `config/scoper.inc.php:75–77` is preserved or replaced by an
  equivalent that keeps `PucFactory::$classVersions` keys and the lookup string in agreement.**
  Any v5 rework must be validated by actually building feeds or gopay and confirming
  `PucFactory::buildUpdateChecker()` returns a `Plugin\UpdateChecker` instance rather than tripping
  `E_USER_ERROR`.
- The dead v4-only patcher at `config/scoper.inc.php:48`
  (`Puc/v4p11/UpdateChecker.php` → inject `use WP_Error;`) targets a path that does not exist in a v5
  tree; removing it is **SAFE** for all four.

---

## 5. `tmp/wpify-woo/scoper.custom.php` (H18) — currently APPLIED, and load-bearing

Full contents:

```php
function customize_php_scoper_config( array $config ): array {
	$config['patchers'][] = function( string $filePath, string $prefix, string $content ): string {
		if ( strpos( $filePath, 'wpify/core' ) !== false ) {
			$content = str_replace( $prefix . '\\\\array_merge', 'array_merge', $content );
			$content = str_replace( $prefix . '\\\\wpml_object_id_filter', 'wpml_object_id_filter', $content );
			$content = str_replace( $prefix . '\\\\WP_Post', 'WP_Post', $content );
		}
		$content = str_replace( "'{$prefix}\\ICL_LANGUAGE_CODE'", "'ICL_LANGUAGE_CODE'", $content );
		$content = str_replace( "{$prefix}\\ICL_LANGUAGE_CODE", "ICL_LANGUAGE_CODE", $content );
		return $content;
	};
	return $config;
}
```

**Is it applied?** Yes. `src/Plugin.php:204` calls `createPath( array('scoper.custom.php'), true )`, and
`createPath` (`:268–276`) returns `getcwd() . '/scoper.custom.php'` only when
`strpos( dirname(__DIR__), 'vendor/wpify/scoper' )` is an int. The global install lives at
`/Users/wpify/.composer/vendor/wpify/scoper`, so `dirname(__DIR__)` is
`/Users/wpify/.composer/vendor/wpify/scoper` — which **contains** the literal substring. Verified by
executing the exact expression: `strpos(...)` → `int(23)` → CWD branch taken. The same holds in CI:
`tmp/wpify-woo/.gitlab-ci.yml:36` does `composer global require wpify/scoper`, and the
`composer:2.8.2` image sets `COMPOSER_HOME=/tmp`, giving `/tmp/vendor/wpify/scoper` — also a match.

So **H18 does not bite this cluster**: the custom file is found today via the global-install path, and an
H18 fix that always resolves against the project root leaves behaviour identical. **SAFE** — but the fix
must not *narrow* discovery, because wpify-woo genuinely needs the file.

**Why it is load-bearing.** The `ICL_LANGUAGE_CODE` half is live and necessary. `ICL_LANGUAGE_CODE` is in
none of the symbol lists (checked `wordpress.php` and `woocommerce.php`: no hit in any key), so
php-scoper prefixes it. In **wpify-woo-feeds**, which has *no* `scoper.custom.php`, the damage is visible:

`vendor/wpify-woo-feeds/wpify/woo-core/src/Abstracts/AbstractModule.php:28,155,179` and
`.../src/Admin/Settings.php:359`
```php
if (is_admin() && defined('WpifyWooFeedsDeps\ICL_LANGUAGE_CODE') && ...) {
```
WPML defines the *global* `ICL_LANGUAGE_CODE`, so `defined('WpifyWooFeedsDeps\ICL_LANGUAGE_CODE')` is
permanently false and the whole WPML per-language-settings branch is dead in feeds. wpify-woo's custom
file is precisely what prevents that for wpify-woo — it depends on the same `wpify/woo-core ^5`.

**Latent defect in the custom file itself (not caused by any proposal):** the first block tests
`strpos($filePath, 'wpify/core')`. The dependency is `wpify/woo-core` (composer-deps.json), installed at
`vendor/wpify/woo-core/…`, which does **not** contain `wpify/core`. The `array_merge`,
`wpml_object_id_filter` and `WP_Post` fixes therefore never fire. Worth reporting to the wpify-woo owner
independently of this audit; it is not a reason to block any proposal.

---

## 6. Mining the built tree for live H1 / H2 corruption

Only `wpify-woo-feeds` has a built scoped tree, so this section is empirical for feeds and inferential
for the other three.

### H2 — `autoload_static.php` rewrite: no live corruption in this cluster

`vendor/wpify-woo-feeds/composer/autoload_static.php` contains exactly **three** occurrences of the
lowercased prefix, all in the `$files` array where they belong (lines 10–12):

```
'wpifywoofeedsdepscdf08174348db7aba2f2aa1537fac4b1' => __DIR__ . '/..' . '/wpify/custom-fields/custom-fields.php',
'wpifywoofeedsdepsbc0af1337b39f0d750e835f5263eb646' => __DIR__ . '/..' . '/yahnis-elsts/plugin-update-checker/load-v5p7.php',
'wpifywoofeedsdepsb33e3d135e5d9e47d845c576147bda89' => __DIR__ . '/..' . '/php-di/php-di/src/functions.php',
```

Zero classmap keys were touched. The reason is specific and worth recording, because it means feeds is
lucky rather than safe by construction:

- Every class in `autoload_classmap.php` is namespaced, so every key contains `\\` and fails the
  regex's `[[:alnum:]]+` key pattern. I grepped for classmap keys without a backslash: **none**.
- The only alnum-only key in the file is `'W'` in `$prefixLengthsPsr4` (line 16), whose value is
  `array (` on the *following* line — the regex requires the value on the same line and terminated by
  `,`, and `(` is outside its value character class, so it does not match.
- Action Scheduler's global classes (`ActionScheduler_*`) all contain `_`, which POSIX `[[:alnum:]]`
  excludes; the single underscore-free name `ActionScheduler` is not in the composer classmap at all
  (Action Scheduler ships its own loader).

**Verdict H2: SAFE for all four** — narrowing the rewrite to the `$files` block is behaviour-preserving
here. The risk the audit describes is real but does not currently fire against these dependency sets.
It *would* fire the moment any of these projects added a dependency exposing an underscore-free global
class through the composer classmap.

### H1 — anchored prefix-stripping: SAFE, and the analysis is worth keeping

I scanned the whole built tree for root-level references (`\Name` not preceded by an identifier
character, plus `use Name` at statement start) whose name is a **strict extension** of one of the 1,190
excluded classes / 455 excluded namespaces — i.e. exactly the symbols the current unanchored
`str_replace` at `config/scoper.inc.php:87–103` de-prefixes by accident and that anchoring would start
prefixing again. The complete result set is six names:

| residue | matched excluded symbol | why it is harmless |
|---|---|---|
| `MONTH_IN_SECONDS` | `MO` (class) | independently in `exclude-constants` — php-scoper already leaves it global; the patcher is redundant here |
| `WP_CONTENT_DIR` | `WP` (class) | same — in `exclude-constants` |
| `WP_MAX_MEMORY_LIMIT` | `WP` | same |
| `WP_PLUGIN_DIR` | `WP` | same |
| `WP_CLI` | `WP` | **not** in the loaded lists (only in `symbols/wp-cli.php`, and `wp-cli` is not in anyone's `globals`) — see below |
| `WP_CLI_Command` | `WP` | same |

The four constants are unaffected by anchoring: they are excluded on their own merit, so the patcher
never had to fire for them.

`WP_CLI` / `WP_CLI_Command` are the only two symbols anchoring would actually change — and the code
that uses them is **already dead**, because the `defined()` guards were prefixed and can never be true:

```
classes/abstracts/ActionScheduler.php:231          if (\defined('WpifyWooFeedsDeps\WP_CLI') && \WP_CLI) {
classes/WP_CLI/ActionScheduler_WPCLI_QueueRunner.php:42   if (!(\defined('WpifyWooFeedsDeps\WP_CLI') && \WP_CLI)) {
classes/abstracts/ActionScheduler_WPCLI_Command.php:32    if (!\defined('WpifyWooFeedsDeps\WP_CLI') || !\constant('WP_CLI')) {
classes/migration/Controller.php:146, classes/migration/Runner.php:83, classes/WP_CLI/Migration_Command.php:33, classes/WP_CLI/ProgressBar.php:60  — same pattern
```

The two unguarded `\WP_CLI::warning()` call sites
(`classes/ActionScheduler_DataController.php:140,143`) live in `free_memory()`, reachable only via
`add_action('action_scheduler/progress_tick', …)` at `:186` gated on `self::$free_ticks`, which is set
only by `set_free_ticks()` — called exclusively from
`classes/WP_CLI/ActionScheduler_WPCLI_Scheduler_command.php:87` and
`classes/WP_CLI/Migration_Command.php:55`, both behind the dead guard. So anchoring cannot produce a
runtime fatal here.

I also checked the *other three* projects statically, since they have no build to scan. Their scoped
dependency sets add `GuzzleHttp`, `Psr`, `Endroid`, `BaconQrCode`, `DASPRiD`, `DragonBe`, `h4kuna`,
`Nette`, `Rikudou`, `Hubipe`, `Heureka`, `GoPay`, `Spatie`, `Laravel`, `DI`, `Invoker`, `PhpDocReader`,
`Wpify`, `YahnisElsts`, `PHPStan`, `Symfony`, `Composer`, `ActionScheduler` as root namespaces. None is
a strict extension of any excluded class or namespace name. (Only the first segment after the prefix can
match, since the patcher searches `\<prefix>\<symbol>`.)

**Verdict H1: SAFE for all four.** All four scope `woocommerce/action-scheduler` (3.9.3 / 3.9.3 / 3.9.2 /
3.9.3), none has `wp-cli` in `globals`, and the affected code paths are inert.
Optional follow-up, not a blocker: adding `wp-cli` to `globals` would fix Action Scheduler's WP-CLI
integration properly, which is currently disabled by the prefixed `defined('…\WP_CLI')` guards.

---

## 7. Remaining checklist items

### C4 — bump `require.php` from `^8.1` to `^8.2`

The distinction matters here: `config.platform.php: 8.1` in all four constrains **scoped dependency
resolution**, not the PHP the tool runs on. Because wpify/scoper is installed **globally** in all four,
the projects' `platform` settings do not participate in resolving wpify/scoper at all.

- Local dev environment: `php -v` → **8.4.20**. Satisfies `^8.2`.
- `tmp/wpify-woo` CI: `.gitlab-ci.yml:18` `image: composer:2.8.2`. Inspected the local image —
  `PHP_VERSION=8.3.13`, `COMPOSER_HOME=/tmp`. Satisfies `^8.2`. The job's
  `composer global require wpify/scoper --with-all-dependencies` (:36) resolves against the image's PHP
  8.3, not against the project's `platform: 8.1`.
- feeds / gopay / paczkomaty CI: delegate entirely to
  `wpify/gitlab-ci-templates :: /pipelines/wpify-plugin.yml`, which is not checked out locally
  (`/Users/wpify/projects/gitlab-ci-templates` does not exist). **UNKNOWN** — the image's PHP version
  must be confirmed in that repo before shipping C4.

**One concrete NEEDS-MIGRATION trap.** `wpify-woo-paczkomaty/composer.json:25` contains
`"composer require --dev wpify/scoper:^2.2"` inside `post-create-project-cmd` — i.e. the scaffold for
new projects installs wpify/scoper **locally**, under `config.platform.php: 8.1` (:12). Today that works
(v2 requires `php ^7.4|^8.0`). After C4, any project that installs wpify/scoper locally while pinning
`config.platform.php` to `8.1` gets a hard resolver failure — and because the constraint is transitive
through `wpify/php-scoper`, the error message will name the wrong package (exactly the symptom C4 is
meant to cure). Migration: raise `config.platform.php` to `≥ 8.2` in any project that moves to a local
install. No action needed while all four install globally.

**Verdict C4:** SAFE for wpify-woo (verified PHP 8.3.13) and for local dev (8.4.20);
**UNKNOWN** for feeds/gopay/paczkomaty CI pending the shared template;
**NEEDS-MIGRATION** for paczkomaty's `post-create-project-cmd` path (composer.json:25 + :12).

### C5 — fail fast on missing/invalid prefix

All four declare a prefix, and all four are legal single-segment PHP namespaces:
`WpifyWooFeedsDeps`, `WpifyWooGopayDeps`, `WpifyWooPaczkomatyDeps`, `WpifyWooDeps`.
Nothing in this cluster relies on the silent no-op at `src/Plugin.php:127` — there is no sub-package
without a prefix that is expected to skip. **SAFE for all four.**

### C1 + M4 — nested Composer as a subprocess, exit-code propagation, re-entrancy guard, `--no-plugins`

I checked every locked scoped package's `type` across all four `composer-deps.lock` files. The only
non-`library` types are `woocommerce/action-scheduler` (`wordpress-plugin`, in all four) and
`dragonbe/vies` (`tool`, wpify-woo only). **No `composer-plugin` package, no `composer/installers`, no
`*-installer`, no `cweagans/composer-patches`, no `dealerdirect/phpcodesniffer-composer-installer`
appears in any `composer-deps.json` or its lock.**

`composer/installers` is absent even though Action Scheduler declares `type: wordpress-plugin` — which
is why it installs to the default `vendor/woocommerce/action-scheduler` (confirmed in the built feeds
tree). Nothing depends on installer-driven paths. `--no-plugins` on the nested install is therefore
**SAFE for all four**.

Note for completeness: `wpify-woo-paczkomaty/composer.json:9` allows
`dealerdirect/phpcodesniffer-composer-installer`, and it *is* in that project's root `composer.lock`
dev packages — but that is the **root** install, not the nested one. The nested install only ever sees a
copy of `composer-deps.json` (`src/Plugin.php:131,175`), so `--no-plugins` there cannot touch it.

Re-entrancy: none of the four copies `extra.wpify-scoper` into its `composer-deps.json` (checked all
four — no `extra` key at all), so the recursion hazard M4 describes is not armed in this cluster.

CI depending on `composer install` exiting 0 despite scoping failure: `tmp/wpify-woo/.gitlab-ci.yml:37`
runs a bare `composer install …` with no `|| true` and no `allow_failure`, so propagating the nested
exit code makes previously-silent failures visible — a **behaviour change in the right direction**, but
it means a scoping failure that CI currently ignores would start red-lighting the pipeline. Flagging it
so it is not mistaken for a regression. Same caveat presumably applies to the other three via the shared
template (UNKNOWN).

**Verdict C1+M4:** SAFE for all four, with the noted (intended) CI-visibility change.

### H7 — make `--no-dev` reachable

**No `require-dev` in any of the four `composer-deps.json`**, and `packages-dev` is `[]` (length 0) in all
four `composer-deps.lock` files. Nothing dev is currently scoped or shipped, so nothing disappears when
`--no-dev` starts working.

Worth recording: `tmp/wpify-woo/.gitlab-ci.yml:37` already passes `--no-dev` to the **outer** install.
That does not propagate — `execute()` sets `$useDevDependencies = true` for `POST_INSTALL_CMD`
(`src/Plugin.php:191–195`), which is exactly the H7 defect. Because there are no dev deps to drop, the
observable output is identical either way.

**Verdict H7: SAFE for all four** (no-op).

### H16 — full-AST symbol extraction, regenerated symbol lists

The question is whether a newly-excluded WP/Woo symbol collides with a **root-namespace** symbol defined
by a scoped dependency. I enumerated every class/interface/trait/enum, function and constant declared in
the feeds build inside a file whose namespace is exactly the prefix (i.e. originally global):
74 classes, 37 "functions", 33 constants.

- The 74 classes are all `ActionScheduler*` — already in `exclude-classes` via `woocommerce.php`
  (spot-checked `ActionScheduler`, `ActionScheduler_Store`, `ActionScheduler_Logger`,
  `ActionScheduler_DateTime`: all IN-LIST). Adding more WP symbols cannot newly collide with them.
- Of the 37 "functions", the 17 real global ones (`as_*`, `wc_*_scheduled_action*`) are **already** in
  `exclude-functions` (verified individually — all 17 IN-LIST). The remaining 20
  (`parse`, `text`, `indent`, `encodeit`, `decodeit`, `code_trick`, `user_sanitize`, `chop_string`,
  `filter_text`, `sanitize_text`, `parse_readme`, `parse_readme_contents`, `setBreaksEnabled`, …) are
  **class methods**, not global functions — they are members of `PucReadmeParser` and `Parsedown` in
  `yahnis-elsts/plugin-update-checker/vendor/PucReadmeParser.php` (confirmed by reading the file: they
  sit inside `class PucReadmeParser`). Method names are not in php-scoper's symbol namespace, so
  additions to `exclude-functions` cannot touch them.
- `wpify_custom_fields` is the one genuinely global project-side function; no WordPress or WooCommerce
  symbol will ever carry that name.
- The 33 "constants" are all class constants (`STATUS_PENDING`, `GROUPS_TABLE`, `DAY`, `HOUR`, …), none
  in `exclude-constants` today. Class constants are likewise outside php-scoper's global-constant
  handling.

**Verdict H16: SAFE for all four**, with an honest limitation — I validated against the *described*
delta (~18 function-body symbols, ~97 top-level consts, ~46 `class_alias` targets), not against the
actual regenerated files, which do not exist yet. The structural conclusion holds regardless: apart from
Action Scheduler (already fully excluded), these dependency sets declare essentially nothing in the root
namespace for a larger WP/Woo symbol list to collide with.

### M3 — stop writing generated `scripts` into the user's `composer-deps.json`

Reading `src/Plugin.php` closely: line 175 `createJson( $composerJsonPath, $composerJson )` writes to
`$source/composer.json` — inside the throwaway temp tree (`:131`), which `postinstall.php` deletes at the
end. The user's own `composer-deps.json` is only written at `:141`, and only when it does not exist yet.
So the current version does **not** clobber a committed `composer-deps.json` on every run.

Confirmed empirically: `composer-deps.json` is committed in all four (`git ls-files` matches), and
`git status --porcelain composer-deps.json composer-deps.lock` is **clean in all four** — including
feeds, whose scoped tree was rebuilt after the last commit (build 20:20, file untouched).

Two things still worth naming:

- `wpify-woo-paczkomaty/composer-deps.json:10` contains `"scripts": {},` — a residue of the
  `src/Plugin.php:137–141` bootstrap (or of an older plugin version that did write back). Harmless.
- **`tmp/wpify-woo/composer-deps.json:8–24` contains a hand-written `scripts.pre-autoload-dump` block**
  with 15 `rm -rf vendor/<pkg>/{test,docs,examples}` entries used to slim the shipped payload. The
  current code path preserves it: `$composerJson` is decoded from the user's file (`:135`) and the
  generated entry is **added** as `$composerJson->scripts->{post-install-cmd}` (`:169`), leaving
  `pre-autoload-dump` intact in the temp copy where the nested Composer will run it. Any M3 rework must
  keep merging rather than replacing `scripts` — dropping this block would silently re-inflate the
  wordpress.org release of wpify-woo by shipping every dependency's test and docs directories.

**Verdict M3:** SAFE for feeds, gopay, paczkomaty (no hand-written scripts, no churn).
**NEEDS-MIGRATION for tmp/wpify-woo** — not because M3 breaks it as specified, but because the
implementation must preserve user-authored `scripts` keys; wpify-woo is the project that would notice.

### M15 — validate `globals` entries

Available symbol files: `action-scheduler.php`, `plugin-update-checker.php`, `woocommerce.php`,
`wordpress.php`, `wp-cli.php`. Every `globals` entry across all four resolves:
`wordpress`, `woocommerce`, `plugin-update-checker` (feeds, gopay), `wordpress`, `woocommerce`
(paczkomaty, wpify-woo). No typos, no unknown names. **SAFE for all four.**

Interaction to sequence: if H15 lands (delete `symbols/plugin-update-checker.php`) *before or with*
M15 (reject unknown `globals`), then feeds and gopay start **failing validation** on a name that was
valid the day before. Those two `globals` arrays must be edited in the same release, or M15 must warn
rather than fail for this one name during a deprecation window.

---

## Verdict table

Legend: SAFE / NEEDS-MIGRATION / BREAKING / UNKNOWN / n-a = not applicable.

| Item | wpify-woo-feeds | wpify-woo-gopay | wpify-woo-paczkomaty | tmp/wpify-woo |
|---|---|---|---|---|
| **C4** php `^8.1`→`^8.2` | UNKNOWN (CI template not local; dev PHP 8.4.20 ok) | UNKNOWN (same) | **NEEDS-MIGRATION** (composer.json:25 installs scoper locally under `platform.php 8.1` :12) | SAFE (composer:2.8.2 = PHP 8.3.13) |
| **C5** fail fast on missing prefix | SAFE | SAFE | SAFE | SAFE |
| **C3** atomic swap via `.bak` sibling | **NEEDS-MIGRATION** (`.bak` lands in `vendor/`, gitignored, never pruned) | **NEEDS-MIGRATION** (same) | SAFE (`deps` is top-level) | **NEEDS-MIGRATION** (`.rsyncinclude:6 + /vendor/***` ships it to wordpress.org; CI artifacts :29–30) |
| **C2** `is_link()` guard | SAFE (0 symlinks in built tree) | SAFE | SAFE | SAFE |
| **C1+M4** subprocess / exit code / `--no-plugins` | SAFE | SAFE | SAFE | SAFE (CI will newly red-light on scoping failure — intended) |
| **H1** anchored prefix-stripping | SAFE (only `WP_CLI`/`WP_CLI_Command`, in dead code) | SAFE | SAFE | SAFE |
| **H2** narrow `autoload_static` rewrite | SAFE (verified: 3 hits, all in `$files`) | SAFE (inferred) | SAFE (inferred) | SAFE (inferred) |
| **H7** `--no-dev` reachable | SAFE (no require-dev) | SAFE | SAFE | SAFE |
| **H14** regenerate PUC for v5 | **BREAKING** unless `scoper.inc.php:75–77` patcher preserved | **BREAKING** (same) | n-a (no PUC) | n-a (no PUC) |
| **H15** drop built-in PUC list | SAFE (list is dead; remove from `globals`) | SAFE (same) | n-a | n-a |
| **H16** regenerated symbol lists | SAFE | SAFE | SAFE | SAFE |
| **H18** `scoper.custom.php` discovery | n-a (no custom file) | n-a | n-a | SAFE — already applied today via global-install path; fix must not narrow discovery |
| **M3** stop writing `scripts` | SAFE | SAFE | SAFE | **NEEDS-MIGRATION** (hand-written `pre-autoload-dump`, composer-deps.json:8–24, must survive) |
| **M15** validate `globals` | SAFE (sequence with H15) | SAFE (sequence with H15) | SAFE | SAFE |
