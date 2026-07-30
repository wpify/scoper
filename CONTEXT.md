# WPify Scoper

A Composer plugin that rewrites a WordPress plugin's dependencies into a private namespace, so that
two plugins active on one site cannot collide over the same class name.

This file fixes the words the code, the documentation and the console output all use for the same
things. It is a glossary, not a specification — for what the settings do, see
[docs/configuration.md](docs/configuration.md).

## Language

### The two dependency sets

**Scoped manifest**:
The second Composer manifest, `composer-deps.json` by default, that holds only the dependencies
which get rewritten.
_Avoid_: deps manifest, dependency file, composer-deps

**Scoped dependency**:
One entry in the scoped manifest's `require` or `require-dev` block — a package name and a version
constraint.
_Avoid_: requirement (that is Composer's word for a `Link`), scoped package, scoped requirement

**Scoped tree**:
The rewritten vendor directory a run produces, `deps/` by default.
_Avoid_: deps folder, output folder, scoped vendor

**Prefix**:
The PHP namespace scoped dependencies are moved into.
_Avoid_: namespace, vendor prefix

### Performing a run

**Run**:
One invocation of the pipeline: resolve, rewrite, publish, swap.

**Action**:
Which of `install`, `update`, `require` or `remove` a run performs.
_Avoid_: command (that is Composer's `Command`, of which this plugin contributes exactly one),
sub-command

**Workspace**:
The throwaway `tmp-*` directory a run resolves and rewrites inside. Removed on success, kept on
failure.
_Avoid_: temp dir, scratch directory, staging area

**Nested Composer**:
The separate Composer process a run spawns inside the workspace to resolve the scoped manifest.
_Avoid_: inner Composer, child install

**Delta**:
The set of scoped dependencies a `require` or `remove` run added, changed or deleted, computed by
comparing the scoped manifest before the run with the workspace manifest after it.

### Keeping WordPress out of the rewrite

**Symbol list**:
A generated file under `symbols/` naming every class, function and constant that one platform —
WordPress, WooCommerce, Action Scheduler or WP-CLI — declares.
_Avoid_: globals list (`globals` is the name of the setting that selects symbol lists, not a name
for the lists themselves), exclusion list, symbol database

**Global symbol**:
A symbol that must survive a run unprefixed, because WordPress rather than the plugin owns it.
_Avoid_: excluded symbol, exposed symbol (php-scoper's "exposed" means something else, and this
plugin comments those out)
