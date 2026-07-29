<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wpify\Scoper\Tools\SymbolCollector;
use Wpify\Scoper\Tools\SymbolExtractor;

/**
 * Golden-file test for the symbol extraction.
 *
 * A silent truncation here is the worst failure this project has: a WordPress function missing
 * from the list gets scoped, and the consumer's site fatals on a call to an undefined function.
 * The expected file is checked in; regenerate it with `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`
 * and review the diff, which is the point.
 */
#[CoversClass( SymbolExtractor::class )]
#[CoversClass( SymbolCollector::class )]
final class SymbolExtractorTest extends TestCase {

	private const PACKAGE = 'fixture/symbols';
	private const VERSION = '1.2.3';
	private const DATE    = '2026-01-01';

	private static function inputDir(): string {
		return dirname( __DIR__ ) . '/fixtures/symbols-input';
	}

	private static function expectedFile(): string {
		return dirname( __DIR__ ) . '/fixtures/symbols-expected.php';
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function extract(): array {
		$extractor = new SymbolExtractor();
		$symbols   = $extractor->extract( self::inputDir() );

		self::assertSame( array(), $extractor->errors(), 'the fixture tree must parse cleanly' );

		return $symbols;
	}

	public function test_the_rendered_output_matches_the_golden_file(): void {
		$rendered = ( new SymbolExtractor() )->render( $this->extract(), self::PACKAGE, self::VERSION, self::DATE );

		if ( '' !== (string) getenv( 'UPDATE_SNAPSHOTS' ) ) {
			file_put_contents( self::expectedFile(), $rendered );

			self::markTestSkipped( 'snapshot updated: ' . self::expectedFile() );
		}

		self::assertStringEqualsFile( self::expectedFile(), $rendered );
	}

	public function test_the_golden_file_is_loadable_php_and_round_trips(): void {
		$loaded = require self::expectedFile();

		self::assertIsArray( $loaded );
		self::assertSame( $this->extract(), $loaded );
	}

	/**
	 * The rendered file must not use explicit integer keys: inserting one symbol would otherwise
	 * renumber every line below it and turn a one-symbol change into a thousand-line diff.
	 */
	public function test_the_rendered_output_is_a_plain_list(): void {
		$rendered = ( new SymbolExtractor() )->render( $this->extract(), self::PACKAGE, self::VERSION, self::DATE );

		self::assertStringNotContainsString( '0 => ', $rendered );
		self::assertStringContainsString( ' * source:    ' . self::PACKAGE, $rendered );
		self::assertStringContainsString( ' * version:   ' . self::VERSION, $rendered );
		self::assertStringContainsString( ' * generated: ' . self::DATE, $rendered );
	}

	// --- the individual visitor branches ------------------------------------------------------

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function collectedSymbols(): iterable {
		yield 'class'                        => array( 'exclude-classes', 'Fixture_Widget' );
		yield 'abstract class'               => array( 'exclude-classes', 'Fixture_Abstract_Widget' );
		yield 'final class'                  => array( 'exclude-classes', 'Fixture_Final_Widget' );
		yield 'interface'                    => array( 'exclude-classes', 'Fixture_Renderable' );
		yield 'trait'                        => array( 'exclude-classes', 'Fixture_Renders' );
		yield 'enum'                         => array( 'exclude-classes', 'Fixture_Status' );
		yield 'class behind class_exists'    => array( 'exclude-classes', 'Fixture_Guarded' );
		yield 'class in an else branch'      => array( 'exclude-classes', 'Fixture_From_Else' );
		yield 'class in an if branch'        => array( 'exclude-classes', 'Fixture_From_If' );
		yield 'class_alias'                  => array( 'exclude-classes', 'Fixture_Guarded_Alias' );
		yield 'class_alias in a namespace'   => array( 'exclude-classes', 'Fixture_Namespaced_Alias' );
		yield 'class in a braced global ns'  => array( 'exclude-classes', 'Fixture_Global_From_Braced' );
		yield 'function'                     => array( 'exclude-functions', 'fixture_render_widget' );
		yield 'function behind exists check' => array( 'exclude-functions', 'fixture_guarded_function' );
		yield 'function in a function body'  => array( 'exclude-functions', 'fixture_cdata' );
		yield 'function in a braced glob ns' => array( 'exclude-functions', 'fixture_global_from_braced' );
		yield 'top-level const'              => array( 'exclude-constants', 'FIXTURE_CONST_A' );
		yield 'second const on one line'     => array( 'exclude-constants', 'FIXTURE_CONST_C' );
		yield 'const in a braced global ns'  => array( 'exclude-constants', 'FIXTURE_GLOBAL_FROM_BRACED' );
		yield 'top-level define'             => array( 'exclude-constants', 'FIXTURE_DEFINED_TOP_LEVEL' );
		yield 'define in a function body'    => array( 'exclude-constants', 'FIXTURE_DEFINED_IN_FUNCTION' );
		yield 'deeply nested define'         => array( 'exclude-constants', 'FIXTURE_DEEPLY_DEFINED' );
		yield 'define inside a namespace'    => array( 'exclude-constants', 'FIXTURE_DEFINED_IN_NAMESPACE' );
		yield 'define in a braced global ns' => array( 'exclude-constants', 'FIXTURE_DEFINED_IN_BRACED_GLOBAL' );
		yield 'namespace'                    => array( 'exclude-namespaces', 'Fixture\\Vendor\\Feature' );
		yield 'braced namespace'             => array( 'exclude-namespaces', 'Fixture\\Braced' );
	}

	#[DataProvider( 'collectedSymbols' )]
	public function test_it_collects( string $category, string $symbol ): void {
		self::assertContains( $symbol, $this->extract()[ $category ] );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function skippedSymbols(): iterable {
		yield 'a class under tests/'            => array( 'exclude-classes', 'Fixture_Must_Not_Be_Collected_Tests' );
		yield 'a function under tests/'         => array( 'exclude-functions', 'fixture_must_not_be_collected_tests' );
		yield 'a class under vendor/'           => array( 'exclude-classes', 'Fixture_Must_Not_Be_Collected_Vendor' );
		yield 'a class in a .txt file'          => array( 'exclude-classes', 'Fixture_Must_Not_Be_Collected_Text' );
		// Everything inside a named namespace is already covered by the namespace entry.
		yield 'a class inside a namespace'      => array( 'exclude-classes', 'NamespacedWidget' );
		yield 'a function inside a namespace'   => array( 'exclude-functions', 'namespaced_function' );
		yield 'a const inside a namespace'      => array( 'exclude-constants', 'NAMESPACED_CONST' );
		yield 'a class in a braced namespace'   => array( 'exclude-classes', 'BracedWidget' );
		yield 'a function in a braced ns'       => array( 'exclude-functions', 'braced_function' );
	}

	#[DataProvider( 'skippedSymbols' )]
	public function test_it_skips( string $category, string $symbol ): void {
		self::assertNotContains( $symbol, $this->extract()[ $category ] );
	}

	public function test_an_anonymous_class_is_not_collected(): void {
		foreach ( $this->extract()['exclude-classes'] as $class ) {
			self::assertNotSame( '', $class );
			self::assertStringNotContainsString( '@anonymous', $class );
		}
	}

	public function test_a_dynamic_define_or_alias_is_skipped_rather_than_guessed_at(): void {
		$symbols = $this->extract();

		foreach ( $symbols['exclude-constants'] as $constant ) {
			self::assertStringNotContainsString( 'DYNAMIC', $constant );
		}

		// class_alias() with a variable second argument yields nothing at all.
		self::assertSame(
			array( 'Fixture_Guarded_Alias', 'Fixture_Namespaced_Alias' ),
			array_values( array_filter(
				$symbols['exclude-classes'],
				static fn ( string $class ): bool => str_contains( $class, 'Alias' )
			) )
		);
	}

	// --- output shape -------------------------------------------------------------------------

	public function test_every_category_is_present_sorted_and_unique(): void {
		$symbols = $this->extract();

		self::assertSame( SymbolExtractor::CATEGORIES, array_keys( $symbols ) );

		foreach ( $symbols as $category => $values ) {
			self::assertSame( array_values( array_unique( $values ) ), $values, $category . ' has duplicates' );

			$sorted = $values;
			sort( $sorted, SORT_STRING );

			self::assertSame( $sorted, $values, $category . ' is not sorted' );
			self::assertSame( array_keys( $values ), range( 0, count( $values ) - 1 ), $category . ' is not a list' );
		}
	}

	public function test_the_result_does_not_depend_on_the_filesystem_order(): void {
		self::assertSame( $this->extract(), $this->extract() );
	}

	public function test_the_symbol_count_matches_the_categories(): void {
		$symbols = $this->extract();

		self::assertSame(
			count( $symbols['exclude-classes'] ) + count( $symbols['exclude-constants'] )
			+ count( $symbols['exclude-functions'] ) + count( $symbols['exclude-namespaces'] ),
			SymbolExtractor::count( $symbols )
		);
	}

	// --- failure handling ---------------------------------------------------------------------

	public function test_a_parse_error_is_reported_rather_than_swallowed(): void {
		$file = tempnam( sys_get_temp_dir(), 'wpify-scoper-broken' ) . '.php';

		file_put_contents( $file, '<?php class Broken { public function' );

		try {
			$extractor = new SymbolExtractor();
			$symbols   = $extractor->extractFile( $file );

			self::assertSame( array(), $symbols['exclude-classes'] );
			self::assertCount( 1, $extractor->errors() );
			self::assertStringContainsString( 'parse error', $extractor->errors()[0] );
		} finally {
			@unlink( $file );
		}
	}

	public function test_an_unreadable_directory_yields_nothing_rather_than_throwing(): void {
		$extractor = new SymbolExtractor();

		self::assertSame( array(), $extractor->files( '/no/such/directory', '/no/such' ) );
		self::assertSame(
			array_fill_keys( SymbolExtractor::CATEGORIES, array() ),
			$extractor->extract( '/no/such/directory' )
		);
	}
}
