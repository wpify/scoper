# 04 — Code Quality, Testability, Project Hygiene & DX

**Audit date:** 2026-07-27
**Scope:** `src/Plugin.php`, `scripts/*.php`, `bin/wpify-scoper`, `config/*.php`, `composer.json`, `README.md`, `.gitignore`, git history/tags.
**Excluded:** `sources/`, `vendor/` (both git-ignored, never shipped).
**Latest tag:** `3.2.21` (2025-05-07), 1 commit on `master` since.

---

## 0. Executive summary

| # | Finding | Severity | Effort |
|---|---------|----------|--------|
| 1 | `require: php ^8.1` is unsatisfiable — `wpify/php-scoper` requires `^8.2` | **critical** | S |
| 2 | Missing/invalid `prefix` silently does nothing (`Plugin.php:127`) | **critical** | S |
| 3 | Zero tests, zero CI — every release is hand-verified | **high** | L |
| 4 | `exit;` inside library code (`Plugin.php:215`) — no message, kills host process | **high** | S |
| 5 | `getCapabilities()` is dead code and would fatal if ever called (`Plugin.php:104`) | **high** | S |
| 6 | `*_NO_DEV_CMD` constants are unreachable — dead feature (`Plugin.php:19,21`) | **medium** | S |
| 7 | `createPath(..., true)` vendor-dir detection is string-matching (`Plugin.php:269`) | **high** | S |
| 8 | Empty `globals` produces undefined-key TypeError in `config/scoper.inc.php:79` | **high** | S |
| 9 | PHP 5-era style throughout `Plugin.php` (no strict_types, no types, `array()`) | **medium** | M |
| 10 | 306-line god class — 7 distinct responsibilities | **medium** | M |
| 11 | No PHPStan / no CS config / no `.editorconfig` | **medium** | M |
| 12 | No `.gitattributes` `export-ignore` (smaller problem than it looks — see §7) | **low** | S |
| 13 | No CHANGELOG, no release notes, README "Requirements" stale at 3.2 | **medium** | S |
| 14 | README has no failure/troubleshooting/`scoper.custom.php` discovery docs | **medium** | M |

Two findings are worth correcting up front relative to common assumptions:

* **`sources/` and `vendor/` do not ship.** They are git-ignored and untracked. `git ls-files` returns exactly 14 files. The `.gitattributes` gap is therefore a *minor* hygiene issue, not a payload problem (§7).
* **A dependency's `repositories` and `require-dev` are ignored by Composer.** Verified against the docs — the `wpackagist.org` repository and the WordPress/WooCommerce dev requirements in the published `composer.json` have **zero** effect on consumers (§7).

---

## 1. PHP baseline & modern PHP usage

### 1.1 The supported-version window

Verified at <https://www.php.net/supported-versions.php> (fetched 2026-07-27):

| Branch | Active support until | Security support until | Status on 2026-07-27 |
|--------|---------------------|------------------------|----------------------|
| 8.2 | 2024-12-31 | **2026-12-31** | security-only, EOL in ~5 months |
| 8.3 | 2025-12-31 | 2027-12-31 | security-only |
| 8.4 | 2026-12-31 | 2028-12-31 | **active** |
| 8.5 | 2027-12-31 | 2029-12-31 | **active** |

Intersected with `wpify/php-scoper` (`vendor/wpify/php-scoper/composer.json` → `"php": "^8.2"`):

> **Supported window = PHP 8.2 – 8.5, with 8.2 dropping out on 2026-12-31.**

### 1.2 CRITICAL — `composer.json:31` declares an unsatisfiable constraint

`composer.json:31` says `"php": "^8.1"` but `wpify/php-scoper` requires `^8.2`.

On PHP 8.1 a consumer gets a resolver error naming a *transitive* package rather than a clear "wpify/scoper needs PHP 8.2" message. The declared constraint is a lie that only surfaces as a confusing failure.

* **Fix:** `"php": "^8.2"` today; plan `"php": ">=8.3"` for the next major after 2026-12-31.
* **Benefit:** honest resolution, correct error message, and it unlocks every 8.2 language feature below.
* **Downside:** consumers still on 8.1 can no longer `composer require` — but they cannot install today either, so nothing real is lost.
* **Severity: critical. Effort: S.**

### 1.3 What changes under an 8.2+ baseline

`src/Plugin.php` is written in a PHP 5.x dialect. Concretely:

| Location | Current | 8.2+ |
|---|---|---|
| top of file | no `declare(strict_types=1)` | add it |
| `Plugin.php:23-24` | `protected $composer; protected $io;` | typed `private readonly` promoted properties |
| `Plugin.php:26-42` | `/** @var string */ private $folder;` ×6 | typed properties, or replaced by a `Configuration` object (§2) |
| `Plugin.php:18-21` | four `const` strings used as a pseudo-enum | `enum ScoperCommand: string` |
| `Plugin.php:159-195` | 5 sequential `if`/`===` chains over `$event->getName()` | one `match` on the enum |
| `Plugin.php:45,56,105,137,296` | `array(...)` | `[...]` |
| `Plugin.php:44,51,98,101,104,110,116` | no return types | `void`, `array`, `string`, `never` |
| `Plugin.php:201,268,278,284,290` | no return types on private methods | `string`, `void` |
| `Plugin.php:215` | `exit;` | throw; the throwing helper gets `: never` |
| `Plugin.php:44,51,98,101` | interface methods unmarked | `#[\Override]` |
| `Plugin.php:223-256` | five near-identical `in_array` blocks | `array_intersect` + loop, or first-class callable pipeline |

#### Before / after — the highest-value three

**(a) The command pseudo-enum → real enum + `match`.** This kills 30 lines of repeated string comparison at `Plugin.php:159-195` and makes the `no-dev` variants reachable (§3.3).

*Before* (`Plugin.php:159-195`, condensed):

```php
$scriptName = $event->getName();
if ( $event->getName() === self::SCOPER_UPDATE_CMD || $event->getName() === self::SCOPER_UPDATE_NO_DEV_CMD ) {
    $scriptName = ScriptEvents::POST_UPDATE_CMD;
}
if ( $event->getName() === self::SCOPER_INSTALL_CMD || $event->getName() === self::SCOPER_INSTALL_NO_DEV_CMD ) {
    $scriptName = ScriptEvents::POST_INSTALL_CMD;
}
// ...
$command = 'install';
if ( $event->getName() === ScriptEvents::POST_UPDATE_CMD || $event->getName() === self::SCOPER_UPDATE_CMD || $event->getName() === self::SCOPER_UPDATE_NO_DEV_CMD ) {
    $command = 'update';
}
$useDevDependencies = true;
if ( $event->getName() === self::SCOPER_UPDATE_NO_DEV_CMD || $event->getName() === self::SCOPER_INSTALL_NO_DEV_CMD ) {
    $useDevDependencies = false;
}
```

*After:*

```php
enum ScoperCommand: string {
    case Install       = 'scoper-install-cmd';
    case InstallNoDev  = 'scoper-install-no-dev-cmd';
    case Update        = 'scoper-update-cmd';
    case UpdateNoDev   = 'scoper-update-no-dev-cmd';
    case PostInstall   = ScriptEvents::POST_INSTALL_CMD;   // 'post-install-cmd'
    case PostUpdate    = ScriptEvents::POST_UPDATE_CMD;    // 'post-update-cmd'

    public function composerCommand(): string {
        return match ( $this ) {
            self::Update, self::UpdateNoDev, self::PostUpdate => 'update',
            default                                           => 'install',
        };
    }

    public function scriptHook(): string {
        return match ( $this ) {
            self::Update, self::UpdateNoDev, self::PostUpdate => ScriptEvents::POST_UPDATE_CMD,
            default                                           => ScriptEvents::POST_INSTALL_CMD,
        };
    }

    public function withDev(): bool {
        return ! in_array( $this, [ self::InstallNoDev, self::UpdateNoDev ], true );
    }
}
```

Call site becomes three lines. Adding a fifth command now fails loudly at every `match` instead of silently falling through to `install`.

* **Benefit:** the three derived values become total functions of one input — trivially unit-testable with a data provider, no filesystem needed. This is the single best testability win in the file.
* **Downside:** `ScoperCommand::from()` throws on an unknown event name; use `tryFrom()` + early return at the boundary.
* **Severity: medium. Effort: S.**

**(b) Constructor promotion + readonly + strict types.**

*Before* (`Plugin.php:16-42`, `51-96`): 20 lines of untyped property declarations, then six `$this->x = $configValues['x']` assignments at the bottom of `activate()`.

*After:*

```php
<?php declare( strict_types=1 );

final class Plugin implements PluginInterface, EventSubscriberInterface {
    private ?Configuration $config = null;   // set in activate(), the only mutable state

    #[\Override]
    public function activate( Composer $composer, IOInterface $io ): void {
        $this->config = Configuration::fromExtra(
            $composer->getPackage()->getExtra()['wpify-scoper'] ?? [],
            getcwd(),
        );
    }
```

with the six scalars living on an immutable value object:

```php
final readonly class Configuration {
    public function __construct(
        public string $prefix,
        public string $folder,
        public string $tempDir,
        public string $composerJson,
        public string $composerLock,
        /** @var list<string> */ public array $globals,
        public bool $autorun,
    ) {}
}
```

* **Benefit:** `readonly` makes it impossible for a future patcher to mutate `$prefix` mid-run; `strict_types` turns "`prefix` was accidentally an int" into an immediate TypeError instead of an odd namespace.
* **Downside:** `Plugin` still needs `?Configuration` nullable because Composer's `PluginInterface` mandates a no-arg constructor — accept the one nullable field, fail fast on it in `execute()`.
* **Severity: medium. Effort: S–M.**

**(c) `exit;` → typed exception with `: never`.** See §3.4.

**(d) Lower-value but free:** `array()` → `[]` (mechanical, do it in the same commit as CS tooling so the diff is one reviewable blob); `#[\Override]` on `activate`/`deactivate`/`uninstall`/`getSubscribedEvents`; `str_contains()` instead of `strpos(...) !== false` in `config/scoper.inc.php:40,44,48,52,57,67,71,75`.

**Named arguments / first-class callables:** genuinely low value here. `$application->run(new ArrayInput([...]), $output)` has two params; `$this->path(...)` is variadic. Do not force them in — that would be change for its own sake.

---

## 2. Architecture / SOLID

`src/Plugin.php` is 306 lines doing seven jobs:

| Lines | Responsibility |
|---|---|
| 51-96 | config parsing + defaulting |
| 110-114 | path normalisation |
| 148-157 | template rendering (`str_replace` on `postinstall.php`) |
| 201-266 | scoper-config assembly + symbol merging |
| 268-282 | vendor-dir detection + `mkdir` |
| 284-288 | JSON writing |
| 290-305 | nested Composer process invocation |

### 2.1 Recommended decomposition (what is actually worth extracting)

Applying **YAGNI > speculative SOLID** and **Rule of Three** — here is the honest split.

#### ✅ EXTRACT — `Configuration` (value object + factory + validation)

```php
final readonly class Configuration {
    public static function fromExtra( array $extra, string $cwd ): self;  // throws ConfigurationException
    // public promoted props: prefix, folder, tempDir, composerJson, composerLock, globals, autorun
}
```

* **Why:** it is pure (array in, object out), it is where the two critical bugs live (§3), and it is 100 % unit-testable with no filesystem. Highest value-per-line in the whole refactor.
* **Effort: S.**

#### ✅ EXTRACT — `ScoperConfigFactory`

```php
final class ScoperConfigFactory {
    public function __construct( private readonly string $symbolsDir, private readonly string $packageRoot ) {}
    /** @return array<string,mixed> */
    public function build( Configuration $config, string $source, string $destination ): array;
}
```

Replaces `Plugin.php:201-266`. Note it should return the **array**, and a separate one-line caller writes it — that keeps the merge logic pure and testable while the write stays trivial.

* **Why:** the `array_merge_recursive` symbol merging (`Plugin.php:223-256`) is real logic with a real bug class (order-dependence, duplicate accumulation), and it is exactly what a golden-file test should pin.
* **Effort: S–M.**

#### ✅ EXTRACT — `ComposerRunner`

```php
interface ComposerRunner { public function run( string $workingDir, string $command, bool $withDev ): int; }
final class ApplicationComposerRunner implements ComposerRunner { /* current Plugin::runInstall body */ }
```

* **Why:** this is the *one* place where an interface is justified today, because there is a concrete second implementation needed **now** — the test double. `new Application()` inline (`Plugin.php:292`) makes `execute()` permanently untestable (§4.3). One real caller + one real test caller = not speculative.
* **Effort: S.**

#### ⚠️ MAYBE — `SymbolsRegistry`

Only if the `if ( in_array( 'x', $globals ) ) { merge symbols/x.php }` block (`Plugin.php:223-256`) grows past its current five entries, or if the "unknown global name" validation (§3.2) needs a canonical list. A `SymbolsRegistry` that just does `glob(symbols/*.php)` and maps basename → path is ~15 lines and removes the hardcoded five-way repetition. Worth it as part of the `ScoperConfigFactory` extraction, **not** as its own class with an interface.

* **Verdict:** fold into `ScoperConfigFactory` as a private method. Rule of Three says five repetitions justify the loop; it does not justify a new type.

#### ❌ YAGNI — `TempWorkspace`

The temp-dir lifecycle is currently: create three dirs (`Plugin.php:208-210`), and delete them from `scripts/postinstall.php:67`. A `TempWorkspace` class would be a 20-line wrapper over `mkdir`. It only earns its keep if you also fix the real problem — that cleanup lives in a *generated child-process script*, so a failure anywhere leaves `tmp-XXXXXXXXXX/` orphaned in the project root. If you do fix that (a `try/finally` in `execute()`), then `TempWorkspace` with `create()`/`path()`/`destroy()` becomes justified. Otherwise skip.

* **Verdict:** extract **only together with** the cleanup fix. Do not add the class alone.

#### ❌ YAGNI — `PostInstallProcessor`

The template rendering at `Plugin.php:148-157` is 10 lines of `str_replace`. Wrapping it in a class buys nothing today. What *is* worth doing is replacing the 7 chained `str_replace` calls with a single `strtr()` and a `%%key%%` map — 3 lines, same behaviour, and it makes "did every placeholder get substituted?" checkable:

```php
$replacements = [
    '%%source%%'        => $source,
    '%%destination%%'   => $destination,
    '%%cwd%%'           => $cwd,
    '%%composer_lock%%' => $config->composerLock,
    '%%deps%%'          => $config->folder,
    '%%temp%%'          => $config->tempDir,
    '%%prefix%%'        => $config->prefix,
];
$rendered = strtr( $template, $replacements );
if ( str_contains( $rendered, '%%' ) ) { throw new \LogicException( 'Unsubstituted placeholder in postinstall template' ); }
```

* **Verdict:** inline improvement, no new class. **Effort: S.**

#### ❌ YAGNI — a `Filesystem` abstraction

`createFolder`/`createJson`/`path` are three trivial helpers. Composer already ships `Composer\Util\Filesystem` — use that instead of writing your own if you want the abstraction (`normalizePath`, `ensureDirectoryExists`, `removeDirectory` all exist and are better than the hand-rolled versions, including `scripts/postinstall.php`'s recursive `remove()`).

* **Verdict:** delete `createFolder`/`path` in favour of `Composer\Util\Filesystem`. Composition over inheritance, zero new types, and it fixes the `//`-collapsing hack at `Plugin.php:113` which only collapses *doubled* separators, not `a/b/../c`.

### 2.2 Resulting shape

```
Plugin              (~70 lines)  — Composer wiring only: activate() → Configuration, execute() → orchestration
Configuration       (~60 lines)  — validated readonly VO + fromExtra() factory
ScoperCommand       (~30 lines)  — enum + 3 match-based accessors
ScoperConfigFactory (~70 lines)  — symbol merging + scoper config assembly
ComposerRunner      (~25 lines)  — interface + Application-backed impl
```

Roughly the same total LOC, but with three fully-unit-testable pure units and one seam. **Do not** go further than this — the remaining `Plugin::execute()` orchestration is genuinely procedural and splitting it more would trade readability for a class count.

* **Severity: medium. Effort: M** (a focused 1–2 day refactor, best done *after* characterisation tests exist — see §4.5).

---

## 3. Config validation

### 3.1 CRITICAL — missing `prefix` silently does nothing

`Plugin.php:127`: `if ( ! empty( $this->prefix ) ) { …everything… }`.

If `extra.wpify-scoper.prefix` is absent, misspelled (`prefixe`), empty, or `"0"` (!), `execute()` returns having done *nothing at all*. `composer install` exits `0`. The user sees no `deps/` folder and no explanation. This is the worst DX bug in the project — the failure mode is total silence.

Note `! empty()` also rejects the literal string `"0"`, which is a (pathological but legal) namespace segment.

* **Fix:** fail fast in `Configuration::fromExtra()`.
* **Severity: critical. Effort: S.**

### 3.2 HIGH — no validation of anything

`Plugin.php:65-88` reads six keys with `! empty()` and:

* never validates that `prefix` is a legal PHP namespace — `"My-Namespace"` or `"123Foo"` or `"Foo\\Bar"` all pass through into `scoper.config.php` and produce broken generated PHP far downstream;
* silently ignores unknown keys — a typo in `composerjson` → `composer-json` is undetectable;
* silently ignores wrong types — `globals: "wordpress"` (string, not array) is dropped by the `is_array()` guard at `:82` with no warning, and the user gets the defaults;
* `autorun` is read in a completely different place (`Plugin.php:120`) directly off `$extra`, using `=== false` so `"false"` (string, a common JSON mistake) does not disable it;
* never validates that a name in `globals` corresponds to a `symbols/*.php` file — `globals: ["wordpres"]` silently produces an unscoped-symbol-free build that breaks at runtime in WordPress.

### 3.3 MEDIUM — dead `no-dev` feature

`SCOPER_INSTALL_NO_DEV_CMD` / `SCOPER_UPDATE_NO_DEV_CMD` (`Plugin.php:19,21`) are checked at `:160,163,186,193` but **nothing ever emits them**. `bin/wpify-scoper` maps only `install` and `update` (`bin/wpify-scoper:14-20`), and Composer never fires those event names. The `--no-dev` path added in commit `2c1360b` is unreachable.

* **Fix:** add `--no-dev` flag parsing to `bin/wpify-scoper` (one `in_array( '--no-dev', $argv, true )` check), or delete the constants. Currently the README does not mention the feature either, so nobody has noticed.
* **Severity: medium. Effort: S.**

### 3.4 HIGH — `exit;` with no message

`Plugin.php:212-216`:

```php
$config = require_once $config_path;
if ( ! is_array( $config ) ) { exit; }
```

Two problems. First, `exit` inside a Composer plugin terminates the *host* Composer process with status 0 and no output whatsoever — indistinguishable from success. Second, `require_once` returns `true` (not the array) on the second call in the same process, so if `createScoperConfig()` is ever invoked twice in one Composer run — which the `post-install` + `post-update` double-subscription in `getSubscribedEvents()` makes possible — the second call takes the `exit` branch. Use `require`, not `require_once`.

* **Fix:** `require` + `throw new \RuntimeException(...)`.
* **Severity: high. Effort: S.**

### 3.5 HIGH — empty `globals` fatals in the patcher

If `globals` is `[]` (a legitimate config — "scope everything"), none of the `array_merge_recursive` branches at `Plugin.php:223-256` runs, so `exclude-classes` and `exclude-namespaces` are never set on `$config`. `config/scoper.inc.php:79` then does `usort( $config['exclude-classes'], … )` → *Undefined array key* warning followed by `usort(): Argument #1 must be of type array, null given` **inside a php-scoper patcher**, i.e. mid-scope, with a stack trace nobody can act on.

* **Fix:** seed `$config['exclude-classes'] = []; $config['exclude-namespaces'] = [];` before the merges (one line in `ScoperConfigFactory::build()`), and defensively `?? []` at `scoper.inc.php:79,95`.
* **Severity: high. Effort: S.**

### 3.6 Proposed fail-fast `Configuration`

```php
<?php declare( strict_types=1 );

namespace Wpify\Scoper;

final readonly class Configuration {
    /** Matches a PHP namespace: one or more segments, no leading/trailing separator. */
    private const PREFIX_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    private const KNOWN_KEYS = [ 'prefix', 'folder', 'temp', 'globals', 'composerjson', 'composerlock', 'autorun' ];

    public function __construct(
        public string $prefix,
        public string $folder,
        public string $tempDir,
        public string $composerJson,
        public string $composerLock,
        /** @var list<string> */ public array $globals,
        public bool $autorun,
    ) {}

    /**
     * @param array<string,mixed> $extra  Contents of extra.wpify-scoper
     * @param list<string>        $availableGlobals  basenames of symbols/*.php
     * @throws ConfigurationException
     */
    public static function fromExtra( array $extra, string $cwd, array $availableGlobals ): self {
        if ( $unknown = array_diff( array_keys( $extra ), self::KNOWN_KEYS ) ) {
            throw ConfigurationException::unknownKeys( $unknown, self::KNOWN_KEYS );
        }

        $prefix = $extra['prefix'] ?? null;
        if ( ! is_string( $prefix ) || $prefix === '' ) {
            throw ConfigurationException::missingPrefix();
        }
        if ( ! preg_match( self::PREFIX_PATTERN, $prefix ) ) {
            throw ConfigurationException::invalidPrefix( $prefix );
        }

        $globals = $extra['globals'] ?? [ 'wordpress', 'woocommerce', 'action-scheduler', 'wp-cli' ];
        if ( ! is_array( $globals ) ) {
            throw ConfigurationException::wrongType( 'globals', 'array', get_debug_type( $globals ) );
        }
        if ( $bad = array_diff( $globals, $availableGlobals ) ) {
            throw ConfigurationException::unknownGlobals( $bad, $availableGlobals );
        }

        $composerJson = $extra['composerjson'] ?? 'composer-deps.json';
        $composerLock = $extra['composerlock'] ?? preg_replace( '/\.json$/', '.lock', $composerJson );

        return new self(
            prefix:       $prefix,
            folder:       self::resolve( $cwd, $extra['folder'] ?? 'deps' ),
            tempDir:      self::resolve( $cwd, $extra['temp'] ?? 'tmp-' . bin2hex( random_bytes( 5 ) ) ),
            composerJson: $composerJson,
            composerLock: $composerLock,
            globals:      array_values( $globals ),
            autorun:      self::bool( $extra['autorun'] ?? true, 'autorun' ),
        );
    }
}
```

The `PREFIX_PATTERN` deliberately allows `\`-separated multi-segment prefixes (php-scoper supports them) but rejects a leading `\`, trailing `\`, hyphens, and leading digits.

**Diagnostic messages** — the payload of this whole section. Composer surfaces the exception message; make it actionable:

```
[wpify/scoper] Missing required configuration: extra.wpify-scoper.prefix

  The scoper needs a namespace to move your dependencies into. Add to composer.json:

      "extra": {
          "wpify-scoper": {
              "prefix": "MyPlugin\\Deps"
          }
      }

  See https://github.com/wpify/scoper#usage
```

```
[wpify/scoper] Invalid namespace in extra.wpify-scoper.prefix: "My-Plugin Deps"

  A prefix must be a valid PHP namespace: letters, digits and underscores,
  segments separated by "\\", not starting with a digit.

  Did you mean "My_Plugin\\Deps"?
```

```
[wpify/scoper] Unknown key in extra.wpify-scoper: "composer-json"

  Did you mean "composerjson"?
  Valid keys: prefix, folder, temp, globals, composerjson, composerlock, autorun
```

The "did you mean" is `levenshtein()`-based, ~6 lines, and is the difference between a five-minute and a two-hour debugging session.

* **Severity: critical (covers 3.1) / high. Effort: M** (~150 lines + tests).

### 3.7 Is a JSON schema for `extra.wpify-scoper` worth it?

**Partially yes, but not as the validation mechanism.**

* Composer does **not** validate `extra.*` against third-party schemas. `composer validate` checks Composer's own schema, and `extra` is `"type": "object"` with no constraints. So a JSON schema buys you **nothing at install time** — `Configuration::fromExtra()` remains the only real gate. Do not build the schema *instead of* the PHP validation.
* Where it *does* pay off is **editor autocomplete**. PhpStorm and VS Code both resolve `composer.json` against SchemaStore; a published schema fragment gives users inline completion and hover docs for `extra.wpify-scoper` while typing. That is a genuine DX win for a config whose keys are currently discoverable only by reading the README.
* **Recommendation:** ship `resources/wpify-scoper.schema.json` and submit it to SchemaStore *after* the PHP validation exists, and generate the "valid keys" list in error messages from the same source so they cannot drift.
* **Severity: low. Effort: S.**

---

## 4. Testing strategy

### 4.1 Current state

Zero. No `tests/`, no `phpunit.xml`, no dev dependency on any test framework. `composer.json:36-44`'s `require-dev` is entirely WordPress/WooCommerce *sources* for symbol extraction, not tooling. Every one of the 65 tags was cut on manual verification.

For a tool whose failure mode is "generates subtly broken PHP that fatals inside somebody else's WordPress site," this is the highest-leverage gap in the audit.

### 4.2 Recommended tooling

| Choice | Recommendation | Why |
|---|---|---|
| Framework | **PHPUnit 11.x** (`phpunit/phpunit: ^11.5`) | PHP 8.2+ native, attributes-based, `#[DataProvider]`. Pest would add a dependency layer for a 300-line codebase with no BDD audience. PHPUnit is what every Composer-plugin contributor already knows. |
| Fixture packages | hand-written `tests/fixtures/tiny-package/` | Do **not** pull real packages from Packagist in tests — network flakiness. |
| Golden files | plain `assertStringEqualsFile` + an `UPDATE_SNAPSHOTS=1` env guard | ~20 lines; `spatie/phpunit-snapshot-assertions` is fine too but adds a dep. |
| Process isolation | `symfony/process` (already transitively present via composer/composer) | For the integration tier. |

### 4.3 What is untestable as written, and why

| Location | Blocker | Minimal fix |
|---|---|---|
| `Plugin.php:57,58,66,87,134,135,141,151,177,178,275` — 11 `getcwd()` calls | Global process state. A test would have to `chdir()`, which is process-wide and breaks parallel tests. | Inject `string $cwd` into `Configuration::fromExtra()` and `ScoperConfigFactory`. **This is the single highest-value change** — it unlocks ~60 % of the file. |
| `Plugin.php:215` `exit;` | Kills the PHPUnit process. Cannot be asserted on without `@runInSeparateProcess` + `@preserveGlobalState disabled`, which is slow and fragile. | Throw. |
| `Plugin.php:292` `new Application()` | Hard-coded collaborator; running it would perform a real `composer install` with network access. | `ComposerRunner` interface (§2.1) + constructor injection with a default. |
| `Plugin.php:269` `strpos( dirname( __DIR__ ), 'vendor/wpify/scoper' )` | Depends on where the test runner's own file lives on disk. Untestable at all — the answer changes depending on the checkout path. | Inject the package root and vendor dir (§5.1). |
| `Plugin.php:212` `require_once $config_path` | Static include-once state — second call in the same process returns `true`. Test order becomes significant. | `require`, or better, `ScoperConfigFactory` takes the base config array as a constructor param. |
| `Plugin.php:58` `str_shuffle( md5( microtime() ) )` | Non-deterministic path in every assertion. | Inject the temp dir name (already configurable via `extra.temp` — just make the *default* injectable). |
| `scripts/extract-symbols.php:41` `static $parser` + top-level `extract_symbols()` calls at `:151-155` | The file is a script, not a library: requiring it *runs* the extraction against `sources/`. Global function names (`resolve`, `path`, `get_files`) also collide with anything else. | Wrap in a `SymbolExtractor` class under `src/`, keep `scripts/extract-symbols.php` as a 5-line CLI entry point. |
| `scripts/postinstall.php` | Entire file is top-level statements with `%%placeholder%%` literals — cannot be loaded, only string-substituted and shelled out. | Acceptable as-is; test it at the integration tier (§4.4b) by rendering + executing it against a fixture directory. |

**Minimal refactor to make ~80 % testable: inject `$cwd`, inject `ComposerRunner`, replace `exit` with `throw`.** Three changes, maybe 40 lines of diff. Everything else is optional.

### 4.4 The three test tiers

**(a) Unit — pure logic. ~35 tests, no filesystem, milliseconds.**

* `ConfigurationTest` — defaults; each override key; missing prefix throws; invalid prefix patterns (data provider: `""`, `"0"`, `"My-Ns"`, `"1Foo"`, `"\\Lead"`, `"Trail\\"`, `"A\\B"` ✅, `"Ünïcode"` ✅); unknown key throws with suggestion; `globals` non-array throws; `composerlock` derived from `composerjson`; `autorun` string `"false"` handling.
* `ScoperCommandTest` — data provider over all 6 cases × 3 accessors (`composerCommand`, `scriptHook`, `withDev`). 18 assertions, catches every regression in the §1.3(a) `match` tables.
* `PathTest` — `path()` joining, doubled-separator collapsing, Windows separator. (Or delete `path()` in favour of `Composer\Util\Filesystem` and test nothing.)
* `PostInstallTemplateTest` — render with a known map, assert exact output, assert no residual `%%`.
* `ScoperConfigFactoryTest` — `globals: []` produces `exclude-classes: []` not missing (regression test for §3.5); `globals: ['wordpress','woocommerce']` merges both without duplicates; merge is order-independent.

**(b) Integration — real scoping of a tiny fixture. ~4 tests, ~30 s each.**

```
tests/fixtures/tiny-package/
  composer.json          → requires nothing, or one vendored fixture package
  vendor/acme/lib/src/Greeter.php   → uses get_option() and a WP class
```

Test body: copy the fixture to a temp dir, run `Plugin::execute()` with a real `ComposerRunner` and `--no-network`/pre-vendored fixture, then assert on the output:

* `deps/scoper-autoload.php` exists;
* `deps/acme/lib/src/Greeter.php` contains `namespace Test\Prefix\Acme\Lib;`;
* it still contains a bare `get_option(` — **not** `Test\Prefix\get_option(` (this is the whole product);
* `deps/composer/autoload_static.php` keys carry the lowercased prefix (`scripts/postinstall.php:41-45` regression);
* `scoper-autoload.php` has every `humbug_phpscoper_expose_*` commented out (`postinstall.php:51-52`);
* the `tmp-*` directory is gone afterwards.

Mark these `#[Group('integration')]` and exclude from the default suite so `composer test` stays fast.

**(c) Golden-file — symbol extraction. 4 tests, fast.**

Check in `tests/fixtures/symbols-input/` containing ~6 hand-written PHP files that exercise every branch of `resolve()` (`scripts/extract-symbols.php:51-78`): namespaced file, class, trait, interface, function, `if ( ! class_exists() )`-wrapped class, and — critically — a `define()` **inside a function body** (the exact case commit `a59d577` just fixed). Assert the extractor output byte-matches `tests/fixtures/symbols-expected/output.php`.

This tier is cheap and directly protects the last two bug-fix commits (`a59d577`, `af1b752`) from regressing.

### 4.5 Sequencing and effort

1. **Characterisation first.** Before touching `Plugin.php`, write tier (b) against the *current* code — even if it needs `chdir()` and `@runInSeparateProcess`. It is ugly, but it is the safety net for the §2 refactor. **Effort: M (1 day).**
2. Refactor per §2.1 + §4.3 minimal fixes. **Effort: M (1–2 days).**
3. Tier (a) unit tests. **Effort: M (1 day, ~400 lines).**
4. Tier (c) golden files. **Effort: S (2 hours).**
5. Clean up tier (b) now that injection exists. **Effort: S.**

**Total: ~4–5 focused days to go from 0 % to meaningful coverage.** Realistic target: 85 %+ line coverage on `src/`, with the process-invocation seam mocked.

* **Severity: high. Effort: L.**

---

## 5. Static analysis & code style

### 5.1 PHPStan

Not installed (`vendor/bin` contains no analyser). I could not run it without modifying `composer.json`, which is outside this audit's write scope — so the following is derived from reading the code, and should be confirmed by an actual run.

**Recommended starting point: level 5, with a baseline, then ratchet.**

Rationale: level 0–4 on untyped PHP 5-style code will report almost nothing useful (no types to check). Level 5 turns on argument-type checking, which is where the real bugs are. Level 6 (`missingType.iterableValue`) would drown you in ~30 "no value type specified in iterable type array" errors on day one — worth reaching, not worth starting at. Level 8 (null-safety) is the eventual target and is realistic *after* the §2 refactor, because `readonly` typed properties give PHPStan everything it needs.

**What level 5 would flag today (predicted, high confidence):**

| Location | Predicted error |
|---|---|
| `Plugin.php:23,24` | `Property Plugin::$composer has no type specified.` (level 6 for the `@var`-only ones too) |
| `Plugin.php:110` | `Method Plugin::path() has parameter $parts with no value type specified in iterable type array.` |
| `Plugin.php:110,116,44,51,98,101,104` | `Method … has no return type specified.` |
| `Plugin.php:135` | `Parameter #1 $json of function json_decode expects string, string\|false given.` — `file_get_contents()` can return `false`. **Real bug**, not noise. |
| `Plugin.php:148` | Same: `file_get_contents()` → `string\|false` passed to `str_replace`. |
| `Plugin.php:144,145` | `Cannot access property $scripts on stdClass\|array\|bool\|float\|int\|string\|null` — `json_decode(..., false)` returns `mixed`. **Real bug**: a malformed `composer-deps.json` produces `null`, then `$composerJson->scripts` on null. |
| `Plugin.php:167` | `realpath()` returns `string\|false`; concatenated into a command string at `:170` unchecked. **Real bug** — if `vendor/wpify/php-scoper/bin/php-scoper.phar` is missing, the generated script becomes ` add-prefix --output-dir=…` and fails cryptically. |
| `Plugin.php:212-218` | `$config` is `mixed` from `require`; `$config['prefix'] = …` on mixed. |
| `Plugin.php:223,230,237,244,251` | `in_array()` called without `$strict` — level 5 `argument.type` won't catch it but `phpstan-strict-rules` will. Worth adding. |
| `Plugin.php:269-271` | `strpos()` returns `int\|false`; the code checks `is_int()` which PHPStan understands — this one is fine, just unusual. |
| `Plugin.php:280` | `mkdir()` return value ignored — a permission failure is silent. |
| `scripts/extract-symbols.php:53` | `$node->name` on `Namespace_` is `?Name` — `->getParts()` on possibly-null. **Real bug** for a file with `namespace { }` (unbraced global namespace block). |
| `scripts/extract-symbols.php:113` | `parse()` returns `?array` — `foreach ( $ast as … )` on possibly-null; `$traverser->traverse( $ast )` requires non-null. **Real bug** on an unparseable file that returns null rather than throwing. |
| `scripts/postinstall.php:40,50` | `file_get_contents()` → `string\|false` into `preg_replace`. |
| `config/scoper.inc.php:79,95` | `$config['exclude-classes']` — offset may not exist (§3.5). **Real bug.** |

That is **six genuine bugs** predicted from static analysis alone, all of which are silent-corruption or cryptic-failure classes. Strong argument for adopting it.

**Config to start with:**

```neon
# phpstan.neon
parameters:
    level: 5
    paths:
        - src
        - scripts
        - config
    excludePaths:
        - symbols/*      # 300KB of generated var_export arrays — nothing to analyse
    treatPhpDocTypesAsCertain: false
```

Add `phpstan/phpstan: ^2.1` and `phpstan/extension-installer` to `require-dev`. **Do not** generate a baseline on the first run — with only ~25 errors it is cheaper to fix them all than to carry a baseline. Reach level 6 in the same PR as the typed-property migration (§1.3b), level 8 after.

* **Severity: medium (the tooling) / high (the six bugs it finds). Effort: M.**

**Psalm:** skip. One analyser is enough for 300 lines, and PHPStan's Composer-plugin ecosystem is better.

### 5.2 Code style — PSR-12 vs. WPCS

The codebase uses WordPress Coding Standards spacing (`function foo( $bar ) {`, tabs, Yoda-ish comparisons) — see `Plugin.php` throughout, `config/scoper.inc.php`, `scripts/*.php`. That is unusual for a Composer plugin, whose contributors and reviewers come from the PSR world, and whose entire dependency surface (`composer/composer`, `symfony/console`) is PSR-12.

**Honest assessment of the trade-off:**

| | Keep WPCS | Migrate to PSR-12 |
|---|---|---|
| Churn | zero | ~100 % of every PHP line reformatted; `git blame` on `src/Plugin.php` becomes useless without `--ignore-rev` |
| Contributor familiarity | matches the WordPress audience the tool serves | matches the Composer-plugin audience actually reading `Plugin.php` |
| Tooling | `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs` (heavier, WPCS 3.x needs `phpcsutils`) | `friendsofphp/php-cs-fixer` — single dep, `@PSR12` + `@PHP82Migration` rulesets, and the `@PHP82Migration` set does the `array()` → `[]` conversion for free |
| Consistency with the product | the *scoped output* is other people's code — style is irrelevant there | — |

**Recommendation: migrate to PSR-12, but do it as one isolated commit with no logic changes**, and add the SHA to `.git-blame-ignore-revs`. The deciding factor is that `@PHP82Migration` in PHP-CS-Fixer mechanically performs several of the §1.3(d) modernisations, so the "churn" commit and the "modernise" commit are the same commit — you pay the blame cost once and get the array-syntax migration free.

If the maintainer feels strongly about WPCS, **the acceptable alternative is to keep WPCS and just add a `.editorconfig` + `phpcs.xml.dist`** — an enforced inconsistent style beats an unenforced consistent one. What is *not* acceptable is the status quo of no config at all, where the style is a convention held only in the maintainer's head.

```ini
# .editorconfig  (do this regardless of the PSR-12 decision)
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = tab
indent_size = 4

[*.{json,yml,yaml,neon}]
indent_style = space
indent_size = 2

[symbols/*.php]
# generated by scripts/extract-symbols.php — do not reformat
trim_trailing_whitespace = false
```

Note the `symbols/*.php` carve-out: those files are `var_export()` output and **must** be excluded from any fixer, or every regeneration produces a 300 KB diff fight between the generator and the formatter.

* **Severity: medium. Effort: M** (S for `.editorconfig` alone).

---

## 6. CI/CD, releasing and versioning

### 6.1 Current state

No `.github/workflows/`, no `.gitlab-ci.yml`. The README documents CI **for consumers** (§Deployment) but the project itself has none. Nothing runs on push. `composer validate` has never been enforced — and it would currently warn, because `composer.lock` is absent from the repo while `require-dev` is populated.

### 6.2 Proposed `.github/workflows/ci.yml`

```yaml
name: CI
on:
  push: { branches: [ master ] }
  pull_request:

jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', coverage: none }
      - run: composer validate --strict
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/phpstan analyse --no-progress
      - run: vendor/bin/php-cs-fixer check --diff

  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: [ '8.2', '8.3', '8.4', '8.5' ]
        composer: [ 'lowest', 'highest' ]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '${{ matrix.php }}', coverage: none }
      - uses: ramsey/composer-install@v3
        with: { dependency-versions: '${{ matrix.composer }}' }
      - run: vendor/bin/phpunit --testsuite unit
      - run: vendor/bin/phpunit --testsuite integration
```

Matrix rationale: **8.2 / 8.3 / 8.4 / 8.5** — exactly the php-scoper-supported ∩ php.net-supported window from §1.1. Drop `8.2` from the matrix on 2027-01-01 and bump the constraint in the same PR. The `lowest`/`highest` axis matters here because `composer/composer: ^2.6` spans a wide API surface and the plugin touches `Composer\Console\Application` directly.

Add a `composer-plugin` smoke job that actually installs the plugin into a scratch project — this is the only way to catch "the plugin errors during `activate()`" class of bug, which no unit test reaches:

```yaml
  smoke:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - name: Install into a scratch project
        run: |
          mkdir -p /tmp/scratch && cd /tmp/scratch
          composer init --no-interaction --name=test/scratch
          composer config repositories.local path "$GITHUB_WORKSPACE"
          composer config --no-plugins allow-plugins.wpify/scoper true
          composer config extra.wpify-scoper.prefix 'Test\Deps'
          echo '{"require":{"psr/log":"^3.0"}}' > composer-deps.json
          composer require wpify/scoper:@dev --no-interaction
          test -f deps/scoper-autoload.php
          grep -q 'namespace Test\\\\Deps' deps/psr/log/src/LoggerInterface.php
```

### 6.3 Scheduled symbol regeneration

The value proposition of this package is "an up-to-date database of WordPress and WooCommerce symbols" (README:11). Today that database is updated whenever a human remembers — the git log shows ad-hoc "add new symbols" / "Update symbols" commits (`b00d523`, `4abe3a6`). A WordPress release that adds a function ships broken scoping for every user until someone notices.

```yaml
name: Refresh symbols
on:
  schedule: [ { cron: '0 4 * * 1' } ]   # Mondays 04:00 UTC
  workflow_dispatch:

jobs:
  refresh:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4' }
      - run: composer update --no-interaction   # pulls latest WP / WC / AS / WP-CLI
      - run: composer run extract
      - name: Report symbol delta
        run: |
          git diff --stat symbols/
          php -r '$o=require "symbols/wordpress.php"; foreach($o as $k=>$v) printf("%s: %d\n",$k,count($v));'
      - uses: peter-evans/create-pull-request@v7
        with:
          branch: chore/refresh-symbols
          title: 'chore: refresh WordPress/WooCommerce symbol lists'
          commit-message: 'chore: refresh symbol lists'
          labels: symbols
```

Two guards worth adding to that job:

* **fail if the symbol count *drops* by more than ~1 %** — a parse failure in `extract-symbols.php` currently just `echo`es (`extract-symbols.php:131`) and continues, silently producing a truncated list. A truncated WordPress list means WP functions get scoped, which breaks consumers at runtime in the worst possible way. This is the single most valuable CI guard in the whole proposal.
* open a PR rather than pushing to `master`, so a human eyeballs the diff.

* **Severity: high. Effort: M.**

### 6.4 Versioning & release practice

* **65 tags**, well-formed SemVer, no `v` prefix, consistently applied. Good.
* **No CHANGELOG.md.** For a tool whose upgrades can silently change generated output, this is a real gap — a consumer upgrading `3.2.15` → `3.2.21` has to read 6 commit messages, several of which are `add new symbols` / `Upgrade`.
  * **Fix:** adopt Keep-a-Changelog, and enable GitHub's auto-generated release notes as a stopgap (zero effort, immediately better than nothing).
  * **Severity: medium. Effort: S.**
* **Commit message hygiene is inconsistent** — `fix: extract constants defined inside function bodies` (Conventional) sits next to `Upgrade`, `Fix twig`, `postinstall update`. Adopting Conventional Commits would let `release-please` or `git-cliff` generate the changelog automatically, which is the only way a changelog survives long-term on a one-maintainer project.
  * **Severity: low. Effort: S.**
* **The `3.2.x` line has absorbed 21 patch releases**, several of which changed generated output (`af1b752 fix exposed classes and function`, `a59d577 fix: extract constants defined inside function bodies`). Changing what bytes land in a consumer's `deps/` is arguably a minor, not a patch. Not worth re-litigating history, but worth a stated policy going forward.
* **README "Requirements" (README:13-18) is stale** — it stops at `3.2` and says "PHP >= 8.1", which is both out of date (§1.2) and unhelpful now that `3.2` has 21 patch releases. Replace the per-version table with a single line: *"Requires PHP 8.2+. See CHANGELOG.md for per-release notes."* The README's own example also pins `"platform": {"php": "8.0.30"}` (README:39) — contradicting its own requirements section two screens earlier.
  * **Severity: medium. Effort: S.**

---

## 7. Packaging hygiene

### 7.1 What actually ships — correcting the premise

`git ls-files` returns **14 files**. `sources/` (163 MB) and `vendor/` are git-ignored (`.gitignore:3-4`) and therefore are not in the repository at all, let alone in the dist archive. The tarball is small today.

Of the 14 tracked files, almost everything is **required at runtime**:

| Path | Ships? | Needed at runtime? |
|---|---|---|
| `src/Plugin.php` | yes | yes |
| `bin/wpify-scoper` | yes | yes (`composer.json:17-19`) |
| `config/scoper.inc.php`, `config/scoper.config.php` | yes | yes — copied to temp (`Plugin.php:262`) |
| `symbols/*.php` (308 KB) | yes | **yes** — `require`d at `Plugin.php:226,233,240,247,254` |
| `scripts/postinstall.php` | yes | **yes** — read at `Plugin.php:148` |
| `scripts/extract-symbols.php` (4.9 KB) | yes | **no** — dev-only |
| `README.md` | yes | no |
| `docs/` (this file) | will ship once committed | no |

So the `.gitattributes` gap is worth roughly **10 KB**, not the megabytes one might assume. It is still worth adding — mostly so that `docs/` does not grow into the payload over time, and to signal intent:

```gitattributes
# .gitattributes
/.github            export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/.editorconfig      export-ignore
/docs               export-ignore
/tests              export-ignore
/phpunit.xml.dist   export-ignore
/phpstan.neon       export-ignore
/.php-cs-fixer.dist.php export-ignore
/scripts/extract-symbols.php export-ignore

# never let a formatter or diff tool touch generated symbol tables
/symbols/*.php      -diff linguist-generated=true
```

Note `scripts/` as a whole must **not** be export-ignored — `postinstall.php` lives there and is loaded at runtime. Only the extractor is excludable. Alternatively move `postinstall.php` to `resources/` and export-ignore all of `scripts/`, which is cleaner but a breaking internal path change.

The `linguist-generated` marker also collapses `symbols/` in GitHub PR diffs, which makes the §6.3 symbol-refresh PRs actually reviewable.

* **Severity: low. Effort: S.**

### 7.2 `composer.lock` in `.gitignore` — correct, with a caveat

`.gitignore:5` ignores `/composer.lock`. For a **library**, that is the conventional and correct choice: consumers resolve against their own constraints, and a committed lock would be dead weight.

The caveat specific to *this* project: `require-dev` (`composer.json:36-43`) is not test tooling, it is the **input data** for symbol extraction (`johnpbloch/wordpress: *`, `wpackagist-plugin/woocommerce: *`, all unconstrained `*`). Without a lock, `composer run extract` produces a different result on every machine and every day, and there is no record of *which* WordPress version a given `symbols/wordpress.php` was generated from.

* **Recommendation:** keep `composer.lock` ignored (correct for a library), but **record the provenance** — have `scripts/extract-symbols.php` write a header comment into each generated file:
  ```php
  <?php
  // Generated by wpify/scoper on 2026-07-27 from johnpbloch/wordpress 6.9.1
  return array( ... );
  ```
  That is ~10 lines using `Composer\InstalledVersions::getPrettyVersion()`, and it makes "which WP version does this support?" answerable, which it currently is not.
* **Severity: low. Effort: S.**

### 7.3 Do the published `repositories` and `require-dev` affect consumers? **No.**

Verified against the Composer documentation (getcomposer.org/doc/05-repositories.md), which states verbatim:

> "Repositories are only available to the root package and the repositories defined in your dependencies will not be loaded."

And per the schema docs, `require-dev`, `repositories`, `config`, `minimum-stability` and `scripts` are all root-only: they "are ignored when the package is installed as a dependency of another project."

Concrete consequences for `wpify/scoper` as a dependency:

| Key in the published `composer.json` | Effect on a consumer |
|---|---|
| `repositories: [ wpackagist.org ]` (`:24-29`) | **none** — not loaded |
| `require-dev: { johnpbloch/wordpress, wpackagist-plugin/woocommerce, … }` (`:36-44`) | **none** — never installed |
| `minimum-stability: stable` (`:23`) | **none** |
| `config.allow-plugins` (`:60-65`) | **none** — the consumer must set `allow-plugins.wpify/scoper` themselves, which the README correctly documents (README:100, 132) |
| `scripts.extract` (`:20-22`) | **none** — dependency scripts are not run |
| `extra.wordpress-install-dir`, `extra.installer-paths`, `extra.textdomain` (`:47-58`) | **none** for resolution; `extra` *is* readable by other plugins, but `composer/installers` and `johnpbloch/wordpress-core-installer` only act on the root package's `extra` |
| `extra.class` (`:46`) | **this one does apply** — it is how Composer finds the plugin entry point. Correct and required. |
| `require: { php, composer-plugin-api, composer/composer, wpify/php-scoper }` (`:30-35`) | **applies** — this is the only section that constrains consumers, hence §1.2 |

So the only real issue in this whole section is the `^8.1` lie. The dev-requirement noise is harmless — but it *is* confusing to read, and a one-line comment or a note in CONTRIBUTING explaining "these are symbol-extraction sources, not test tooling" would save the next contributor a puzzled ten minutes.

One genuine oddity worth flagging: **`extra.textdomain: { "wpify-custom-fields": "some-new-textdomain" }` (`composer.json:56-58`) appears to be leftover debris** — nothing in this repository reads it, and `wpify-custom-fields` is a different package. It is inert, but it ships to every consumer and looks like a copy-paste accident.

* **Severity: low. Effort: S** (delete it).

---

## 8. Documentation

### 8.1 Gaps in the current README

| Gap | Impact |
|---|---|
| **No failure documentation.** What does the user see when scoping fails? Today: often nothing at all (§3.1), or a `usort()` TypeError from inside a php-scoper patcher (§3.5), or a silent `exit` (§3.4). The README never sets expectations. | high |
| **No troubleshooting section.** The most common real-world problems — a dependency that needs a custom patcher, a WP function getting scoped anyway, `allow-plugins` not set, the `tmp-*` folder left behind — are undocumented. | high |
| **`scoper.custom.php` discovery is undocumented and subtly broken.** README:150 says "in root of your project", but `Plugin::createPath( [ 'scoper.custom.php' ], true )` (`Plugin.php:204,268-276`) resolves to the project root **only if `dirname(__DIR__)` contains the literal substring `vendor/wpify/scoper`**. It therefore silently falls back to the *package's own* directory when: the consumer has renamed `vendor-dir` in `config` (fully supported by Composer); the plugin is installed globally *and* invoked in a project (the global path is `~/.composer/vendor/wpify/scoper`, so this case happens to work); or the plugin is symlinked in via a `path` repository during development. In every failing case the user's `scoper.custom.php` is **silently ignored** with no diagnostic. | high |
| **No guidance on committing `deps/` and `composer-deps.lock`.** This is the #1 question for anyone deploying a WordPress plugin. The README's CI examples imply `deps/` is a build artifact (README:95, 142) but never says so, and `composer-deps.lock` is never mentioned at all despite being written to the project root (`postinstall.php:57-58`). | high |
| **No upgrade guide.** With 65 tags and no changelog, upgrading is a leap of faith. | medium |
| **No CONTRIBUTING.** How to regenerate symbols, what `sources/` is for, why `require-dev` contains WordPress. | medium |
| **README:39 pins `"php": "8.0.30"`** in the `config.platform` example while README:18 says "PHP >= 8.1". Directly contradictory. | medium |
| **`--no-dev` is undocumented** because it is unreachable (§3.3). | low |

### 8.2 The `scoper.custom.php` fix

Document *and* fix. The fix is small and removes the string-matching entirely:

```php
// in activate(), where $composer is available:
$this->projectRoot = dirname( Factory::getComposerFile() );
// or, robustly, for the vendor location:
$vendorDir = $composer->getConfig()->get( 'vendor-dir' );
```

Then `createPath( [ 'scoper.custom.php' ], true )` becomes `$this->projectRoot . '/scoper.custom.php'` unconditionally — correct in every installation topology, and testable (§4.3). Additionally, **log when a custom file is found**, via the injected `IOInterface` (which is stored at `Plugin.php:53` and then *never used* — the plugin produces no output whatsoever):

```php
if ( file_exists( $custom_path ) ) {
    $this->io->write( sprintf( '<info>wpify/scoper: applying customizations from %s</info>', $custom_path ) );
    copy( ... );
}
```

That `$this->io` is captured and unused is itself a finding: **the plugin is entirely silent**, which is why every failure mode in this report presents as "nothing happened."

* **Severity: high. Effort: S.**

### 8.3 Proposed documentation structure

```
README.md                     ← keep short: what it does, install, minimal config, link out
docs/
  configuration.md            ← every extra.wpify-scoper key: type, default, example, failure mode
  customization.md            ← scoper.custom.php: discovery rules, function signature, worked examples
                                 (move the Guzzle patcher example here from README:154-170)
  deployment.md               ← GitLab CI + GitHub Actions (move from README:83-144)
                                 + the "commit deps/ or build it?" decision, both branches explained
  troubleshooting.md          ← symptom → cause → fix table (see below)
  upgrading.md                ← per-major migration notes
CHANGELOG.md                  ← Keep a Changelog
CONTRIBUTING.md               ← regenerating symbols, what sources/ is, running tests
```

`docs/troubleshooting.md` should be symptom-first, because that is how users arrive:

| Symptom | Cause | Fix |
|---|---|---|
| `composer install` succeeds but no `deps/` folder | `extra.wpify-scoper.prefix` missing or misspelled | §3.1 — after the fix this becomes a loud error instead |
| `Class "…\WP_Query" not found` at runtime | `globals` does not include `wordpress`, or the symbol list predates your WP version | add to `globals`; update `wpify/scoper` |
| `scoper.custom.php` seems to be ignored | non-standard `vendor-dir`, or path-repository install | §8.2 |
| `tmp-XXXXXXXXXX/` left in the project root | scoping aborted before `postinstall.php` ran | safe to delete; add `tmp-*` to `.gitignore` |
| Plugin never runs at all | `allow-plugins.wpify/scoper` not set | `composer config allow-plugins.wpify/scoper true` |
| A vendored library breaks after scoping | it uses dynamic class names / string-based FQCNs | write a patcher — link to `docs/customization.md` |

Also add `tmp-*` and (optionally) `deps/` to a documented `.gitignore` snippet in the README — currently a user's first `composer install` can litter their repo root with an untracked `tmp-*` directory if anything fails.

* **Severity: medium. Effort: M** (1 day for the full set; S for troubleshooting alone, which is the highest-value single page).

---

## 9. Recommended sequencing

**Ship this week (all S, all independently valuable):**

1. `composer.json:31` → `"php": "^8.2"` — **critical**, one character.
2. Fail-fast on missing/invalid `prefix` (§3.1, §3.6) — **critical**, ~40 lines for the minimal version.
3. `exit;` → `throw` at `Plugin.php:215`; `require_once` → `require` at `:212` (§3.4).
4. Seed `exclude-classes`/`exclude-namespaces` to `[]` (§3.5).
5. Delete or wire up `getCapabilities()` (§0/#5) and the `NO_DEV` constants (§3.3).
6. `.editorconfig` + `.gitattributes` (§5.2, §7.1).
7. Use the captured-but-unused `$this->io` to report what the plugin is doing (§8.2).

**Next (M):**

8. `docs/troubleshooting.md` + fix `scoper.custom.php` discovery (§8.2) + README requirements/platform contradiction (§6.4).
9. PHPStan level 5 and fix the ~25 findings — six of which are real bugs (§5.1).
10. CI: validate + PHPStan + smoke job (§6.2); scheduled symbol refresh with the count-drop guard (§6.3).

**Then (L):**

11. Characterisation integration test → refactor per §2.1 → full unit suite (§4.5).
12. PSR-12 migration as one blame-ignored commit bundled with `@PHP82Migration` (§5.2).
13. CHANGELOG + Conventional Commits + release automation (§6.4).

**Note on verification:** PHPStan findings in §5.1 are predicted from reading the code, not from an actual run — installing an analyser would have required editing `composer.json`, which is outside this audit's write scope. Confirm with a real run before treating the list as exhaustive.
