<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * What Composer's runtime knows about this plugin's own package.
 *
 * Three callers need these facts - the run header prints the version, and the update check needs
 * both the version to compare and the path to tell a global install from a project-local one - and
 * every one of them would otherwise carry its own copy of the same `class_exists()` guard and
 * OutOfBoundsException catch.
 */
final class PluginPackage {

	public const NAME = 'wpify/scoper';

	/**
	 * The version actually running.
	 *
	 * @return string|null Null when Composer's runtime has no record of the package, which is what
	 *                     a path repository or a hand-dropped copy looks like. `InstalledVersions`
	 *                     itself is missing only when the autoloader predates Composer 2.
	 */
	public static function version(): ?string {
		if ( ! class_exists( InstalledVersions::class ) ) {
			return null;
		}

		try {
			return InstalledVersions::getPrettyVersion( self::NAME );
		} catch ( OutOfBoundsException ) {
			return null;
		}
	}

	/**
	 * Where this copy of the plugin is installed.
	 *
	 * @return string|null Resolved through realpath(), because Composer answers this with paths
	 *                     like `.../vendor/composer/../../` that no string comparison can use.
	 */
	public static function installPath(): ?string {
		if ( ! class_exists( InstalledVersions::class ) ) {
			return null;
		}

		try {
			$path = InstalledVersions::getInstallPath( self::NAME );
		} catch ( OutOfBoundsException ) {
			return null;
		}

		$resolved = realpath( $path ?? '' );

		return false !== $resolved ? $resolved : null;
	}
}
