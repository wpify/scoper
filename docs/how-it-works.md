# How it works

Background for when the defaults are not enough — or when something has gone wrong and you need to
know what the tool was trying to do.

- [Why scoping is necessary](#why-scoping-is-necessary)
- [Why php-scoper alone is not enough](#why-php-scoper-alone-is-not-enough)
- [The symbol lists](#the-symbol-lists)
- [The pipeline](#the-pipeline)
- [The fixups](#the-fixups)
- [The swap](#the-swap)
- [Design decisions worth knowing](#design-decisions-worth-knowing)

## Why scoping is necessary

WordPress loads every active plugin, the theme, and every must-use plugin into a single PHP
process. PHP has one global namespace and one class table per process. Two plugins that both
require `guzzlehttp/guzzle` are two plugins competing to define `GuzzleHttp\Client`.

Composer's autoloader does not arbitrate this. Whichever autoloader registers first resolves the
name, and the second one never gets asked. If the versions are compatible, nothing happens and
nobody notices. If they are not, the plugin that lost gets a class with the wrong methods, and the
site fatals — on somebody else's installation, triggered by a plugin you have never heard of.

You cannot solve this by pinning versions, because you do not control what else is installed. The
only reliable fix is to stop competing for the name: move your copy somewhere nobody else will
look.

## Why php-scoper alone is not enough

[php-scoper](https://github.com/humbug/php-scoper) rewrites a PHP tree so every symbol it declares
lives under a prefix. `GuzzleHttp\Client` becomes `MyPlugin\Deps\GuzzleHttp\Client`, and the
collision is gone.

The trouble is that it rewrites *references* too, and it cannot tell the difference between a name
your dependency declares and a name your dependency merely calls. So this:

```php
add_action( 'init', array( $this, 'boot' ) );
```

becomes this:

```php
\MyPlugin\Deps\add_action( 'init', array( $this, 'boot' ) );
```

which does not exist. The same happens to `WP_Query`, `ABSPATH`, `wp_remote_get()` and every other
name WordPress declares. A naively scoped WordPress plugin fatals on its first line of real work.

php-scoper's answer is `exclude-classes`, `exclude-functions`, `exclude-constants` and
`exclude-namespaces` — lists of names to leave alone. Which means the real problem is producing
those lists, for a codebase the size of WordPress, and keeping them current. That is what this
package is.

## The symbol lists

`symbols/*.php` holds every name declared by WordPress, WooCommerce, Action Scheduler and WP-CLI:

| List | Classes | Functions | Constants | Namespaces |
|---|---:|---:|---:|---:|
| `wordpress.php` | 528 | 4205 | 637 | 78 |
| `woocommerce.php` | 597 | 1020 | 7 | 374 |
| `action-scheduler.php` | 71 | 19 | 0 | 3 |
| `wp-cli.php` | 7 | 2 | 18 | 20 |

They are generated, never hand-written. `composer extract` walks the actual source of each project
— installed into `sources/` as a dev dependency of this package — with a full
[nikic/php-parser](https://github.com/nikic/PHP-Parser) AST traversal, and records what it
declares.

A full traversal rather than a top-level scan, because WordPress declares symbols in every shape
PHP allows: classes behind `class_exists()` guards, classes in `else` branches, `define()` calls
several levels inside a function body, `class_alias()` targets that exist nowhere else. Anything
the extractor misses is a symbol that gets prefixed, which is a site that fatals.

A scheduled workflow regenerates them every Monday and opens a pull request. It refuses to open one
if any count drops by more than 1%, because a parse failure that silently drops half of WordPress
would otherwise ship as a routine update.

The lists overlap heavily — WooCommerce bundles all of Action Scheduler, WP-CLI repeats a good part
of WordPress — so the merged result is de-duplicated before it reaches php-scoper.

## The pipeline

One run of the scoper, in order:

1. **Set `WPIFY_SCOPER_RUNNING`** and clear `COMPOSER` and `COMPOSER_VENDOR_DIR` from the
   environment. Both are inherited by child processes and both would send the nested Composer
   somewhere wrong.

2. **Create the workspace** — the `tmp-*` directory, with `source/` and `destination/` inside it.

3. **Write the manifest.** Your `composer-deps.json` is read, `extra.wpify-scoper` is stripped from
   the copy, and the result is written to `source/composer.json`. Your `composer-deps.lock` is
   copied to `source/composer.lock` if it exists. Your files are never modified.

4. **Assemble the php-scoper config.** The symbol lists named in `globals` are loaded, merged and
   de-duplicated into the `exclude-*` keys; your `scoper.custom.php` is copied in and given the
   last word. The result is written into the workspace.

5. **`composer install`** (or `update`) in `source/`, as a separate process, with
   `--optimize-autoloader` and — when asked for — `--no-dev`.

6. **`php-scoper add-prefix`** from `source/` into `destination/`.

7. **`composer dump-autoload --optimize`** in `destination/`, so the autoloader matches the
   rewritten class names.

8. **[Fixups](#the-fixups)** on the scoped tree.

9. **Publish the lock**, then **[swap](#the-swap)** `destination/vendor` into place as your `deps/`.

10. **Remove the workspace** — but only on success. A failed run keeps it, because your previous
    `deps/` is inside it.

Everything except the fixups happens in a subprocess. Nothing writes into your project until step 9.

## The fixups

Three things have to be corrected before the scoped tree is usable.

**Autoload file keys.** Composer dedupes `autoload.files` entries through
`$GLOBALS['__composer_autoload_files']`, keyed by an md5 of the package name and file path. The
scoped copy of a package produces the *same* key as the unscoped one, so whichever autoloader runs
second silently skips its own file — and a library whose bootstrap never ran fails in ways that
look nothing like a scoping problem. The keys in `autoload_static.php` get the prefix mixed in.

**Exposed symbols.** php-scoper's `scoper-autoload.php` can emit global aliases back to the
original names. That would undo the entire point, so those lines are commented out.

**Un-prefixing.** php-scoper prefixes first and consults `exclude-*` second, so the final patcher
walks the output and puts excluded symbols back. This matching is anchored on both sides and on
namespace segment boundaries. Before 4.0 it was a plain `str_replace()` per symbol, which meant any
vendor namespace *starting* with an excluded WordPress name had its prefix stripped and was pushed
back into the global namespace — `WPSEO\Utils` via the WordPress class `WP`, `POBox\Mailer` via
`PO`. The tool was recreating the collision it exists to prevent.

## The swap

`deps/` is replaced, not written into. The order matters:

1. Move the existing `deps/` aside, into `deps-backup-<pid>` **inside the workspace**.
2. Move the scoped tree into `deps/`.
3. If step 2 fails, move the backup back.
4. Delete the backup.

Before 4.0 the old tree was deleted before the new one was in place, so a full disk or a
permissions error mid-swap left a project with no dependencies at all.

Two details:

- **The backup lives in the workspace**, not next to `deps/`. `folder` is often a path inside
  `vendor/`, where a sibling `.bak` would be swept up by release builds and CI artifacts.
- **Symlinks are never followed.** `is_dir()` and `is_file()` both return true for a symlink to
  one, so a recursive delete that checks them deletes the link's *target*. A project whose `deps/`
  was a symlink had the target destroyed. Every tree walk now checks `is_link()` first.

When `rename()` cannot do the move — different filesystems report `EXDEV`, Windows fails on locked
files — it falls back to copy, verify every entry by type and size, then delete.

## Design decisions worth knowing

**Configuration errors are raised when the pipeline runs, not when the plugin activates.** Composer
does not guard plugin activation, so an exception thrown there aborts *every* command in the
project — including the `composer config` you would use to fix the configuration. The error is
carried until it is actionable.

**A project without `extra.wpify-scoper` is a complete no-op.** The plugin is usually installed
globally, which activates it for every Composer project on the machine, including `COMPOSER_HOME`
itself and the nested install this pipeline spawns.

**Re-entrancy is guarded by an environment variable, not a static flag.** The nested install is a
separate process, and it loads the same globally installed plugin. A static would not survive the
process boundary.

**`composer-deps.json` is only ever read.** It used to be rewritten on every run with a `scripts`
block full of absolute host paths, which clobbered anything you had put there.

**The generated symbol lists are validated on load,** not trusted. They are generated files; a
generator that broke halfway would otherwise hand php-scoper a config it fails on far downstream,
with an error pointing at neither.

## See also

- [Configuration](configuration.md) — the settings this pipeline reads
- [Customizing php-scoper](customizing.md) — hooking into step 4
- [CONTRIBUTING.md](../CONTRIBUTING.md) — regenerating the symbol lists, and the test tiers that
  cover all of the above
