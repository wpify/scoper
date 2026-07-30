<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Cache;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Composer\Util\HttpDownloader;
use UnexpectedValueException;

/**
 * Reads the published version list from Packagist's package metadata.
 *
 * Packagist rather than the GitHub tag API on purpose - see docs/adr/0001. The short version: a
 * version that is here is one `composer update` can actually install, and this endpoint is
 * CDN-served with no rate limit, where GitHub's unauthenticated API gives 60 requests an hour per
 * IP and returns 403 on the shared addresses CI runners come from.
 */
final class PackagistReleaseSource implements ReleaseSource {

	public const PACKAGE = 'wpify/scoper';

	private const URL = 'https://repo.packagist.org/p2/' . self::PACKAGE . '.json';

	/**
	 * Seconds a cached answer stays good for.
	 *
	 * `cache-dir` is a machine-global Composer setting, so this is one lookup per machine per day
	 * however many projects and however many runs.
	 */
	private const TTL = 86400;

	private const CACHE_FILE = 'latest-release.txt';

	/**
	 * Composer's default is `max(default_socket_timeout, 300)`. Five minutes is an absurd thing to
	 * make somebody wait for a courtesy notice, so the request gets its own budget.
	 */
	private const TIMEOUT = 3;

	private const MAX_SIZE = 524288;

	public function __construct(
		private readonly HttpDownloader $downloader,
		private readonly ?Cache $cache = null,
	) {
	}

	public function latestStable(): ?string {
		$cached = $this->readCache();

		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->downloader->get( self::URL, array(
			'http'          => array( 'timeout' => self::TIMEOUT ),
			'max_file_size' => self::MAX_SIZE,
		) );

		$latest = self::newestStable( (string) $response->getBody() );

		// Only a successful lookup resets the clock. Caching the failure would buy 24 hours of
		// silence for a laptop that merely happened to be off wifi when the run started.
		if ( null !== $latest && null !== $this->cache ) {
			$this->cache->write( self::CACHE_FILE, $latest );
		}

		return $latest;
	}

	/**
	 * The newest stable version in a Packagist p2 response, or null if there is none to be had.
	 *
	 * Static and pure so that the parsing can be tested against a committed fixture with no cache
	 * and no HttpDownloader in sight.
	 *
	 * The response is in the minified `composer/2.0` format, where each entry after the first
	 * carries only the keys that differ from its predecessor. `version` is by definition one of
	 * them, so it is always present and the entries need no expanding for this purpose.
	 */
	public static function newestStable( string $json ): ?string {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$packages = $decoded['packages'] ?? null;
		$versions = is_array( $packages ) ? ( $packages[ self::PACKAGE ] ?? null ) : null;

		if ( ! is_array( $versions ) ) {
			return null;
		}

		$parser = new VersionParser();
		$latest = null;

		foreach ( $versions as $entry ) {
			$version = is_array( $entry ) ? ( $entry['version'] ?? null ) : null;

			if ( ! is_string( $version ) || '' === $version ) {
				continue;
			}

			try {
				// Rejects anything Comparator would throw on further down, which keeps this
				// function total: any input at all produces a version or null, never an exception.
				$parser->normalize( $version );
			} catch ( UnexpectedValueException ) {
				continue;
			}

			if ( 'stable' !== VersionParser::parseStability( $version ) ) {
				continue;
			}

			if ( null === $latest || Comparator::greaterThan( $version, $latest ) ) {
				$latest = $version;
			}
		}

		return $latest;
	}

	private function readCache(): ?string {
		if ( null === $this->cache ) {
			return null;
		}

		$age = $this->cache->getAge( self::CACHE_FILE );

		if ( false === $age || $age > self::TTL ) {
			return null;
		}

		$cached = $this->cache->read( self::CACHE_FILE );

		return is_string( $cached ) && '' !== $cached ? $cached : null;
	}
}
