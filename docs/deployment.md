# Deployment

How to get a scoped tree onto a server, and what to put in git.

- [What to commit](#what-to-commit)
- [GitLab CI](#gitlab-ci)
- [GitHub Actions](#github-actions)
- [Deploying by git push](#deploying-by-git-push)
- [Bedrock](#bedrock)
- [Scoping into a subdirectory of `vendor/`](#scoping-into-a-subdirectory-of-vendor)
- [Deploying to WordPress.org](#deploying-to-wordpressorg)
- [Repositories with several plugins](#repositories-with-several-plugins)

## What to commit

| Path | Commit it? | Why |
|---|---|---|
| `composer-deps.json` | **Yes** | It is your source of truth for the scoped dependency set. |
| `composer-deps.lock` | **Yes** | It is what makes `composer wpify-scoper install` reproducible. Without it every machine resolves independently. |
| `deps/` | Your call — see below | A build artifact. |
| `tmp-*` | **Never** | Scratch workspace. Add `tmp-*` to `.gitignore`. |

### Should `deps/` be in git?

**Build it in CI and ignore it** if you deploy an artifact — a zip, a container image, an rsync of a
built directory. This is the default recommendation. The tree is large, it changes wholesale on
every scoper upgrade, and reviewing its diff is not a thing anyone does.

```gitignore
/vendor/
/deps/
/tmp-*
```

**Commit it** if you deploy by pushing a git checkout to a server where you cannot run Composer —
shared hosting, a managed WordPress host with no shell. See
[Deploying by git push](#deploying-by-git-push).

There is no third option where the server builds it on demand. Scoping spawns Composer and
php-scoper and takes real time; it is a build step, not a runtime one.

## GitLab CI

```yaml
composer:
  stage: .pre
  image: composer:2
  artifacts:
    paths:
      - $CI_PROJECT_DIR/deps
      - $CI_PROJECT_DIR/vendor
    expire_in: 1 week
  script:
    - PATH=$(composer global config bin-dir --absolute --quiet):$PATH
    - composer global config --no-plugins allow-plugins.wpify/scoper true
    - composer global require wpify/scoper:^4.0
    - composer install --prefer-dist --optimize-autoloader --no-ansi --no-interaction --no-dev
```

`--no-dev` propagates: the scoped run inherits the dev mode of the command that triggered it, so
this also scopes without the `require-dev` block of your `composer-deps.json`.

Pin the constraint (`:^4.0`). An unpinned `composer global require` means your pipeline silently
changes what it produces the day a new major lands.

## GitHub Actions

```yaml
name: Build

on:
  push:
  workflow_dispatch:

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v5

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
          tools: composer:v2

      - name: Cache Composer downloads
        uses: actions/cache@v4
        with:
          path: ~/.cache/composer
          key: ${{ runner.os }}-${{ hashFiles('**/composer.lock', '**/composer-deps.lock') }}

      - run: composer global config --no-plugins allow-plugins.wpify/scoper true
      - run: composer global require wpify/scoper:^4.0

      - run: composer install --no-dev --optimize-autoloader

      - name: Archive the build
        uses: actions/upload-artifact@v4
        with:
          name: plugin
          path: |
            deps/
            vendor/
```

Set `php-version` to the version your **site** runs, and make it agree with `config.platform.php`
in both manifests. A pipeline on 8.4 resolving for a site on 8.2 produces a tree that fatals in
production.

## Deploying by git push

If the server cannot run Composer, `deps/` and `vendor/` both have to be in the repository.

```gitignore
/tmp-*
```

Then the rule that matters: **build, then commit, then push.** Never push a source change and
re-scope afterwards — between those two moments the site is running your new code against the old
dependency tree.

Because the scoped output depends on a scoper version that no lock file records, decide *one*
machine or pipeline that is allowed to regenerate `deps/`, and pin its scoper constraint. Two
developers committing `deps/` from different scoper versions produce a diff of the entire tree and
a merge nobody can resolve.

## Bedrock

[Bedrock](https://roots.io/bedrock/) puts WordPress under `web/wp` and plugins under
`web/app/plugins`. Two layouts work.

**Scoping a single plugin in its own repository** — nothing special, use the defaults.

**Scoping at the Bedrock project root**, for dependencies shared by the site's own mu-plugin code:

```json
{
  "extra": {
    "wpify-scoper": {
      "prefix": "MySite\\Deps",
      "folder": "web/app/deps"
    }
  }
}
```

`folder` is relative to the directory holding the `composer.json` Composer resolved, which is the
Bedrock root. Load it the same way as anywhere else:

```php
require_once dirname( __DIR__ ) . '/deps/scoper-autoload.php';
```

Bedrock's own `.gitignore` already covers `web/app/plugins/` and `vendor/`. Add `deps/`
(or `web/app/deps/`) and `tmp-*` yourself.

## Scoping into a subdirectory of `vendor/`

```json
"folder": "vendor/my-plugin-deps"
```

This keeps one dependency directory in the project instead of two, which some deployment scripts
prefer. It works, with three caveats:

- The directory is **replaced wholesale** on every run. It must be a path Composer itself never
  installs into — a name no package you require could claim, and never a bare `vendor/`, which
  would delete Composer's own vendor directory.
- Anything that archives `vendor/` also archives the scoped tree. That is usually what you want;
  make sure it is not counted twice.
- Anything that **excludes** `vendor/` from a release now excludes your dependencies too, and the
  plugin fatals on activation with a missing class. Check `.distignore`, `.gitattributes`
  (`export-ignore`), `rsync --exclude` rules and any `zip -x` in your build.

That last one is why a plugin headed to WordPress.org is better served by `vendor-prefixed/` as a
sibling of `vendor/` — it gets the same Plugin Check exemption without inheriting every rule
written about the Composer vendor directory. See
[Deploying to WordPress.org](#deploying-to-wordpressorg).

The backup taken during the swap deliberately lives inside the `tmp-*` workspace rather than
next to `folder`, precisely so that a `vendor/`-adjacent `.bak` cannot end up in a release build.

## Deploying to WordPress.org

Scope to `vendor-prefixed/` for these, not to `deps/` — WordPress.org's mandatory Plugin Check
skips the first and scans the second. [Publishing to WordPress.org](wordpress-org.md) explains why
and has the pre-submission checklist.

```json
"folder": "vendor-prefixed"
```

WordPress.org serves whatever is in SVN — nothing runs Composer there, so the release has to carry
`vendor/` for Composer's autoloader **and** `vendor-prefixed/` for your scoped dependencies. With
[10up's deploy action](https://github.com/10up/action-wordpress-plugin-deploy) that means building
before the deploy step and keeping both out of `.distignore`:

```yaml
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer:v2

      - run: composer global config --no-plugins allow-plugins.wpify/scoper true
      - run: composer global require wpify/scoper:^4.0

      - run: composer install --no-dev --optimize-autoloader

      - uses: 10up/action-wordpress-plugin-deploy@stable
        env:
          SVN_USERNAME: ${{ secrets.SVN_USERNAME }}
          SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}
```

That single `composer install` does both halves: Composer populates `vendor/`, and the
`post-install-cmd` it fires writes the scoped tree into `vendor-prefixed/`. `--no-dev` propagates to
the scoped run, so the `require-dev` block of `composer-deps.json` stays out of the release.

The `.distignore` for that build excludes development directories and nothing else. Neither
`/vendor` nor `/vendor-prefixed` belongs in it — the plugin needs both at runtime:

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

`composer.json` and `composer-deps.json` stay in — reviewers are told to expect manifests, and
yours are the only record of what the scoped tree was built from.

In git, `/vendor-prefixed/` needs its own `.gitignore` line: it is a sibling of `/vendor/`, not a
child, so the rule you already have does not cover it.

## Repositories with several plugins

Each plugin scopes independently. There is no shared or monorepo mode, and you do not want one:
two plugins that share a prefix share the collision they were scoped to avoid.

```
repo/
  plugin-a/
    composer.json          extra.wpify-scoper.prefix = PluginA\Deps
    composer-deps.json
    deps/
  plugin-b/
    composer.json          extra.wpify-scoper.prefix = PluginB\Deps
    composer-deps.json
    deps/
```

Run Composer once per plugin — `composer install --working-dir=plugin-a`, and so on. `folder`,
`composerjson` and `composerlock` all resolve relative to the `composer.json` Composer resolved for
that run, so `--working-dir` behaves the way you would expect.

Give every plugin its own prefix. Sharing one across plugins in the same repository puts two copies
of the same library back in the same namespace at runtime, which is the original problem with extra
steps.

Git submodules are fine, and so are Composer `path` repositories — `deps/` being a symlink is
handled: the swap never follows links when it removes a tree.
