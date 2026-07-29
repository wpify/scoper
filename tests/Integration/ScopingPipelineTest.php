<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Integration;

use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wpify\Scoper\Configuration;
use Wpify\Scoper\ProcessComposerRunner;
use Wpify\Scoper\Scoper;

/**
 * Runs the whole pipeline against the offline fixture in tests/fixtures/e2e and asserts on the
 * bytes that land in the consumer's deps/ folder.
 *
 * Every assertion here corresponds to a way the tool has silently produced broken output: a
 * WordPress symbol left prefixed, a vendor symbol wrongly un-prefixed, an autoload key collision
 * that makes one of two vendor trees invisible, or a delete that followed a symlink out of the
 * project. It is slow because it spawns Composer and php-scoper - hence the group.
 */
#[Group( 'integration' )]
#[CoversNothing]
final class ScopingPipelineTest extends TestCase {

	private const PREFIX = 'FixtureDeps';

	private static string $root = '';

	private static string $output = '';

	public static function setUpBeforeClass(): void {
		self::$root = self::materialiseFixture();

		$manifest = json_decode( (string) file_get_contents( self::$root . '/composer.json' ), true );

		self::assertIsArray( $manifest );
		self::assertIsArray( $manifest['extra'] );

		$config = Configuration::fromExtra( $manifest['extra'], self::$root, self::$root . '/vendor' );
		$io     = new BufferIO();

		$exitCode = ( new Scoper( $config, $io, new ProcessComposerRunner( $io ) ) )->run( 'update', true );

		self::$output = $io->getOutput();

		self::assertSame( 0, $exitCode, 'the pipeline failed:' . PHP_EOL . self::$output );
	}

	public static function tearDownAfterClass(): void {
		if ( '' !== self::$root ) {
			( new Filesystem() )->removeDirectory( self::$root );
		}

		self::$root   = '';
		self::$output = '';
	}

	/**
	 * Copies the fixture into a scratch directory and points the path repository at the copy.
	 *
	 * The path repository URL has to be absolute: the manifest is copied into the temp workspace
	 * before the nested Composer resolves it, so a relative URL would resolve from the wrong place.
	 */
	private static function materialiseFixture(): string {
		$filesystem = new Filesystem();
		$root       = $filesystem->normalizePath( sys_get_temp_dir() . '/wpify-scoper-e2e-' . bin2hex( random_bytes( 6 ) ) );

		$filesystem->ensureDirectoryExists( $root );

		self::assertTrue( $filesystem->copy( dirname( __DIR__ ) . '/fixtures/e2e', $root ) );

		$manifest = (string) file_get_contents( $root . '/composer-deps.json' );

		file_put_contents( $root . '/composer-deps.json', str_replace( '%%PKG_DIR%%', $root . '/pkg', $manifest ) );

		// deps/ starts out as a symlink pointing outside the folder the pipeline replaces. The
		// swap has to move the link aside and delete the link itself - following it would wipe
		// the target, which is how the tool used to eat a project's real dependency tree.
		$filesystem->ensureDirectoryExists( $root . '/deps-real' );
		file_put_contents( $root . '/deps-real/sentinel.txt', 'must survive the swap' );

		self::assertTrue( symlink( $root . '/deps-real', $root . '/deps' ) );

		return $root;
	}

	private function scoped( string $relativePath ): string {
		$path = self::$root . '/deps/' . $relativePath;

		self::assertFileExists( $path, self::$output );

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			throw new RuntimeException( 'cannot read ' . $path );
		}

		return $contents;
	}

	// --- the scoped tree exists and is loadable ------------------------------------------------

	public function test_the_scoped_autoloader_is_produced(): void {
		self::assertFileExists( self::$root . '/deps/scoper-autoload.php' );
		self::assertFileExists( self::$root . '/deps/autoload.php' );
		self::assertFileExists( self::$root . '/deps/composer/autoload_static.php' );
		self::assertFileExists( self::$root . '/deps/acme/lib/composer.json' );
	}

	public function test_every_scoped_file_is_syntactically_valid_php(): void {
		$files = glob( self::$root . '/deps/acme/lib/{src,wpseo,pobox}/*.php', GLOB_BRACE );

		self::assertIsArray( $files );
		self::assertNotEmpty( $files );

		foreach ( $files as $file ) {
			exec( sprintf( '%s -l %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $file ) ), $out, $status );

			self::assertSame( 0, $status, $file . ': ' . implode( PHP_EOL, $out ) );
		}
	}

	public function test_the_lock_file_is_published_to_the_project_root(): void {
		self::assertFileExists( self::$root . '/composer-deps.lock' );

		$lock = json_decode( (string) file_get_contents( self::$root . '/composer-deps.lock' ), true );

		self::assertIsArray( $lock );
		self::assertSame( 'acme/lib', $lock['packages'][0]['name'] );
	}

	// --- what must be prefixed -----------------------------------------------------------------

	public function test_a_vendor_class_is_moved_into_the_prefix(): void {
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\Acme\\Lib;', $this->scoped( 'acme/lib/src/Greeter.php' ) );
	}

	public function test_a_vendor_function_is_moved_into_the_prefix(): void {
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\Acme\\Lib;', $this->scoped( 'acme/lib/src/functions.php' ) );
		self::assertStringContainsString( 'function acme_site_name(', $this->scoped( 'acme/lib/src/functions.php' ) );
	}

	/**
	 * `WPSEO` starts with `WP`, an excluded WordPress class, and `POBox` starts with `PO`.
	 * The unanchored patcher this replaced stripped the prefix off both, which put third-party
	 * code straight back into the global namespace it was supposed to be moved out of.
	 */
	public function test_a_vendor_namespace_that_starts_with_an_excluded_class_stays_prefixed(): void {
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\WPSEO;', $this->scoped( 'acme/lib/wpseo/Utils.php' ) );
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\POBox;', $this->scoped( 'acme/lib/pobox/Mailer.php' ) );
	}

	public function test_references_to_such_a_namespace_stay_prefixed(): void {
		$consumer = $this->scoped( 'acme/lib/src/Consumer.php' );

		self::assertStringContainsString( 'use ' . self::PREFIX . '\\WPSEO\\Utils;', $consumer );
		self::assertStringContainsString( 'use ' . self::PREFIX . '\\POBox\\Mailer;', $consumer );
		self::assertStringContainsString( '\\' . self::PREFIX . '\\WPSEO\\Utils(', $consumer );
		self::assertStringContainsString( '\\' . self::PREFIX . '\\POBox\\Mailer(', $consumer );

		self::assertStringNotContainsString( 'use WPSEO\\Utils;', $consumer );
		self::assertStringNotContainsString( 'use POBox\\Mailer;', $consumer );
	}

	public function test_a_vendor_class_whose_name_starts_with_an_excluded_class_stays_prefixed(): void {
		self::assertStringContainsString( 'class WPSEO_Utils', $this->scoped( 'acme/lib/src/WPSEO_Utils.php' ) );
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\Acme\\Lib;', $this->scoped( 'acme/lib/src/WPSEO_Utils.php' ) );
		self::assertStringContainsString( 'namespace ' . self::PREFIX . '\\Acme\\Lib;', $this->scoped( 'acme/lib/src/POStuff.php' ) );
	}

	// --- what must stay global ------------------------------------------------------------------

	public function test_wordpress_functions_stay_global(): void {
		foreach ( array( 'acme/lib/src/functions.php', 'acme/lib/src/Greeter.php' ) as $file ) {
			$contents = $this->scoped( $file );

			self::assertStringContainsString( 'get_option(', $contents );
			self::assertStringNotContainsString( self::PREFIX . '\\get_option', $contents );
		}
	}

	public function test_wordpress_classes_stay_global(): void {
		$greeter = $this->scoped( 'acme/lib/src/Greeter.php' );

		self::assertStringContainsString( 'use WP_Post;', $greeter );
		self::assertStringContainsString( 'use WP_Query;', $greeter );
		self::assertStringNotContainsString( 'use ' . self::PREFIX . '\\WP_Post;', $greeter );
		self::assertStringNotContainsString( 'use ' . self::PREFIX . '\\WP_Query;', $greeter );
	}

	/**
	 * Both roots are listed in exclude-namespaces, but the referenced symbols sit several
	 * segments below them. Whole-string matching left these prefixed, which fatals HPOS order
	 * screens and every SMTP send in production.
	 */
	public function test_a_child_of_an_excluded_namespace_stays_global(): void {
		$integration = $this->scoped( 'acme/lib/src/Integration.php' );

		$hpos = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';

		self::assertStringContainsString( 'use ' . $hpos . ';', $integration );
		self::assertStringContainsString( '\\' . $hpos . '(', $integration );
		self::assertStringNotContainsString( self::PREFIX . '\\Automattic', $integration );

		self::assertStringContainsString( 'use PHPMailer\\PHPMailer\\PHPMailer;', $integration );
		self::assertStringNotContainsString( self::PREFIX . '\\PHPMailer', $integration );
	}

	// --- the post-install fixups ------------------------------------------------------------------

	/**
	 * Composer dedupes `files` autoloads through a global keyed by an md5 of the package name and
	 * path, so the scoped and unscoped copies of one package produce the same key and whichever
	 * autoloader runs second skips its own file. Prefixing the keys keeps the two trees apart.
	 */
	public function test_the_files_autoload_keys_carry_the_prefix(): void {
		$static = $this->scoped( 'composer/autoload_static.php' );

		self::assertMatchesRegularExpression( '/\$files\s*=\s*array\s*\(/', $static );
		self::assertMatchesRegularExpression( "/'" . strtolower( self::PREFIX ) . "[[:alnum:]]+'\s*=>/", $static );
	}

	public function test_the_exposed_symbols_are_neutralised(): void {
		$autoload = $this->scoped( 'scoper-autoload.php' );

		self::assertDoesNotMatchRegularExpression( '/^humbug_phpscoper_expose_/m', $autoload );
		self::assertDoesNotMatchRegularExpression( '/^if \(!function_exists\(/m', $autoload );

		// The fixture's vendor declares nothing php-scoper would expose, so this asserts the
		// file was rewritten at all rather than that a marker comment is present.
		self::assertStringContainsString( '$GLOBALS[\'__composer_autoload_files\']', $autoload );
	}

	// --- the workspace and the sources survive -----------------------------------------------------

	public function test_no_temporary_directory_is_left_behind(): void {
		$leftovers = glob( self::$root . '/tmp-*' );

		self::assertSame( array(), $leftovers, 'the temp workspace was not cleaned up' );
	}

	/**
	 * The path repository is mirrored, not symlinked, but the source tree still has to be intact:
	 * anything that deletes through a link or moves the wrong tree would take it with it.
	 */
	public function test_the_path_repository_source_tree_is_intact(): void {
		foreach ( array( 'composer.json', 'src/Greeter.php', 'src/Integration.php', 'wpseo/Utils.php', 'pobox/Mailer.php' ) as $file ) {
			$path = self::$root . '/pkg/acme-lib/' . $file;

			self::assertFileExists( $path );
			self::assertGreaterThan( 0, filesize( $path ) );
		}

		// And it is still the unscoped original.
		self::assertStringContainsString(
			'namespace Acme\\Lib;',
			(string) file_get_contents( self::$root . '/pkg/acme-lib/src/Greeter.php' )
		);
	}

	/**
	 * The regression that made the tool destroy data: deps/ was a symlink, and removing it
	 * followed the link and deleted what it pointed at.
	 */
	public function test_replacing_a_symlinked_deps_folder_does_not_delete_its_target(): void {
		self::assertFileExists( self::$root . '/deps-real/sentinel.txt' );
		self::assertSame( 'must survive the swap', file_get_contents( self::$root . '/deps-real/sentinel.txt' ) );

		self::assertDirectoryExists( self::$root . '/deps' );
		self::assertFalse( is_link( self::$root . '/deps' ), 'deps/ should be a real directory after the swap' );
		self::assertFileDoesNotExist( self::$root . '/deps-real/scoper-autoload.php' );
	}

	// --- the manifest is only ever read --------------------------------------------------------

	public function test_the_scoped_manifest_is_not_rewritten(): void {
		$manifest = (string) file_get_contents( self::$root . '/composer-deps.json' );

		self::assertJson( $manifest );
		self::assertStringContainsString( '"acme/lib": "*"', $manifest );
		self::assertStringNotContainsString( 'scripts', $manifest );
		self::assertStringNotContainsString( self::$root . '/tmp-', $manifest );
	}
}
