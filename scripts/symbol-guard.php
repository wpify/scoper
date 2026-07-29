<?php declare( strict_types=1 );
/**
 * Guards a symbol regeneration against a silent truncation.
 *
 * A parse failure in the extractor drops symbols rather than failing loudly, and a WordPress list
 * that lost entries means WordPress functions get scoped - the consumer's site then fatals on a
 * call to an undefined function, in production, far from anything that points back here. So the
 * scheduled regeneration snapshots the counts first and refuses to open a pull request if any
 * category shrank by more than the tolerance.
 *
 *   php scripts/symbol-guard.php snapshot before.json
 *   composer extract
 *   php scripts/symbol-guard.php compare before.json [tolerance-percent]
 *
 * Growth is always fine. So is a small shrink: WordPress does remove things.
 */

$mode      = $argv[1] ?? '';
$file      = $argv[2] ?? '';
$tolerance = (float) ( $argv[3] ?? '1.0' );
$symbolsIn = dirname( __DIR__ ) . '/symbols';

if ( ! in_array( $mode, array( 'snapshot', 'compare' ), true ) || '' === $file ) {
	fwrite( STDERR, "Usage: symbol-guard.php snapshot|compare <file> [tolerance-percent]\n" );

	exit( 2 );
}

/**
 * @return array<string, array<string, int>> file => category => count
 */
function wpify_scoper_symbol_counts( string $directory ): array {
	$counts = array();
	$files  = glob( $directory . '/*.php' );

	if ( false === $files ) {
		fwrite( STDERR, "symbol-guard: cannot list {$directory}\n" );

		exit( 1 );
	}

	foreach ( $files as $path ) {
		$symbols = require $path;

		if ( ! is_array( $symbols ) ) {
			fwrite( STDERR, sprintf( "symbol-guard: %s does not return an array\n", $path ) );

			exit( 1 );
		}

		$perCategory = array();

		foreach ( $symbols as $category => $values ) {
			$perCategory[ (string) $category ] = is_array( $values ) ? count( $values ) : 0;
		}

		$counts[ basename( $path ) ] = $perCategory;
	}

	ksort( $counts );

	return $counts;
}

$counts = wpify_scoper_symbol_counts( $symbolsIn );

if ( 'snapshot' === $mode ) {
	file_put_contents( $file, (string) json_encode( $counts, JSON_PRETTY_PRINT ) );

	printf( "symbol-guard: snapshot of %d lists written to %s\n", count( $counts ), $file );

	exit( 0 );
}

$raw    = @file_get_contents( $file );
$before = false === $raw ? null : json_decode( $raw, true );

if ( ! is_array( $before ) ) {
	fwrite( STDERR, sprintf( "symbol-guard: cannot read the snapshot %s\n", $file ) );

	exit( 1 );
}

$failed = false;

foreach ( $before as $list => $categories ) {
	if ( ! is_array( $categories ) ) {
		continue;
	}

	foreach ( $categories as $category => $recorded ) {
		$was = is_int( $recorded ) ? $recorded : 0;
		$now = $counts[ (string) $list ][ (string) $category ] ?? 0;
		$max = max( 1, (int) floor( $was * $tolerance / 100 ) );

		printf( "%-24s %-20s %6d -> %-6d %+d\n", $list, $category, $was, $now, $now - $was );

		if ( $was - $now > $max ) {
			fwrite( STDERR, sprintf(
				"symbol-guard: %s/%s lost %d symbols (tolerance %d). A truncated list scopes symbols that must stay global - check the extractor before merging.\n",
				$list,
				$category,
				$was - $now,
				$max
			) );

			$failed = true;
		}
	}
}

foreach ( array_keys( $counts ) as $list ) {
	if ( ! isset( $before[ $list ] ) ) {
		printf( "%-24s NEW LIST\n", $list );
	}
}

exit( $failed ? 1 : 0 );
