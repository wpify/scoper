# wpify/scoper — Composer Plugin API audit

**Scope:** `src/Plugin.php`, `bin/wpify-scoper`, `composer.json`, `README.md`
**Baseline:** Composer 2.10.2 (`vendor/composer/composer`), `PluginInterface::PLUGIN_API_VERSION = 2.9.0`, symfony/console v8.1.1
**Date:** 2026-07-27

Every claim below was checked against the Composer source vendored in this repo (paths given), and the
headline finding (F1) was reproduced empirically. Where the audit brief's premise turned out to be wrong,
that is stated explicitly (F6, F11).

## Summary

| # | Finding | Severity | Effort |
|---|---|---|---|
| F1 | `runInstall()` terminates the whole PHP process (`exit()` via Symfony `autoExit`) | **Critical** | M |
| F2 | PHP requirement `^8.1` is unsatisfiable — php-scoper needs `^8.2` | **Critical** | S |
| F3 | `getCapabilities()` is dead code, and is a landmine if `Capable` is ever added | **High** | S |
| F4 | Pseudo-events + hand-rolled bootstrap instead of a real `BaseCommand` | **High** | M |
| F5 | `bin/wpify-scoper` hardcodes `__DIR__ . '/../../..'` as the vendor root | **High** | S |
| F6 | `$this->io` stored but never used; all output goes to a fresh `ConsoleOutput` | **High** | S |
| F7 | php-scoper phar located by hardcoded relative path, invoked without a PHP binary | **Medium** | S |
| F8 | `getcwd()` used as the project root instead of Composer's `Config` | **Medium** | M |
| F9 | Generated `composer-deps.json` embeds absolute host paths into a tracked file | **Medium** | M |
| F10 | Re-entrancy is only avoided by accident; global plugins do reload in the nested run | **Medium** | S |
| F11 | `composer.json` metadata for a `composer-plugin` | **Medium** | S |
| F12 | `exit` inside `createScoperConfig()`; `require_once` used to load a value-returning config | **Low** | S |
| F13 | Temp directory naming/placement/cleanup | **Low** | S |
| F14 | Silent no-op when `extra.wpify-scoper.prefix` is missing | **Low** | S |

---

## F1 — `runInstall()` terminates the whole PHP process — **Critical / M**

### What the code does now

`src/Plugin.php:290-305`:

```php
private function runInstall( string $path, string $command = 'install', bool $useDevDependencies = true ) {
    $output      = new ConsoleOutput();
    $application = new Application();

    return $application->run( new ArrayInput( array( /* ... */ ) ), $output );
}
```

A `Composer\Console\Application` is constructed and run **in-process**, from inside a
`POST_INSTALL_CMD` / `POST_UPDATE_CMD` listener.

### Why it is wrong

`Composer\Console\Application` extends `Symfony\Component\Console\Application`, which defaults to
`autoExit = true` (`vendor/symfony/console/Application.php:84`). At the end of `run()`
(`vendor/symfony/console/Application.php:266-271`):

```php
if ($this->autoExit) {
    if ($exitCode > 255) { $exitCode = 255; }
    exit($exitCode);
}
return $exitCode;
```

**`run()` never returns.** Reproduced:

```
$ php -r 'require "vendor/autoload.php";
  $app = new Composer\Console\Application();
  $code = $app->run(new ArrayInput(["command"=>"about"]), new ConsoleOutput());
  echo "RETURNED-TO-CALLER code=$code\n";'
Composer - Dependency Manager for PHP - version 2.10.2
Composer is a dependency manager tracking local dependencies of your projects and libraries.
See https://getcomposer.org/ for more information.
```

`RETURNED-TO-CALLER` is never printed. The `return` on `src/Plugin.php:294` is unreachable.

Concrete consequences — the outer `composer install`/`update` is killed mid-flight at
`vendor/composer/composer/src/Composer/Installer.php:438-441`:

1. **The security audit never runs.** `Installer.php:448-470` performs the vulnerability audit
   *after* dispatching `POST_INSTALL_CMD`. With this plugin active and a prefix configured,
   `composer install`/`composer update` silently skips auditing for every user.
2. **Every listener registered after this plugin is skipped** — other plugins' `POST_INSTALL_CMD`
   handlers and the user's own `post-install-cmd` scripts. Listeners run in registration order
   (all priorities are 0), so ordering is effectively arbitrary and users will see scripts that
   "sometimes don't run".
3. **The outer exit code is replaced by the inner one.** A failing outer install that reached the
   post-install stage would exit 0 if the nested install succeeded.
4. `gc_enable()` (`Installer.php:444-446`) and the normal `InstallCommand` return path are skipped.

Two further problems in the same method, which matter the moment F1 is fixed:

- **The exit code is discarded even in principle.** `execute()` (`src/Plugin.php:197`) ignores
  `runInstall()`'s return value. A failed nested install would be reported as success.
- **CWD leaks on failure.** `Application::doRun()` chdirs into `--working-dir`
  (`Console/Application.php:167-174`) and only chdirs back on the *success* path
  (`Console/Application.php:461-464`); the `finally` block only calls `restore_error_handler()`.
  An exception in the nested run leaves the outer process sitting in `tmp-xxxxxxxxxx/source`.
- **Shared process state.** `Composer::setRunningCommand()`, `ErrorHandler::register()`,
  `Platform::putEnv('COMPOSER_CACHE_DIR')` (on `--no-cache`), the registered shutdown function,
  and the already-loaded class table are all shared between outer and inner Composer. If the
  scoped project's own plugins ship classes whose names collide with already-loaded ones, the
  nested run fatals with "Cannot redeclare class".

### Composer's own precedent

Composer itself runs a nested `Application` in exactly one place —
`vendor/composer/composer/src/Composer/EventDispatcher/EventDispatcher.php:312-318` — and it
neutralises all of the above first:

```php
$app = new Application();
$app->setCatchExceptions(false);
if (method_exists($app, 'setCatchErrors')) {
    $app->setCatchErrors(false);
}
$app->setAutoExit(false);
```

…and it reuses the current IO's output stream rather than creating a new one
(`EventDispatcher.php:330-340`).

For re-invoking `composer` itself, Composer uses a **subprocess**
(`EventDispatcher.php:253`, `EventDispatcher.php:431`):

```php
$exec = $this->getPhpExecCommand() . ' ' . ProcessExecutor::escape(Platform::getEnv('COMPOSER_BINARY')) . ' ' . $args;
```

`COMPOSER_BINARY` is set by `vendor/composer/composer/bin/composer:107`.

### Fix

**Recommended — separate process** (matches Composer's own idiom, gives full isolation, correct exit
code, and no state contamination):

```php
private function runInstall( string $path, string $command = 'install', bool $useDev = true ): int {
    $binary = Platform::getEnv( 'COMPOSER_BINARY' );
    $php    = ( new PhpExecutableFinder() )->find( false );

    $cmd = array_filter( array(
        $php, $binary, $command,
        '--working-dir=' . $path,
        $useDev ? null : '--no-dev',
        '--optimize-autoloader',
        '--no-plugins',                 // see F10
    ) );

    $exitCode = $this->composer->getLoop()->getProcessExecutor()->executeTty( $cmd, $path );

    if ( 0 !== $exitCode ) {
        throw new \RuntimeException( sprintf( 'wpify/scoper: nested composer %s failed with code %d', $command, $exitCode ) );
    }

    return $exitCode;
}
```

`ProcessExecutor::execute()`/`executeTty()` accept an array command
(`Util/ProcessExecutor.php:93,109`) and handle escaping. Fall back to
`PhpExecutableFinder` + a resolved `composer` path if `COMPOSER_BINARY` is unset (i.e. when the
plugin is driven from `bin/wpify-scoper` rather than from Composer itself).

**Minimum viable fix** if the in-process design must be kept:

```php
$application = new Application();
$application->setAutoExit( false );
$application->setCatchExceptions( false );
if ( method_exists( $application, 'setCatchErrors' ) ) {
    $application->setCatchErrors( false );
}
$exitCode = $application->run( $input, $output );
if ( 0 !== $exitCode ) {
    throw new \RuntimeException( ... );
}
```

### Benefit

`composer install` completes normally: the audit runs, other plugins' and users' post-install
scripts run, and a failing dependency install is reported as a failure instead of being swallowed.

### Downside / risk

Subprocess spawning costs ~1 s of PHP bootstrap and loses the shared HTTP/cache warm state. Users
who currently rely on the outer install "ending" right after scoping (and therefore never noticing
that their own post-install scripts are skipped) will see those scripts start running — behaviour
change, though the previous behaviour was a bug. Throwing on non-zero will surface nested-install
failures that were previously invisible; expect bug reports that are actually pre-existing breakage.

---

## F2 — PHP requirement `^8.1` is unsatisfiable — **Critical / S**

### What the code does now

`composer.json:31`: `"php": "^8.1"`, alongside `composer.json:34`: `"wpify/php-scoper": "^0.18"`.

### Why it is wrong

`vendor/wpify/php-scoper/composer.json` requires `"php": "^8.2"`. The bundled phar is
php-scoper 0.18.19 (`php vendor/wpify/php-scoper/bin/php-scoper.phar --version` →
`PhpScoper version 0.18.19 2026-03-02`), and upstream `humbug/php-scoper` at tag `0.18.19` also
requires `"php": "^8.2"`. The phar carries a hard runtime gate — its Box requirement checker
(`phar://…/.box/.requirements.php`) contains:

```php
array ( 'type' => 'php', 'condition' => '^8.2',
        'message' => 'This application requires a PHP version matching "^8.2".' )
```

So on PHP 8.1 the package is not installable at all (resolver rejects it), and even if it were, the
phar would refuse to run. The declared `^8.1` produces a confusing transitive-conflict error instead
of a clear "this package requires PHP 8.2".

`README.md:15-18` repeats the same wrong claim (`wpify/scoper:3.2 → PHP >= 8.1`).

### Supported-version analysis

Per <https://www.php.net/supported-versions.php> as of 2026-07-27:

| Branch | Active support until | Security support until | Status today |
|---|---|---|---|
| 8.1 | — | 31 Dec 2025 | **EOL** |
| 8.2 | 31 Dec 2024 | 31 Dec 2026 | Security only |
| 8.3 | 31 Dec 2025 | 31 Dec 2027 | Security only |
| 8.4 | 31 Dec 2026 | 31 Dec 2028 | Active |
| 8.5 | 31 Dec 2027 | 31 Dec 2029 | Active |

Intersection of "still supported" and "php-scoper 0.18 supports it" = **8.2, 8.3, 8.4, 8.5**.

### Fix

```json
"require": {
  "php": "^8.2",
  ...
}
```

`^8.2` resolves to `>=8.2 <9.0`, which covers 8.5. Update `README.md:15-18` to
`wpify/scoper:3.3 → PHP >= 8.2`. Plan a bump to `^8.3` after 2026-12-31 when 8.2 goes EOL.

Note: `scripts/extract-symbols.php:45` pins the *parser* target to `PhpVersion::fromString("8.1.0")`.
That is the version the WordPress sources are parsed as, not the runtime requirement, so it is a
separate (defensible) choice — but it is worth a comment saying so, since it reads like a
contradiction next to an 8.2 floor.

### Benefit

Users on PHP 8.1 get an immediate, accurate error. Users reading the README get correct information.

### Downside / risk

None material — PHP 8.1 users cannot install today regardless. Publishing this as a patch release
would technically narrow the accepted range for already-resolved lock files; ship it with a minor
version bump.

---

## F3 — `getCapabilities()` is dead code, and a landmine — **High / S**

### What the code does now

`src/Plugin.php:16`:

```php
class Plugin implements PluginInterface, EventSubscriberInterface {
```

`src/Plugin.php:104-108`:

```php
public function getCapabilities() {
    return array(
        CommandProvider::class => self::class,
    );
}
```

### Why it is wrong

**It is never called.** `PluginManager::getCapabilityImplementationClassName()`
(`vendor/composer/composer/src/Composer/Plugin/PluginManager.php:611-616`) short-circuits:

```php
protected function getCapabilityImplementationClassName(PluginInterface $plugin, string $capability): ?string
{
    if (!($plugin instanceof Capable)) {
        return null;
    }
    ...
}
```

`Wpify\Scoper\Plugin` does not implement `Composer\Plugin\Capable`, so `getCapabilities()` is dead
code. The only consumer of the `CommandProvider` capability is
`Console/Application.php:795`, which is reached only through `getPluginCommands()`.

**Worse: adding `Capable` naively would break Composer for every user.** The declared implementation
class is `self::class` = `Wpify\Scoper\Plugin`, which does **not** implement
`Composer\Plugin\Capability\CommandProvider` and has no `getCommands()` method.
`PluginManager::getPluginCapability()` (`PluginManager.php:644-660`) would then do:

```php
$ctorArgs['plugin'] = $plugin;
$capabilityObj = new $capabilityClass($ctorArgs);   // new Plugin([...]) — PHP allows this, no ctor

if (!$capabilityObj instanceof Capability || !$capabilityObj instanceof $capabilityClassName) {
    throw new \RuntimeException(
        'Class ' . $capabilityClass . ' must implement both Composer\Plugin\Capability\Capability and '. $capabilityClassName . '.'
    );
}
```

That `RuntimeException` would fire on every `composer list`, `composer help`, tab-completion, and any
unrecognised command (`Console/Application.php:255-266` — `$mayNeedPluginCommand`), for every user
of the plugin.

### Fix

Either delete `getCapabilities()` (`src/Plugin.php:104-108`) outright, or — preferably, in
combination with F4 — implement it correctly with a **separate** provider class:

```php
// src/Plugin.php
use Composer\Plugin\Capable;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface {
    public function getCapabilities() {
        return array(
            \Composer\Plugin\Capability\CommandProvider::class => \Wpify\Scoper\CommandProvider::class,
        );
    }
}
```

```php
// src/CommandProvider.php
namespace Wpify\Scoper;

class CommandProvider implements \Composer\Plugin\Capability\CommandProvider {
    public function getCommands() {
        return array( new ScoperCommand() );   // extends Composer\Command\BaseCommand
    }
}
```

Note the provider's constructor receives a single array argument containing
`composer`, `io` and `plugin` keys (`Plugin/Capability/CommandProvider.php:17-21`,
`PluginManager.php:645-647`).

### Benefit

Removes dead code, removes a latent crash, and unlocks the `composer wpify-scoper …` UX in F4.

### Downside / risk

None for the delete-only variant. For the full variant, adding commands means Composer will now
instantiate them on `composer list` — keep the command constructor free of side effects.

---

## F4 — Pseudo-events instead of a real command — **High / M**

### What the code does now

`src/Plugin.php:18-21` declares four fake event names:

```php
public const SCOPER_INSTALL_CMD        = 'scoper-install-cmd';
public const SCOPER_INSTALL_NO_DEV_CMD = 'scoper-install-no-dev-cmd';
public const SCOPER_UPDATE_CMD         = 'scoper-update-cmd';
public const SCOPER_UPDATE_NO_DEV_CMD  = 'scoper-update-no-dev-cmd';
```

`bin/wpify-scoper:32-45` bootstraps a whole Composer instance by hand and fabricates an event:

```php
$factory    = new Factory();
$ioInterace = new NullIO();
$composer   = $factory->createComposer( $ioInterace );
$fakeEvent  = new Event( $command, $composer, $ioInterace );

$scoper = new Plugin();
$scoper->activate( $composer, $ioInterace );
$scoper->execute( $fakeEvent );
```

`execute()` then string-matches those names back into real `ScriptEvents` constants
(`src/Plugin.php:159-165`, `181-195`).

### Why it is wrong

- The four constants are **not** events. They are never dispatched through
  `Composer\EventDispatcher\EventDispatcher`; nothing else can subscribe to them; they exist purely
  as a magic string channel between the bin script and one method.
- `new NullIO()` (`bin/wpify-scoper:34`) discards *all* Composer output — no progress, no warnings,
  no auth prompts. A private-repository dependency in `composer-deps.json` will hang or fail with no
  explanation.
- Calling `$scoper->activate()` manually bypasses `PluginManager`, so `config.allow-plugins`,
  plugin-API version checks, and the runtime plugin autoloader
  (`PluginManager::registerPackage()`, `PluginManager.php:169-330`) are all skipped.
- The two `--no-dev` variants (`SCOPER_INSTALL_NO_DEV_CMD`, `SCOPER_UPDATE_NO_DEV_CMD`) are
  **unreachable** — `bin/wpify-scoper:13-21` only ever produces `SCOPER_INSTALL_CMD` or
  `SCOPER_UPDATE_CMD`. There is no way for a user to get a `--no-dev` scoped install. Dead branches
  at `src/Plugin.php:193-195` and `src/Plugin.php:163-165`.
- No `--help`, no `-v`, no `--no-interaction`, no argument validation. `bin/wpify-scoper:23-30`
  prints usage and `exit`s with code **0** on bad input.
- `README.md:64` documents `composer wpify-scoper install`, which only works because the user is
  told to add a `scripts` alias — a workaround for the missing real command.

### The idiomatic Composer 2 way

A `Composer\Command\BaseCommand` exposed through the `CommandProvider` capability (F3).
`BaseCommand` gives you `requireComposer()`, `tryComposer()`, `getIO()`, and full Symfony Console
argument/option/help handling for free, and Composer registers it automatically
(`Console/Application.php:790-800`) with correct `--working-dir`, `-v`, `--no-interaction`
and `--no-plugins` semantics.

### Fix

```php
namespace Wpify\Scoper;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScoperCommand extends BaseCommand {
    protected function configure(): void {
        $this->setName( 'wpify-scoper' )
             ->setDescription( 'Scope the dependencies declared in composer-deps.json' )
             ->addArgument( 'action', InputArgument::REQUIRED, 'install or update' )
             ->addOption( 'no-dev', null, InputOption::VALUE_NONE, 'Skip dev dependencies' );
    }

    protected function execute( InputInterface $input, OutputInterface $output ): int {
        $composer = $this->requireComposer();
        $scoper   = new Scoper( $composer, $this->getIO() );   // extracted from Plugin

        return $scoper->run(
            $input->getArgument( 'action' ),
            ! $input->getOption( 'no-dev' )
        );
    }
}
```

`bin/wpify-scoper` can then be dropped entirely (remove `composer.json:17-19`), or reduced to a thin
deprecation shim that prints "use `composer wpify-scoper …`". Keep `Plugin::execute()` for the
`POST_INSTALL_CMD`/`POST_UPDATE_CMD` autorun path, but have it delegate to the same `Scoper` class —
the string round-tripping at `src/Plugin.php:159-195` disappears.

### Benefit

`composer wpify-scoper install --no-dev -vv` works out of the box, with real help output, real
verbosity, correct exit codes, and Composer's own IO. The four pseudo-event constants and the manual
bootstrap both go away, and `--no-dev` becomes reachable.

### Downside / risk

BC break: `vendor/bin/wpify-scoper` disappears (or changes behaviour), and the four public constants
would be removed. CI pipelines and the `"scripts": {"wpify-scoper": "wpify-scoper"}` alias documented
in `README.md:44` need updating. Ship behind a major version, and keep the bin as a shim for one
release. Also: the command is only available when the plugin is allowed in `config.allow-plugins`,
which the README already instructs users to do.

---

## F5 — `bin/wpify-scoper` hardcodes the vendor root — **High / S**

### What the code does now

`bin/wpify-scoper:9-10`:

```php
$vendorRoot = __DIR__ . '/../../..';
require_once $vendorRoot . '/autoload.php';
```

### Why it is wrong

`__DIR__` in PHP is the **resolved real path** of the containing directory — symlinks are already
followed. The `../../..` walk therefore only works when the file physically lives at
`<root>/vendor/wpify/scoper/bin/`.

| Scenario | `__DIR__` | `$vendorRoot . '/autoload.php'` | Result |
|---|---|---|---|
| Normal `composer require` | `<root>/vendor/wpify/scoper/bin` | `<root>/vendor/autoload.php` | works |
| `composer global require` | `$COMPOSER_HOME/vendor/wpify/scoper/bin` | `$COMPOSER_HOME/vendor/autoload.php` | loads the **global** autoloader, then `Factory::createComposer()` reads the *project* composer.json — mixed worlds |
| Path repo (symlinked, Composer's default) | `<somewhere>/packages/scoper/bin` | `<somewhere>/autoload.php` | **fatal**: `require_once(): Failed opening required` |
| Running from a clone of this repo | `/Users/…/projects/scoper/bin` | `/Users/autoload.php` | **fatal** |
| Custom `config.vendor-dir` | still `<root>/<vendordir>/wpify/scoper/bin` | `<root>/<vendordir>/autoload.php` | works (relative layout is preserved) |
| `--working-dir` | unaffected (`__DIR__` is absolute) | — | works |

Composer has provided the correct mechanism since 2.2. Its generated bin proxies set two globals —
see the real proxy at `vendor/bin/php-scoper.phar:15-16` and the generator at
`vendor/composer/composer/src/Composer/Installer/BinaryInstaller.php:221,230`:

```php
$GLOBALS['_composer_bin_dir'] = __DIR__;
$GLOBALS['_composer_autoload_path'] = __DIR__ . '/..'.'/autoload.php';
```

### Fix

```php
#!/usr/bin/env php
<?php

$autoload = $GLOBALS['_composer_autoload_path'] ?? null;

if ( null === $autoload ) {
    foreach ( array(
        __DIR__ . '/../vendor/autoload.php',   // running from a clone of this repo
        __DIR__ . '/../../../autoload.php',    // installed as a vendor package
    ) as $candidate ) {
        if ( file_exists( $candidate ) ) {
            $autoload = $candidate;
            break;
        }
    }
}

if ( null === $autoload ) {
    fwrite( STDERR, 'wpify-scoper: could not locate the Composer autoloader.' . PHP_EOL );
    exit( 1 );
}

require_once $autoload;
```

`$_composer_autoload_path` requires declaring `"composer-runtime-api": "^2.2"` in `require`
(see F11). If F4 is implemented, this file goes away entirely and the problem is moot.

### Benefit

Works for global installs, symlinked path repos, and from a clone of the repo itself — which today
makes the plugin impossible to develop against without a full vendor install.

### Downside / risk

None. The fallback chain is strictly additive.

---

## F6 — `$this->io` is stored but never used — **High / S**

### What the code does now

`src/Plugin.php:24` declares `protected $io;`, `src/Plugin.php:53` assigns it — and it is never read
anywhere in the file. All output flows through `src/Plugin.php:291`:

```php
$output = new ConsoleOutput();
```

### Why it is wrong

A freshly constructed `Symfony\Component\Console\Output\ConsoleOutput` has none of the outer run's
settings:

- **`--quiet` is ignored.** The default verbosity is `VERBOSITY_NORMAL`; the nested composer prints
  its full output even under `composer install -q`.
- **`-v` / `-vv` / `-vvv` are ignored** in the other direction — you cannot get debug output out of
  the nested install.
- **`--no-ansi` / `--ansi` are ignored.** `ConsoleOutput` auto-detects colour from the stream. In CI
  where the outer Composer was told `--no-ansi` but the stream still looks like a TTY, the nested
  output carries ANSI escapes into the log.
- **`--no-interaction` is not propagated** as an output/IO concern, and `bin/wpify-scoper` passes a
  `NullIO` (F4), so any auth prompt from the nested install has nowhere to go.
- Non-TTY CI: `ConsoleOutput` will decorate based on its own detection rather than the outer run's
  resolved decision.

Composer's own nested-application code deliberately reaches into the current IO to reuse its stream
(`vendor/composer/composer/src/Composer/EventDispatcher/EventDispatcher.php:330-340`):

```php
if ($this->io instanceof ConsoleIO) {
    $reflProp = new \ReflectionProperty($this->io, 'output');
    ...
    $output = $reflProp->getValue($this->io);
} else {
    $output = new ConsoleOutput();
}
```

### Fix

With the subprocess approach from F1, propagate the outer IO's state as flags:

```php
$verbosity = null;
if ( $this->io->isDebug() )        { $verbosity = '-vvv'; }
elseif ( $this->io->isVeryVerbose() ) { $verbosity = '-vv'; }
elseif ( $this->io->isVerbose() )  { $verbosity = '-v'; }

$cmd = array_filter( array(
    $php, $binary, $command,
    '--working-dir=' . $path,
    $verbosity,
    $this->io->isDecorated() ? '--ansi' : '--no-ansi',
    $this->io->isInteractive() ? null : '--no-interaction',
    ...
) );
```

…and route all of the plugin's own messages through `$this->io->write()` /
`$this->io->writeError()` instead of `echo`/`ConsoleOutput`. `IOInterface` exposes
`isVerbose()`, `isVeryVerbose()`, `isDebug()`, `isDecorated()`, `isInteractive()` for exactly this.

### Benefit

`composer install -q` is quiet, CI logs are clean, and `-vvv` actually shows what the scoper is doing
— currently the only way to debug a scoping failure.

### Downside / risk

None. Output volume changes for users who relied on the nested install always being loud.

---

## F7 — php-scoper phar located and invoked incorrectly — **Medium / S**

### What the code does now

`src/Plugin.php:167`:

```php
$phpscoper = realpath( __DIR__ . '/../../php-scoper/bin/php-scoper.phar' );
```

`src/Plugin.php:169-173` then writes it into the generated `composer-deps.json` as a raw shell
command:

```php
$composerJson->scripts->{$scriptName} = array(
    $phpscoper . ' add-prefix --output-dir="' . $destination . '" --force --config="' . $scoperConfig . '"',
    'composer dump-autoload --working-dir="' . $destination . '" --optimize',
    'php "' . $postinstallPath . '"',
);
```

### Why it is wrong

1. **Path assumption.** `__DIR__/../../php-scoper` assumes `wpify/scoper` and `wpify/php-scoper`
   are siblings in the same vendor dir. True for a normal install; **false** for a symlinked path
   repo, and false when running from a clone of this repo (`realpath()` then returns `false`, and
   the generated command begins with a bare ` add-prefix …`, producing a baffling error).
   Composer's runtime API gives the answer directly:

   ```php
   \Composer\InstalledVersions::getInstallPath( 'wpify/php-scoper' ) . '/bin/php-scoper.phar'
   ```

   Verified working in this checkout (`vendor/composer/InstalledVersions.php:242`).
2. **No PHP binary.** The phar is invoked directly, relying on its `#!/usr/bin/env php` shebang
   (confirmed via `Phar::getStub()`). That means (a) it runs under whatever `php` is first on
   `PATH`, which may be a different version from the one running Composer — and the phar hard-fails
   on anything below 8.2 (F2); (b) it requires the executable bit to have survived
   installation; (c) **it cannot work on Windows**, where a shebang is meaningless. Composer solves
   this with `getPhpExecCommand()`
   (`vendor/composer/composer/src/Composer/EventDispatcher/EventDispatcher.php` —
   `PhpExecutableFinder` plus `-d memory_limit=…` etc.) or with the `@php` script prefix.
3. **Bare `composer`** on line 171 — same problem. Composer's own answer is
   `Platform::getEnv('COMPOSER_BINARY')` (`EventDispatcher.php:253`) or the `@composer` script prefix.
4. **Manual quoting.** `'--output-dir="' . $destination . '"'` breaks on any path containing a
   double quote, and the `"` quoting is not correct on Windows `cmd`. Use
   `ProcessExecutor::escape()`.

### Fix

```php
$phpscoper = \Composer\InstalledVersions::getInstallPath( 'wpify/php-scoper' ) . '/bin/php-scoper.phar';

$composerJson->scripts->{$scriptName} = array(
    '@php ' . ProcessExecutor::escape( $phpscoper )
        . ' add-prefix --force'
        . ' --output-dir=' . ProcessExecutor::escape( $destination )
        . ' --config='     . ProcessExecutor::escape( $scoperConfig ),
    '@composer dump-autoload --working-dir=' . ProcessExecutor::escape( $destination ) . ' --optimize',
    '@php ' . ProcessExecutor::escape( $postinstallPath ),
);
```

`@php` and `@composer` are resolved by Composer's `EventDispatcher` to the current PHP binary and the
current Composer binary respectively (`EventDispatcher.php:253`, `EventDispatcher.php:431`), which is
exactly the guarantee needed here.

### Benefit

Correct PHP binary (matching the resolver's platform config), correct Composer binary, Windows
support, and paths with spaces/quotes stop breaking.

### Downside / risk

`@php` inherits Composer's memory/ini flags, which changes the phar's effective `memory_limit` —
usually an improvement (scoping WordPress is memory-hungry), but worth verifying on a large project.

---

## F8 — `getcwd()` used as the project root — **Medium / M**

### What the code does now

`getcwd()` appears at `src/Plugin.php:57`, `58`, `66`, `87`, `134`, `135`, `141`, `151`, `177`, `178`
and `275`, defining the deps folder, temp folder, `composer-deps.json` location, and the
`%%cwd%%` token baked into `postinstall.php`.

### Why it is (partly) wrong — and where the brief's premise does not hold

**`--working-dir` is *not* actually broken.** `Application::doRun()` chdirs into the target
directory at `vendor/composer/composer/src/Composer/Console/Application.php:167-174`, well before
`Factory::createComposer()` and before any plugin is activated. So during `activate()`,
`getcwd()` genuinely is the working directory. Same for the `use-parent-dir` fallback
(`Console/Application.php:213-217`) and for `composer global …`, which chdirs to `$COMPOSER_HOME`
(`Command/GlobalCommand.php:148`). The current code works in all of those.

The real breakages are narrower but real:

1. **`COMPOSER` env var.** `Factory::getComposerFile()` (`Factory.php:224-238`) honours
   `COMPOSER=/path/to/other.json`, and `Factory::createComposer()` then sets the project root from
   `dirname($localConfig)` (`Factory.php:285`), *not* from the cwd. With
   `COMPOSER=../other/composer.json composer install`, the plugin writes `deps/`, the temp dir, and
   `composer-deps.json` into the wrong directory.
2. **Custom `config.vendor-dir`** is never consulted; the plugin has no notion of it (F7 works
   around it by accident because the relative layout is preserved).
3. **Brittleness / implicit coupling.** Nothing documents that `getcwd()` must equal the project
   root. Any future in-process embedding (including this plugin's own `bin/wpify-scoper`, which does
   not chdir) is silently wrong.
4. `createPath()` (`src/Plugin.php:268-276`) uses a **string heuristic** to decide whether it is
   running as an installed package:

   ```php
   $vendor = strpos( dirname( __DIR__ ), 'vendor' . DIRECTORY_SEPARATOR . 'wpify' . DIRECTORY_SEPARATOR . 'scoper' );
   ```

   With a symlinked path repo, `dirname(__DIR__)` is the real source path, the heuristic returns
   `false`, and `scoper.custom.php` is looked up inside the plugin directory instead of the project
   root — **user customisations silently stop being applied**, with no warning.

### Fix

Resolve the root once in `activate()` from Composer's own config and drop every `getcwd()`:

```php
public function activate( Composer $composer, IOInterface $io ) {
    $this->composer = $composer;
    $this->io       = $io;
    $this->rootDir  = dirname( $composer->getConfig()->getConfigSource()->getName() );
    ...
}
```

`getConfigSource()->getName()` returns the absolute path to the active `composer.json` — verified in
this checkout it returns `/Users/wpify/projects/scoper/composer.json`. Use
`$composer->getConfig()->get('vendor-dir')` where the vendor dir is needed.

Replace `createPath()`'s heuristic with an explicit check for the file in `$this->rootDir`, and
`$io->warning()` when a `scoper.custom.php` is found in an unexpected place.

### Benefit

Correct under `COMPOSER=`, correct for symlinked path repos, and the coupling to process cwd
becomes explicit and testable.

### Downside / risk

If any user currently relies on running Composer from a subdirectory with `use-parent-dir` *and*
expects `deps/` in the subdirectory, that changes. Unlikely, but call it out in the changelog.

---

## F9 — Generated `composer-deps.json` embeds absolute host paths — **Medium / M**

### What the code does now

`src/Plugin.php:169-175` mutates the user's **tracked, hand-maintained** `composer-deps.json` (or
creates it, `src/Plugin.php:136-142`) and writes a `scripts` block full of absolute host paths:

```json
"scripts": {
  "post-install-cmd": [
    "/Users/me/project/vendor/wpify/php-scoper/bin/php-scoper.phar add-prefix --output-dir=\"/Users/me/project/tmp-a3f9c1e2b0/destination\" --force --config=\"/Users/me/project/tmp-a3f9c1e2b0/scoper.inc.php\"",
    "composer dump-autoload --working-dir=\"/Users/me/project/tmp-a3f9c1e2b0/destination\" --optimize",
    "php \"/Users/me/project/tmp-a3f9c1e2b0/postinstall.php\""
  ]
}
```

The `tmp-` segment is regenerated on every run (`src/Plugin.php:58`).

### Why it is wrong

- **Every run produces a diff.** The random temp directory name changes each time, so
  `composer-deps.json` is dirty after every `composer install`. Committed, it is pure noise;
  gitignored, it is not reproducible.
- **Not portable.** A `composer-deps.json` committed from a developer's Mac contains
  `/Users/me/...` and is meaningless in CI or on another machine. The next run overwrites it, so it
  "works", but the file in git is a lie.
- **`composer-deps.lock` is derived from it** (`src/Plugin.php:177-179`, `scripts/postinstall.php:57-58`),
  so the lock's `content-hash` churns for reasons unrelated to the dependency set.
- **User data loss risk.** If a user legitimately defines `post-install-cmd` in their
  `composer-deps.json`, `src/Plugin.php:169` overwrites it wholesale — no merge, no warning.
- The file is a *user-owned config file* being used as an internal scratch buffer. That is the
  category error at the root of all of the above.

### Fix

Keep `composer-deps.json` read-only. Write the *derived* manifest into the temp directory and run
Composer against that:

```php
$generated = $this->path( $source, 'composer.json' );   // already the case (src/Plugin.php:131)
```

…then drop the `scripts` injection entirely and drive the three steps directly from PHP after the
nested install returns (F1 already turns this into an ordinary sequential flow):

```php
$this->runInstall( $source, $command, $useDev );      // 1. resolve + install
$this->runScoper( $scoperConfig, $destination );      // 2. php-scoper add-prefix
$this->runDumpAutoload( $destination );               // 3. composer dump-autoload
$this->runPostInstall( ... );                         // 4. fixups + move into place
```

That also removes the need for the token-substituted `scripts/postinstall.php` template
(`src/Plugin.php:148-157`) — it can become a plain class with typed parameters.

If the `scripts` mechanism must be kept for now, at minimum: (a) write the scripts only into the
copy at `$source/composer.json`, never back into the user's `composer-deps.json`; and (b) use a
**stable** temp directory (`$rootDir . '/.wpify-scoper'`) so nothing churns.

### Benefit

`composer-deps.json` becomes a stable, committable, machine-independent file. No user script is
clobbered. Reproducible builds.

### Downside / risk

Medium refactor touching the whole pipeline. Users who (accidentally) depended on the injected
scripts running inside the nested Composer's environment — e.g. platform config from
`composer-deps.json`'s `config.platform`, which the README explicitly recommends at
`README.md:32-33` — must be checked: the scoper step is currently run *by* the nested Composer and
would now run in the outer process. Preserve the platform-php semantics explicitly.

---

## F10 — Re-entrancy is avoided only by accident — **Medium / S**

### What the code does now

`src/Plugin.php:44-49` subscribes to `POST_INSTALL_CMD` and `POST_UPDATE_CMD`; the handler
(`src/Plugin.php:197`) runs a nested `install`/`update`, which will itself dispatch
`POST_INSTALL_CMD`/`POST_UPDATE_CMD`.

### Why it is a latent problem

Plugins in the nested run are loaded from two places
(`vendor/composer/composer/src/Composer/Plugin/PluginManager.php:104-111`):

```php
$this->loadRepository($repo, false, $this->composer->getPackage());   // local vendor of the nested project

if ($this->globalComposer !== null && !$this->arePluginsDisabled('global')) {
    $this->loadRepository($this->globalComposer->getRepositoryManager()->getLocalRepository(), true);
}
```

The generated `$source/composer.json` does not require `wpify/scoper`, so it is not loaded locally.
But `README.md:22` and both CI recipes (`README.md:88-93`, `README.md:118-119`) tell users to
`composer global require wpify/scoper` — and global plugins **are** loaded in the nested run.

Recursion is currently broken only because `execute()` bails when `$this->prefix` is empty
(`src/Plugin.php:127`), and the generated `$source/composer.json` has no
`extra.wpify-scoper.prefix`. That is a coincidence, not a guard:

- A user who copies their `extra` block into `composer-deps.json` (a very natural thing to do — the
  README describes it at `README.md:23-25` as having "exactly same structure like composer.json")
  gets **unbounded recursion**: each level creates a new `tmp-xxxxxxxxxx` directory and spawns
  another install, until the machine runs out of disk or file descriptors.
- The `autorun: false` escape hatch (`src/Plugin.php:119-125`) does not help, because the copied
  `extra` would carry `autorun: true`.

Today the recursion terminates instead because of F1 (`exit()` kills the process at depth 1) —
i.e. one bug is masking another. **Fixing F1 without adding a guard makes this reachable.**

### Fix

Two independent guards:

1. **Pass `--no-plugins` to the nested run.** The nested install of scoped dependencies has no
   business loading plugins at all. This is the primary fix and is a one-line addition to the
   command built in F1.
2. **Add an explicit re-entrancy flag** for defence in depth:

   ```php
   private static bool $running = false;

   public function execute( Event $event ) {
       if ( self::$running ) {
           $this->io->writeError( '<warning>wpify/scoper: re-entrant invocation detected, skipping.</warning>' );
           return;
       }
       self::$running = true;
       try { ... } finally { self::$running = false; }
   }
   ```

   With a subprocess (F1) a static flag does not cross the process boundary, so also set and check
   an env var (e.g. `WPIFY_SCOPER_RUNNING=1`) via `Platform::putEnv()` / `Platform::getEnv()`.

Additionally, strip `extra.wpify-scoper` from the generated `$source/composer.json` before writing it
(`src/Plugin.php:175`).

### Benefit

Removes an unbounded-recursion foot-gun that a reasonable reading of the README leads users into,
and makes the nested install faster and more predictable by not loading unrelated plugins.

### Downside / risk

`--no-plugins` will break users whose scoped dependency set genuinely needs an installer plugin
(e.g. `composer/installers` for a scoped WordPress package). If any such case exists, prefer the
env-var guard alone and leave plugins enabled. Worth checking against real consumer projects before
shipping.

---

## F11 — `composer.json` metadata — **Medium / S**

Taking the brief's items one at a time, including the two where the premise does not hold.

### 11a. `composer/composer` in `require` — should be `require-dev` (or accepted deliberately)

`composer.json:33`: `"composer/composer": "^2.6"`.

The official plugin documentation (<https://getcomposer.org/doc/articles/plugins.md>) says:

> You must require the special package called `composer-plugin-api` to define which Plugin API
> versions your plugin is compatible with. […] When developing a plugin, although not required,
> it's useful to add a **require-dev** dependency on `composer/composer` to have IDE autocompletion
> on Composer classes.

The plugin currently *does* use classes outside the plugin API — `Composer\Console\Application`
(`src/Plugin.php:6`) and `Composer\Factory` (`bin/wpify-scoper:3`). Those are supplied by the
running Composer at runtime regardless of the declaration; requiring `composer/composer` pulls a
second, possibly different, copy into the user's vendor dir and can conflict with the running
Composer.

**Fix:** implementing F1 (subprocess) and F4 (`BaseCommand`) removes the need for
`Composer\Console\Application` and `Composer\Factory` entirely. Then move `composer/composer` to
`require-dev` and keep only `composer-plugin-api` in `require`. If some non-API class must be kept,
document why and leave the require in place.
**Severity:** Medium. **Effort:** S (once F1/F4 land).

### 11b. `symfony/console` used directly but not required

`src/Plugin.php:13-14` imports `Symfony\Component\Console\Input\ArrayInput` and
`Symfony\Component\Console\Output\ConsoleOutput`; `composer.json` never requires
`symfony/console`. It resolves transitively via `composer/composer` (`composer.lock:180`:
`"symfony/console": "^5.4.47 || ^6.4.25 || ^7.1.10 || ^8.0"`; currently installed v8.1.1).

`ArrayInput` and `ConsoleOutput` are stable across all of those, so this is not currently a
functional bug — but it is an undeclared direct dependency that `composer-require-checker` flags,
and it breaks the moment `composer/composer` moves to `require-dev` (11a).

**Fix:** if the classes survive the F1/F4 refactor, add
`"symfony/console": "^5.4 || ^6.4 || ^7.0 || ^8.0"` to `require`. If F4 lands, `BaseCommand` needs
it anyway.
**Severity:** Low-Medium. **Effort:** S.

### 11c. Missing `composer-runtime-api`

Needed to legitimately use `$GLOBALS['_composer_autoload_path']` (F5) and
`Composer\InstalledVersions` (F7). Add `"composer-runtime-api": "^2.2"` to `require`.
**Severity:** Low. **Effort:** S.

### 11d. `nikic/php-parser` in `require-dev` while `composer extract` is exposed

`composer.json:21` declares `"extra": "php ./scripts/extract-symbols.php"`, and
`scripts/extract-symbols.php:3-7` imports `PhpParser\*`, with `nikic/php-parser` only in
`require-dev` (`composer.json:39`).

This is fine as-is: `scripts` in a non-root package are never executed by consumers, and
`scripts/extract-symbols.php:9` requires `__DIR__ . '/../vendor/autoload.php'` — the *plugin's own*
vendor dir, which only exists in a clone. It is a maintainer-only tool. The one improvement worth
making is a `scripts-descriptions` entry so `composer list` explains it, and a guard that fails
clearly if `PhpParser` is missing.
**Severity:** Low. **Effort:** S.

### 11e. Missing `keywords`, `homepage`, `support`

`composer.json` has none of these. Packagist uses them for discovery and for the "Issues"/"Source"
links.

```json
"keywords": ["wordpress", "woocommerce", "php-scoper", "prefix", "namespace", "composer-plugin", "scoper"],
"homepage": "https://github.com/wpify/scoper",
"support": {
  "issues": "https://github.com/wpify/scoper/issues",
  "source": "https://github.com/wpify/scoper"
}
```

**Severity:** Low. **Effort:** S.

### 11f. `minimum-stability: stable` is redundant

`composer.json:23`. `stable` is Composer's default, and `minimum-stability` is **ignored entirely**
in non-root packages. Harmless but noise — remove it.
**Severity:** Low. **Effort:** S.

### 11g. `repositories` entry for wpackagist — **premise does not hold**

`composer.json:24-29` adds `https://wpackagist.org`. The brief suggests this "leaks into a published
plugin". It does not: Composer **ignores the `repositories` key of any non-root package** — it is
only honoured in the root `composer.json`. The entry is genuinely required for this repo's own
`require-dev` (`wpackagist-plugin/woocommerce`, `composer.json:41`) and is inert for consumers.

The one real cost is cosmetic: it shows up on the Packagist page and can confuse readers. Optionally
move the WordPress-source dev packages plus the repository entry into a separate
`tools/composer.json` or a Composer bin-plugin scope. Not required.
**Severity:** Low (informational). **Effort:** S.

### 11h. `.gitattributes` / package size — **premise does not hold**

The brief suggests `sources/` bloats the published package. It does not. `.gitignore:3` excludes
`/sources/`, and `git ls-files` shows only **14 tracked files** — the largest being
`symbols/wordpress.php` (197 KB) and `symbols/woocommerce.php` (92 KB), both of which are **required
at runtime** (`src/Plugin.php:226-255`). Dist archives are built from the git tree, so `sources/` is
already absent.

A `.gitattributes` is still mildly worth adding for hygiene, but the win is small:

```gitattributes
/docs           export-ignore
/scripts/extract-symbols.php export-ignore
/.gitattributes export-ignore
/.gitignore     export-ignore
```

(Do **not** export-ignore `symbols/` — it is runtime data. `scripts/postinstall.php` is also runtime,
read at `src/Plugin.php:148`.)
**Severity:** Low. **Effort:** S.

### 11i. `config.allow-plugins` in the package

`composer.json:60-65` is only honoured in the root `composer.json` (it exists here for this repo's
own dev install of `johnpbloch/wordpress-core-installer`). Correct as-is; no change.

---

## F12 — `exit` inside `createScoperConfig()`; `require_once` for config — **Low / S**

`src/Plugin.php:212-216`:

```php
$config = require_once $config_path;

if ( ! is_array( $config ) ) {
    exit;
}
```

Two problems:

1. **`exit` in a Composer plugin.** Same category as F1: it kills the outer Composer process,
   skipping the audit and every subsequent listener — and here it exits with code **0**, i.e. a
   configuration failure is reported to CI as success. It also prints nothing, so the user gets a
   silent, successful-looking no-op.
2. **`require_once` on a value-returning file.** `require_once` returns `true` (not the file's
   return value) if the path was already included. `config/scoper.config.php` is a
   `return array(...)` file, and `config/scoper.inc.php:5` also does
   `require_once __DIR__ . '/scoper.config.php'`. Any future code path that loads it twice in one
   process makes `$config` become `true`, hit the `is_array` check, and `exit(0)` — a silent
   failure that is very hard to diagnose. `require` is correct for value-returning files.

**Fix:**

```php
$config = require $config_path;

if ( ! is_array( $config ) ) {
    throw new \RuntimeException(
        sprintf( 'wpify/scoper: %s must return an array, got %s.', $config_path, get_debug_type( $config ) )
    );
}
```

Composer catches the exception and reports it properly with a non-zero exit code.

**Benefit:** failures are visible and correctly signalled. **Downside:** none.

---

## F13 — Temp directory naming, placement and cleanup — **Low / S**

`src/Plugin.php:58`:

```php
'temp' => $this->path( getcwd(), 'tmp-' . substr( str_shuffle( md5( microtime() ) ), 0, 10 ) ),
```

- `str_shuffle(md5(microtime()))` is a permutation of a fixed 32-char string — it does not increase
  entropy; it draws from `microtime()` (low resolution, predictable) and `str_shuffle`'s non-CSPRNG.
  Use `bin2hex(random_bytes(5))` — which is exactly what Composer itself does at
  `Console/Application.php:369-370`.
- The directory is created **in the project root**, so a failed run leaves `tmp-xxxxxxxxxx/`
  littering the user's repo. It is only removed by `scripts/postinstall.php:67`, i.e. on the
  full-success path. There is no cleanup on failure and no `register_shutdown_function` guard.
- A random name per run defeats caching and makes the `composer-deps.json` diff churn (F9).
- `mkdir( $path, 0755, true )` (`src/Plugin.php:280`) ignores its return value and does not respect
  the process umask expectations; failures surface later as confusing `file_put_contents` errors.

**Fix:** use a single stable directory (`$rootDir . '/.wpify-scoper'`), add it to a recommended
`.gitignore` snippet in the README, wipe it at the start of each run, and wrap the whole pipeline in
`try/finally` to clean up. Check `mkdir()`'s return value and throw on failure. Consider
`$composer->getConfig()->get('cache-dir')` if the scratch space should live outside the project.

**Benefit:** no repo litter, reproducible paths, no churn.
**Downside:** a stable path is a (minor) concurrency hazard if two Composer runs execute in the same
project simultaneously; add a lock file if that matters.

---

## F14 — Silent no-op when `prefix` is missing — **Low / S**

`src/Plugin.php:127`: `if ( ! empty( $this->prefix ) ) { ... }` — with no `else`. A user who installs
the plugin but forgets `extra.wpify-scoper.prefix` (step 3 of `README.md:26-28`) gets absolutely no
feedback: no scoping, no warning, no error. This is the single most likely first-run failure mode.

**Fix:**

```php
if ( empty( $this->prefix ) ) {
    $this->io->writeError(
        '<warning>wpify/scoper: no "extra.wpify-scoper.prefix" configured in composer.json — skipping scoping.</warning>'
    );
    return;
}
```

(Requires F6 so `$this->io` is actually usable.) Early-return also flattens the 70-line `if` body at
`src/Plugin.php:127-198`.

**Benefit:** the most common setup mistake becomes self-diagnosing.
**Downside:** users who intentionally install the plugin without a prefix (e.g. it is a transitive
dev dependency) will see a warning on every install. Gate it behind
"`composer-deps.json` exists but no prefix is set" if that is a real scenario.

---

## Suggested sequencing

1. **F2** (PHP constraint) — one line, ship immediately.
2. **F3** (delete `getCapabilities()`) and **F5** (autoloader lookup) — small, independent, no BC risk.
3. **F1 + F6 + F10** together — the nested-run rewrite. F10's `--no-plugins` / env guard **must**
   land in the same change as F1, since F1 unmasks the recursion.
4. **F7 + F12 + F13 + F14** — hardening, all small.
5. **F9** — the `composer-deps.json` refactor (largest single piece).
6. **F4** — `BaseCommand` + `CommandProvider`, alongside F3's provider class. Major version.
7. **F8** and **F11** — cleanup, any time.

There are no automated tests in the repo. Before touching F1/F9, a smoke test that runs
`composer install` end-to-end against a fixture project with a real `composer-deps.json` (asserting
`deps/scoper-autoload.php` exists, a known symbol is prefixed, and a known WordPress function is
*not*) would make the rest of this list far safer to execute.
