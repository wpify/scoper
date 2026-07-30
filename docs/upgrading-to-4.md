# Upgrading to 4.0

**Nothing in a correctly configured project needs editing to upgrade.** But 4.0 changes what lands
in `deps/`, and it turns two previously silent failures into errors — so a project that appeared to
work may start telling you it never did.

> **Re-scope and test the result before shipping it.** Several fixes below alter which symbols end
> up prefixed. That is the point of the release, and it is not something your project can detect on
> its own.

[Full changelog](../CHANGELOG.md)

## 1. Bump the constraint

If you install the scoper globally — most people do — `^3.2` will **never** resolve to 4.0. Nothing
will tell you that; you will simply keep running the old version.

```bash
composer global config --no-plugins allow-plugins.wpify/scoper true
composer global require wpify/scoper:^4.0
composer global show wpify/scoper | head -2
```

As a dev dependency:

```bash
composer require --dev wpify/scoper:^4.0
```

Do this on **every** machine and in **every** pipeline before re-scoping anywhere. The scoper
version that produced a tree is not recorded in any lock file, so a laptop on 3.2 and a CI runner
on 4.0 will produce different output from the same commit and nothing will flag it.

## 2. Check PHP 8.2

4.0 requires **PHP 8.2 or newer**.

3.2.x declared `^8.1`, but that constraint was unsatisfiable — `wpify/php-scoper` has always
required `^8.2`, so a PHP 8.1 user got a resolver error naming a transitive package instead of a
clear message. In practice you were already on 8.2.

Update `config.platform.php` in both `composer.json` and `composer-deps.json` to the version your
site actually runs.

## 3. Re-scope and read the output

```bash
composer install -v
```

Read what it prints. 4.0 is the first release that prints anything at all — the plugin used to
capture Composer's `IOInterface` and never use it, which is why every failure mode below presented
as "nothing happened".

Then go through the sections that apply.

## Errors you may now see

### `extra.wpify-scoper.prefix is missing`

A missing or invalid prefix used to be a silent no-op: `composer install` exited 0, no `deps/`
appeared, and nothing was printed. It is now a configuration error.

```
wpify-scoper: extra.wpify-scoper.prefix is missing in /srv/my-plugin/composer.json.
Set it to a valid PHP namespace, for example "MyPlugin\Deps".
```

If you are seeing this on a project that "worked", it never scoped anything. Set a valid prefix and
look at what actually shipped.

The prefix is now validated as a PHP namespace, so a value with hyphens, spaces, a leading digit or
a leading/trailing `\` is rejected too.

### `unknown extra.wpify-scoper.globals entry "…"`

A warning, not an error — your install continues. But a typo here used to be ignored silently and
produced a build that broke at runtime on the first WordPress call. Fix it.

Valid values: `wordpress`, `woocommerce`, `action-scheduler`, `wp-cli`.

### `"plugin-update-checker" is deprecated and ignored`

Remove the entry from `globals`. The shipped list only ever held dead PUC v4 class names, and
Plugin Update Checker is now scoped like every other dependency.

The patcher that makes PUC work when scoped is retained, so update checking keeps working —
`PucFactory::buildUpdateChecker()` builds its registry lookup key from a variable, which php-scoper
does not prefix, and both the JSON and the VCS branch are still fixed up by hand.

### `composerlock points at the manifest …`

New validation. If `composerjson` and `composerlock` resolve to the same file, the run would
overwrite the file your dependency set is declared in. Point `composerlock` elsewhere.

## Behaviour that changed underneath you

### Your `composer-deps.json` is no longer rewritten

The run used to inject a `scripts` block full of absolute host paths into your manifest on every
invocation — clobbering anything already there, a hand-maintained `pre-autoload-dump` in
particular. The manifest is now only ever read.

If your `composer-deps.json` contains a `scripts` block with paths like
`/Users/someone/.composer/vendor/...`, that is the old debris. Delete it.

### `scoper.custom.php` may start applying for the first time

The project root used to be located by looking for the literal string `vendor/wpify/scoper` in the
plugin's own path, which silently ignored your file for a custom `vendor-dir`, a symlinked path
repository, or a global install — which is to say, for most installations.

If your customizations never seemed to take effect, they are about to. **Re-read them before you
re-scope**, and confirm with:

```bash
composer install -v | grep customizations
```

See [Customizing php-scoper](customizing.md).

### Failures now fail

`exit;` inside the plugin used to kill the host Composer process with status 0 and no output —
indistinguishable from success. Failures now throw with a message and a non-zero exit code.

CI pipelines that were silently producing no `deps/` will start going red. That is the fix working.

### A failed run keeps its workspace

Cleanup used to live in a generated child-process script, so it never ran on an error and a `tmp-*`
directory was left behind every time. It is now a `finally` — and deliberately skipped on failure,
because a swap that could not be completed parks your previous `deps/` in `deps-backup-<pid>`
inside it.

Add `tmp-*` to `.gitignore` if it is not there already.

## Output fixes — re-scope and test

These are the reason to test rather than just deploy.

**Namespace exclusions now cover their subtree.** `Automattic\WooCommerce` in `exclude-namespaces`
did not match `Automattic\WooCommerce\Internal\...`, so HPOS classes and
`PHPMailer\PHPMailer\PHPMailer` came out prefixed and fatalled at runtime. Exclusions now cover the
whole subtree, still on segment boundaries — `Foo\Bar` never matches `Foo\Barbecue`.

**Prefix stripping is anchored.** The un-prefixer used a plain `str_replace()` per excluded symbol,
so any vendor namespace or class whose name *started* with an excluded WordPress symbol had its
prefix stripped and was put back into the global namespace — the exact collision this package
exists to prevent. `WPSEO\Utils` (via the WordPress class `WP`) and `POBox\Mailer` (via `PO`) are
real examples. If your project depends on Yoast's libraries or anything with a similarly-shaped
namespace, this changes your output.

**An empty or unrecognised `globals` no longer crashes mid-scope.** `exclude-classes` and
`exclude-namespaces` were only defined as a side effect of merging a symbol list, so a project that
enabled none got a `TypeError` from inside a php-scoper patcher.

**Symlinked `deps/` is no longer destructive.** The recursive delete used `is_dir()`/`is_file()`,
both of which follow symlinks, so a project whose `deps/` was a symlink had the link's *target*
deleted. Every tree walk now checks `is_link()` first.

**A failed swap can no longer lose your dependencies.** The old tree was deleted before the new one
was in place. It is now moved aside and restored if the move fails.

**The symbol lists gained constants declared inside function bodies** — which is where WordPress
declares most of them, in `wp_initial_constants()` and friends — plus classes in `else` branches,
`class_alias()` targets, and braced `namespace { }` blocks. More symbols stay unprefixed than
before.

## New things you may want

### `composer wpify-scoper`

```bash
composer wpify-scoper install
composer wpify-scoper update
composer wpify-scoper install --no-dev
```

A real Composer command, replacing the pseudo-events. `bin/wpify-scoper` still works and prints a
deprecation notice; it will be removed in a future major. Switch your pipelines.

### `--no-dev` actually works

The `*_NO_DEV_CMD` code paths existed since 2023 but nothing could emit them. The flag now works,
and automatic scoping inherits the dev mode of the run that triggered it — so `composer install
--no-dev` also scopes without the `require-dev` block of your scoped manifest.

## Deprecated

- `bin/wpify-scoper` — use `composer wpify-scoper install|update`.
- `Plugin::SCOPER_*_CMD` constants and `Plugin::path()` — kept only because they have always been
  public. Nothing in a normal project touches them.

## Support

4.x is the only supported line. 3.2.x and 3.1.x are end of life and will not receive fixes,
including security fixes. See [SECURITY.md](../SECURITY.md).

If the upgrade breaks something not covered here, [open an issue](https://github.com/wpify/scoper/issues)
with the output of `composer install -vvv`.
