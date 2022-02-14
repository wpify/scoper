# WPify Scoper - A scoper for the WordPress plugins and themes

Using Composer in your WordPress plugin or theme can benefit from that. But it also comes with a danger of conflicts with dependencies of other plugins or themes. Luckily, a great tool called [PHP Scoper](https://github.com/humbug/php-scoper) adds all your needed dependencies to your namespace to prevent conflicts. Unfortunately, the configuration is non-trivial, and for that reason, we created the Composer plugin to make scoping easy in WordPress projects.

The main issue with PHP Scoper is that it also scopes global functions, constants and classes. Usually, that is what you want, but that also means that WordPress functions, classes and constants will be scoped. This Composer solves that. It has an up-to-date database of all WordPress and WooCommerce symbols that we want to keep unscoped.

## Requirements

* MacOS or Linux operating system (Windows are not supported)
* PHP 7.4+

## Usage

1. The configuration requires creating `composer-deps.json` file, that has exactly same structure like `composer.json` file, but serves only for scoped dependencies. Dependencies that you don't want to scope comes to `composer.json`.

2. Add `extra.wpify-scoper.prefix` to you `composer.json`, where you can specify the namespace, where your dependencies will be in. All other config options (`folder`, `globals`, `composerjson`, `composerlock`) are optional. 

3. The easiest way how to use the scoper on development environment is to install WPify Scoper as a dev dependency. After each `composer install` or `composer update`, all the dependencies specified in `composer-deps.json` will be scoped for you.

**Example of `composer.json` with it's default values**

```json
{
  "require-dev": {
    "wpify/scoper": "^2.4"
  },
  "extra": {
	"wpify-scoper": {
	  "prefix": "MyNamespaceForDeps",
	  "folder": "deps",
	  "globals": ["wordpress", "woocommerce"],
	  "composerjson": "composer-deps.json",
	  "composerlock": "composer-deps.lock"
	}
  }
}
```

4. Scoped dependencies will be in `deps` folder of your project. You must include the scoped autoload alongside with the composer autoloader.

5. After that, you can use your dependencies with the namespace.

**Example PHP file:**

```php
<?php
use MyNamespaceForDeps\Example\Dependency;

require_once __DIR__ . '/deps/scoper-autoload.php';
include_once __DIR__ . '/vendor/autoload.php';

$example_dependency = new Dependency();

```

## Manual deployment

When you want to deploy the project, you need to scope your dependencies, but not to include dev depencencies. That can be achieved by following commands:

```bash
composer install --optimize-autoloader
composer install --no-dev --optimize-autoloader
```

The first command installs all the dependencies and run the scoper, the second command removes dev dependencies including the scoper. After that, you can deploy the files manually.

### Deployment with Gitlab CI

To use WPify Scoper with Gitlab CI, you can add the following job to your `.gitlab-ci.yml` file:

```yaml
composer:
  stage: build
  image: composer:2
  cache:
    paths:
      - .composer-cache/
  artifacts:
    paths:
      - ./vendor
      - ./deps
    expire_in: 1 week
  before_script:
    - PATH=$(composer global config bin-dir --absolute --quiet):$PATH
    - composer config -g cache-dir "$(pwd)/.composer-cache"
    - composer global require wpify/scoper:^2
  script:
    - composer install --no-dev --optimize-autoloader --ignore-platform-reqs
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
          php_version: 8.0
          php_extensions: json
          version: 2
          dev: yes
          progress: no
          args: --optimize-autoloader

      - name: Remove dev dependencies
        run: |
          composer install --no-dev --optimize-autoloader

      - name: Archive plugin artifacts
        uses: actions/upload-artifact@v2
        with:
          name: vendor
          path: |
            deps/
            vendor/
```
