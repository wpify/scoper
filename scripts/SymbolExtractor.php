<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tools;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Collects every symbol a scoped dependency must not have prefixed, anywhere in a source tree.
 *
 * Development-only: this class is never loaded at runtime, it is the engine behind
 * `composer extract`. It lives in scripts/ (which ships to nobody, see .gitattributes) rather than
 * in src/ so that the runtime package does not carry a nikic/php-parser dependency it never uses.
 */
final class SymbolExtractor {

	/**
	 * The php-scoper config keys this produces, in the order they are rendered.
	 *
	 * @var list<string>
	 */
	public const CATEGORIES = array(
		'exclude-classes',
		'exclude-constants',
		'exclude-functions',
		'exclude-namespaces',
	);

	private ?Parser $parser = null;

	/**
	 * Parse failures of the last extract() call.
	 *
	 * A parse error used to be echoed and forgotten, which silently truncated the symbol list -
	 * and a truncated WordPress list means WordPress functions get scoped, the worst possible
	 * failure mode. The caller decides what to do with them.
	 *
	 * @var list<string>
	 */
	private array $errors = array();

	public function __construct( private readonly string $phpVersion = '8.1.0' ) {
	}

	/**
	 * @return array<string, list<string>> Sorted, de-duplicated, keyed by exclusion category.
	 */
	public function extract( string $directory, ?string $relativeTo = null ): array {
		$this->errors = array();
		$symbols      = array_fill_keys( self::CATEGORIES, array() );

		foreach ( $this->files( $directory, $relativeTo ?? $directory ) as $file ) {
			foreach ( $this->extractFile( $file ) as $category => $values ) {
				$symbols[ $category ] = array_merge( $symbols[ $category ], $values );
			}
		}

		// Sorted and reindexed so that the output does not depend on the order the filesystem
		// happened to hand the files over.
		foreach ( $symbols as $category => $values ) {
			$values = array_values( array_unique( $values ) );
			sort( $values, SORT_STRING );

			$symbols[ $category ] = $values;
		}

		ksort( $symbols );

		return $symbols;
	}

	/**
	 * @return array<string, list<string>> Raw, unsorted, possibly with duplicates.
	 */
	public function extractFile( string $file ): array {
		$contents = @file_get_contents( $file );

		if ( false === $contents ) {
			$this->errors[] = sprintf( 'cannot read %s', $file );

			return array_fill_keys( self::CATEGORIES, array() );
		}

		try {
			$ast = $this->parser()->parse( $contents );
		} catch ( Error $error ) {
			$this->errors[] = sprintf( 'parse error: %s in %s', $error->getMessage(), $file );

			return array_fill_keys( self::CATEGORIES, array() );
		}

		if ( null === $ast ) {
			$this->errors[] = sprintf( 'the parser produced no statements for %s', $file );

			return array_fill_keys( self::CATEGORIES, array() );
		}

		$collector = new SymbolCollector();
		$traverser = new NodeTraverser();
		$traverser->addVisitor( $collector );
		$traverser->traverse( $ast );

		return $collector->symbols;
	}

	/**
	 * Every PHP file under $directory that could be loaded in a WordPress request.
	 *
	 * @return list<string>
	 */
	public function files( string $directory, string $relativeTo ): array {
		$files    = array();
		$resolved = realpath( $directory );
		$base     = realpath( $relativeTo );

		if ( false === $resolved || false === $base ) {
			return $files;
		}

		$found = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $resolved ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $found as $file ) {
			if ( ! $file instanceof SplFileInfo ) {
				continue;
			}

			$realPath = $file->getRealPath();

			if ( false === $realPath ) {
				continue;
			}

			$normalized = str_replace( $base . DIRECTORY_SEPARATOR, '', $realPath );

			if ( 1 === preg_match( '#(^|/)(vendor|wp-content)/#i', $normalized ) ) {
				continue;
			}

			// Test suites are never loaded in a WordPress request, so their symbols would be pure
			// over-exclusion. Matched case-sensitively: `Features` with a capital F is a real
			// WooCommerce namespace, `features/` is wp-cli's Behat suite.
			if ( 1 === preg_match( '#(^|/)(tests?|spec|features|\.github)/#', $normalized ) ) {
				continue;
			}

			if ( 1 === preg_match( '/\.php$/i', $realPath ) ) {
				$files[] = $realPath;
			}
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Renders the symbol list as a PHP file.
	 *
	 * var_export() on the whole array writes explicit integer keys, so inserting a single symbol
	 * renumbers every line below it and a one-symbol change rewrites thousands of lines. A plain
	 * list keeps a regeneration diff to the symbols that actually moved.
	 *
	 * @param array<string, list<string>> $symbols
	 */
	public function render( array $symbols, string $package, string $version, string $date ): string {
		$lines = array(
			'<?php',
			'/**',
			' * GENERATED FILE - DO NOT EDIT.',
			' *',
			' * Regenerate with: composer extract',
			' *',
			' * source:    ' . $package,
			' * version:   ' . $version,
			' * generated: ' . $date,
			' */',
			'',
			'return array(',
		);

		foreach ( $symbols as $category => $values ) {
			$lines[] = "\t'" . $category . "' => array(";

			foreach ( $values as $value ) {
				$lines[] = "\t\t" . var_export( $value, true ) . ',';
			}

			$lines[] = "\t),";
		}

		$lines[] = ');';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param array<string, list<string>> $symbols
	 */
	public static function count( array $symbols ): int {
		$count = 0;

		foreach ( $symbols as $values ) {
			$count += count( $values );
		}

		return $count;
	}

	/**
	 * @return list<string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	private function parser(): Parser {
		if ( null === $this->parser ) {
			$this->parser = ( new ParserFactory() )->createForVersion( PhpVersion::fromString( $this->phpVersion ) );
		}

		return $this->parser;
	}
}
