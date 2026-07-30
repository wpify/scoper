# `require` resolves in the workspace and writes back a delta

Status: accepted

`composer wpify-scoper require guzzlehttp/guzzle` has to end with a constraint in
`composer-deps.json`, and the obvious way to get one — decide the constraint ourselves, write it,
then run the existing `update` pipeline — is not the one the code takes. Instead the run hands the
whole job to a nested `composer require` inside the workspace, then compares that workspace's
`composer.json` with the scoped manifest as it was before the run and applies only the difference.
That deviation is deliberate, for four reasons.

**Resolution has to happen against the scoped manifest, not the root one.** `composer-deps.json`
carries its own `repositories` and its own `config.platform.php`, and both change the answer: a
plugin resolving against wpackagist, or pinning `php` to the version its *site* runs rather than
the version the developer's laptop runs, must get the constraint that follows from *those*
settings. The nested Composer already reads them, because the workspace manifest is a copy of the
scoped manifest. Deciding the constraint ourselves would mean rebuilding a `RepositorySet` from
those keys by hand, and Composer's own answer to this question —
`InitCommand::findBestVersionAndNameForPackage()` — is `protected`, so it would be a
reimplementation rather than a call.

**Failure needs no rollback.** The scoped manifest is not touched until the nested Composer has
already succeeded, so an unsatisfiable constraint, a network failure or a platform conflict leaves
the user's file exactly as it was. Writing the constraint first and reverting on failure — which is
what Composer's own `RequireCommand` does — needs the file restored on every error path, including
the ones that throw from php-scoper much later.

**The user's file survives intact.** The workspace manifest is not byte-identical to the scoped
manifest: the run writes it with `json_encode()` and strips `extra.wpify-scoper` from it, so
copying it back wholesale would reformat the whole file on every run and silently delete that block
if a user had put one there. Applying the delta with `Composer\Json\JsonManipulator` touches only
the `require` and `require-dev` entries that actually changed and leaves every other byte alone,
which is what makes the narrower promise in [configuration.md](../configuration.md) — the run never
rewrites the manifest — still true.

**The delta is computed in both directions across both blocks.** `composer require foo/bar --dev`
on a package already in `require` does not add it twice; it *moves* it. So the comparison looks for
removals as well as additions, in `require` and `require-dev` alike, rather than assuming a
`require` run only ever adds.

The consequence to be aware of: the nested `composer require` replaces the `composer install` step
of the pipeline rather than running in addition to it, so anything that would break that step —
`--no-update` or `--no-install`, which would leave the workspace with no `composer.lock` and no
`vendor/` — cannot be allowed through. That is why the command declares an explicit allowlist of
flags per action instead of forwarding whatever the user typed.
