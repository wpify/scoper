<?php declare( strict_types=1 );

use Isolated\Symfony\Component\Finder\Finder;

$config = require_once __DIR__ . '/scoper.config.php';

$project_customizations = __DIR__ . '/scoper.custom.php';

if ( file_exists( $project_customizations ) ) {
	require_once $project_customizations;
}

if ( ! function_exists( 'customize_php_scoper_config' ) ) {
	function customize_php_scoper_config( array $config = array() ) {
		return $config;
	}
}

$prefix      = $config['prefix'];
$whitelist   = $config['whitelist'];
$source      = $config['source'];
$destination = $config['destination'];

return customize_php_scoper_config( array(
	'prefix'                     => $prefix,
	'finders'                    => array(
		Finder::create()
		      ->files()
		      ->ignoreVCS( true )
		      ->in( $source . DIRECTORY_SEPARATOR . 'vendor' ),
		Finder::create()
		      ->append( array(
			      $source . '/composer.json',
			      $source . '/composer.lock'
		      ) ),
	),
	'patchers'                   => array(
		function ( string $filePath, string $prefix, string $content ) use ( $whitelist ): string {
			if ( strpos( $filePath, 'guzzlehttp/guzzle/src/Handler/CurlFactory.php' ) !== false ) {
				$content = str_replace( 'stream_for($sink)', 'Utils::streamFor()', $content );
			}

			if ( strpos( $filePath, 'php-di/php-di/src/Compiler/Template.php' ) !== false ) {
				$content = str_replace( "namespace $prefix;", '', $content );
			}

			if ( strpos( $filePath, 'yahnis-elsts/plugin-update-checker/Puc/v4p11/UpdateChecker.php' ) !== false ) {
				$content = str_replace( "namespace $prefix;", "namespace $prefix;\n\nuse WP_Error;", $content );
			}

			usort( $whitelist, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );

			$count        = 0;
			$searches     = array();
			$replacements = array();

			foreach ( $whitelist as $symbol ) {
				$searches[]     = "\\$prefix\\$symbol";
				$replacements[] = "\\$symbol";

				$searches[]     = "use $prefix\\$symbol";
				$replacements[] = "use $symbol";
			}

			$content = str_replace( $searches, $replacements, $content, $count );

			return $content;
		},
	),
	'whitelist'                  => array(),
	'whitelist-global-constants' => false,
	'whitelist-global-classes'   => false,
	'whitelist-global-functions' => false,
) );
