<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wpify\Scoper\PluginPackage;

#[CoversClass( PluginPackage::class )]
final class PluginPackageTest extends TestCase {

	private function fixture( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/installed/' . $name . '.php';
	}

	public function test_it_reads_the_version_out_of_an_installed_file(): void {
		self::assertSame( '4.0.1', PluginPackage::versionFrom( $this->fixture( 'with-package' ) ) );
	}

	/**
	 * The file returns an array, so a `require_once` in the implementation would hand back `true`
	 * the second time and silently produce null.
	 */
	public function test_reading_the_same_file_twice_gives_the_same_answer(): void {
		$file = $this->fixture( 'with-package' );

		self::assertSame( '4.0.1', PluginPackage::versionFrom( $file ) );
		self::assertSame( '4.0.1', PluginPackage::versionFrom( $file ) );
	}

	public function test_a_file_that_does_not_list_the_plugin_gives_null(): void {
		self::assertNull( PluginPackage::versionFrom( $this->fixture( 'without-package' ) ) );
	}

	public function test_a_file_that_is_not_an_installed_file_gives_null(): void {
		self::assertNull( PluginPackage::versionFrom( $this->fixture( 'not-an-array' ) ) );
	}

	public function test_a_missing_file_gives_null(): void {
		self::assertNull( PluginPackage::versionFrom( $this->fixture( 'no-such-fixture' ) ) );
	}

	/**
	 * The regression this class exists for.
	 *
	 * Composer registers a global plugin's autoloader under the *project's* vendor directory, so
	 * `InstalledVersions` reads that project's `installed.php`, does not find the plugin in it, and
	 * throws - which used to leave the version unknown, silencing both the run header and the
	 * update notice for every globally installed user. Resolving it must not depend on the
	 * registry alone.
	 */
	public function test_the_version_resolves_without_help_from_the_composer_runtime(): void {
		$fromRegistry = null;

		try {
			$fromRegistry = InstalledVersions::getPrettyVersion( PluginPackage::NAME );
		} catch ( \OutOfBoundsException ) {
			$fromRegistry = null;
		}

		$fromOwnMetadata = PluginPackage::versionFrom(
			dirname( __DIR__, 2 ) . '/vendor/composer/installed.php'
		);

		self::assertNotNull(
			$fromOwnMetadata,
			'the plugin must be able to read its own version without the Composer runtime registry'
		);

		if ( null !== $fromRegistry ) {
			self::assertSame( $fromRegistry, $fromOwnMetadata, 'both sources must agree' );
		}
	}

	public function test_the_install_path_is_this_checkout(): void {
		self::assertSame( realpath( dirname( __DIR__, 2 ) ), PluginPackage::installPath() );
	}

	public function test_the_version_is_resolvable_in_this_environment(): void {
		self::assertNotNull( PluginPackage::version() );
	}
}
