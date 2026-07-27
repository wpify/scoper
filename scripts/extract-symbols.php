<?php

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Collects every define() call in a file, no matter how deeply nested.
 *
 * WordPress declares most of its constants inside functions (see
 * wp_initial_constants() and friends in wp-includes/default-constants.php),
 * so walking only the top-level statements misses them.
 *
 * Constants are always global regardless of the namespace the define() call
 * sits in, so unlike classes and functions they're safe to collect everywhere.
 */
class ConstantCollector extends NodeVisitorAbstract {
	/** @var string[] */
	public $constants = array();

	public function enterNode( Node $node ) {
		if (
			$node instanceof Node\Expr\FuncCall
			&& $node->name instanceof Node\Name
			&& strtolower( $node->name->toString() ) === 'define'
			&& isset( $node->args[0] )
			&& $node->args[0] instanceof Node\Arg
			&& $node->args[0]->value instanceof Node\Scalar\String_
		) {
			$this->constants[] = $node->args[0]->value->value;
		}

		return null;
	}
}

function get_parser() {
	static $parser;

	if ( empty( $parser ) ) {
		$parser = ( new ParserFactory() )->createForVersion( \PhpParser\PhpVersion::fromString("8.1.0") );
	}

	return $parser;
}

function resolve( Node $node ) {
	if ( $node instanceof Node\Stmt\Namespace_ ) {
		$namespace = join( '\\', $node->name->getParts() );

		return array( 'exclude-namespaces' => array( $namespace ) );
	} elseif ( $node instanceof Node\Stmt\Class_ ) {
		return array( 'exclude-classes' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\Function_ ) {
		return array( 'exclude-functions' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\If_ ) {
		$symbols = array();

		foreach ( $node->stmts as $subnode ) {
			foreach ( resolve( $subnode ) as $key => $result ) {
				$symbols[ $key ] = array_merge( $symbols[ $key ] ?? array(), $result );
			}
		}

		return $symbols;
	} elseif ( $node instanceof Node\Stmt\Trait_ ) {
		return array( 'exclude-classes' => array( $node->name->name ) );
	} elseif ( $node instanceof Node\Stmt\Interface_ ) {
		return array( 'exclude-classes' => array( $node->name->name ) );
	}

	// Constants are handled by ConstantCollector, which walks the whole AST.
	return array();
}

function get_files( string $folder, string $root ) {
	$files  = array();
	$folder = realpath( $folder );

	if ( file_exists( $folder ) ) {
		$found = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $found as $file ) {
			$real_path       = $file->getRealPath();
			$normalized_path = str_replace( realpath( __DIR__ . '/../' . $root ) . '/', '', $real_path );

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

function extract_symbols( string $where, string $root, string $result ) {
	$files   = get_files( $where, $root );
	$symbols = array();

	foreach ( $files as $file ) {
		try {
			$ast = get_parser()->parse( file_get_contents( $file ) );

			foreach ( $ast as $node ) {
				$symbols = array_merge_recursive( $symbols, resolve( $node ) );
			}

			$collector = new ConstantCollector();
			$traverser = new NodeTraverser();
			$traverser->addVisitor( $collector );
			$traverser->traverse( $ast );

			if ( ! empty( $collector->constants ) ) {
				$symbols['exclude-constants'] = array_merge(
					$symbols['exclude-constants'] ?? array(),
					$collector->constants,
				);
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

extract_symbols( __DIR__ . '/../sources/wordpress', 'sources', realpath( __DIR__ . '/../symbols' ) . '/wordpress.php' );
extract_symbols( __DIR__ . '/../sources/plugin-woocommerce', 'sources', realpath( __DIR__ . '/../symbols' ) . '/woocommerce.php' );
//extract_symbols( __DIR__ . '/../vendor/yahnis-elsts/plugin-update-checker', 'vendor', realpath( __DIR__ . '/../symbols' ) . '/plugin-update-checker.php' );
extract_symbols( __DIR__ . '/../sources/plugin-action-scheduler', 'sources', realpath( __DIR__ . '/../symbols' ) . '/action-scheduler.php' );
extract_symbols( __DIR__ . '/../vendor/wp-cli/wp-cli',  'vendor', realpath( __DIR__ . '/../symbols' ) . '/wp-cli.php' );
