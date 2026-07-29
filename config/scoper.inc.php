<?php declare( strict_types=1 );

use Isolated\Symfony\Component\Finder\Finder;
use Wpify\Scoper\SymbolUnprefixer;

// php-scoper runs this file from inside its phar, whose autoloader knows nothing about this
// package, so the patcher's one collaborator is loaded by hand. ScoperConfigFactory copies it
// into the same directory as this file.
if ( ! class_exists( SymbolUnprefixer::class, false ) ) {
	require_once __DIR__ . '/SymbolUnprefixer.php';
}

// Plain require: require_once returns true instead of the config on a second include.
$config = require __DIR__ . '/scoper.config.php';

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
$source      = $config['source'];
$destination = $config['destination'];

// remove source and destination from the config, as they're not part of php-scoper config
unset( $config['source'], $config['destination'] );

/**
 * The un-prefixing patcher's lookup tables.
 *
 * Built once here rather than inside the closure: the closure runs for every file php-scoper
 * processes, and the tables are identical for all of them.
 */
$unprefixer = new SymbolUnprefixer(
	$prefix,
	$config['exclude-classes'] ?? array(),
	$config['exclude-namespaces'] ?? array()
);

return customize_php_scoper_config( array_merge( $config, array(
	'finders'                    => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->in( $source . DIRECTORY_SEPARATOR . 'vendor' ),
		Finder::create()
			->append( array(
				$source . '/composer.json',
				$source . '/composer.lock',
			) ),
	),
	'patchers'                   => array(
		function ( string $filePath, string $prefix, string $content ) use ( $unprefixer ): string {
			if ( str_contains( $filePath, 'guzzlehttp/guzzle/src/Handler/CurlFactory.php' ) ) {
				$content = str_replace( 'stream_for($sink)', 'Utils::streamFor()', $content );
			}

			if ( str_contains( $filePath, 'php-di/php-di/src/Compiler/Template.php' ) ) {
				$content = str_replace( "namespace $prefix;", '', $content );
			}

			if ( str_contains( $filePath, 'twig/src/Node/ModuleNode.php' ) ) {
				$content = str_replace( 'write("use Twig', 'write("use ' . $prefix . '\\\\Twig', $content );
				$content = str_replace( 'Template;\\n\\n', 'Template;\\n\\n use function ' . $prefix . '\\\\twig_escape_filter; \\n\\n', $content );
			}

			if ( str_contains( $filePath, '/vendor/twig/twig/' ) ) {
				$content = str_replace( "'twig_escape_filter_is_safe'", "'" . $prefix . "\\\\twig_escape_filter_is_safe'", $content );
				$content = str_replace( "'twig_get_attribute(", "'" . $prefix . "\\\\twig_get_attribute(", $content );
				$content = str_replace( " = twig_ensure_traversable(", " = " . $prefix . "\\\\twig_ensure_traversable(", $content );
				// `?? $content` because preg_replace() returns null when PCRE gives up - the
				// backtrack limit on a large generated Twig template is a real way to get there,
				// and passing that null on turns into a TypeError from inside a patcher, which
				// aborts the whole scoping run with a message nobody can act on.
				$content = preg_replace( '/new TwigFilter\(\s*\'([^\']+)\'\s*,\s*\'(_?twig_[^\']+)\'/m', 'new TwigFilter(\'$1\', \'' . $prefix . '\\\\$2\'', $content ) ?? $content;
				$content = preg_replace( '/\\$compiler->raw\(\s*\'(twig_[^(]+)\(/m', '\$compiler->raw(\'' . $prefix . '\\\\$1(', $content ) ?? $content;
				$content = str_replace( "'\\\\Twig\\\\", "'\\\\" . $prefix . "\\\\Twig\\\\", $content );
				$content = str_replace( "'\\Twig\\", "'" . $prefix . "\\Twig\\", $content );
			}

			if ( str_contains( $filePath, '/vendor/giggsey/libphonenumber-for-php/' ) ) {
				$content = str_replace( $prefix . "\\\\array_merge", "array_merge", $content );
			}

			if ( str_contains( $filePath, '/league/oauth2-client' ) ) {
				$content = str_replace( "League\\\\OAuth2\\\\Client\\\\Grant", $prefix . "\\\\League\\\\OAuth2\\\\Client\\\\Grant", $content );
			}

			// PucFactory::buildUpdateChecker() looks the checker up in a registry whose keys are
			// string literals in load-v5pX.php - which php-scoper's StringScalarPrefixer *does*
			// prefix. The lookup key is built from a variable, which it does not. Both branches
			// have to be prefixed by hand or getCompatibleClassVersion() returns null and PUC
			// fires trigger_error( ..., E_USER_ERROR ).
			if ( str_contains( $filePath, 'yahnis-elsts/plugin-update-checker' ) ) {
				// PucFactory::buildUpdateChecker(), the plain JSON branch.
				$content = str_replace( '$checkerClass = $type', '$checkerClass = "' . $prefix . '\\\\".$type', $content );
				// The same method, the VCS branch a couple of lines down - GitHub, GitLab
				// and BitBucket hosted update checking never reached the registry without it.
				$content = str_replace(
					"\$checkerClass = 'Vcs\\\\' . \$type",
					"\$checkerClass = \"" . $prefix . "\\\\Vcs\\\\\" . \$type",
					$content
				);
			}

			// Undo the prefixing php-scoper applied to symbols it was told to exclude.
			return $unprefixer->unprefix( $content );
		},
	),
	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
) ) );
