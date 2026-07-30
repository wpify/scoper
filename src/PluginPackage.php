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
	 * `InstalledVersions` alone is not enough, and the reason is the whole point of this class.
	 * Composer registers a plugin's autoloader under the vendor directory of the project being
	 * operated on - `createLoader($map, $config->get('vendor-dir'))` in
	 * `PluginManager::registerPackage()` - and `InstalledVersions` only ever scans the loaders that
	 * were registered. So a globally installed plugin, which is how this one is documented to be
	 * installed, reads the *project's* `installed.php`, does not find itself in it, and throws.
	 * Asking Composer's runtime what version this plugin is therefore fails in exactly the setup
	 * that matters most.
	 *
	 * @return string|null Null only when neither source can answer, which is a hand-dropped copy
	 *                     with no Composer metadata anywhere near it.
	 */
	public static function version(): ?string {
		// Correct whenever the plugin is a dependency of the project being operated on, and the
		// only source that accounts for replaced or aliased versions.
		if ( class_exists( InstalledVersions::class ) ) {
			try {
				return InstalledVersions::getPrettyVersion( self::NAME );
			} catch ( OutOfBoundsException ) {
				// Not this project's dependency. Fall through and read our own metadata.
			}
		}

		foreach ( self::installedFiles() as $file ) {
			$version = self::versionFrom( $file );

			if ( null !== $version ) {
				return $version;
			}
		}

		return null;
	}

	/**
	 * Where this copy of the plugin is installed.
	 *
	 * Derived from `__DIR__` rather than from `InstalledVersions::getInstallPath()`, which fails
	 * for a global install for the reason described above - and which answers with unresolved
	 * paths like `.../vendor/composer/../../` even when it does work.
	 */
	public static function installPath(): ?string {
		$path = realpath( self::packageRoot() );

		return false !== $path ? $path : null;
	}

	/**
	 * Reads a package version out of a Composer `installed.php`.
	 *
	 * Public and free of any discovery so the parsing can be tested against a committed fixture,
	 * the same bargain {@see PackagistReleaseSource::newestStable()} makes.
	 *
	 * `require` rather than `require_once`: the file returns an array, and `require_once` would
	 * hand back `true` on any second call.
	 */
	public static function versionFrom( string $file ): ?string {
		if ( ! is_file( $file ) ) {
			return null;
		}

		/** @var mixed $data */
		$data = require $file;

		if ( ! is_array( $data ) ) {
			return null;
		}

		$versions = $data['versions'] ?? null;
		$entry    = is_array( $versions ) ? ( $versions[ self::NAME ] ?? null ) : null;
		$pretty   = is_array( $entry ) ? ( $entry['pretty_version'] ?? null ) : null;

		return is_string( $pretty ) && '' !== $pretty ? $pretty : null;
	}

	/**
	 * The `installed.php` files that could describe this plugin, best guess first.
	 *
	 * @return list<string>
	 */
	private static function installedFiles(): array {
		$root = self::packageRoot();

		return array(
			// An ordinary install: the package sits at <vendor>/wpify/scoper.
			dirname( $root, 2 ) . '/composer/installed.php',
			// A checkout of this repository, with its own vendor directory.
			$root . '/vendor/composer/installed.php',
		);
	}

	private static function packageRoot(): string {
		return dirname( __DIR__ );
	}
}
