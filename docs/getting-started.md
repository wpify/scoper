# Getting started

By the end of this page you will have a WordPress plugin whose copy of Guzzle lives in a namespace
no other plugin can reach, and you will have verified that on disk rather than taken it on trust.

Guzzle is the example because it is the collision people actually hit. It is bundled by a large
number of WordPress plugins, its major versions are not source-compatible, and only one copy can
own the `GuzzleHttp\Client` name in a running site.

You need **PHP 8.2 or newer** and **Composer 2.6 or newer**.

## 1. Install the scoper

The scoper is a Composer plugin. Install it globally, pinned to a major version:

```bash
composer global config --no-plugins allow-plugins.wpify/scoper true
composer global require wpify/scoper:^4.0
```

Composer refuses to run plugins it has not been told to trust, and it does so *silently* — the
`allow-plugins` line above is not optional, and skipping it produces a `composer install` that
appears to succeed and scopes nothing.

> **Pin the same constraint everywhere.** The scoper version that produced your scoped tree is not
> recorded in any lock file. A laptop on 3.2 and a CI runner on 4.0 can emit different bytes from
> identical sources. Use the same constraint on every machine and in CI, and re-scope after you
> upgrade the scoper. If you would rather have it pinned in `composer.lock` like everything else,
> install it as a dev dependency instead — see [Configuration](configuration.md#installing-as-a-dev-dependency).

## 2. Create the plugin

```bash
mkdir my-plugin && cd my-plugin
```

## 3. Declare the dependencies to scope

Scoped dependencies do not go in `composer.json`. They go in a second manifest, `composer-deps.json`,
which has exactly the same format:

```json
{
  "require": {
    "guzzlehttp/guzzle": "^7.0"
  },
  "config": {
    "platform": {
      "php": "8.2.0"
    }
  }
}
```

The split is the whole idea. `composer-deps.json` holds what gets prefixed; `composer.json` holds
everything else — your dev tooling, your test framework, anything that never runs inside a
WordPress request alongside another plugin.

`config.platform.php` should match the PHP version your **site** runs, not the one your laptop
runs. Composer resolves against it, so setting it lower than production is how you end up with a
scoped tree that fatals on the server.

## 4. Configure the prefix

In `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "wpify/scoper": true
    },
    "platform": {
      "php": "8.2.0"
    }
  },
  "extra": {
    "wpify-scoper": {
      "prefix": "MyPlugin\\Deps"
    }
  }
}
```

`prefix` is the only required setting. It must be a valid PHP namespace — identifiers separated by
backslashes, no leading or trailing separator, and remember that JSON needs each backslash doubled.
Everything else has a default; see [Configuration](configuration.md).

Pick something nobody else will: your plugin's own vendor namespace with `\Deps` on the end is a
good default. `Deps` on its own is not — two plugins that both chose it collide exactly the way
this tool exists to prevent.

## 5. Run it

```bash
composer install
```

You will see the scoper announce itself:

```
wpify-scoper: running composer install for /path/to/my-plugin/composer-deps.json,
scoping it with the prefix MyPlugin\Deps into /path/to/my-plugin/deps
```

Behind that line it resolves `composer-deps.json` in a temporary workspace, rewrites the result
with php-scoper, and moves the finished tree into `deps/`. If nothing at all is printed and no
`deps/` folder appears, the plugin is not allowed to run — go back to step 1.

## 6. Check the result

Three things should now exist:

```bash
ls deps/
```

```
autoload.php  composer/  guzzlehttp/  psr/  scoper-autoload.php
```

Confirm the prefix actually landed:

```bash
grep -r "namespace MyPlugin" deps/guzzlehttp/guzzle/src/Client.php
```

```php
namespace MyPlugin\Deps\GuzzleHttp;
```

And confirm WordPress was left alone. There is no WordPress in this example yet, but the rule is
worth seeing now: any call to `add_action()`, `WP_Query`, `wp_remote_get()` and the thousands of
other names WordPress declares stays exactly as written. Only your dependencies move.

Two files were also written next to your manifest:

- `composer-deps.lock` — the lock file for the scoped set. **Commit it.** It is what makes
  `composer install` reproducible for everyone else on the project.
- a `tmp-*` directory, if the run failed. A successful run removes its workspace. See
  [Troubleshooting](troubleshooting.md).

## 7. Load it from your plugin

Create `my-plugin.php`:

```php
<?php
/**
 * Plugin Name: My Plugin
 */

require_once __DIR__ . '/deps/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

add_action( 'init', function () {
	$client = new \MyPlugin\Deps\GuzzleHttp\Client();
	// ...
} );
```

Two autoloaders, in that order. `deps/scoper-autoload.php` pulls in `deps/autoload.php` itself, so
you only ever require the one file — but you still need `vendor/autoload.php` for your own
unscoped code.

Note that `add_action()` is called unprefixed. That is the point of the symbol lists: WordPress's
own names are excluded from the rewrite, so your plugin talks to WordPress normally while its
dependencies live somewhere private.

## 8. Decide what to commit

```gitignore
/vendor/
/tmp-*
```

- `composer-deps.json` — commit. It is your source of truth.
- `composer-deps.lock` — commit. Without it, `composer install` is not reproducible.
- `deps/` — your call. It is a build artifact, so most projects build it in CI and ignore it.
  Commit it if you deploy by pushing a git checkout to a server where you cannot run Composer.
- `tmp-*` — never. Always ignore it.

[Deployment](deployment.md) covers the trade-off properly.

## Where to go next

- [Configuration](configuration.md) — change the output folder, drop symbol lists you do not need,
  turn off automatic scoping.
- [Deployment](deployment.md) — GitLab CI, GitHub Actions, Bedrock, multi-plugin repositories.
- [Customizing php-scoper](customizing.md) — when a dependency builds class names dynamically and
  php-scoper cannot see them.
- [How it works](how-it-works.md) — what the symbol lists are and how the pipeline is put together.
