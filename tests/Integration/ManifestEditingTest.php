<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Integration;

use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Wpify\Scoper\Configuration;
use Wpify\Scoper\ProcessComposerRunner;
use Wpify\Scoper\Scoper;
use Wpify\Scoper\ScoperCommand;
use Wpify\Scoper\ScoperRequest;

/**
 * Drives `require` and `remove` against the offline fixture and asserts on the bytes of the scoped
 * manifest afterwards.
 *
 * The unit suite covers what a delta is; this covers that a real nested Composer run produces one,
 * that it is published only with the lock, and that removing the last scoped dependency does not
 * take the pipeline down with it - the scoped tree still has to be produced from an empty vendor.
 */
#[Group( 'integration' )]
#[CoversNothing]
final class ManifestEditingTest extends TestCase {

	private string $root = '';

	protected function tearDown(): void {
		if ( '' !== $this->root ) {
			( new Filesystem() )->removeDirectory( $this->root );
		}

		$this->root = '';
	}

	/**
	 * Parsed through the definition {@see ScoperCommand} actually declares, so this exercises the
	 * real command line surface rather than a copy of it that could drift.
	 *
	 * @param list<string> $packages
	 * @param list<string> $flags
	 */
	private function request( string $action, array $packages = array(), array $flags = array() ): ScoperRequest {
		$parameters = array( 'action' => $action, 'packages' => $packages );

		foreach ( $flags as $flag ) {
			$parameters[ $flag ] = true;
		}

		return ScoperRequest::fromInput( new ArrayInput( $parameters, ( new ScoperCommand() )->getDefinition() ) );
	}

	private function scope( ScoperRequest $request ): string {
		$manifest = json_decode( (string) file_get_contents( $this->root . '/composer.json' ), true );

		self::assertIsArray( $manifest );
		self::assertIsArray( $manifest['extra'] );

		$config = Configuration::fromExtra( $manifest['extra'], $this->root, $this->root . '/vendor' );
		$io     = new BufferIO();

		$exitCode = ( new Scoper( $config, $io, new ProcessComposerRunner( $io ) ) )->run( $request );
		$output   = $io->getOutput();

		self::assertSame( 0, $exitCode, 'the pipeline failed:' . PHP_EOL . $output );

		return $output;
	}

	private function manifest(): string {
		return (string) file_get_contents( $this->root . '/composer-deps.json' );
	}

	private function materialiseFixture(): void {
		$filesystem = new Filesystem();
		$root       = $filesystem->normalizePath( sys_get_temp_dir() . '/wpify-scoper-edit-' . bin2hex( random_bytes( 6 ) ) );

		$filesystem->ensureDirectoryExists( $root );

		self::assertTrue( $filesystem->copy( dirname( __DIR__ ) . '/fixtures/e2e', $root ) );

		$manifest = (string) file_get_contents( $root . '/composer-deps.json' );

		file_put_contents( $root . '/composer-deps.json', str_replace( '%%PKG_DIR%%', $root . '/pkg', $manifest ) );

		$this->root = $root;
	}

	/**
	 * One fixture, three runs, in the order a user would hit them. Each phase spawns Composer and
	 * php-scoper, so they share a fixture rather than each paying for their own.
	 */
	public function test_require_and_remove_edit_the_scoped_manifest(): void {
		$this->materialiseFixture();

		$before = $this->manifest();

		// --- a dry run writes nothing at all ---------------------------------------------------

		$this->scope( $this->request( 'remove', array( 'acme/lib' ), array( '--dry-run' ) ) );

		self::assertSame( $before, $this->manifest(), 'a dry run must not touch the scoped manifest' );
		self::assertFileDoesNotExist( $this->root . '/composer-deps.lock', 'a dry run must not publish a lock' );
		self::assertFileDoesNotExist( $this->root . '/deps', 'a dry run must not produce a scoped tree' );
		self::assertSame( array(), glob( $this->root . '/tmp-*' ), 'a dry run must clean up its workspace' );

		// --- removing the only scoped dependency ------------------------------------------------

		$this->scope( $this->request( 'remove', array( 'acme/lib' ) ) );

		$emptied = $this->manifest();

		self::assertJson( $emptied );
		self::assertStringNotContainsString( 'acme/lib', $emptied );

		// Emptying a block removes it, the way Composer's own remove does.
		self::assertStringNotContainsString( '"require"', $emptied );

		// Everything the run has no business touching is still there, byte for byte.
		self::assertStringContainsString( '"name": "fixture/deps"', $emptied );
		self::assertStringContainsString( '"minimum-stability": "dev"', $emptied );
		self::assertStringContainsString( '"prefer-stable": true', $emptied );
		self::assertStringContainsString( '"type": "path"', $emptied );

		// The scoped tree is still produced, from a vendor that now holds nothing.
		self::assertFileExists( $this->root . '/deps/scoper-autoload.php' );
		self::assertDirectoryDoesNotExist( $this->root . '/deps/acme' );

		$lock = json_decode( (string) file_get_contents( $this->root . '/composer-deps.lock' ), true );

		self::assertIsArray( $lock );
		self::assertSame( array(), $lock['packages'] );

		// --- and putting it back ------------------------------------------------------------------

		$this->scope( $this->request( 'require', array( 'acme/lib:*' ) ) );

		$restored = $this->manifest();

		self::assertJson( $restored );
		self::assertStringContainsString( '"acme/lib"', $restored );
		self::assertStringContainsString( '"minimum-stability": "dev"', $restored );

		self::assertFileExists( $this->root . '/deps/acme/lib/src/Greeter.php' );
		self::assertStringContainsString(
			'namespace FixtureDeps\\Acme\\Lib;',
			(string) file_get_contents( $this->root . '/deps/acme/lib/src/Greeter.php' )
		);

		self::assertSame( array(), glob( $this->root . '/tmp-*' ), 'the workspace must be cleaned up' );
	}

	/**
	 * The delta goes into require-dev when --dev is given, and the run says so on screen.
	 */
	public function test_dev_requires_land_in_require_dev(): void {
		$this->materialiseFixture();

		$output = $this->scope( $this->request( 'require', array( 'acme/lib:*' ), array( '--dev' ) ) );

		$manifest = json_decode( $this->manifest(), true );

		self::assertIsArray( $manifest );
		self::assertIsArray( $manifest['require-dev'] );
		self::assertArrayHasKey( 'acme/lib', $manifest['require-dev'] );

		// It was already in require, so it moved rather than being declared twice.
		self::assertIsArray( $manifest['require'] ?? array() );
		self::assertArrayNotHasKey( 'acme/lib', $manifest['require'] ?? array() );

		self::assertStringContainsString( 'composer-deps.json: +acme/lib', $output );
	}
}
