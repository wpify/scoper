# Publishing to WordPress.org

If your plugin is going to the WordPress.org plugin directory, **write the scoped tree to
`vendor-prefixed/`** rather than to the default `deps/`. It is one configuration line, and it is the
difference between a submission that clears the automated gate and one that is blocked by errors
raised against code you did not write.

- [Why the location matters](#why-the-location-matters)
- [What Plugin Check skips](#what-plugin-check-skips)
- [The recommended layout](#the-recommended-layout)
- [Loading the autoloader](#loading-the-autoloader)
- [What the skip does and does not cover](#what-the-skip-does-and-does-not-cover)
- [Making sure the tree is actually shipped](#making-sure-the-tree-is-actually-shipped)
- [Checking it before you submit](#checking-it-before-you-submit)
- [Checklist](#checklist)

## Why the location matters

Since **1 October 2024**, every new plugin submitted to the WordPress.org directory is run through
[Plugin Check](https://wordpress.org/plugins/plugin-check/) before a human ever sees it. The
announcement is explicit about what happens next:

> If the new plugin has an error level item in this category, the submission will be blocked from
> being submitted for review, until it is fixed.
>
> — [Plugin Check and 2FA Now Mandatory For New Plugin Submissions](https://make.wordpress.org/plugins/2024/10/01/plugin-check-and-2fa-now-mandatory-for-new-plugin-submissions/)

Plugin Check is largely PHPCS with the WordPress rulesets behind it. Pointed at your own code that
is exactly what you want. Pointed at Guzzle, Monolog or the AWS SDK it is not: general-purpose PHP
libraries use `file_get_contents()`, `curl_*`, `fopen()`, `error_log()`, `base64_decode()` and
`serialize()` as a matter of course, none of which they can be talked out of, and none of which you
can fix — the code belongs to somebody else and any edit is undone by the next scoping run.

A scoped tree in `deps/` gets read as your code. The same tree in `vendor-prefixed/` — a directory
Plugin Check carries in its ignore list *for exactly this purpose* — is skipped entirely.

## What Plugin Check skips

Plugin Check keeps a list of directory names it never scans. As of the current release it is:

```php
$default_ignore_directories = array(
	'.git',
	'vendor',
	'node_modules',
	'vendor_prefixed',
	'vendor-prefixed',
);
```

— [`Plugin_Request_Utility::get_directories_to_ignore()`](https://github.com/WordPress/plugin-check/blob/trunk/includes/Utilities/Plugin_Request_Utility.php)

The same list is what the CLI documents:

> Additional directories to exclude from checks. By default, `.git`, `vendor`, `vendor_prefixed`,
> `vendor-prefixed` and `node_modules` directories are excluded.
>
> — [`wp plugin check --exclude-directories`](https://github.com/WordPress/plugin-check/blob/trunk/docs/CLI.md)

Three things follow from that list, and they are the whole reason this page exists:

- **`vendor-prefixed` and `vendor_prefixed` are on it.** They are on it precisely *because* they are
  the names the ecosystem settled on for scoped dependencies — [Yoast SEO's among
  them](https://yoast.com/developer-blog/safely-using-php-dependencies-in-the-wordpress-ecosystem/).
  Plugin Check carries them so that prefixed vendor code does not produce false positives. That is
  this exact use case, named in the tool.
- **`vendor` is on it too.** Anything under it, at any depth, is out of scope for the automated run.
- **`deps` is not on it.** This plugin's default output folder is scanned like the rest of your
  plugin. That default predates the mandatory check and is kept for compatibility; for a plugin
  headed to WordPress.org it is the wrong choice.

The list is filterable (`wp_plugin_check_ignore_directories`) and the CLI takes
`--exclude-directories`, but neither helps you here. WordPress.org runs the check on its own
infrastructure when you submit, so the only lever you have is where the files sit.

## The recommended layout

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

```
my-plugin/
  my-plugin.php
  composer.json
  composer-deps.json
  composer-deps.lock
  vendor/                   <- Composer's own, for your unscoped code
    autoload.php
    composer/
  vendor-prefixed/          <- the scoped tree
    scoper-autoload.php
    autoload.php
    guzzlehttp/
```

A sibling of `vendor/` rather than a directory inside it. That is the cleaner shape for three
reasons:

- **The two trees stay separate.** One is Composer's, one is the scoper's, and the scoper
  [replaces its own wholesale on every run](configuration.md#folder). Keeping them apart means
  there is no path Composer might one day install a package into, and no way to point `folder`
  somewhere that would take Composer's vendor directory with it.
- **The name says what it is.** Anyone reading your plugin — including a WordPress.org reviewer —
  knows what `vendor-prefixed/` holds without being told.
- **It survives build scripts that strip `/vendor`.** Plenty of release tooling excludes the
  Composer vendor directory by reflex; a scoped tree nested inside it disappears with it.

`vendor_prefixed` with an underscore is equally valid — it is on the same ignore list, and it is
what Yoast uses. Pick one and stay with it; do not use both.

Do not point `folder` at `vendor/` itself. The run would delete Composer's own vendor directory.

## Loading the autoloader

The location changes, the two-autoloader rule does not:

```php
<?php
/**
 * Plugin Name: My Plugin
 */

require_once __DIR__ . '/vendor-prefixed/scoper-autoload.php';
require_once __DIR__ . '/vendor/autoload.php';

add_action( 'init', function () {
	$client = new \MyPlugin\Deps\GuzzleHttp\Client();
} );
```

`scoper-autoload.php` pulls in its own `autoload.php`, so that is still one `require_once` for the
scoped tree — only the path in front of it moved. `vendor/autoload.php` is still needed for your own
unscoped code, and `add_action()` is still called unprefixed.

If you are moving an existing plugin, this line is the one thing that breaks silently. Grep for it:

```bash
grep -rn "deps/scoper-autoload.php" --include="*.php" .
```

## What the skip does and does not cover

Be clear about what you are buying. Plugin Check skipping `vendor-prefixed/` is a **scanner**
exemption, not a licence. The [plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
apply to everything in your zip, bundled libraries included, and a human reviewer reads what the
scanner passed over.

| | |
|---|---|
| **Covered** | The PHPCS-derived checks — escaping, sanitisation, alternative functions, development functions, direct database access, i18n — stop being raised against library code you cannot change. |
| **Not covered** | Licensing. Everything you ship must be GPL-compatible, third-party libraries included. |
| **Not covered** | Unmaintained or abandoned libraries. Those are refused on their own merits, wherever they sit. |
| **Not covered** | [Guideline 4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) — no obfuscated or unreadable code. Scoped output is ordinary readable PHP with the namespaces rewritten, so this is satisfied by default. Do not minify or strip the tree to save space. |
| **Not covered** | [Guideline 13](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) — do not bundle libraries WordPress already ships (jQuery, SimplePie, PHPMailer, PHPass, …). Scoping one does not make it acceptable; use the copy WordPress provides. |

Keep `composer.json` and `composer-deps.json` in the published zip. The reviewer guidance asks for
manifests to be left in place so the dependency set can be read, and yours is the only record of
what the scoped tree was built from.

Strip the development-only directories the
[common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/) page names —
`node_modules`, build tooling, tests, demos. Your scoped tree is not one of those: it is runtime
code and it has to ship.

## Making sure the tree is actually shipped

`vendor-prefixed/` is a build artifact like any other, so it has to be built before the deploy and
must not be filtered out of it. A release missing it fatals on activation with a missing class.

Three places to check:

**`.gitignore`** — this one **needs a new line**. `/vendor/` no longer covers the scoped tree now
that it is a sibling rather than a child:

```gitignore
/vendor/
/vendor-prefixed/
/tmp-*
```

Unless you deploy by pushing a git checkout, in which case commit the tree and see
[Deployment](deployment.md#deploying-by-git-push).

**`.distignore`** — used by [10up's deploy
action](https://github.com/10up/action-wordpress-plugin-deploy) and most WordPress.org release
scripts. Neither `/vendor` nor `/vendor-prefixed` belongs in it: the first holds Composer's
autoloader, the second holds your dependencies, and the plugin needs both at runtime.

```
/.git
/.github
/.wordpress-org
/node_modules
/tests
/tmp-*
.distignore
.gitignore
composer.lock
```

**`.gitattributes`** — the fallback when there is no `.distignore`. Same rule: no `export-ignore` on
either directory.

Then build before you deploy, so the directory exists at all:

```bash
composer install --no-dev --optimize-autoloader
```

That one command installs your unscoped dependencies into `vendor/` and, on the `post-install-cmd`
it triggers, writes the scoped tree into `vendor-prefixed/`. `--no-dev` propagates to the scoped
run, so the `require-dev` block of `composer-deps.json` is left out of the release.

## Checking it before you submit

Run the same tool WordPress.org will. Plugin Check is itself a WordPress plugin, so it installs into
a site and runs through WP-CLI:

```bash
wp plugin install plugin-check --activate
wp plugin check my-plugin
```

`wp plugin check` also takes a path or a zip, which is the form you want here — point it at the
**built** plugin, the directory as it will be published with `vendor/` and `vendor-prefixed/`
present, rather than at a clean checkout. A checkout with neither passes for the wrong reason:

```bash
wp plugin check /path/to/build/my-plugin
```

In a pipeline, use the [Plugin Check
action](https://github.com/marketplace/actions/wordpress-plugin-check) after your build step.

What you are looking for is that nothing in the report has a path under `vendor-prefixed/`. If
something does, the scoped tree is not where you think it is: re-read the
`wpify-scoper: prefix …, folder …` line that `composer install -v` prints.

## Checklist

- [ ] `folder` is `vendor-prefixed`, not `deps`
- [ ] the autoloader `require_once` points at the new path, and no stale `deps/` path is left in any PHP file
- [ ] `prefix` is a namespace nobody else would pick — your own vendor namespace with `\Deps` on the end
- [ ] `/vendor-prefixed/` is in `.gitignore` (unless you deploy by git push)
- [ ] `composer install --no-dev --optimize-autoloader` runs in the release build
- [ ] neither `/vendor` nor `/vendor-prefixed` is in `.distignore`, and neither carries `export-ignore` in `.gitattributes`
- [ ] `composer.json` and `composer-deps.json` are in the published zip
- [ ] `node_modules`, tests and build tooling are not
- [ ] `wp plugin check` on the built directory reports nothing under `vendor-prefixed/`
- [ ] every scoped library is currently maintained and GPL-compatible
- [ ] nothing scoped duplicates a library WordPress already ships

## See also

- [Configuration → `folder`](configuration.md#folder) — the setting itself
- [Deployment](deployment.md) — CI recipes, what to commit, multi-plugin repositories
- [Getting started](getting-started.md) — the full first-run walkthrough
