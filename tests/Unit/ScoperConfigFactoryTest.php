<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wpify\Scoper\Configuration;
use Wpify\Scoper\ScoperConfigFactory;

#[CoversClass( ScoperConfigFactory::class )]
final class ScoperConfigFactoryTest extends TestCase {

	private string $tempDir;

	private BufferIO $io;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/wpify-scoper-factory-' . bin2hex( random_bytes( 6 ) );
		$this->io      = new BufferIO();
	}

	protected function tearDown(): void {
		( new Filesystem() )->removeDirectory( $this->tempDir );
	}

	/**
	 * @param list<string> $globals
	 *
	 * @return array<string, mixed>
	 */
	private function build( array $globals ): array {
		$config = Configuration::fromExtra(
			array( 'wpify-scoper' => array( 'prefix' => 'Acme\\Deps', 'globals' => $globals, 'temp' => $this->tempDir ) ),
			$this->tempDir . '/root',
			$this->tempDir . '/root/vendor'
		);

		$path = ( new ScoperConfigFactory( $config, $this->io, new Filesystem(), dirname( __DIR__, 2 ) ) )
			->create( '/src', '/dest' );

		self::assertFileExists( $path );

		$written = require $this->tempDir . '/scoper.config.php';

		self::assertIsArray( $written );

		return $written;
	}

	/**
	 * `globals: []` falls back to the defaults, so the empty case has to be forced past
	 * {@see Configuration} - which is exactly what a project that lists only unknown names does.
	 *
	 * @return array<string, mixed>
	 */
	private function buildWithNoSymbolLists(): array {
		return $this->build( array( 'nothing-here' ) );
	}

	public function test_it_writes_the_files_php_scoper_needs(): void {
		$this->build( array( 'wordpress' ) );

		self::assertFileExists( $this->tempDir . '/scoper.inc.php' );
		self::assertFileExists( $this->tempDir . '/scoper.config.php' );
		// The patcher's collaborator has to travel with the config: php-scoper runs it from
		// inside its phar, where this package's autoloader does not exist.
		self::assertFileExists( $this->tempDir . '/SymbolUnprefixer.php' );
	}

	public function test_it_carries_the_prefix_source_and_destination_through(): void {
		$config = $this->build( array( 'wordpress' ) );

		self::assertSame( 'Acme\\Deps', $config['prefix'] );
		self::assertSame( '/src', $config['source'] );
		self::assertSame( '/dest', $config['destination'] );
	}

	public function test_an_empty_symbol_set_still_produces_the_keys_the_patcher_reads(): void {
		// Regression guard: without the seed, config/scoper.inc.php read an undefined key and
		// blew up with a TypeError from inside a php-scoper patcher, mid-scope.
		$config = $this->buildWithNoSymbolLists();

		self::assertArrayHasKey( 'exclude-classes', $config );
		self::assertArrayHasKey( 'exclude-namespaces', $config );
		self::assertSame( array(), $config['exclude-classes'] );
		self::assertSame( array(), $config['exclude-namespaces'] );
	}

	public function test_the_constant_exclusions_are_seeded_before_the_symbol_lists_merge(): void {
		self::assertSame( array( 'NULL', 'TRUE', 'FALSE' ), $this->buildWithNoSymbolLists()['exclude-constants'] );

		// And the seeds survive the merge with a real list rather than being replaced by it.
		self::assertSame(
			array( 'NULL', 'TRUE', 'FALSE' ),
			array_slice( $this->build( array( 'wordpress' ) )['exclude-constants'], 0, 3 )
		);
	}

	/**
	 * @return iterable<string, array{list<string>}>
	 */
	public static function knownGlobals(): iterable {
		yield 'wordpress'        => array( array( 'wordpress' ) );
		yield 'woocommerce'      => array( array( 'woocommerce' ) );
		yield 'action-scheduler' => array( array( 'action-scheduler' ) );
		yield 'wp-cli'           => array( array( 'wp-cli' ) );
	}

	/**
	 * @param list<string> $globals
	 */
	#[DataProvider( 'knownGlobals' )]
	public function test_every_shipped_symbol_list_loads( array $globals ): void {
		$config = $this->build( $globals );

		self::assertNotSame( array(), $config['exclude-classes'] );
		self::assertSame( '', $this->io->getOutput() );
	}

	public function test_merging_two_symbol_lists_de_duplicates(): void {
		// WooCommerce bundles all of Action Scheduler, so the two lists genuinely overlap and
		// array_merge_recursive() - which concatenates rather than de-duplicates - would double up.
		$merged = $this->build( array( 'woocommerce', 'action-scheduler' ) );

		foreach ( array( 'exclude-classes', 'exclude-functions', 'exclude-constants', 'exclude-namespaces' ) as $key ) {
			self::assertSame(
				array_values( array_unique( $merged[ $key ] ) ),
				$merged[ $key ],
				sprintf( '%s contains duplicates', $key )
			);
		}
	}

	public function test_the_merge_covers_the_union_of_both_lists(): void {
		$woo    = $this->build( array( 'woocommerce' ) );
		$as     = $this->build( array( 'action-scheduler' ) );
		$merged = $this->build( array( 'woocommerce', 'action-scheduler' ) );

		self::assertSame(
			array(),
			array_diff( array_merge( $woo['exclude-classes'], $as['exclude-classes'] ), $merged['exclude-classes'] )
		);
	}

	public function test_the_merge_is_order_independent(): void {
		$forwards  = $this->build( array( 'wordpress', 'wp-cli' ) );
		$backwards = $this->build( array( 'wp-cli', 'wordpress' ) );

		self::assertSame( $forwards, $backwards );
	}

	public function test_an_unknown_global_warns_rather_than_fatals(): void {
		$config = $this->build( array( 'wordpress', 'wordpres' ) );

		self::assertNotSame( array(), $config['exclude-classes'] );
		self::assertStringContainsString( 'unknown extra.wpify-scoper.globals entry "wordpres"', $this->io->getOutput() );
		self::assertStringContainsString( 'Known values: action-scheduler, woocommerce, wordpress, wp-cli', $this->io->getOutput() );
	}

	public function test_a_removed_global_warns_with_the_reason(): void {
		$this->build( array( 'plugin-update-checker' ) );

		$output = $this->io->getOutput();

		self::assertStringContainsString( '"plugin-update-checker" is deprecated and ignored', $output );
		self::assertStringContainsString( 'Remove it from your composer.json', $output );
	}

	public function test_a_scoper_custom_php_in_the_project_root_is_picked_up(): void {
		$root = $this->tempDir . '/root';

		( new Filesystem() )->ensureDirectoryExists( $root );
		file_put_contents( $root . '/scoper.custom.php', '<?php // custom' );

		$this->build( array( 'wordpress' ) );

		self::assertFileExists( $this->tempDir . '/scoper.custom.php' );
		self::assertStringContainsString( 'using the customizations from', $this->io->getOutput() );
	}

	public function test_no_scoper_custom_php_is_not_an_error(): void {
		$this->build( array( 'wordpress' ) );

		self::assertFileDoesNotExist( $this->tempDir . '/scoper.custom.php' );
	}
}
