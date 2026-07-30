# Troubleshooting

Start with the table. If your symptom is not in it, run the command again with `-v` — the scoper
prints the configuration it resolved and every process it spawns, and that is usually the answer.

If you are coming from 3.2 or earlier, several long-standing failure modes were fixed in 4.0 rather
than worked around. Check [Upgrading to 4.0](upgrading-to-4.md) before debugging.

## Quick reference

| Symptom | Cause | Fix |
|---|---|---|
| `composer install` succeeds, no `deps/` folder, no output at all | The plugin is not allowed to run | `composer config allow-plugins.wpify/scoper true`, or `composer global config --no-plugins allow-plugins.wpify/scoper true` for a global install. Composer skips disallowed plugins silently. |
| `extra.wpify-scoper.prefix is missing in …` | No prefix, or a typo in the key | Add a valid namespace. See [`prefix`](configuration.md#prefix). |
| `… is not a valid PHP namespace` | Hyphens, spaces, a leading digit, or a leading/trailing `\` in the prefix | Use identifiers separated by `\\`, e.g. `MyPlugin\\Deps`. |
| `unknown extra.wpify-scoper.globals entry "…"` | A typo in `globals` | Valid values: `wordpress`, `woocommerce`, `action-scheduler`, `wp-cli`. |
| `"plugin-update-checker" is deprecated and ignored` | `globals` still lists it | Remove the line. PUC is scoped like any other dependency now. |
| `composerlock points at the manifest …` | `composerjson` and `composerlock` resolve to the same file | Point `composerlock` somewhere else. The run publishes the lock over that path and would destroy your manifest. |
| `Class "…\WP_Query" not found` at runtime | A WordPress symbol got scoped | See [A WordPress symbol got prefixed](#a-wordpress-symbol-got-prefixed). |
| `no extra.wpify-scoper block in composer.json, nothing to scope` | You ran `composer wpify-scoper` in a project that does not configure the scoper | Run it from the project root, or add the block. |
| `unknown action "…", expected install or update` | A typo in the command | `composer wpify-scoper install` or `composer wpify-scoper update`. |
| `tmp-XXXXXXXXXX/` left in the project root | The run failed or was killed | See [A `tmp-` directory was left behind](#a-tmp--directory-was-left-behind). |
| `php-scoper was not found` | `wpify/php-scoper` is missing from the install | Reinstall the plugin. The message lists every path that was tried. |
| `the Composer binary could not be located` | The deprecated `bin/wpify-scoper` was driven from a pipeline with no `composer` on `PATH` | Use `composer wpify-scoper install`, or set `COMPOSER_BINARY`. |
| `already running (WPIFY_SCOPER_RUNNING is set), skipping this nested invocation` | A scoping run was triggered from inside a scoping run | See [Nested invocation warning](#nested-invocation-warning). |
| A vendored library breaks after scoping | It builds class names dynamically, so php-scoper cannot see them | Write a patcher — see [Customizing php-scoper](customizing.md). |
| Plugin Check reports errors inside the scoped tree, or a WordPress.org submission is blocked by them | The scoped tree is in `deps/`, which Plugin Check scans | See [Plugin Check flags my scoped dependencies](#plugin-check-flags-my-scoped-dependencies). |
| `Class "MyPlugin\Deps\…" not found` on a WordPress.org release only | The release build stripped `vendor/`, and the scoped tree was inside it | See [The scoped tree is missing from the release](#the-scoped-tree-is-missing-from-the-release). |
| Two developers get different scoped output from the same commit | Different scoper versions | The scoper version is not in any lock file. Pin the same constraint everywhere; see [Installing](configuration.md#installing). |

## Nothing happens

The single most common report, and it is almost never the scoper.

```bash
composer install
# ...no wpify-scoper line, no deps/ folder, exit code 0
```

Composer refuses to load plugins that have not been explicitly allowed, and it says nothing when it
does so. Check:

```bash
composer config allow-plugins.wpify/scoper
```

If that is not `true`, set it — for a global install, in the global config:

```bash
composer global config --no-plugins allow-plugins.wpify/scoper true
```

If the plugin *is* allowed and still nothing happens, the second cause is a missing
`extra.wpify-scoper` block. The scoper deliberately stays a complete no-op in projects that do not
configure it, because a global install activates it for every project on the machine.

Third: `"autorun": false`. Run `composer wpify-scoper install` explicitly.

Confirm all three at once with `-v`. If the configuration line does not appear, the plugin is not
running; if it does, the plugin is running and the problem is later in the pipeline.

## A WordPress symbol got prefixed

```
PHP Fatal error: Uncaught Error: Class "MyPlugin\Deps\WP_Query" not found
```

Something WordPress declares was moved into your namespace. Three causes:

1. **`globals` is missing `wordpress`.** Check the block; the default includes it, so this only
   happens when the key was set by hand.
2. **A typo in `globals`.** `"wordpess"` produces a warning and is then ignored, leaving you with
   no WordPress exclusions at all. Re-read the install output.
3. **Your WordPress is newer than the shipped symbol list.** The lists are regenerated weekly, but
   a symbol added in a WordPress release newer than your scoper will be prefixed. Update
   `wpify/scoper` and re-scope.

For a symbol that is genuinely missing from the lists, open an issue — that is a bug in the symbol
extraction, and it is the most serious class of bug this project has.

For a symbol WordPress does not declare but you still need unprefixed, use
[`scoper.custom.php`](customizing.md).

## Plugin Check flags my scoped dependencies

```
FILE: deps/guzzlehttp/guzzle/src/Handler/CurlFactory.php
ERROR  curl_setopt() is discouraged. Use wp_remote_get() instead.
```

Plugin Check never scans `vendor`, `vendor_prefixed` or `vendor-prefixed`. It does scan `deps`,
which is this plugin's default output folder, so a scoped Guzzle is read as code you wrote.

There is nothing to fix in the library — any edit is undone by the next run. Move the tree instead:

```json
"folder": "vendor-prefixed"
```

then update the `require_once` in your plugin file to match, add `/vendor-prefixed/` to
`.gitignore`, and re-scope. Since October 2024 an error here blocks a new WordPress.org submission
outright, so this is worth getting right before you submit rather than after.
[Publishing to WordPress.org](wordpress-org.md) covers the move and what the exemption does not
buy you.

## The scoped tree is missing from the release

```
PHP Fatal error: Uncaught Error: Class "MyPlugin\Deps\GuzzleHttp\Client" not found
```

on a published build, when the same commit works locally. The scoped tree is a build artifact and
the release does not have it. Two causes:

1. **The build never ran.** WordPress.org SVN and plain git deploys do not run Composer. The
   release step has to `composer install --no-dev --optimize-autoloader` first — that populates
   `vendor/` and fires the `post-install-cmd` that writes the scoped tree.
2. **Something stripped the directory.** `.distignore`, `export-ignore` in `.gitattributes`, an
   `rsync --exclude`, a `zip -x`. `vendor-prefixed/` has to ship, and so does `vendor/` — the
   plugin needs Composer's autoloader too.

Confirm by listing the published artifact rather than your working copy:

```bash
unzip -l my-plugin.zip | grep scoper-autoload
```

## A `tmp-` directory was left behind

A successful run removes its workspace. A failed one keeps it on purpose:

```
wpify-scoper: the run failed, the workspace /srv/my-plugin/tmp-a1b2c3d4e5 was kept
- remove it once you no longer need it.
```

Inside it you may find `deps-backup-<pid>/`, which holds your **previous** `deps/` folder. The swap
moves the old tree aside before installing the new one, so an interrupted run does not leave you
with no dependencies at all.

```bash
ls tmp-a1b2c3d4e5/
# deps-backup-41234/  destination/  source/  scoper.inc.php  scoper.config.php
```

Recover whatever you need, then delete the directory. Add `tmp-*` to `.gitignore` so a leftover
never gets committed.

## Nested invocation warning

```
wpify-scoper: already running (WPIFY_SCOPER_RUNNING is set), skipping this nested invocation.
Remove extra.wpify-scoper from your scoped manifest to silence this.
```

The scoper sets `WPIFY_SCOPER_RUNNING` for the duration of a run, so the nested Composer — which
loads the globally installed copy of the plugin — cannot start a run of its own. Seeing this
warning means something asked for a second run from inside the first.

Usually: your `composer-deps.json` carries an `extra.wpify-scoper` block. It should not. Remove it.

## The scoped tree changed and something broke

Any release that changes what lands in `deps/` can break a site with no warning inside your
project — nothing records which scoper version produced the tree.

When output changes unexpectedly:

1. `composer global show wpify/scoper` (or `composer show wpify/scoper`) on both machines.
2. Compare against [CHANGELOG.md](../CHANGELOG.md). Anything that alters generated output is at
   least a minor release.
3. Re-scope everywhere and test the result before shipping it.

Pin the same constraint on every machine and in CI. See [Installing](configuration.md#installing).

## Still stuck

Run the failing command with `-vvv` and open an issue with:

- the `wpify/scoper` version and how it is installed (global or `require-dev`)
- your PHP version
- your `extra.wpify-scoper` block
- your `composer-deps.json`
- the full `-vvv` output

<https://github.com/wpify/scoper/issues>

Security issues do not belong in a public issue — see [SECURITY.md](../SECURITY.md).
