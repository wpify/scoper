# Consumer impact — Group A: Bedrock sites (alfamarka, marieolivie, dluhopisy, teatechnik)

Verification of the proposed `wpify/scoper` changes against four real Bedrock-style WordPress
projects. **All inspection was read-only.** No file in any of the four projects was created,
modified or deleted; no `composer install/update` was run. Scratch artefacts live in
`/private/tmp/claude-503/-Users-wpify-projects-scoper/a8a9361a-6f4f-45a7-9ff3-d48b90efa403/scratchpad/`.

---

## 0. Baseline facts established first

These govern every verdict below, so they are stated up front with evidence.

### 0.1 The plugin is installed **globally and unpinned**, not per project

`wpify/scoper` is in **no** project's `vendor/`. It is installed at
`/Users/wpify/.composer/vendor/wpify/scoper` from `/Users/wpify/.composer/composer.json`:

```json
{ "require": { "wpify/scoper": "^3.2" }, "config": { "allow-plugins": { "wpify/scoper": true } } }
```

Installed version **3.2.21** (`~/.composer/composer.lock`), alongside `wpify/php-scoper` 0.18.18
(which itself declares `"php": "^8.2"` — direct confirmation of C4's unsatisfiability claim).

The CI of three of the four projects re-resolves it on **every pipeline, with no constraint**:

- `alfamarka/.gitlab-ci.yml:43` — `composer global require wpify/scoper`
- `marieolivie/.gitlab-ci.yml:41` — same
- `dluhopisy/.gitlab-ci.yml:44` — same

There is no lock file, no version pin and no staging gate between a Packagist release and these
production pipelines. **Every change discussed below reaches these three sites on their next
pipeline run.** This is the largest consumer-side risk multiplier in this report and it is
independent of the merits of any individual change.

I verified that the installed 3.2.21 is **byte-identical** to repo HEAD for `src/Plugin.php`,
`scripts/postinstall.php` and `config/scoper.inc.php` (`diff` returned no output for all three).
Only `symbols/*.php` differ. So all source-level reasoning below applies directly to the code that
actually built these projects' `deps/`.

### 0.2 `deps/` is a hard bootstrap dependency of every request

Every one of the four requires the scoped autoloader from `wp-config.php`, before anything else:

| Project | Line |
|---|---|
| alfamarka | `web/wp-config.php:12` — `require_once dirname(__DIR__) . '/web/app/deps/scoper-autoload.php';` |
| marieolivie | `web/wp-config.php:12` — same |
| dluhopisy | `web/wp-config.php:8` — same |
| teatechnik | `web/wp-config.php:17` — same |

`alfamarka/bootstrap.php:4-5` then does `use AlfamarkaDeps\DI\Container;`. Prefix usage is
widespread in project code: 29 files (alfamarka), 15 (marieolivie), 61 (dluhopisy), 42 (teatechnik)
under `src/` + `web/app/mu-plugins/`.

**Consequence:** a missing, half-written or symbol-corrupted `deps/` is not a degraded feature, it
is a whole-site HTTP 500. This raises the value of C3 and the cost of any H1 regression.

### 0.3 Configuration is identical across all four

`extra.wpify-scoper` contains exactly two keys everywhere — `prefix` and `folder` — with
`folder: "web/app/deps"` in all four. No `globals`, no `autorun`, no `temp`, no `composerjson`.

| Project | prefix | composer.json:line |
|---|---|---|
| alfamarka | `AlfamarkaDeps` | `composer.json:117-120` |
| marieolivie | `AlfamarkaDeps` | `composer.json:97-100` |
| dluhopisy | `DluhopisyDeps` | `composer.json:91-94` |
| teatechnik | `TeatechnikDeps` | `composer.json:77-80` |

All four `composer-deps.json` files are **byte-identical** (612 bytes): 8 requires + `ext-json`,
`config.platform.php: "8.3"`, and an `allow-plugins` block. No `require-dev`, no `scripts`, no
`repositories`, no `extra`.

`composer-deps.lock`: 14 packages, **0 dev packages, 0 packages of type `composer-plugin`** in all
four. Packages scoped: `laravel/serializable-closure`, `php-di/invoker`, `php-di/php-di`,
`psr/container`, `symfony/deprecation-contracts`, `symfony/polyfill-ctype`,
`symfony/polyfill-mbstring`, `twig/twig`, `wpify/{asset,custom-fields,model,plugin-utils,snippets,templates}`.

The `allow-plugins` block in `composer-deps.json` (`composer/installers`,
`dealerdirect/phpcodesniffer-composer-installer`, `roots/wordpress-core-installer`,
`mnsami/composer-custom-directory-installer`) is **vestigial copy-paste from the outer
`composer.json`** — none of those packages exists in the scoped tree.

### 0.4 Deployment

| Project | CI | deps reaches production via |
|---|---|---|
| alfamarka | `.gitlab-ci.yml` active | `composer` job artifact `$CI_PROJECT_DIR/web/app/deps` (`:34`) → `server_deploy … web/app/deps/ …` (`:82`) |
| marieolivie | active | artifact `:32` → `server_deploy … web/app/deps/ …` (`:77`) |
| dluhopisy | active | artifact `:35` → `server_deploy … web/app/deps/ …` (`:72`) |
| teatechnik | **none** (`.gitlab-ci.example.yml` only) | **UNKNOWN** — see §4.6 |

`deps/` is **not committed** anywhere: `.gitignore` has `web/app/deps/*` in all four
(alfamarka `:20`, marieolivie `:20`, dluhopisy `:14`, teatechnik `:9`), and `git ls-files
web/app/deps` returns 0 files in all four. It is a pure build artefact.

The final rsync is **additive** — `alfamarka/.gitlab-ci/scripts/server-deploy:9`:

```bash
rsync -av --exclude=".gitlab-ci" "$FILES_PATH/" "$PROJECT_PATH/"
```

no `--delete`, reinforced by `RSYNC_NO_DELETE: true` in each `.gitlab-ci.yml`. **Files that
disappear from a build are never removed from the server.**

CI runner image is `composer:2.8.2` (all three). I resolved its PHP version from the upstream
build definition rather than guessing: `composer/docker` commit `327b1e81` ("release 2.8.2",
2024-10-30) has `latest/Dockerfile:1` = `FROM php:8-alpine`, which at that build date resolved to
**PHP 8.3.x**. DDEV `php_version`: 8.3 (alfamarka, marieolivie, dluhopisy), 8.4 (teatechnik).
Host CLI: PHP 8.4.20.

---

## 1. Live-corruption evidence mined from the built `deps/`

All four have a built `deps/` on disk (alfamarka Jul 6, marieolivie Jul 6, dluhopisy Jul 13,
teatechnik May 1). I mined all four for the H1 and H2 bugs.

### H2 — `autoload_static.php` classmap corruption: **not present**

The postinstall regex (`scripts/postinstall.php:40-44`) matches `'([[:alnum:]]+)' => …` and
prefixes the key with the lowercased prefix. Grepping each generated
`deps/composer/autoload_static.php` for keys with no backslash:

| Project | unqualified keys found | what they are |
|---|---|---|
| alfamarka | 9 (lines 10-18) | exactly the `$files` md5 keys — `alfamarkadeps6e3fae29…` etc. |
| marieolivie | 9 (lines 10-18) | same, `alfamarkadeps…` |
| dluhopisy | 9 (lines 10-18) | `dluhopisydeps…` |
| teatechnik | 9 (lines 10-18) | `teatechnikdeps…` |

Every `$classMap` key is namespaced (`'AlfamarkaDeps\\DI\\Container' => …`) and therefore contains
a backslash, which the `[[:alnum:]]+` character class cannot match. **The over-broad regex has
never fired on a classmap key in any of these four projects.** Narrowing it to the `$files` block
produces byte-identical output here.

### H1 — prefix-stripping with no right-hand boundary: **no live corruption**

Two independent probes (`scratchpad/h1-probe.php`, `scratchpad/h1-content-scan.php`):

1. **Symbol-set probe.** Loaded the 1,147 excluded classes + 464 excluded namespaces, extracted
   every scoped symbol from each `autoload_static.php` (480 / 480 / 480 / 475), and tested whether
   any excluded symbol is a *proper* prefix of any scoped symbol. **0 collisions in all four.**
   None of the seven scoped root namespaces (`DI`, `Invoker`, `Laravel`, `Psr`, `Symfony`, `Twig`,
   `Wpify`) is itself an excluded symbol.
2. **Content grep.** `grep -rE "^\s*use\s+\\\\?(DI|Invoker|Twig|Psr|Laravel|Symfony|Wpify)\\\\"`
   across each `deps/` tree: **0 files** in all four. No scoped root namespace has been de-prefixed.

The one case where the de-prefixing has demonstrably run is **namespace exclusions, and there it is
doing the right thing**:

- `deps/wpify/snippets/src/CustomSMTP.php:5` — `use PHPMailer\PHPMailer\PHPMailer;`
  (excluded namespace `PHPMailer\PHPMailer` is a strict prefix of the referenced FQN)
- `deps/wpify/custom-fields/src/Integrations/OrderMetabox.php:10` and
  `.../SubscriptionMetabox.php:10` — `Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController`
  (excluded namespace `Automattic\WooCommerce\Internal\DataStores\Orders`)

**This is the constraint that makes H1 dangerous.** See §2, H1.

Also observed and *not* corruption: `\WP_CONTENT_DIR` in `deps/wpify/asset/src/AssetFactory.php:26`,
`deps/wpify/custom-fields/src/{CustomFields.php:268,337, Api.php:177, Helpers.php:243-244}`.
`WP_CONTENT_DIR` is present in `exclude-constants` (540 entries in the installed list), so
php-scoper leaves it global natively; the class symbol `WP` being a textual prefix of it is
coincidental and the patcher needle never matches, because php-scoper never prefixed it.

---

## 2. Checklist, item by item

### C4 — bump `require.php` from `^8.1` to `^8.2` → **SAFE ×4**

What I actually checked, per project: `.ddev/config.yaml` `php_version`, `.gitlab-ci.yml` runner
image, host `php -v`. No `Dockerfile`, `.tool-versions` or `.php-version` exists in any of the four.

| Project | dev (DDEV) | CI runs the tool on | verdict |
|---|---|---|---|
| alfamarka | `.ddev/config.yaml:3` → 8.3 | `composer:2.8.2` = PHP 8.3.x (`.gitlab-ci.yml:30`) | SAFE |
| marieolivie | `.ddev/config.yaml:3` → 8.3 | `composer:2.8.2` (`.gitlab-ci.yml:28`) | SAFE |
| dluhopisy | `.ddev/config.yaml:4` → 8.3 | `composer:2.8.2` (`.gitlab-ci.yml:31`) | SAFE |
| teatechnik | `.ddev/config.yaml:4` → 8.4 | no CI | SAFE |

Not conflated: `composer-deps.json` `config.platform.php: "8.3"` and `require.php: "^8.3"`
constrain the **scoped dependency resolution** only, and are untouched by C4.

Because CI does `composer global require wpify/scoper` **unconstrained**, a `^8.2` release lands
immediately. That is fine at PHP 8.3, but note it means C4 is *self-enforcing on the next pipeline*
with no opportunity to test — if any of these images were ever pinned back to a PHP-8.1 composer
image the pipeline would fail at the `global require` step, not at install time.

### C5 — fail fast on missing/invalid `prefix` → **SAFE ×4**

All four prefixes are present, non-empty, and match `^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$`.
Nothing relies on the silent no-op: no project has `autorun`, and all four have a populated
`deps/` proving the path is exercised. There is no sub-package or nested `composer.json` in these
repos carrying an `extra.wpify-scoper` block without a prefix.

### C3 — atomic swap via a `.bak` sibling → **SAFE ×4** (with one advisory)

- `folder` is `web/app/deps` in all four — **not** inside `vendor/`. A `.bak` sibling lands at
  `web/app/deps.bak`, inside the WordPress content directory, not inside `vendor/`.
- **Same filesystem:** `stat -f %d` returns device `16777231` for project root, `web`, `web/app`
  and `web/app/deps` in all four. `tmp-XXXX` is created at `getcwd()` (`Plugin.php:58`), i.e. the
  project root — same device. No cross-device rename risk locally. In DDEV and in CI the whole tree
  is one bind mount / one workspace, so this holds there too.
- **Value is high here**, not marginal: because `deps/scoper-autoload.php` is required from
  `wp-config.php`, today's `remove($deps); rename(...)` (`scripts/postinstall.php:62-63`) is a
  window in which the site has no dependencies at all, and a failed `rename()` leaves it that way
  while still exiting 0.
- **Advisory (not a blocker):** `.gitignore` ignores `web/app/deps/*` (the *contents*), which does
  **not** match a `web/app/deps.bak` sibling. A backup left behind after a failure would show up as
  untracked in `git status` and would sit under the web content dir. It would not be deployed —
  the deploy lists `web/app/deps/` explicitly — and it would not be archived as a CI artifact,
  which names `$CI_PROJECT_DIR/web/app/deps`. Cheapest mitigations, in order of preference:
  put the backup inside the existing `tmp-*` directory rather than beside `deps/`, or ship a
  documented `.gitignore` line. This applies equally to `alfamarka/.worktrees/product-details`.

### C2 — `remove()` gains an `is_link()` guard → **SAFE ×4**

Purely additive hardening; nothing in these projects can currently trigger the bug, and nothing
depends on the symlink-following behaviour.

- `web/app/deps` is a real directory in all four (`ls -ld`, no `l` bit).
- `find <deps> -type l` → **0 symlinks** in all four trees (511 / 511 / 511 / 505 PHP files scanned).
- `find web/app -maxdepth 2 -type l` → 0.
- **No `path` repositories anywhere.** `composer-deps.json` has no `repositories` key at all, so
  the nested install resolves from Packagist only. The outer `composer.json` repositories are all
  `type: composer` (wpackagist, satispress, and for dluhopisy `repo.wp-packages.org`).
- DDEV uses bind mounts, not symlinks, for the project root.

### C1 + M4 — subprocess, exit-code propagation, re-entrancy guard, `--no-plugins`

**`--no-plugins` on the nested install: SAFE ×4.** `composer-deps.lock` contains zero packages of
type `composer-plugin` and zero dev packages in all four (verified by decoding each lock). No
`*-installer`, no `cweagans/composer-patches`, no `dealerdirect/phpcodesniffer`. The `allow-plugins`
block inside `composer-deps.json` names four plugins that are **not in the dependency tree** — it is
copy-paste from the outer manifest and can be ignored.

**Re-entrancy guard: SAFE ×4.** No `composer-deps.json` contains an `extra` key, so the recursion
scenario the guard defends against cannot arise here.

**Exit-code propagation: behaviour change, all four.** Today a scoping failure still exits 0 (M6),
so the CI `composer` job goes green with a broken or stale `deps/`, and — because the rsync has no
`--delete` — the previous `deps/` survives on the server, masking it. After the fix the job goes
red and blocks `deploy` (`needs: [assets, composer]`). This is the desired outcome; flagging it
because latent failures may surface as new CI reds on the first pipeline after the change.

**alfamarka: NEEDS-MIGRATION.** This project has a real `post-install-cmd`
(`composer.json:135-137` → `@apply-woocommerce-cart-skeleton-patch`), and it is **not running
today**. Composer's `EventDispatcher::getListeners()` merges plugin subscriber listeners *before*
root-package script listeners at the same priority:

`~/.composer/vendor/composer/composer/src/Composer/EventDispatcher/EventDispatcher.php:593`
```php
$listeners[$event->getName()][0] = array_merge($listeners[$event->getName()][0], $scriptListeners);
```

`Plugin::getSubscribedEvents()` registers `POST_INSTALL_CMD => 'execute'` at default priority 0
(`src/Plugin.php:44-49`), so `execute()` runs first and `runInstall()`'s `Application::run()` exits
the process before the root script is reached. `Composer\Console\Application` never calls
`setAutoExit(false)` (grep returns nothing), so Symfony's default `autoExit = true` applies.

The workaround is visible in the pipeline — `alfamarka/.gitlab-ci.yml:45` runs
`php scripts/apply-woocommerce-cart-skeleton-patch.php` manually, immediately after
`composer install`. **This is direct field evidence of C1's impact.** After the fix the script
starts running from `post-install-cmd` and will therefore run **twice** in CI. I verified this is
harmless: `scripts/apply-woocommerce-cart-skeleton-patch.php:11-20` treats `already-patched` as a
valid result and exits 0. Migration = delete the now-redundant `.gitlab-ci.yml:45`. Note the local
dev path is currently *worse* than CI: on a developer machine the patch never runs at all.

**marieolivie / dluhopisy / teatechnik: SAFE.** marieolivie declares `"post-install-cmd": []` and
`"post-update-cmd": []` (`composer.json:112-113`); dluhopisy and teatechnik declare no
install/update scripts at all. Nothing depends on `composer install` exiting 0 while scoping fails.

### H1 — anchored prefix-stripping → **BREAKING as literally specified ×4**

No live corruption exists (§1), so there is nothing to gain here for these four projects — only
something to lose. The checklist describes the change as *"only exact symbol matches get
de-prefixed"*. Implemented literally, that removes the segment-boundary behaviour that
`exclude-namespaces` depends on, and these projects' `deps/` demonstrably contains references that
need it:

| Reference | File:line (present in all four) | Excluded entry that must still match |
|---|---|---|
| `use PHPMailer\PHPMailer\PHPMailer;` | `deps/wpify/snippets/src/CustomSMTP.php:5` | namespace `PHPMailer\PHPMailer` |
| `Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController` | `deps/wpify/custom-fields/src/Integrations/OrderMetabox.php:10`, `SubscriptionMetabox.php:10` | namespace `Automattic\WooCommerce\Internal\DataStores\Orders` |

Both namespaces are still present in the regenerated lists (verified old→new: both `YES`→`YES`).
If those FQNs come back prefixed, the failure modes are a fatal on every SMTP send
(`phpmailer_init`) and a fatal on HPOS order/subscription metabox registration — on **all four
sites**, at `wp-config.php` bootstrap depth.

**The condition that makes this SAFE:** anchor the *right-hand* side at a namespace-separator or
end-of-symbol boundary (`(?=$|\\|\W)`), keeping `exclude-namespaces` as a segment-wise prefix match
and `exclude-classes` as an exact match. That is what the audit's own suggested regex
`(?:use\s+|\\)?{$prefix}\\([A-Za-z_][\w\\]*)` + hash-set lookup would do **only if** the lookup
walks the captured symbol's namespace segments, not just tests the whole string.

**Honest limit of this finding:** php-scoper 0.18 handles `exclude-namespaces` natively at the AST
level, so those two FQNs were most likely already correct before the patcher ever ran, and the
patcher is a no-op. I cannot prove which of the two produced them without re-running a build, which
would violate the read-only constraint. That is exactly why this is the audit's own phase-2 gate
("establish why the block exists at all") and why I am reporting the naive variant as BREAKING
rather than assuming it is safe.

### H2 — narrow the `autoload_static.php` rewrite to `$files` → **SAFE ×4**

See §1. Byte-identical output for all four. Recommend the golden-file test use one of these real
`deps/composer/autoload_static.php` files as the fixture — they exercise the `$files`,
`$prefixLengthsPsr4`, `$prefixDirsPsr4` and `$classMap` sections together.

### H7 — make `--no-dev` reachable → **SAFE ×4**

`composer-deps.json` has **no `require-dev`** in any of the four, and every `composer-deps.lock`
reports `packages-dev: 0`. Nothing would disappear from `deps/`. No project code references a
scoped dev dependency (there are none to reference).

Worth recording for other consumer groups: were dev packages ever dropped here, the additive rsync
(§0.4) would leave the stale files on the server indefinitely.

### H14 / H15 — `plugin-update-checker` → **SAFE ×4 (not applicable)**

- `globals` is unset in all four, so the defaults apply (`Plugin.php:60`:
  `wordpress`, `woocommerce`, `action-scheduler`, `wp-cli`). `plugin-update-checker` is **not**
  among them, so `symbols/plugin-update-checker.php` is never loaded and the two PUC patchers in
  `config/scoper.inc.php:48,75` never match a file path.
- `yahnis-elsts/plugin-update-checker` is **not** in any `composer-deps.lock`.
- `grep -rln "Puc_v4\|Puc_v5\|YahnisElsts\|plugin-update-checker"` across `src/`,
  `web/app/mu-plugins/`, `composer.json` and `composer-deps.json`: **0 hits in all four.**

The highest-risk item in the checklist has **zero exposure** in this group. Either resolution —
regenerate for v5 or drop the built-in list — is invisible to these four.

### H16 — full-AST symbol extraction, regenerated lists → **SAFE ×4**

This is the change with the widest blast radius, so I measured the actual delta these projects
would experience rather than the delta between two repo commits. Comparing the symbol lists in the
**installed 3.2.21** (which produced their current `deps/`) against repo HEAD, merged over the four
default globals (`scratchpad/h16-delta.php`):

| List | old | new | added | removed |
|---|---|---|---|---|
| `exclude-functions` | 4,995 | 5,213 | 230 | 12 |
| `exclude-constants` | 450 | 551 | 102 | 1 |
| `exclude-classes` | 1,092 | 1,147 | 59 | 4 |
| `exclude-namespaces` | 212 | 464 | 278 | 26 |

**Additions — 0 collisions in all four.** I extracted every function, class/interface/trait/enum,
`define()` and top-level `const` *defined* inside each project's `deps/` tree and tested it against
the newly-added symbols. Zero hits. No scoped package defines a symbol that newly becomes excluded.

**Removals — 2 references, both benign.** The only references any project makes to a removed
exclusion are PHP built-ins:

- `is_countable` — `deps/twig/twig/src/Extension/CoreExtension.php:330` (all four)
- `array_key_last` — `deps/twig/twig/src/NodeVisitor/CorrectnessNodeVisitor.php:164`
  (alfamarka, marieolivie, dluhopisy; teatechnik's older Twig 3.24 does not use it)

Both are emitted **unqualified** in the current output (`if (!is_countable($values))`), i.e.
php-scoper's own internal-symbol registry already keeps them global independently of the exclusion
lists. Removing them from `exclude-functions` changes nothing. The other removals
(`MailPoet\EmailEditor\*`, `Automattic\WooCommerce\Vendor\League\Container`,
`WC_Interactivity_Initial_State`, …) are not referenced anywhere in these `deps/` trees.

**Pre-existing, not introduced by H16:** `symfony/polyfill-mbstring` defines `mb_strlen` and
`mb_substr` (`bootstrap72.php`, `bootstrap80.php`; teatechnik: `bootstrap.php`), and both names are
in the WordPress exclusion list. That is correct — a polyfill *must* define the global function —
and it is unchanged by this work.

### H18 — fix `scoper.custom.php` discovery → **SAFE ×4**

`createPath()` (`src/Plugin.php:268-276`) tests `strpos(dirname(__DIR__), 'vendor/wpify/scoper')`.
For the global install `dirname(__DIR__)` is `/Users/wpify/.composer/vendor/wpify/scoper`, which
**does** contain the literal substring, so the check passes and `$in_root` resolution to `getcwd()`
works today. The custom-config path is *not* silently ignored in this topology.

Moot in practice: `find -maxdepth 3 -name scoper.custom.php` returns nothing in any of the four
projects (nor in the worktree). There is no customisation to start or stop applying, so the fix is
a behaviour change for nobody here.

### M3 — stop writing generated `scripts` into the user's `composer-deps.json` → **SAFE ×4**

**Correction to the audit's premise.** The shipped code does **not** write to the user's
`composer-deps.json`. `src/Plugin.php:131` sets `$composerJsonPath = $this->path($source,
'composer.json')` — inside the temp directory — and `:175` writes there. The user's path is written
at `:141` only in the branch where the file **does not exist**, to seed a stub. All four projects
have the file, so that branch is never taken.

Empirically confirmed: all four `composer-deps.json` are tracked, contain **no `scripts` key**, no
absolute paths and no `tmp-` strings, and `git status --porcelain composer-deps.json
composer-deps.lock` is **clean** in all four. Zero churn.

`composer-deps.lock` *is* written back (`scripts/postinstall.php:57-58`, copying the nested
`composer.lock` over it) and is tracked in all four — that is the intended behaviour for a lock
file, not clobbering. No hand-written script would be lost by this change in this group.

### M15 — validate `globals` against available symbol files → **SAFE ×4**

`globals` is not set in any of the four; the hardcoded defaults at `src/Plugin.php:60` are used, and
all four names map to real files (`symbols/{wordpress,woocommerce,action-scheduler,wp-cli}.php`).
Validation would pass silently.

---

## 3. `.worktrees/` (alfamarka)

`git worktree list` in alfamarka:

```
/Users/wpify/projects/alfamarka                            bd40641 [master]
/Users/wpify/projects/alfamarka/.worktrees/product-details b1611e3 [test]
```

The worktree is a **full independent working copy**: its own `vendor/`, its own
`web/app/deps/` (built Jun 4, same 11 entries), its own `composer-deps.lock`, its own
`node_modules/`, and its own `.ddev/`. Its `extra.wpify-scoper` is identical to the main tree —
same `AlfamarkaDeps` prefix, same `web/app/deps` folder (`composer.json:146-149`).

Interaction with the temp-dir findings (M8):

- The temp directory is `getcwd() . '/tmp-' . …` (`Plugin.php:58`), and `folder` is resolved
  relative to `getcwd()` too (`:66`). Since each worktree has its own cwd, **main and worktree
  builds cannot collide** — each gets its own `tmp-*` and writes its own `web/app/deps`. The weak
  `str_shuffle(md5(microtime()))` randomness is not a cross-worktree hazard.
- **No `tmp-*` leftovers** exist in any of the four projects or in the worktree — the happy-path
  cleanup works.
- `tmp-*` is **not** in any project's `.gitignore`. A temp dir left behind by a failure (M8) would
  show as untracked. `.worktrees/` itself *is* ignored (`alfamarka/.gitignore:51`), so leftovers
  inside the worktree are invisible to `git status` — which cuts both ways.
- Same prefix in both trees is harmless: they are separate directories loaded by separate PHP
  processes.

The C3 `.bak` advisory applies per worktree: `web/app/deps.bak` would appear in each and is not
covered by `web/app/deps/*`.

---

## 4. Additional findings outside the checklist

**4.1 — marieolivie is an unrenamed fork of alfamarka.** The shared `AlfamarkaDeps` prefix is not
an isolated typo in `extra.wpify-scoper`; the whole project was copied and never renamed:

- `composer.json:2` — `"name": "wpify/alfamarka"`; `:5` — `"description": "Alfamarka"`
- `composer.json:117` — `"autoload": {"psr-4": {"Alfamarka\\": "src/"}}`
- `src/Plugin.php:3` — `namespace Alfamarka;`
- `web/app/mu-plugins/alfamarka/` is the mu-plugin directory
- `.gitlab-ci.yml:20` artifacts `web/app/themes/alfamarka/build`; `:68` patches
  `web/app/mu-plugins/alfamarka/alfamarka.php`; `:2` `SERVER_ADDR: alfamarka.infra.church`
- `git log`: `0f0c67c Initial MarieOlivie import`

`diff -rq` of the two `deps/` trees differs only in `composer/installed.php` (install paths). This
is **not a scoper misconfiguration and has no functional consequence** — the prefix is per-process
and the two sites never share one. It is a naming-hygiene issue for the project team, and it means
any future "rename the project properly" task must change the prefix, which forces a full
`deps/` rebuild and a coordinated deploy. Flagging it, not blocking on it.

**4.2 — the global unpinned install is the dominant risk.** Repeating §0.1 because it changes how
any rollout should be staged: three of four pipelines run `composer global require wpify/scoper`
with no constraint and no lock. There is no mechanism today by which a bad release is caught before
it builds a production `deps/`. Recommend pinning to `wpify/scoper:^3.2.21` (or a tag) in these
pipelines *before* landing phase 2 or 3.

**4.3 — failures are currently double-masked.** Scoping failure exits 0 (M6) **and** the deploy
rsync has no `--delete`, so the previous `deps/` remains on the server. A silently broken build is
therefore invisible from both the pipeline and the site. Fixing exit-code propagation (C1) removes
the first mask; the second is a project-side choice.

**4.4 — `deps/` under the docroot.** `web/app/deps/` sits inside the web root. Not introduced by
any proposed change, but it means a stray `web/app/deps.bak` would also be under the docroot.
Reinforces the recommendation in C3 to keep the backup in the temp directory.

**4.5 — teatechnik's `deps/` is 3 months stale** (built May 1; Twig 3.24 and
`wpify/custom-fields` 4.7.0 vs 3.28/4.9.3 elsewhere). Its `composer-deps.lock` matches, so the
build is self-consistent; it simply has not been rebuilt. It will pick up all of the above at once
on its next `composer install`.

**4.6 — teatechnik has no active CI, and its template would ship a broken site.** Only
`.gitlab-ci.example.yml` exists. The referenced `.gitlab-ci/pipeline/deploy.yml` archives
`$CI_PROJECT_DIR/deps` (`:25`) — the plugin's **default** folder, not the configured
`web/app/deps` — and its `server_deploy` list (`:48-62`) contains **no deps path at all**.
Enabling that template as-is would deploy a site that fatals at `web/wp-config.php:17`. This is a
project-side bug, not a scoper bug, but it is worth telling the team. Deployment for teatechnik is
otherwise **UNKNOWN**: to resolve it I would need the GitLab project's CI settings or the server's
current layout, neither of which is inspectable from this checkout.

---

## 5. Verdict matrix

| Checklist item | alfamarka | marieolivie | dluhopisy | teatechnik |
|---|---|---|---|---|
| **C4** php `^8.1`→`^8.2` | SAFE | SAFE | SAFE | SAFE |
| **C5** fail fast on missing/invalid prefix | SAFE | SAFE | SAFE | SAFE |
| **C3** atomic swap via `.bak` sibling | SAFE¹ | SAFE¹ | SAFE¹ | SAFE¹ |
| **C2** `is_link()` guard in `remove()` | SAFE | SAFE | SAFE | SAFE |
| **C1+M4** subprocess / exit code / re-entrancy / `--no-plugins` | **NEEDS-MIGRATION**² | SAFE | SAFE | SAFE |
| **H1** anchored prefix-stripping | **BREAKING**³ | **BREAKING**³ | **BREAKING**³ | **BREAKING**³ |
| **H2** narrow `autoload_static.php` rewrite | SAFE | SAFE | SAFE | SAFE |
| **H7** make `--no-dev` reachable | SAFE | SAFE | SAFE | SAFE |
| **H14/H15** plugin-update-checker | SAFE (n/a) | SAFE (n/a) | SAFE (n/a) | SAFE (n/a) |
| **H16** full-AST extraction + regenerated symbols | SAFE | SAFE | SAFE | SAFE |
| **H18** `scoper.custom.php` discovery | SAFE (n/a) | SAFE (n/a) | SAFE (n/a) | SAFE (n/a) |
| **M3** stop writing `scripts` into `composer-deps.json` | SAFE⁴ | SAFE⁴ | SAFE⁴ | SAFE⁴ |
| **M15** validate `globals` | SAFE | SAFE | SAFE | SAFE |
| *(context)* deployment path for `deps/` | verified | verified | verified | **UNKNOWN**⁵ |

¹ A leftover `web/app/deps.bak` is not matched by the `web/app/deps/*` gitignore rule. Prefer
placing the backup inside the existing `tmp-*` directory; otherwise ship a `.gitignore` line.
Applies per worktree in alfamarka.

² `composer.json:135-137` declares `post-install-cmd → @apply-woocommerce-cart-skeleton-patch`,
which the C1 `exit()` prevents from running today; `.gitlab-ci.yml:45` runs it manually as a
workaround. After the fix it runs from both places (verified idempotent). Migration: delete
`.gitlab-ci.yml:45`.

³ Only if "anchored" is implemented as *exact symbol match*. All four `deps/` trees contain
namespace references that require segment-boundary prefix matching against `exclude-namespaces`
(`use PHPMailer\PHPMailer\PHPMailer;`,
`Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController`). Preserving
segment-wise matching for namespaces makes this **SAFE ×4** — there is no live H1 corruption to fix
in this group (0 collisions across 480/480/480/475 scoped symbols).

⁴ The audit's premise does not match the shipped code: generated scripts go to the temp manifest
(`Plugin.php:131`, `:175`), never to the user's file. All four `composer-deps.json` are git-clean
with no `scripts` key.

⁵ No `.gitlab-ci.yml`; the example template would not deploy `deps/` at all. See §4.6.
