# Packagist, not GitHub tags, as the release source

Status: accepted

The update notice was asked for as "check whether there is a newer tag in the GitHub repository",
and the code queries `https://repo.packagist.org/p2/wpify/scoper.json` instead. That deviation is
deliberate, for three reasons.

**A tag is not a release.** The plugin is installed with `composer global require wpify/scoper`,
so what matters to a user is whether a newer version exists *on Packagist*, which is the only
place `composer update` resolves against. A tag that has been pushed but not yet published is not
something the notice could tell anyone to install, and a notice that cannot be actioned is worse
than no notice.

**The GitHub API is rate limited where this code runs.** Unauthenticated requests are capped at 60
per hour per IP. Scoping runs happen on developer machines behind shared office NAT and on CI
runners behind shared cloud addresses, so a meaningful share of users would get `403` rather than
an answer. Packagist's package metadata is CDN-served, unauthenticated and not rate limited.

**Composer already speaks it.** `HttpDownloader` carries the user's proxy configuration, TLS
settings and `COMPOSER_DISABLE_NETWORK`, so using the endpoint Composer itself uses means the
check inherits all of it rather than reimplementing any of it.

The consequence to be aware of: a version that is tagged in git but not published on Packagist is
invisible to the notice. That is the correct behaviour — such a version is also uninstallable —
but it does mean the notice lags a tag by however long Packagist takes to pick it up.
