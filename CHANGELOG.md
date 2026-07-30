# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 4.0.0 have no entries here; the project had no changelog at the time. See the
[commit history](https://github.com/wpify/scoper/commits/master) for those.

## [4.0.0] - 2026-07-30

The first release with a changelog. It is a major version because the PHP requirement rises and
two long-standing behaviours change, but nothing in a correctly configured project needs editing
to upgrade.

**Anything that produces scoped output changed in this release. Re-run the scoper and test the
result before shipping it** — several of the fixes below alter which symbols end up prefixed.

### Fixed

- **Deleting through a symlink destroyed the target.** Replacing the deps folder removed the old
  one with a recursive delete that used `is_dir()`/`is_file()`, both of which follow symlinks. A
  project whose `deps/` was a symlink — or whose scoped tree contained one — had the link's target
  deleted instead of the link. The whole tree walk now checks `is_link()` first and never follows.
- **A failed swap could leave a project with no dependencies at all.** The old tree was deleted
  before the new one was in place. It is now moved aside into the temporary workspace and restored
  if the move fails, so a full disk or a permissions error can no longer lose the previous build.
  A failed run keeps that workspace instead of deleting it, because the backup lives in there.
- **Prefix stripping was unanchored and mangled third-party code.** The patcher used a plain
  `str_replace()` per excluded symbol, so any vendor namespace or class whose name *started* with
  an excluded WordPress symbol had the prefix stripped off it and was put straight back into the
  global namespace — the collision this package exists to prevent. `WPSEO\Utils` (via the WordPress
  class `WP`) and `POBox\Mailer` (via `PO`) are real examples. Matching is now anchored on both
  sides and on namespace segment boundaries.
- **Excluded namespaces only matched exactly.** `Automattic\WooCommerce` in `exclude-namespaces`
  did not cover `Automattic\WooCommerce\Internal\...`, so HPOS classes and
  `PHPMailer\PHPMailer\PHPMailer` came out prefixed and fatalled at runtime. A namespace exclusion
  now covers its whole subtree, still on a segment boundary: `Foo\Bar` never matches `Foo\Barbecue`.
- **An empty or unrecognised `globals` list crashed mid-scope.** `exclude-classes` and
  `exclude-namespaces` were only defined as a side effect of merging a symbol list, so a project
  that listed none got a `TypeError` from inside a php-scoper patcher. Both keys are now always
  present.
- **A missing or invalid `prefix` silently did nothing.** `composer install` exited 0, no `deps/`
  folder appeared, and there was no message. It is now a configuration error with an actionable
  message, and the prefix is validated as a PHP namespace. It is raised when the pipeline runs, not
  while the plugin activates: Composer does not guard plugin activation, so throwing there would
  break every command in the project — including the ones needed to fix the configuration.
- **`scoper.custom.php` was silently ignored in most installations.** The project root was located
  by looking for the literal string `vendor/wpify/scoper` in the plugin's own path, which fails for
  a custom `vendor-dir`, a symlinked path repository and a global install. The root is now taken
  from Composer, and the plugin reports which customization file it loaded.
- **`composer-deps.json` was rewritten on every run.** A `scripts` block full of absolute host
  paths was injected into the user's file, clobbering anything already there — a hand-maintained
  `pre-autoload-dump` in particular. The manifest is now only ever read.
- **A `tmp-*` directory was left in the project root whenever anything failed.** Cleanup lived in a
  generated child-process script, so it never ran on an error. It is now a `finally`.
- **`exit;` inside the plugin killed the host Composer process** with status 0 and no output,
  indistinguishable from success. Failures now throw with a message.
- **Constants declared inside function bodies were missing from the symbol lists**, which is where
  WordPress declares most of them (`wp_initial_constants()` and friends). Also fixed: classes in
  `else` branches, `class_alias()` targets, and braced `namespace { }` blocks.
- **Nothing was ever printed.** The plugin captured Composer's `IOInterface` and never used it, so
  every failure mode above presented as "nothing happened". It now reports what it is doing, and
  propagates verbosity, colour and interactivity to the processes it spawns.

### Changed

- **Requires PHP 8.2** (was `^8.1`, which was unsatisfiable: `wpify/php-scoper` requires `^8.2`, so
  a PHP 8.1 user got a resolver error naming a transitive package instead of a clear message).
- **The nested install and php-scoper run as subprocesses** instead of in-process
  `Composer\Console\Application` calls, which terminated the host process on completion because
  Symfony's console application defaults to `autoExit`. The subprocess uses the same PHP binary and
  the same Composer binary as the outer run, so the scoped set can never be resolved against a
  different PHP version than the one that resolved your `composer.json`.
- **Symbol lists regenerated** from current WordPress, WooCommerce, Action Scheduler and WP-CLI,
  and now carry a header recording which package version they came from.
- **Symbol lists are rendered as plain lists** rather than `var_export()` output with explicit
  integer keys, so adding one symbol no longer renumbers every line below it.
- Unknown entries in `extra.wpify-scoper.globals` now produce a warning naming the valid values.
  They used to be ignored silently, so `"wordpres"` produced a build that broke at runtime.
- **`extra.wpify-scoper.composerjson` is no longer read-only**, but the guarantee that replaced the
  3.x behaviour still holds: a *scoping run* never rewrites it. Only the new `require` and `remove`
  actions edit it, and they touch only the entries you named, leaving your key order, your
  formatting and every other block byte for byte as they were.
- `Scoper::run()` takes a `ScoperRequest` instead of `(string $command, bool $useDevDependencies)`,
  and no longer validates the action itself — `ScoperRequest` does that once, at the command line.
  `Scoper` is not documented as an extension point and is constructed only by `Plugin` and
  `ScoperCommand`, but the signature is public, so it is recorded here.

### Added

- **`composer wpify-scoper require` and `composer wpify-scoper remove`.** Add and drop scoped
  dependencies without hand-editing `composer-deps.json`. Each one resolves the change, updates the
  manifest and the lock, and rebuilds the scoped tree — there is no second command to run.
  Constraints are resolved against the *scoped* manifest, so its own `repositories` and
  `config.platform.php` decide the answer. Supports `--dev`, `-W`/`--with-all-dependencies`,
  `--fixed` (require only) and `--dry-run`. Available only on `composer wpify-scoper`, not on the
  deprecated `bin/wpify-scoper`.
- **A warning when a package is required both scoped and unscoped.** `composer wpify-scoper require`
  now says so when the package is also in the root `composer.json`, because both copies end up
  autoloaded and the unprefixed class name is back in the global namespace.
- **`composer wpify-scoper install|update [--no-dev]`**, a real Composer command. The plugin
  previously declared a `CommandProvider` capability pointing at a class that did not implement it,
  which would have thrown on every `composer list` had it ever been reached.
- **`--no-dev`.** The `*_NO_DEV_CMD` code paths existed since 2023 but nothing could emit them:
  `bin/wpify-scoper` mapped only `install` and `update`, and Composer never fires those event
  names. The flag now works, and `post-install-cmd`/`post-update-cmd` inherit the dev mode of the
  run that triggered them.
- A test suite: unit, golden-file and end-to-end tiers, run in CI across PHP 8.2–8.5.
- PHPStan (level 9, `phpstan-strict-rules`, no baseline), a PHP-CS-Fixer config and an
  `.editorconfig`.
- A scheduled workflow that regenerates the symbol lists when WordPress or WooCommerce release, and
  fails rather than opening a pull request if any symbol count drops — a silently truncated list is
  how a broken extractor would otherwise ship.
- `CONTRIBUTING.md`, and a troubleshooting section in the README.
- **[Publishing to WordPress.org](docs/wordpress-org.md).** WordPress.org has run every new
  submission through Plugin Check since October 2024, and an error blocks it. Plugin Check skips
  `vendor`, `vendor_prefixed` and `vendor-prefixed` but not `deps`, so the default output folder
  gets scanned and scoped libraries are reported as code you wrote — for calls no third-party
  library can be talked out of and you cannot patch. The new page recommends
  `"folder": "vendor-prefixed"` for published plugins — a name Plugin Check carries specifically so
  that prefixed dependencies do not raise false positives — gives the autoloader path that goes
  with it, is clear about what the skip does *not* cover (licensing, unmaintained libraries,
  bundling what WordPress already ships), and covers the two things that change with the move:
  `/vendor-prefixed/` needs its own `.gitignore` line, and no release filter may strip it. The
  default stays `deps` — this is documentation, not a behaviour change.
- **An update notification.** A scoping run now ends by telling you when a newer release of the
  plugin exists, and how to get it — a plain command for a patch or minor, a link to the upgrade
  guide for a major, since `composer update` cannot cross one. It reads the same public Packagist
  metadata Composer resolves against, sends nothing about you or your project, caches the answer
  for 24 hours, gives up after three seconds, and can never fail or delay a run. It is skipped
  entirely when the run is non-interactive, which is CI and Docker builds, and when
  `WPIFY_SCOPER_NO_UPDATE_CHECK` or `COMPOSER_DISABLE_NETWORK` is set. See
  [Update notifications](docs/configuration.md#update-notifications).

### Removed

- **`plugin-update-checker` as a `globals` entry.** The shipped list only ever held dead PUC v4
  class names, under a key the plugin neutralises anyway; PUC is now scoped like every other
  dependency. Listing the name is a warning and a no-op, not an error, so existing projects keep
  installing — remove the line at your convenience.
  **The `$checkerClass` patcher is retained**: `PucFactory::buildUpdateChecker()` builds its
  registry lookup key from a variable, which php-scoper does not prefix, and both the JSON and the
  VCS branch still have to be fixed up by hand or update checking fails with an `E_USER_ERROR`.
- `extra.textdomain` from this package's own `composer.json` — leftover debris referencing an
  unrelated package, inert but shipped to every consumer.

### Deprecated

- **`bin/wpify-scoper`.** Use `composer wpify-scoper install|update` instead. The binary still
  works and prints a notice; it will be removed in a future major.
- The `Plugin::SCOPER_*_CMD` constants and `Plugin::path()`, kept only because they have always
  been public.

[Unreleased]: https://github.com/wpify/scoper/compare/4.0.0...HEAD
[4.0.0]: https://github.com/wpify/scoper/compare/3.2.21...4.0.0
