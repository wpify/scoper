# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | ✅ Fixes and security updates |
| 3.2.x | ❌ End of life |
| 3.1.x | ❌ End of life |

Only the current major line receives fixes of any kind. If you are on 3.2 or earlier, see
[Upgrading to 4.0](docs/upgrading-to-4.md).

## Reporting a vulnerability

**Do not open a public issue.**

Report privately, either way:

- [Open a private security advisory](https://github.com/wpify/scoper/security/advisories/new) on
  GitHub — preferred, because it keeps the discussion and the eventual advisory in one place.
- Or email **info@wpify.io** with `wpify/scoper` in the subject.

Please include:

- what an attacker can do, and what they need in order to do it
- the affected version, and how the scoper is installed (global or `require-dev`)
- the smallest reproduction you can manage — a `composer-deps.json` and an `extra.wpify-scoper`
  block, ideally
- the output of the failing command with `-vvv`

You will get an acknowledgement within a week. This is a small project with one maintainer; a fix
may take longer than that, and I will tell you where it stands rather than go quiet.

Please give me a reasonable window to ship a fix before disclosing publicly. I will credit you in
the advisory and the changelog unless you would rather I did not.

## Scope

This package is a build-time tool. It reads your manifests, spawns Composer and php-scoper, and
rewrites a dependency tree on your machine or in your pipeline. Things that are in scope:

- code execution triggered by installing or running the plugin beyond what Composer itself does
- the scoper writing outside the paths it is configured to write to, or destroying data it should
  not touch — path traversal through `folder`, `temp`, `composerjson` or `composerlock`, symlink
  following during the swap, or the backup-and-restore logic losing a tree
- **a symbol that should have stayed unprefixed being prefixed, or vice versa.** This is the most
  serious class of bug here: the wrong exclusion silently reintroduces a namespace collision on a
  production site, or produces a call to a function that does not exist. Report it as a security
  issue if it is exploitable, and as a normal bug otherwise.
- the generated scoped tree containing code that was not in the input

Out of scope:

- vulnerabilities in the dependencies you choose to scope — report those upstream
- vulnerabilities in [php-scoper](https://github.com/humbug/php-scoper) or
  [Composer](https://github.com/composer/composer) itself, unless this package's use of them is
  what makes them exploitable
- anything requiring an attacker to already control your `composer.json`, `composer-deps.json` or
  `scoper.custom.php`. Those files are executable configuration; if an attacker can write to them,
  they can already run code as you.
