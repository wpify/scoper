## What this changes

<!-- And why. Link the issue if there is one. -->

## Checks

<!-- See CONTRIBUTING.md. CI runs all of these, but running them locally is faster than a red pipeline. -->

- [ ] `composer validate --strict`
- [ ] `composer analyse` — PHPStan level 9, no baseline
- [ ] `composer cs` — code style
- [ ] `composer test` — unit and golden-file
- [ ] `composer test:integration` — end-to-end

## Does this change what lands in `deps/`?

- [ ] **No** — the generated scoped output is byte-identical.
- [ ] **Yes** — and I have added a `CHANGELOG.md` entry saying so.

If yes: this is at least a **minor** release, never a patch, even when the diff is three
characters. Consumers cannot see that their scoped tree changed until something fatals in
production.

## Symbol lists

<!-- Delete this section if symbols/*.php is untouched. -->

- [ ] Generated with `composer extract`, not edited by hand.
- [ ] Counts checked against the baseline:

```
php scripts/symbol-guard.php snapshot /tmp/before.json
composer extract
php scripts/symbol-guard.php compare /tmp/before.json 1.0
```

## Documentation

- [ ] User-facing behaviour changed, and `docs/` is updated to match.
- [ ] Not needed.
