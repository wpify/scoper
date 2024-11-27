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

* wpify/scoper:**3.1**
    * PHP 7.4 || 8.0
* wpify/scoper:**3.2**
    * PHP >= 8.1

## Usage

1. This composer plugin is meant to be installed globally, but you can also require it as a dev dependency.
2. The configuration requires creating `composer-deps.json` file, that has exactly same structure like `composer.json`
   file, but serves only for scoped dependencies. Dependencies that you don't want to scope comes to `composer.json`.
3. Add `extra.wpify-scoper.prefix` to you `composer.json`, where you can specify the namespace, where your dependencies
   will be in. All other config options (`folder`, `globals`, `composerjson`, `composerlock`, `autorun`) are optional.
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
      "php": "8.0.30"
    }
  },
  "scripts": {
    "wpify-scoper": "wpify-scoper"
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

6. Option `autorun` defaults to `true` so that scoping is run automatically upon composer `update` or `install` command.
   That is not what you want in all cases, so you can set it `false` if you need.
   To start prefixing manually, you need to add for example the line `"wpify-scoper": "wpify-scoper"` to the "scripts" section of your composer.json. 
   You then run the script with the command `composer wpify-scoper install` or `composer wpify-scoper update`.

7. Scoped dependencies will be in `deps` folder of your project. You must include the scoped autoload alongside with the
   composer autoloader.

8. After that, you can use your dependencies with the namespace.

**Example PHP file:**

```php
<?php
require_once __DIR__ . '/deps/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

new \MyNamespaceForDeps\Example\Dependency();
```

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
    runs-on: ubuntu-20.04

    steps:
      - name: Checkout
        uses: actions/checkout@v2

      - name: Cache Composer dependencies
        uses: actions/cache@v2
        with:
          path: /tmp/composer-cache
          key: ${{ runner.os }}-${{ hashFiles('**/composer.lock') }}

      - name: Install composer
        uses: php-actions/composer@v6
        with:
          php_extensions: json
          version: 2
          dev: no
      - run: composer global config --no-plugins allow-plugins.wpify/scoper true
      - run: composer global require wpify/scoper
      - run: sudo chown -R $USER:$USER $GITHUB_WORKSPACE/vendor
      - run: composer install --no-dev --optimize-autoloader

      - name: Archive plugin artifacts
        uses: actions/upload-artifact@v2
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
        if ( strpos( $filePath, 'guzzlehttp/guzzle/src/Handler/CurlFactory.php' ) !== false ) {
            $content = str_replace( 'stream_for($sink)', 'Utils::streamFor()', $content );
        }
        
        return $content;
    };
    
    return $config;
}
```
