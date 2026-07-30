# Configuration

Everything the scoper reads: the settings block, the commands, and the environment variables.

- [Installing](#installing)
- [The `extra.wpify-scoper` block](#the-extrawpify-scoper-block)
- [Settings reference](#settings-reference)
- [The scoped manifest](#the-scoped-manifest)
- [Commands](#commands)
- [Environment variables](#environment-variables)
- [Verbose output](#verbose-output)

## Installing

### Globally

The recommended install. One copy serves every plugin you work on.

```bash
composer global config --no-plugins allow-plugins.wpify/scoper true
composer global require wpify/scoper:^4.0
```

Pin the constraint, and pin the *same* constraint on every machine and in CI. The scoper version
that produced a scoped tree is not recorded in `composer.lock`, `composer-deps.lock` or anywhere
else, so nothing will tell you that two machines built different output. Re-scope after upgrading
the scoper: a release that changes what lands in `deps/` cannot announce itself inside your project.

### Installing as a dev dependency

```bash
composer require --dev wpify/scoper:^4.0
composer config allow-plugins.wpify/scoper true
```

This pins the scoper in your `composer.lock` like any other dependency, which is what you want for
a project with more than one developer or for byte-reproducible builds. The cost is a copy per
project and a slower `composer install`.

A project may have both a global and a local install. The local one wins for that project — it is
the copy Composer loads — but keeping two constraints in sync by hand is a good way to produce
output nobody can explain. Pick one.

## The `extra.wpify-scoper` block

The presence of this block is what turns the plugin on. A project without it is a complete no-op,
which matters because a global install activates the plugin for **every** Composer project on the
machine.

```json
{
  "extra": {
    "wpify-scoper": {
      "prefix": "MyPlugin\\Deps",
      "folder": "deps",
      "globals": [ "wordpress", "woocommerce", "action-scheduler", "wp-cli" ],
      "composerjson": "composer-deps.json",
      "composerlock": "composer-deps.lock",
      "autorun": true
    }
  }
}
```

Those are the defaults, spelled out. Only `prefix` has to be there.

Do not put an `extra.wpify-scoper` block in your **scoped** manifest. It configures the scoper, not
the dependencies being scoped, and the run strips it before resolving anyway.

## Settings reference

### `prefix`

**Required. No default.**

The namespace your dependencies are moved into.

Must be a valid PHP namespace: identifiers separated by backslashes, no leading or trailing
separator. In JSON every backslash is doubled, so `MyPlugin\Deps` is written `"MyPlugin\\Deps"`.

```json
"prefix": "MyPlugin\\Deps"
```

A missing or malformed prefix is a hard error, raised when the pipeline runs:

```
wpify-scoper: extra.wpify-scoper.prefix is missing in /srv/my-plugin/composer.json.
Set it to a valid PHP namespace, for example "MyPlugin\Deps".
```

It is raised at run time rather than when the plugin activates on purpose. Composer does not guard
plugin activation, so throwing there would break every command in the project — including the
`composer config` you would reach for to fix it.

Choose a prefix nobody else will choose. Your own vendor namespace with `\Deps` appended is a safe
default; a bare `Deps` or `Vendor` is not.

### `folder`

**Default: `deps`**

Where the scoped tree is written, relative to your project root. Absolute paths are accepted.

```json
"folder": "web/app/deps"
```

The directory is replaced wholesale on every run. Do not keep anything of your own in it.

Common non-default values: `web/app/deps` on Bedrock, or a subdirectory of `vendor/` such as
`vendor/my-plugin-deps`. See [Deployment](deployment.md).

### `globals`

**Default: `[ "wordpress", "woocommerce", "action-scheduler", "wp-cli" ]`**

Which of the shipped symbol lists stay **unscoped** — the names WordPress and friends already
declare, which your plugin calls and which therefore must not be prefixed.

Despite the name, this does not list global variables, and it is not a list of your own symbols.

| Value | Keeps unprefixed |
|---|---|
| `wordpress` | WordPress core: `add_action()`, `WP_Query`, `ABSPATH`, … |
| `woocommerce` | WooCommerce, including the `Automattic\WooCommerce` namespace tree |
| `action-scheduler` | Action Scheduler |
| `wp-cli` | WP-CLI |

Trimming the list makes the generated php-scoper config smaller; it does not make scoping faster in
any way you will notice. Leave the default unless you have a reason.

An unknown entry is a warning, not an error, and is ignored:

```
wpify-scoper: unknown extra.wpify-scoper.globals entry "wordpess", ignoring it.
Known values: action-scheduler, woocommerce, wordpress, wp-cli.
```

Take that warning seriously. Before 4.0 a typo here was silent, and the result was a scoped tree
that fatalled at runtime on the first WordPress call.

`plugin-update-checker` used to be a valid entry. It now warns and is ignored — the list only ever
held dead PUC v4 class names, and PUC is scoped like any other dependency. Remove the line.

An empty list, or a value that is not a list, falls back to the defaults. An empty `globals` has
never meant "scope everything".

### `composerjson`

**Default: `composer-deps.json`**

The manifest describing the dependencies to scope. Relative to the project root; absolute paths
accepted.

It is **only ever read**. Before 4.0 the run rewrote it on every invocation with a `scripts` block
full of absolute host paths, clobbering anything you had written there.

If the file does not exist, an empty one (`{"require": {}}`) is created for you.

### `composerlock`

**Default: the value of `composerjson` with `.json` replaced by `.lock`**

The lock file for the scoped manifest. A `composerjson` that does not end in `.json` gets `.lock`
appended instead.

This file **is written** by the run. Commit it — it is what makes `composer wpify-scoper install`
reproducible.

Pointing it at the manifest is a hard error, because the run would overwrite the file your whole
dependency set is declared in:

```
wpify-scoper: extra.wpify-scoper.composerlock points at the manifest
/srv/my-plugin/composer-deps.json, which the run would overwrite. Set it to a different file.
```

### `temp`

**Default: `tmp-` followed by ten random hex characters**

The scratch workspace. The nested Composer install, the php-scoper output and the backup of your
previous `deps/` all live in here for the duration of a run.

Removed when the run succeeds. **Kept when it fails**, deliberately: a swap that could not be
completed parks your previous `deps/` in `deps-backup-<pid>` inside it, and deleting the workspace
on error would destroy the tree the error message tells you how to recover.

Add `tmp-*` to `.gitignore`.

### `autorun`

**Default: `true`**

Whether `composer install` and `composer update` also scope.

Only a literal `false` turns it off. Any other value — `0`, `"false"`, `null` — leaves it on.

```json
"autorun": false
```

With `autorun` off, scoping happens only when you run [`composer wpify-scoper`](#commands)
explicitly. When it is on, the scoped run inherits the dev mode of the command that triggered it,
so `composer install --no-dev` scopes without the dev dependencies of your scoped manifest.

## The scoped manifest

`composer-deps.json` is an ordinary Composer manifest. Everything you declare in it is passed
through to the nested install untouched — `require`, `require-dev`, `repositories`, `scripts`,
`config`, all of it. Two notes:

- **Set `config.platform.php`** to the PHP version your site runs. The nested install resolves
  against it, and a value lower than production produces a scoped tree that fatals on the server.
- **Do not add `extra.wpify-scoper`.** It is stripped before the nested install runs, because a
  nested root package that configures the scoper would recurse.

## Commands

```bash
composer wpify-scoper install    # install the locked scoped dependency set
composer wpify-scoper update     # re-resolve it and rewrite composer-deps.lock
composer wpify-scoper install --no-dev
```

The mapping is the one you already know from Composer: `install` honours `composer-deps.lock`,
`update` ignores it and writes a new one.

`--no-dev` skips the `require-dev` block of your **scoped** manifest. That is what you want for a
release build.

### The deprecated binary

```bash
vendor/bin/wpify-scoper install
```

Still works, prints a deprecation notice on every run, and will be removed. It needs `composer` on
`PATH` (or [`COMPOSER_BINARY`](#environment-variables) set) where the Composer command does not.
Use `composer wpify-scoper` instead.

## Environment variables

| Variable | Read by | Meaning |
|---|---|---|
| `COMPOSER_BINARY` | the scoper | Path to the Composer executable used for the nested install. Set by Composer itself; only worth setting by hand when driving the deprecated binary from a pipeline that has no `composer` on `PATH`. |
| `COMPOSER` | Composer | Names the manifest Composer reads. **Cleared for the duration of a run** and restored afterwards, because the nested Composer would otherwise look for your `composer-deps.json` inside the temporary workspace. |
| `COMPOSER_VENDOR_DIR` | Composer | Names the directory Composer installs into. Also **cleared for the duration of a run** — php-scoper's finder only ever looks at the workspace's own `vendor/`. |
| `WPIFY_SCOPER_RUNNING` | the scoper | Set to `1` while a run is in progress. It is how the nested Composer — which loads the globally installed copy of this plugin — knows not to start a run of its own. Do not set it yourself. |
| `WPIFY_SCOPER_NO_UPDATE_CHECK` | the scoper | Set to any non-empty value to switch off the [update notification](#update-notifications). |
| `COMPOSER_DISABLE_NETWORK` | Composer | Composer's own offline switch. The update check honours it and does not run. |

Exporting `COMPOSER` or `COMPOSER_VENDOR_DIR` in your shell used to break scoping for that one
developer and nobody else. It no longer does.

## Update notifications

When a scoping run finishes, the plugin tells you if a newer version of itself has been released:

```
wpify-scoper: version 4.0.1 is available, you have 4.0.0.
wpify-scoper: update with "composer global update wpify/scoper".
```

Crossing a major version links the upgrade guide instead of a command, because
`composer update` cannot cross a major on its own.

### What it does on the network

One unauthenticated `GET` to `https://repo.packagist.org/p2/wpify/scoper.json`, the same public
package metadata Composer itself reads when resolving this package. **Nothing about you, your
project or your dependencies is sent** — it is a plain request for a file, with no query string
and no request body.

The answer is cached in Composer's own cache directory for 24 hours, so this happens at most once
a day per machine no matter how many projects you scope or how often. Only a successful lookup
resets that clock, so being offline in the morning does not cost you the check in the afternoon.

The request has a three-second timeout and is capped in size. If it fails for any reason —
offline, a proxy, Packagist having a bad day — the run is entirely unaffected and nothing is
printed. Run with `-v` to see why it failed.

Packagist rather than GitHub's tag API on purpose: a version on Packagist is one you can actually
install, and GitHub's unauthenticated API is rate limited per IP in a way that breaks on shared
office and CI addresses. See [ADR 0001](adr/0001-packagist-not-github-tags-as-the-release-source.md).

### Turning it off

The check is skipped automatically whenever the run is **non-interactive** — no TTY, or
`--no-interaction`. That covers CI, Docker builds and deploy scripts without any configuration,
which is where the notice would be noise nobody can act on anyway.

To switch it off everywhere else:

```bash
export WPIFY_SCOPER_NO_UPDATE_CHECK=1
```

`COMPOSER_DISABLE_NETWORK` also suppresses it.

## Verbose output

```bash
composer install -v      # resolved configuration, and every process the scoper spawns
composer install -vvv    # the above, plus the nested Composer's own debug output
```

`-v` prints the configuration the plugin actually resolved:

```
wpify-scoper: prefix "MyPlugin\Deps", folder "/srv/my-plugin/deps",
/srv/my-plugin/composer-deps.json / /srv/my-plugin/composer-deps.lock, temp "/srv/my-plugin/tmp-a1b2c3d4e5"
```

and which customization file was picked up, or that none was found. It is the first thing to run
when the scoper is not doing what you expect.
