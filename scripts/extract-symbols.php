<?php declare( strict_types=1 );
/**
 * Regenerates symbols/*.php from the sources in sources/ and vendor/.
 *
 * Run with `composer extract`. All of the logic lives in {@see SymbolExtractor}; this is the CLI
 * entry point, so that the extraction is reachable from a test without also running it.
 */

use Composer\InstalledVersions;
use Wpify\Scoper\Tools\SymbolExtractor;

require_once __DIR__ . '/../vendor/autoload.php';

$root      = dirname( __DIR__ );
$extractor = new SymbolExtractor();
$failed    = false;

/**
 * Every tree to scan, as source directory => [output file, package the version is read from].
 *
 * The second element of the value is the directory paths are made relative to before the
 * vendor/, wp-content/ and test-suite filters are applied.
 */
$targets = array(
	array( $root . '/sources/wordpress', $root . '/sources', 'wordpress.php', 'johnpbloch/wordpress' ),
	array( $root . '/sources/plugin-woocommerce', $root . '/sources', 'woocommerce.php', 'wpackagist-plugin/woocommerce' ),
	array( $root . '/sources/plugin-action-scheduler', $root . '/sources', 'action-scheduler.php', 'woocommerce/action-scheduler' ),
	array( $root . '/vendor/wp-cli/wp-cli', $root . '/vendor', 'wp-cli.php', 'wp-cli/wp-cli' ),
);

foreach ( $targets as list( $directory, $relativeTo, $file, $package ) ) {
	$output  = $root . '/symbols/' . $file;
	$symbols = $extractor->extract( $directory, $relativeTo );
	$errors  = $extractor->errors();

	foreach ( $errors as $error ) {
		fwrite( STDERR, 'wpify-scoper: ' . $error . PHP_EOL );

		$failed = true;
	}

	// The list this run produced is missing whatever the failing files declared. Writing it would
	// leave a truncated symbol table in the working tree, one commit away from scoping WordPress.
	if ( array() !== $errors ) {
		fwrite( STDERR, sprintf( 'wpify-scoper: %s was left untouched' . PHP_EOL, $output ) );

		continue;
	}

	$version = InstalledVersions::isInstalled( $package )
		? ( InstalledVersions::getPrettyVersion( $package ) ?? 'unknown' )
		: 'not installed';

	$rendered = $extractor->render( $symbols, $package, $version, date( 'Y-m-d' ) );

	if ( false === file_put_contents( $output, $rendered ) ) {
		fwrite( STDERR, sprintf( 'wpify-scoper: cannot write %s' . PHP_EOL, $output ) );

		exit( 1 );
	}

	printf( ">>> %d symbols exported to %s\n", SymbolExtractor::count( $symbols ), $output );
}

// A parse failure silently truncates a symbol list, and a truncated WordPress list means WordPress
// functions get scoped - the worst failure mode this project has. Never exit 0 on one.
exit( $failed ? 1 : 0 );
