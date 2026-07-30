<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tools;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks one file and records the symbols it declares.
 *
 * WordPress and WooCommerce declare symbols in every shape PHP allows: inside function bodies
 * (wxr_cdata() in export_wp(), wp_initial_constants() and friends), inside else branches
 * (WP_Block_Cloner), inside conditionals nested several levels deep. Only a full traversal finds
 * them all, and it stays correct when a new nesting shape shows up.
 */
final class SymbolCollector extends NodeVisitorAbstract {

	/**
	 * @var array<string, list<string>>
	 */
	public array $symbols;

	private ?string $namespace = null;

	public function __construct() {
		$this->symbols = array_fill_keys( SymbolExtractor::CATEGORIES, array() );
	}

	public function enterNode( Node $node ): ?int {
		if ( $node instanceof Node\Stmt\Namespace_ ) {
			// A braced `namespace { }` block has no name - that is the global namespace.
			$this->namespace = $node->name?->toString();

			if ( null !== $this->namespace ) {
				$this->symbols['exclude-namespaces'][] = $this->namespace;
			}

			return null;
		}

		// Constants and class aliases are global whatever namespace the call sits in.
		if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
			$this->collectFromCall( $node );

			return null;
		}

		// Everything declared inside a namespace is already covered by exclude-namespaces.
		if ( null !== $this->namespace ) {
			return null;
		}

		if (
			$node instanceof Node\Stmt\Class_
			|| $node instanceof Node\Stmt\Interface_
			|| $node instanceof Node\Stmt\Trait_
			|| $node instanceof Node\Stmt\Enum_
		) {
			// Anonymous classes have no name.
			if ( null !== $node->name ) {
				$this->symbols['exclude-classes'][] = $node->name->name;
			}
		} elseif ( $node instanceof Node\Stmt\Function_ ) {
			$this->symbols['exclude-functions'][] = $node->name->name;
		} elseif ( $node instanceof Node\Stmt\Const_ ) {
			// Top-level `const FOO = 1;`, as used by the sodium_compat polyfill.
			foreach ( $node->consts as $const ) {
				$this->symbols['exclude-constants'][] = $const->name->name;
			}
		}

		return null;
	}

	public function leaveNode( Node $node ): ?int {
		if ( $node instanceof Node\Stmt\Namespace_ ) {
			$this->namespace = null;
		}

		return null;
	}

	private function collectFromCall( Node\Expr\FuncCall $node ): void {
		if ( ! $node->name instanceof Node\Name ) {
			return;
		}

		$name = strtolower( $node->name->toString() );

		if ( 'define' === $name ) {
			$constant = $this->literalArgument( $node, 0 );

			if ( null !== $constant ) {
				$this->symbols['exclude-constants'][] = $constant;
			}
		} elseif ( 'class_alias' === $name ) {
			// The alias, not the class it points at: the alias exists only at runtime and is
			// declared nowhere, so nothing else in the tree would report it.
			$alias = $this->literalArgument( $node, 1 );

			if ( null !== $alias ) {
				$this->symbols['exclude-classes'][] = $alias;
			}
		}
	}

	private function literalArgument( Node\Expr\FuncCall $node, int $index ): ?string {
		$argument = $node->args[ $index ] ?? null;

		if ( $argument instanceof Node\Arg && $argument->value instanceof Node\Scalar\String_ ) {
			return $argument->value->value;
		}

		return null;
	}
}
