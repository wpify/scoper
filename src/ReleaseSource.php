<?php declare( strict_types=1 );

namespace Wpify\Scoper;

/**
 * Answers what the newest published release of this plugin is.
 *
 * Exists as an interface purely so that {@see UpdateNotifier} can be exercised without touching
 * the network, exactly as {@see ComposerRunner} lets {@see Scoper} be exercised without spawning
 * Composer.
 */
interface ReleaseSource {

	/**
	 * The newest stable released version.
	 *
	 * @return string|null Null whenever the answer cannot be determined - offline, rate limited,
	 *                     malformed response, nothing stable published yet. Callers treat null as
	 *                     "say nothing" rather than as an error.
	 */
	public function latestStable(): ?string;
}
