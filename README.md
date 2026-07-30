# wpify/scoper

[![CI](https://github.com/wpify/scoper/actions/workflows/ci.yml/badge.svg)](https://github.com/wpify/scoper/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/wpify/scoper.svg)](https://packagist.org/packages/wpify/scoper)
[![Downloads](https://img.shields.io/packagist/dt/wpify/scoper.svg)](https://packagist.org/packages/wpify/scoper)
[![PHP](https://img.shields.io/packagist/dependency-v/wpify/scoper/php.svg)](https://packagist.org/packages/wpify/scoper)
[![License](https://img.shields.io/packagist/l/wpify/scoper.svg)](LICENSE)

A Composer plugin that moves your dependencies into a namespace of your own, so that your
WordPress plugin or theme cannot collide with anybody else's.

## The problem

WordPress loads every active plugin into one PHP process, and PHP has one global namespace. Your
plugin requires `guzzlehttp/guzzle` 7. Another plugin on the same site bundles Guzzle 6. Whichever
autoloader registers first wins, the other plugin gets a class it does not recognise, and something
fatals — on a site you do not control and cannot test against.

[PHP Scoper](https://github.com/humbug/php-scoper) solves this by rewriting your dependencies under
a prefix that nobody else will use. But it prefixes *everything* it can see, WordPress's own
functions and classes included, so a scoped plugin ends up calling
`MyPlugin\Deps\add_action()` — which does not exist.

This plugin is PHP Scoper wired up correctly for WordPress. It ships a generated database of every
symbol declared by WordPress, WooCommerce, Action Scheduler and WP-CLI, and keeps those unprefixed
while everything else moves into your namespace.

## Requirements

**PHP 8.2 or newer**, and Composer 2.6 or newer.

## Quickstart

Install the plugin globally, pinned to a major version:

```bash
composer global config --no-plugins allow-plugins.wpify/scoper true
composer global require wpify/scoper:^4.0
```

In your plugin, declare the dependencies you want scoped in `composer-deps.json` — same format as
`composer.json`, but it holds *only* the dependencies that get prefixed:

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

Then tell the plugin what namespace to use, in your ordinary `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "wpify/scoper": true
    }
  },
  "extra": {
    "wpify-scoper": {
      "prefix": "MyPlugin\\Deps"
    }
  }
}
```

Run Composer:

```bash
composer install
```

The scoped tree is written to `deps/`. Load its autoloader alongside Composer's own:

```php
<?php
require_once __DIR__ . '/deps/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

$client = new \MyPlugin\Deps\GuzzleHttp\Client();
```

That class is yours alone. The other plugin's Guzzle no longer matters.

Full walkthrough with the verification steps: **[Getting started](docs/getting-started.md)**.

## Publishing to WordPress.org?

**Write the scoped tree to `vendor-prefixed/` instead.** Since October 2024 every new plugin
submitted to the WordPress.org directory is run through
[Plugin Check](https://wordpress.org/plugins/plugin-check/) first, and an error blocks the
submission until it is fixed. Plugin Check skips `vendor-prefixed/` — it carries the name in its
ignore list precisely so that prefixed dependencies do not raise false positives — but it does scan
`deps/`, so a scoped Guzzle gets read as your code and reported for every `file_get_contents()` and
`curl_*` call in it, none of which you can fix.

```json
{
  "extra": {
    "wpify-scoper": {
      "prefix": "MyPlugin\\Deps",
      "folder": "vendor-prefixed"
    }
  }
}
```

```php
require_once __DIR__ . '/vendor-prefixed/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';
```

Add `/vendor-prefixed/` to `.gitignore`, and make sure your release build does not strip it. The
full picture — what the exemption does and does not cover, and the checklist to run before
submitting — is in **[Publishing to WordPress.org](docs/wordpress-org.md)**.

## Documentation

| | |
|---|---|
| **[Getting started](docs/getting-started.md)** | Install it and scope your first dependency, start to finish. |
| **[Configuration](docs/configuration.md)** | Every `extra.wpify-scoper` key, every command, every environment variable. |
| **[Troubleshooting](docs/troubleshooting.md)** | Symptom, cause, fix. Start here when something is wrong. |
| **[Publishing to WordPress.org](docs/wordpress-org.md)** | Why the scoped tree belongs in `vendor-prefixed/`, and the pre-submission checklist. |
| **[Deployment](docs/deployment.md)** | What to commit, CI recipes, Bedrock, multi-plugin repositories. |
| **[Customizing php-scoper](docs/customizing.md)** | `scoper.custom.php` and patchers, for dependencies that need help. |
| **[How it works](docs/how-it-works.md)** | The symbol lists, the pipeline, and why the scoped tree is swapped rather than written in place. |
| **[Upgrading to 4.0](docs/upgrading-to-4.md)** | Coming from 3.2 or earlier. |

## Contributing

Bug reports, symbol-list regressions and pull requests are welcome. See
[CONTRIBUTING.md](CONTRIBUTING.md) — in particular for what `sources/` is, why `require-dev`
contains WordPress, and how to regenerate the symbol lists.

Security issues should not go in a public issue. See [SECURITY.md](SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE). Copyright © 2021–2026 Daniel Mejta and contributors.
