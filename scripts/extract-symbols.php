<?php

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

require_once __DIR__ . '/../vendor/autoload.php';

function getParser() {
	static $parser;

	if ( empty( $parser ) ) {
		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );
	}

	return $parser;
}

function resolve( Node $node ) {
	if ( $node instanceof Node\Stmt\Namespace_ ) {
		$namespace = join( '\\', $node->name->parts );
		$symbols   = array();

		foreach ( $node->stmts as $subnode ) {
			foreach ( resolve( $subnode ) as $result ) {
				$symbols[] = $namespace . '\\' . $result;
			}
		}

		return $symbols;
	} elseif ( $node instanceof Node\Stmt\Class_ ) {
		return array( $node->name->name );
	} elseif ( $node instanceof Node\Stmt\Function_ ) {
		return array( $node->name->name );
	} elseif ( $node instanceof Node\Stmt\If_ ) {
		$symbols = array();

		foreach ( $node->stmts as $subnode ) {
			foreach ( resolve( $subnode ) as $result ) {
				$symbols[] = $result;
			}
		}

		return $symbols;
	} elseif ( $node instanceof Node\Stmt\Trait_ ) {
		return array( $node->name->name );
	} elseif ( $node instanceof Node\Stmt\Interface_ ) {
		return array( $node->name->name );
	} elseif (
		$node instanceof Node\Stmt\Expression
		&& $node->expr instanceof Node\Expr\FuncCall
		&& in_array( 'define', $node->expr->name->parts )
	) {
		return array( $node->expr->args[0]->value->value );
	}

	return array();
}

function getFiles( string $folder ) {
	$files = array();

	if ( file_exists( $folder ) ) {
		$found = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder ), RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $found as $file ) {
			$normalizedPath = realpath( str_replace( realpath( __DIR__ . '/../sources/' ), '', $file ) );

			if ( preg_match( "/\/vendor\//i", $normalizedPath ) || preg_match( "/\/wp-content\//i", $normalizedPath ) ) {
				continue;
			}

			if ( preg_match( "/\.php$/i", $normalizedPath ) ) {
				$files[] = $normalizedPath;
			}
		}
	}

	return $files;
}

function extractSymbols( string $where, string $result ) {
	$files   = getFiles( $where );
	$symbols = array();

	foreach ( $files as $file ) {
		try {
			$ast = getParser()->parse( file_get_contents( $file ) );

			foreach ( $ast as $node ) {
				$symbols = array_merge( $symbols, resolve( $node ) );
			}
		} catch ( Error $error ) {
			echo "Parse error: {$error->getMessage()} in {$file}\n";
		}
	}

	$symbols = array_unique( $symbols );

	$content = join( array(
		"<?php return array('",
		join( '\',\'', array_map( 'addslashes', $symbols ) ),
		"');",
	) );

	file_put_contents( $result, $content );

	echo ">>> " . count( $symbols ) . " symbols exported to " . $result . "\n";
}

extractSymbols( __DIR__ . '/../sources/wordpress', realpath( __DIR__ . '/../symbols' ) . '/wordpress.php' );
extractSymbols( __DIR__ . '/../sources/plugin-woocommerce', realpath( __DIR__ . '/../symbols' ) . '/woocommerce.php' );
//extractSymbols( __DIR__ . '/../vendor/yahnis-elsts/plugin-update-checker', realpath( __DIR__ . '/../symbols' ) . '/plugin-update-checker.php' );
extractSymbols( __DIR__ . '/../sources/plugin-action-scheduler/', realpath( __DIR__ . '/../symbols' ) . '/action-scheduler.php' );
