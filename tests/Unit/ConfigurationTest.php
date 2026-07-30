<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wpify\Scoper\Configuration;

#[CoversClass( Configuration::class )]
final class ConfigurationTest extends TestCase {

	private const ROOT   = '/projects/plugin';
	private const VENDOR = '/projects/plugin/vendor';

	/**
	 * @param array<string, mixed> $settings
	 */
	private function config( array $settings, string $rootDir = self::ROOT, string $vendorDir = self::VENDOR ): Configuration {
		return Configuration::fromExtra( array( 'wpify-scoper' => $settings ), $rootDir, $vendorDir );
	}

	public function test_it_applies_the_documented_defaults(): void {
		$config = $this->config( array( 'prefix' => 'MyPlugin\\Deps' ) );

		self::assertSame( 'MyPlugin\\Deps', $config->prefix );
		self::assertSame( self::ROOT, $config->rootDir );
		self::assertSame( self::VENDOR, $config->vendorDir );
		self::assertSame( self::ROOT . '/deps', $config->folder );
		self::assertSame( self::ROOT . '/composer-deps.json', $config->composerJson );
		self::assertSame( self::ROOT . '/composer-deps.lock', $config->composerLock );
		self::assertSame( Configuration::DEFAULT_GLOBALS, $config->globals );
		self::assertTrue( $config->autorun );
		self::assertMatchesRegularExpression( '#^' . preg_quote( self::ROOT, '#' ) . '/tmp-[0-9a-f]{10}$#', $config->tempDir );
	}

	public function test_the_temp_directory_is_different_on_every_run(): void {
		$first  = $this->config( array( 'prefix' => 'A' ) )->tempDir;
		$second = $this->config( array( 'prefix' => 'A' ) )->tempDir;

		self::assertNotSame( $first, $second );
	}

	public function test_the_working_directories_live_inside_the_temp_directory(): void {
		$config = $this->config( array( 'prefix' => 'A', 'temp' => 'work' ) );

		self::assertSame( self::ROOT . '/work', $config->tempDir );
		self::assertSame( self::ROOT . '/work/source', $config->sourceDir() );
		self::assertSame( self::ROOT . '/work/destination', $config->destinationDir() );
	}

	public function test_it_honours_every_override_key(): void {
		$config = $this->config( array(
			'prefix'       => 'Acme\\Vendor',
			'folder'       => 'lib',
			'temp'         => 'build/tmp',
			'globals'      => array( 'wordpress' ),
			'composerjson' => 'deps.json',
			'composerlock' => 'deps.lock',
			'autorun'      => false,
		) );

		self::assertSame( 'Acme\\Vendor', $config->prefix );
		self::assertSame( self::ROOT . '/lib', $config->folder );
		self::assertSame( self::ROOT . '/build/tmp', $config->tempDir );
		self::assertSame( array( 'wordpress' ), $config->globals );
		self::assertSame( self::ROOT . '/deps.json', $config->composerJson );
		self::assertSame( self::ROOT . '/deps.lock', $config->composerLock );
		self::assertFalse( $config->autorun );
	}

	public function test_absolute_paths_are_left_alone(): void {
		$config = $this->config( array(
			'prefix'       => 'A',
			'folder'       => '/srv/build/deps',
			'composerjson' => '/srv/build/deps.json',
		) );

		self::assertSame( '/srv/build/deps', $config->folder );
		self::assertSame( '/srv/build/deps.json', $config->composerJson );
	}

	public function test_the_root_directory_is_normalised(): void {
		$config = $this->config( array( 'prefix' => 'A' ), '/projects/./plugin/sub/..' );

		self::assertSame( '/projects/plugin', $config->rootDir );
	}

	public function test_an_empty_vendor_dir_falls_back_to_the_project_vendor(): void {
		self::assertSame( self::ROOT . '/vendor', $this->config( array( 'prefix' => 'A' ), self::ROOT, '' )->vendorDir );
	}

	// --- extra.wpify-scoper presence -------------------------------------------------------

	public function test_an_absent_block_is_a_no_op(): void {
		self::assertFalse( Configuration::isConfigured( array() ) );
		self::assertFalse( Configuration::isConfigured( array( 'other-plugin' => array( 'a' => 1 ) ) ) );
	}

	public function test_a_non_array_block_is_a_no_op(): void {
		self::assertFalse( Configuration::isConfigured( array( 'wpify-scoper' => 'yes' ) ) );
		self::assertFalse( Configuration::isConfigured( array( 'wpify-scoper' => true ) ) );
	}

	public function test_an_empty_block_still_counts_as_configured(): void {
		// The block is the opt-in. Present-but-empty is a misconfiguration, not an opt-out, so it
		// has to reach the prefix validation rather than silently doing nothing.
		self::assertTrue( Configuration::isConfigured( array( 'wpify-scoper' => array() ) ) );
	}

	public function test_a_present_block_without_a_prefix_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'extra.wpify-scoper.prefix is missing in /projects/plugin/composer.json' );

		$this->config( array( 'folder' => 'deps' ) );
	}

	// --- prefix validation ------------------------------------------------------------------

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function invalidPrefixes(): iterable {
		yield 'empty string'      => array( '' );
		yield 'zero string'       => array( '0' );
		yield 'hyphen'            => array( 'My-Ns' );
		yield 'leading digit'     => array( '1Foo' );
		yield 'leading separator' => array( '\\Lead' );
		yield 'trailing separator' => array( 'Trail\\' );
		yield 'empty segment'     => array( 'A\\\\B' );
		yield 'space'             => array( 'My Ns' );
		yield 'not a string'      => array( 123 );
		yield 'null'              => array( null );
		yield 'array'             => array( array( 'A' ) );
	}

	#[DataProvider( 'invalidPrefixes' )]
	public function test_it_rejects_an_invalid_prefix( mixed $prefix ): void {
		$this->expectException( RuntimeException::class );

		$this->config( array( 'prefix' => $prefix ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function validPrefixes(): iterable {
		yield 'single segment'    => array( 'MyPlugin' );
		yield 'two segments'      => array( 'A\\B' );
		yield 'many segments'     => array( 'Acme\\Plugin\\Vendor' );
		yield 'underscore'        => array( '_Private\\Deps' );
		yield 'digits after first' => array( 'Ns2\\V3' );
		yield 'unicode'           => array( 'Ünïcode' );
	}

	#[DataProvider( 'validPrefixes' )]
	public function test_it_accepts_a_valid_prefix( string $prefix ): void {
		self::assertSame( $prefix, $this->config( array( 'prefix' => $prefix ) )->prefix );
	}

	public function test_the_error_names_the_offending_prefix_and_the_file(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '"My-Ns" in /projects/plugin/composer.json is not a valid PHP namespace' );

		$this->config( array( 'prefix' => 'My-Ns' ) );
	}

	// --- composerlock derivation -------------------------------------------------------------

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function lockDerivations(): iterable {
		yield 'default'            => array( 'composer-deps.json', 'composer-deps.lock' );
		yield 'custom name'        => array( 'scoped.json', 'scoped.lock' );
		yield 'nested'             => array( 'build/scoped.json', 'build/scoped.lock' );
		yield 'json in the middle' => array( 'my.json.deps.json', 'my.json.deps.lock' );
		// `.lock` is appended rather than substituted: deriving the same name as the manifest would
		// have the run publish the lock over the manifest it was resolved from.
		yield 'no json suffix'     => array( 'deps-manifest', 'deps-manifest.lock' );
	}

	#[DataProvider( 'lockDerivations' )]
	public function test_the_lock_file_is_derived_from_the_manifest( string $json, string $lock ): void {
		$config = $this->config( array( 'prefix' => 'A', 'composerjson' => $json ) );

		self::assertSame( self::ROOT . '/' . $json, $config->composerJson );
		self::assertSame( self::ROOT . '/' . $lock, $config->composerLock );
	}

	public function test_a_lock_that_points_at_the_manifest_is_rejected(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'points at the manifest /projects/plugin/scoped.json' );

		$this->config( array(
			'prefix'       => 'A',
			'composerjson' => 'scoped.json',
			'composerlock' => 'scoped.json',
		) );
	}

	public function test_an_explicit_lock_wins_over_the_derived_one(): void {
		$config = $this->config( array(
			'prefix'       => 'A',
			'composerjson' => 'scoped.json',
			'composerlock' => 'elsewhere/other.lock',
		) );

		self::assertSame( self::ROOT . '/elsewhere/other.lock', $config->composerLock );
	}

	// --- globals -----------------------------------------------------------------------------

	public function test_an_empty_globals_list_falls_back_to_the_defaults(): void {
		// Preserved verbatim from the previous implementation: `! empty()` treated [] as absent.
		self::assertSame( Configuration::DEFAULT_GLOBALS, $this->config( array( 'prefix' => 'A', 'globals' => array() ) )->globals );
	}

	public function test_a_non_array_globals_value_falls_back_to_the_defaults(): void {
		self::assertSame( Configuration::DEFAULT_GLOBALS, $this->config( array( 'prefix' => 'A', 'globals' => 'wordpress' ) )->globals );
	}

	public function test_globals_entries_are_coerced_to_strings_and_reindexed(): void {
		$config = $this->config( array( 'prefix' => 'A', 'globals' => array( 3 => 'wordpress', 7 => 'wp-cli' ) ) );

		self::assertSame( array( 'wordpress', 'wp-cli' ), $config->globals );
	}

	// --- autorun -----------------------------------------------------------------------------

	/**
	 * @return iterable<string, array{array<string, mixed>, bool}>
	 */
	public static function autorunValues(): iterable {
		yield 'absent'         => array( array(), true );
		yield 'true'           => array( array( 'autorun' => true ), true );
		yield 'false'          => array( array( 'autorun' => false ), false );
		yield 'null'           => array( array( 'autorun' => null ), true );
		// Only a literal `false` opts out - preserved verbatim from the previous implementation,
		// where the string "false" (a common JSON mistake) left autorun enabled.
		yield 'string "false"' => array( array( 'autorun' => 'false' ), true );
		yield 'zero'           => array( array( 'autorun' => 0 ), true );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	#[DataProvider( 'autorunValues' )]
	public function test_autorun_only_opts_out_on_a_literal_false( array $settings, bool $expected ): void {
		self::assertSame( $expected, $this->config( $settings + array( 'prefix' => 'A' ) )->autorun );
	}
}
