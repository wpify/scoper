# Customizing php-scoper

Some libraries cannot be scoped by static analysis alone. They build class names by concatenating
strings, they write PHP at runtime, they pass function names around as strings. php-scoper cannot
see any of that, so it leaves those names unprefixed and the scoped copy breaks.

The fix is a **patcher**: a callback that rewrites the source of a file after php-scoper has
processed it. This page is about adding your own.

You should not need this for a well-behaved library. Reach for it when something specific is broken
after scoping, not preemptively.

## `scoper.custom.php`

Create `scoper.custom.php` in your project root — the directory holding the `composer.json` Composer
resolved for the run. It must define one function:

```php
<?php

function customize_php_scoper_config( array $config ): array {
	// modify $config
	return $config;
}
```

It receives the fully assembled [php-scoper configuration](https://github.com/humbug/php-scoper/blob/main/docs/configuration.md)
and must return a valid one. Whatever you return is what php-scoper runs with.

## What is in `$config`

| Key | Contents |
|---|---|
| `prefix` | Your `extra.wpify-scoper.prefix`. |
| `finders` | Two finders: everything in the workspace's `vendor/`, plus its `composer.json` and `composer.lock`. |
| `patchers` | One entry: the built-in patcher (see below). |
| `exclude-classes`, `exclude-functions`, `exclude-constants`, `exclude-namespaces` | The merged, de-duplicated contents of the symbol lists you enabled in `globals`. |
| `expose-global-classes`, `expose-global-functions`, `expose-global-constants` | All `false`. Nothing is meant to be exposed. |

The built-in patcher already handles Guzzle, Twig, PHP-DI, libphonenumber, `league/oauth2-client`
and Plugin Update Checker — check whether your problem is already solved before writing your own.
It also runs the un-prefixer, which is what puts the excluded WordPress symbols back after
php-scoper has prefixed them. **Do not replace `patchers`; append to it.** Dropping the built-in
entry removes the un-prefixer and every WordPress call in your scoped tree breaks.

## A patcher

```php
<?php

function customize_php_scoper_config( array $config ): array {
	$config['patchers'][] = function ( string $filePath, string $prefix, string $content ): string {
		if ( str_contains( $filePath, 'acme/widgets/src/Registry.php' ) ) {
			$content = str_replace(
				"'Acme\\\\Widgets\\\\'",
				"'" . $prefix . "\\\\Acme\\\\Widgets\\\\'",
				$content
			);
		}

		return $content;
	};

	return $config;
}
```

Three things to keep in mind:

- **Always guard on `$filePath`.** The callback runs for every file in the scoped tree. An
  unguarded `str_replace()` rewrites libraries you never meant to touch.
- **Always return `$content`.** Returning `null` — which `preg_replace()` does when PCRE gives up,
  and a large generated template is a real way to get there — aborts the whole run from inside a
  patcher, with a stack trace nobody can act on. Write `preg_replace( ... ) ?? $content`.
- **Backslashes are doubled twice.** You are writing PHP string literals that contain PHP string
  literals. `\` in the scoped source is `\\` in your patcher's single-quoted string.

Patchers run in order, so yours sees content the built-in patcher has already un-prefixed.

## Adding exclusions

To keep a symbol unprefixed that is not in any shipped symbol list:

```php
function customize_php_scoper_config( array $config ): array {
	$config['exclude-classes'][]    = 'My_Legacy_Global_Class';
	$config['exclude-functions'][]  = 'my_theme_helper';
	$config['exclude-namespaces'][] = 'Acme\\Shared';

	return $config;
}
```

A namespace exclusion covers its whole subtree, on segment boundaries: `Acme\Shared` matches
`Acme\Shared\Http` but never `Acme\Sharedish`.

If the symbol is one WordPress, WooCommerce, Action Scheduler or WP-CLI declares, do not do this —
it is a bug in the shipped lists. [Open an issue.](https://github.com/wpify/scoper/issues)

## Where the file is looked for

Exactly two places, in this order:

1. **Your project root** — the directory holding the `composer.json` Composer resolved for this run.
   This is the one you want. It is correct under `--working-dir`, with a custom `vendor-dir`, with
   `COMPOSER=` pointing elsewhere, and for a global install of the plugin.
2. The plugin's own directory, so that a checkout of this repository keeps working.

Run with `-v` and the scoper tells you which it picked up:

```
wpify-scoper: using the customizations from /srv/my-plugin/scoper.custom.php
```

or, when there is none:

```
wpify-scoper: no scoper.custom.php found in /srv/my-plugin
```

If you get that second line and you are sure the file exists, you are not in the project root the
scoper resolved. The `-v` configuration line prints that root.

> Releases before 4.0 located the project root by looking for the literal string
> `vendor/wpify/scoper` in the plugin's own path, which silently ignored your file whenever
> `vendor-dir` was renamed, the plugin was symlinked in through a path repository, or it was
> installed globally. If your customizations never seemed to apply, that is why.

## Gotchas

**`__DIR__` is not your project.** The file is copied into the `tmp-*` workspace and executed from
there, so `__DIR__` and `getcwd()` point at the workspace. Do not build paths from them. If you
need a path in your project, hard-code it or derive it from `$filePath`.

**The file is not autoloaded.** It is `require_once`d, and only `customize_php_scoper_config` is
called. Anything else in it runs at include time, once, inside the php-scoper phar's process — a
process whose autoloader knows nothing about your project.

**Debugging.** `-vvv` shows the nested processes. To see what your patcher actually received, write
to a file with an absolute path; standard output from inside php-scoper is not reliably visible.

## Further reading

- [php-scoper configuration reference](https://github.com/humbug/php-scoper/blob/main/docs/configuration.md)
- [How it works](how-it-works.md) — where in the pipeline this file is loaded
