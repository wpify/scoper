# WPify Scoper - A scoper for WordPress plugins and themes

Using Composer in your WordPress plugin or theme can benefit from that. But it also comes with a danger of conflicts
with dependencies of other plugins or themes. Luckily, a great tool
called [PHP Scoper](https://github.com/humbug/php-scoper) adds all your needed dependencies to your namespace to prevent
conflicts. Unfortunately, the configuration is non-trivial, and for that reason, we created the Composer plugin to make
scoping easy in WordPress projects.

The main issue with PHP Scoper is that it also scopes global functions, constants and classes. Usually, that is what you
want, but that also means that WordPress functions, classes and constants will be scoped. This Composer plugin solves
that. It has an up-to-date database of all WordPress and WooCommerce symbols that we want to keep unscoped.

## Requirements

**PHP 8.2 or newer.** See [CHANGELOG.md](CHANGELOG.md) for per-release notes.

Older lines, which are no longer maintained: `3.2.x` required PHP 8.1 (in practice 8.2 — its
declared constraint could not be satisfied), and `3.1.x` required PHP 7.4 or 8.0.

## Usage

1. This composer plugin is meant to be installed globally, but you can also require it as a dev dependency.
2. The configuration requires creating `composer-deps.json` file, that has exactly same structure like `composer.json`
   file, but serves only for scoped dependencies. Dependencies that you don't want to scope comes to `composer.json`.
3. Add `extra.wpify-scoper.prefix` to you `composer.json`, where you can specify the namespace, where your dependencies
   will be in. All other config options (`folder`, `globals`, `composerjson`, `composerlock`, `temp`, `autorun`) are
   optional.
4. The easiest way how to use the scoper on development environment is to install WPify Scoper as a dev dependency.
   After each `composer install` or `composer update`, all the dependencies specified in `composer-deps.json` will be
   scoped for you.
5. Add a `config.platform` option in your composer.json and composer-deps.json. This settings will make sure that the
   dependencies will be installed with the correct PHP version.

**Example of `composer.json` with its default values**

```json
{
  "config": {
    "platform": {
      "php": "8.2.0"
    },
    "allow-plugins": {
      "wpify/scoper": true
    }
  },
  "extra": {
    "wpify-scoper": {
      "prefix": "MyNamespaceForDeps",
      "folder": "deps",
      "globals": [
        "wordpress",
        "woocommerce",
        "action-scheduler",
        "wp-cli"
      ],
      "composerjson": "composer-deps.json",
      "composerlock": "composer-deps.lock",
      "autorun": true
    }
  }
}
```

`config.platform.php` should match the PHP version your site actually runs, and it must be one this
package supports (8.2 or newer). Setting it lower than your production PHP is how you end up with a
scoped tree that fatals on the server.

### Configuration reference

| Key | Default | What it does |
|---|---|---|
| `prefix` | *required* | The namespace your dependencies are moved into. Must be a valid PHP namespace: identifiers separated by `\\`, no leading or trailing separator. A missing or malformed prefix is now a hard error. |
| `folder` | `deps` | Where the scoped tree is written, relative to the project root (absolute paths are allowed). |
| `globals` | all four | Which shipped symbol lists to keep unscoped: `wordpress`, `woocommerce`, `action-scheduler`, `wp-cli`. An unknown name produces a warning and is ignored. |
| `composerjson` | `composer-deps.json` | The manifest describing the dependencies to scope. Only ever read, never written. |
| `composerlock` | `composerjson` with `.lock` | The lock file for that manifest. Written by the plugin — commit it. |
| `temp` | `tmp-` + random | The scratch workspace. Removed when the run succeeds; kept when it fails, because a failed swap parks your previous `deps/` in there. |
| `autorun` | `true` | Whether `composer install`/`composer update` also scope. Only a literal `false` turns it off. |

### Running it manually

```bash
composer wpify-scoper install    # install the locked scoped dependency set
composer wpify-scoper update     # re-resolve it and rewrite composer-deps.lock
composer wpify-scoper install --no-dev
```

`--no-dev` skips the `require-dev` block of your `composer-deps.json`, which is what you want for a
release build. When scoping runs automatically from `composer install`/`composer update`, it
inherits the dev mode of that command, so `composer install --no-dev` also scopes without dev
dependencies.

Set `"autorun": false` if you only ever want to scope on demand.

> The `wpify-scoper` binary (`vendor/bin/wpify-scoper install`) still works but is deprecated and
> prints a notice. Use the Composer command.

6. Scoped dependencies will be in `deps` folder of your project. You must include the scoped autoload alongside with the
   composer autoloader.

7. After that, you can use your dependencies with the namespace.

**Example PHP file:**

```php
<?php
require_once __DIR__ . '/deps/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

new \MyNamespaceForDeps\Example\Dependency();
```

### What to commit

- **`composer-deps.json`** — yes, it is your source of truth.
- **`composer-deps.lock`** — yes. It is what makes `composer wpify-scoper install` reproducible.
- **`deps/`** — your call. It is a build artifact, so most projects build it in CI (see
  *Deployment* below) and add it to `.gitignore`. Commit it if you deploy by pushing a git
  checkout to the server and cannot run Composer there.
- **`tmp-*`** — never. Add `tmp-*` to `.gitignore`. A successful run removes its workspace; a
  failed one keeps it on purpose, because a swap that could not be completed leaves your previous
  `deps/` in there. Delete it once you have recovered whatever you need.

## Deployment

### Deployment with Gitlab CI

To use WPify Scoper with Gitlab CI, you can add the following job to your `.gitlab-ci.yml` file:

```yaml
composer:
  stage: .pre
  image: composer:2
  artifacts:
    paths:
      - $CI_PROJECT_DIR/deps
      - $CI_PROJECT_DIR/vendor
    expire_in: 1 week
  script:
    - PATH=$(composer global config bin-dir --absolute --quiet):$PATH
    - composer global config --no-plugins allow-plugins.wpify/scoper true
    - composer global require wpify/scoper
    - composer install --prefer-dist --optimize-autoloader --no-ansi --no-interaction --no-dev
```

### Deployment with Github Actions

To use WPify Scoper with Github Actions, you can add the following action:

```yaml
name: Build vendor

jobs:
  install:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: ~/.cache/composer
          key: ${{ runner.os }}-${{ hashFiles('**/composer.lock', '**/composer-deps.lock') }}

      - run: composer global config --no-plugins allow-plugins.wpify/scoper true
      - run: composer global require wpify/scoper
      - run: composer install --no-dev --optimize-autoloader

      - name: Archive plugin artifacts
        uses: actions/upload-artifact@v4
        with:
          name: vendor
          path: |
            deps/
            vendor/
```

## Advanced configuration

PHP Scoper has plenty
of [configuration options](https://github.com/humbug/php-scoper/blob/master/docs/configuration.md#configuration). You
can modify this configuration array by creating `scoper.custom.php` file in root of your project. The file should
contain `customize_php_scoper_config` function, where the first parameter is the preconfigured configuration array. Expected output is
valid [PHP Scoper configuration array](https://github.com/humbug/php-scoper/blob/master/docs/configuration.md#configuration).

**Example `scoper.custom.php` file**

```php
<?php

function customize_php_scoper_config( array $config ): array {
    $config['patchers'][] = function( string $filePath, string $prefix, string $content ): string {
        if ( str_contains( $filePath, 'guzzlehttp/guzzle/src/Handler/CurlFactory.php' ) ) {
            $content = str_replace( 'stream_for($sink)', 'Utils::streamFor()', $content );
        }

        return $content;
    };

    return $config;
}
```

### Where `scoper.custom.php` is looked for

Exactly two places, in order:

1. **Your project root** — the directory holding the `composer.json` Composer resolved for this
   run. This is the one you want. It is correct under `--working-dir`, with a custom `vendor-dir`,
   with `COMPOSER=` pointing elsewhere, and for a global install of this plugin.
2. The plugin's own directory, so that a checkout of this repository keeps working.

Run with `-v` and the plugin tells you which file it picked up, or that it found none:

```
wpify-scoper: using the customizations from /srv/my-plugin/scoper.custom.php
```

Earlier releases picked between the two by looking for the literal string `vendor/wpify/scoper` in
the plugin's own path, which silently ignored your file whenever `vendor-dir` was renamed or the
plugin was symlinked in through a path repository.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `composer install` succeeds but there is no `deps/` folder, and no output | The plugin is not allowed to run | `composer config allow-plugins.wpify/scoper true` (or `composer global config --no-plugins allow-plugins.wpify/scoper true` for a global install). Composer silently skips plugins that are not allowed. |
| `extra.wpify-scoper.prefix is missing in …` | No prefix, or a typo in the key | Add a valid namespace. This used to be a silent no-op, which is why you may be seeing it for the first time on a project that "worked". |
| `… is not a valid PHP namespace` | Hyphens, spaces, a leading digit, or a leading/trailing `\` in the prefix | Use identifiers separated by `\\`, e.g. `MyPlugin\\Deps`. |
| `unknown extra.wpify-scoper.globals entry "…"` | A typo in `globals` | Valid values: `wordpress`, `woocommerce`, `action-scheduler`, `wp-cli`. A typo used to be ignored silently and produced a build that broke at runtime. |
| `"plugin-update-checker" is deprecated and ignored` | `globals` still lists it | Remove the line. PUC is now scoped like any other dependency; the list only ever held dead v4 class names. |
| `Class "…\WP_Query" not found` at runtime | A WordPress symbol got scoped: `globals` is missing `wordpress`, or your WordPress is newer than the symbol list | Add it to `globals`; update `wpify/scoper`. |
| A WooCommerce or PHPMailer class is not found after scoping | Fixed in 4.0 — namespace exclusions only matched exactly, so children of `Automattic\WooCommerce` and `PHPMailer\PHPMailer` came out prefixed | Upgrade and re-scope. |
| Your own vendor library collides with another plugin again after scoping | Fixed in 4.0 — prefix stripping was unanchored, so a vendor namespace starting with an excluded WordPress class name (`WPSEO\…`, `POBox\…`) was put back into the global namespace | Upgrade and re-scope. |
| `scoper.custom.php` seems to be ignored | A non-standard `vendor-dir`, or a path-repository install, in a release before 4.0 | Upgrade; then run with `-v` to see which file is loaded. |
| `tmp-XXXXXXXXXX/` left in the project root | The run failed or was killed mid-run | Check it for a `deps-backup-*` holding your previous `deps/`, then delete it. Add `tmp-*` to `.gitignore`. |
| `the Composer binary could not be located` | The pipeline was driven from the deprecated `bin/wpify-scoper` without `composer` on `PATH` | Use `composer wpify-scoper install`, or set `COMPOSER_BINARY`. |
| `php-scoper was not found` | `wpify/php-scoper` is missing from the install | Reinstall the plugin. The message lists every path that was tried. |
| `already running (WPIFY_SCOPER_RUNNING is set), skipping this nested invocation` | Your `composer-deps.json` also carries an `extra.wpify-scoper` block | Remove it. The scoped manifest must not configure the scoper. |
| A vendored library breaks after scoping | It builds class names dynamically, so php-scoper cannot see them | Write a patcher in `scoper.custom.php` — see *Advanced configuration*. |

Run any command with `-v` for the configuration the plugin resolved and every process it spawns,
and `-vvv` to see the nested Composer's own debug output.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — in particular for what `sources/` is, why `require-dev`
contains WordPress, and how to regenerate the symbol lists.
