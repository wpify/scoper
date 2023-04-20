<?php

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

require_once __DIR__ . '/../vendor/autoload.php';

function get_parser() {
	static $parser;

	if ( empty( $parser ) ) {
		$parser = ( new ParserFactory )->create( ParserFactory::PREFER_PHP7 );
	}

	return $parser;
}

function resolve( Node $node ) {
	if ( $node instanceof Node\Stmt\Namespace_ ) {
		$namespace = join( '\\', $node->name->parts );

		return array( 'expose-namespaces' => $namespace );
	} elseif ( $node instanceof Node\Stmt\Class_ ) {
		return array( 'expose-classes' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\Function_ ) {
		return array( 'expose-functions' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\If_ ) {
		$symbols = array();

		foreach ( $node->stmts as $subnode ) {
			foreach ( resolve( $subnode ) as $key => $result ) {
				$symbols[ $key ] = array_merge( $symbols[ $key ] ?? array(), $result );
			}
		}

		return $symbols;
	} elseif ( $node instanceof Node\Stmt\Trait_ ) {
		return array( 'expose-classes' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\Interface_ ) {
		return array( 'expose-classes' => array( $node->name->name ) );
	} elseif (
		$node instanceof Node\Stmt\Expression
		&& $node->expr instanceof Node\Expr\FuncCall
		&& in_array( 'define', $node->expr->name->parts )
	) {
		return array( 'expose-constants' => array( $node->expr->args[0]->value->value ) );
	}

	return array();
}

function get_files( string $folder ) {
	$files  = array();
	$folder = realpath( $folder );

	if ( file_exists( $folder ) ) {
		$found = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $found as $file ) {
			$real_path       = $file->getRealPath();
			$normalized_path = str_replace( realpath( __DIR__ . '/../sources' ) . '/', '', $real_path );

			if ( preg_match( "/\/vendor\//i", $normalized_path ) || preg_match( "/\/wp-content\//i", $normalized_path ) ) {
				continue;
			}

			if ( preg_match( "/\.php$/i", $real_path ) ) {
				$files[] = $real_path;
			}
		}
	}

	return $files;
}

function extract_symbols( string $where, string $result ) {
	$files   = get_files( $where );
	$symbols = array();

	foreach ( $files as $file ) {
		try {
			$ast = get_parser()->parse( file_get_contents( $file ) );

			foreach ( $ast as $node ) {
				$symbols = array_merge_recursive( $symbols, resolve( $node ) );
			}
		} catch ( Error $error ) {
			echo "Parse error: {$error->getMessage()} in {$file}\n";
		}
	}

	$count = 0;

	foreach ( $symbols as $exclusion => $values ) {
		$symbols[ $exclusion ] = array_unique( $values );
		$count                 += count( $values );
	}

	$content = join( array(
		"<?php return " . var_export( $symbols, true ) . ';',
	) );

	file_put_contents( $result, $content );

	echo ">>> " . $count . " symbols exported to " . $result . "\n";
}

extract_symbols( __DIR__ . '/../sources/wordpress', realpath( __DIR__ . '/../symbols' ) . '/wordpress.php' );
extract_symbols( __DIR__ . '/../sources/plugin-woocommerce', realpath( __DIR__ . '/../symbols' ) . '/woocommerce.php' );
// extract_symbols( __DIR__ . '/../vendor/yahnis-elsts/plugin-update-checker', realpath( __DIR__ . '/../symbols' ) . '/plugin-update-checker.php' );
extract_symbols( __DIR__ . '/../sources/plugin-action-scheduler/', realpath( __DIR__ . '/../symbols' ) . '/action-scheduler.php' );
