# Contributing

Thanks for helping out. This document covers the two things that are not obvious from the code:
what `sources/` is, and how the symbol lists are produced.

## Getting set up

```bash
git clone https://github.com/wpify/scoper.git
cd scoper
composer install
```

`composer install` downloads about 160 MB into `sources/`. That is expected — see below.

## Why `require-dev` contains WordPress

`require-dev` is not test tooling. Most of it is the **input data for symbol extraction**:

| Package | What it is for |
|---|---|
| `johnpbloch/wordpress` | WordPress core, installed into `sources/wordpress` |
| `wpackagist-plugin/woocommerce` | WooCommerce, installed into `sources/plugin-woocommerce` |
| `woocommerce/action-scheduler` | Action Scheduler, installed into `sources/plugin-action-scheduler` |
| `wp-cli/wp-cli` | WP-CLI, read from `vendor/wp-cli/wp-cli` |
| `nikic/php-parser` | Parses all of the above |
| `jetbrains/phpstorm-stubs` | Editor support while working on the extractor |

The install locations come from `extra.wordpress-install-dir` and `extra.installer-paths` in
`composer.json`. `sources/` and `vendor/` are both git-ignored and never ship.

None of this reaches consumers: Composer ignores a dependency's `require-dev`, `repositories`,
`config`, `minimum-stability` and `scripts`. The only sections of this package's `composer.json`
that affect a consumer are `require` and `extra.class`.

The rest of `require-dev` — PHPUnit, PHPStan, PHP-CS-Fixer — is what you would expect.

## The symbol lists

`symbols/*.php` is the point of this package: the database of names that must **not** be moved into
the consumer's prefix, because WordPress declares them and the consumer's plugin calls them.

They are generated. Do not edit them by hand — the header says so, and the next regeneration would
discard your change.

```bash
composer update    # pull the current WordPress / WooCommerce / Action Scheduler / WP-CLI
composer extract   # rewrite symbols/*.php from them
```

`composer extract` exits non-zero if any source file failed to parse. Take that seriously: a parse
failure drops symbols, a symbol missing from the WordPress list gets scoped, and the consumer's
site then fatals on a call to an undefined function. That is the worst failure this project has.

Before opening a regeneration pull request, check the counts did not collapse:

```bash
php scripts/symbol-guard.php snapshot /tmp/before.json
composer extract
php scripts/symbol-guard.php compare /tmp/before.json 1.0
```

The scheduled `Refresh symbols` workflow does exactly this every Monday and refuses to open a pull
request when a count drops by more than 1%.

`symbols/*.php` is marked `linguist-generated` in `.gitattributes`, so GitHub collapses the diff.
Review the counts, not the lines.

### How extraction works

`scripts/SymbolExtractor.php` walks every PHP file in a source tree and records what it declares.
It is a full AST traversal rather than a top-level scan, because WordPress declares symbols in
every shape PHP allows: classes behind `class_exists()` guards, classes in `else` branches,
`define()` calls several levels inside a function body, `class_alias()` targets that exist nowhere
else. Files under `vendor/`, `wp-content/`, `tests/`, `spec/`, `features/` and `.github/` are
skipped — their symbols are never loaded in a WordPress request, so excluding them would only stop
the consumer's own dependencies from using those names.

`scripts/extract-symbols.php` is a thin CLI over that class. Everything under `scripts/` is
development-only and is `export-ignore`d from dist archives.

## Tests

```bash
composer test              # unit + golden-file, milliseconds
composer test:integration  # end-to-end, spawns Composer and php-scoper
composer test:all
```

Three tiers:

- **`tests/Unit/`** — pure logic. `ConfigurationTest` and `ScoperConfigFactoryTest` cover the
  config surface; `SymbolUnprefixerTest` covers the patcher that decides which symbols come back
  out of the prefix, which is where a mistake silently breaks somebody's production site.
- **The golden-file test** — `SymbolExtractorTest` runs the extractor over
  `tests/fixtures/symbols-input/` and compares the rendered output to
  `tests/fixtures/symbols-expected.php`. When you change the extractor deliberately:

  ```bash
  UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter golden
  ```

  Then read the diff. That diff is the whole value of the test.
- **`tests/Integration/`** — marked `#[Group('integration')]` and excluded from the default suite.
  Runs the real pipeline against `tests/fixtures/e2e/`, a self-contained path repository, and
  asserts on the scoped bytes. It never touches the network: `packagist.org` is disabled in the
  fixture's manifest.

If you add a fixture package, keep it offline. A test that resolves from Packagist is a test that
fails on a train.

## Static analysis and style

```bash
composer analyse   # PHPStan level 9 + phpstan-strict-rules, no baseline
composer cs        # check
composer cs:fix    # fix
```

There is no baseline, and there should not be one. If PHPStan finds something, it found something.

### On the code style

The codebase is written in a WordPress-ish dialect: tabs, a space inside every bracket
(`function foo( $bar ) {`), `array()` rather than `[]`, Yoda comparisons. That is unusual for a
Composer plugin, whose reviewers come from the PSR world and whose entire dependency surface
(`composer/composer`, `symfony/console`) is PSR-12.

`.php-cs-fixer.dist.php` enforces the style that is actually there rather than replacing it. It
covers imports, whitespace, casing, Yoda comparisons and docblocks, and deliberately leaves brace
placement, bracket spacing and array syntax alone, because PHP-CS-Fixer cannot express the
WordPress variants of those rules.

**What a PSR-12 migration would cost, if anyone wants to do it:** essentially every line of every
PHP file is reformatted — bracket spacing alone touches almost all of them. `git blame` on `src/`
becomes useless unless the migration commit is added to `.git-blame-ignore-revs`, and every open
branch conflicts. The offsetting argument is that PHP-CS-Fixer's `@PHP82Migration` ruleset performs
the `array()` → `[]` conversion and several other modernisations mechanically, so the reformat
commit and the modernise commit can be the same commit — you pay the blame cost once. If you do
it: one isolated commit, no logic changes, and add the SHA to `.git-blame-ignore-revs` in the same
pull request.

## Before opening a pull request

```bash
composer validate --strict
composer analyse
composer test
composer test:integration
```

CI runs all of it across PHP 8.2, 8.3, 8.4 and 8.5, plus a smoke job that installs the plugin into
a scratch project — the only tier that catches "the plugin throws during `activate()`".

## Changing what lands in `deps/`

Anything that changes the generated output is at least a minor release, not a patch, even when the
diff is three characters. Consumers cannot see that their scoped tree changed until something
fatals in production. Say so in `CHANGELOG.md`.
