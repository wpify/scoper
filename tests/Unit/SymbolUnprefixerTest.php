<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wpify\Scoper\SymbolUnprefixer;

/**
 * The patcher that decides which symbols come back out of the prefix.
 *
 * Every failure here is a silent production break: a WordPress symbol left prefixed fatals with
 * "class not found" inside somebody's site, and a vendor symbol wrongly un-prefixed reintroduces
 * exactly the collision this package exists to prevent.
 */
#[CoversClass( SymbolUnprefixer::class )]
final class SymbolUnprefixerTest extends TestCase {

	private const PREFIX = 'Acme\\Deps';

	/**
	 * A realistic slice of what the shipped symbol lists actually contain.
	 *
	 * `WP` and `PO` are genuine WordPress class names and the reason the naive str_replace()
	 * implementation mangled `WPSEO\Utils` and `POBox\Mailer`.
	 *
	 * @var list<string>
	 */
	private const CLASSES = array( 'WP', 'PO', 'WP_Post', 'WP_Query', 'WPSEO_Utils_Core' );

	/**
	 * @var list<string>
	 */
	private const NAMESPACES = array(
		'Automattic\\WooCommerce',
		'PHPMailer\\PHPMailer',
		'WpOrg\\Requests',
	);

	private function unprefixer( string $prefix = self::PREFIX ): SymbolUnprefixer {
		return new SymbolUnprefixer( $prefix, self::CLASSES, self::NAMESPACES );
	}

	// --- the four cases from the audit -------------------------------------------------------

	public function test_a_vendor_class_that_merely_starts_with_an_excluded_class_stays_prefixed(): void {
		// `WP` is excluded and is a strict string prefix of `WPSEO_Utils`. Anchoring on the
		// segment boundary is the whole point.
		self::assertSame(
			'new \\Acme\\Deps\\WPSEO_Utils();',
			$this->unprefixer()->unprefix( 'new \\Acme\\Deps\\WPSEO_Utils();' )
		);
	}

	public function test_an_excluded_wordpress_class_becomes_global_again(): void {
		self::assertSame(
			'new \\WP_Post();',
			$this->unprefixer()->unprefix( 'new \\Acme\\Deps\\WP_Post();' )
		);
	}

	public function test_a_use_statement_for_a_vendor_namespace_stays_prefixed(): void {
		self::assertSame(
			'use Acme\\Deps\\WPBakery\\Thing;',
			$this->unprefixer()->unprefix( 'use Acme\\Deps\\WPBakery\\Thing;' )
		);
	}

	public function test_a_child_of_an_excluded_namespace_becomes_global_again(): void {
		// Segment-wise matching: the list holds `Automattic\WooCommerce`, the reference is six
		// segments deeper. Whole-string equality would leave it prefixed and fatal HPOS.
		$symbol = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';

		self::assertSame(
			'new \\' . $symbol . '();',
			$this->unprefixer()->unprefix( 'new \\Acme\\Deps\\' . $symbol . '();' )
		);
	}

	public function test_a_repeated_segment_namespace_becomes_global_again(): void {
		self::assertSame(
			'new \\PHPMailer\\PHPMailer\\PHPMailer();',
			$this->unprefixer()->unprefix( 'new \\Acme\\Deps\\PHPMailer\\PHPMailer\\PHPMailer();' )
		);
	}

	// --- segment-boundary anchoring -----------------------------------------------------------

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function symbols(): iterable {
		// symbol => should it come out un-prefixed (global)?
		yield 'exact excluded class'            => array( 'WP_Post', true );
		yield 'excluded class, different case'  => array( 'wp_post', true );
		yield 'excluded class, upper case'      => array( 'WP_POST', true );
		yield 'excluded single-segment class'   => array( 'WP', true );
		yield 'class the excluded one prefixes' => array( 'WP_Posting', false );
		yield 'vendor class starting with WP'   => array( 'WPSEO_Utils', false );
		yield 'vendor class starting with PO'   => array( 'POStuff', false );
		yield 'vendor ns starting with WP'      => array( 'WPSEO\\Utils', false );
		yield 'vendor ns starting with PO'      => array( 'POBox\\Mailer', false );
		yield 'excluded class used as a ns'     => array( 'WP_Post\\Nested', false );
		yield 'excluded namespace itself'       => array( 'Automattic\\WooCommerce', true );
		yield 'excluded namespace child'        => array( 'Automattic\\WooCommerce\\Admin', true );
		yield 'excluded namespace grandchild'   => array( 'Automattic\\WooCommerce\\Admin\\API\\Reports', true );
		yield 'namespace it is a prefix of'     => array( 'Automattic\\WooCommerceBlocks\\Thing', false );
		yield 'sibling of excluded namespace'   => array( 'Automattic\\Jetpack\\Thing', false );
		yield 'excluded ns, different case'     => array( 'automattic\\woocommerce\\Admin', true );
		yield 'unrelated vendor class'          => array( 'Psr\\Log\\LoggerInterface', false );
	}

	#[DataProvider( 'symbols' )]
	public function test_it_anchors_on_segment_boundaries( string $symbol, bool $expectedGlobal ): void {
		self::assertSame( $expectedGlobal, $this->unprefixer()->isExcluded( $symbol ) );

		self::assertSame(
			$expectedGlobal ? '\\' . $symbol : '\\Acme\\Deps\\' . $symbol,
			$this->unprefixer()->unprefix( '\\Acme\\Deps\\' . $symbol )
		);
	}

	#[DataProvider( 'symbols' )]
	public function test_it_anchors_on_segment_boundaries_in_use_statements( string $symbol, bool $expectedGlobal ): void {
		self::assertSame(
			$expectedGlobal ? 'use ' . $symbol . ';' : 'use Acme\\Deps\\' . $symbol . ';',
			$this->unprefixer()->unprefix( 'use Acme\\Deps\\' . $symbol . ';' )
		);
	}

	// --- what must never be touched -----------------------------------------------------------

	public function test_content_without_the_prefix_is_returned_untouched(): void {
		$content = "<?php\nnamespace Vendor\\Lib;\n\nuse WP_Post;\n\nclass A { }\n";

		self::assertSame( $content, $this->unprefixer()->unprefix( $content ) );
	}

	public function test_a_bare_reference_without_a_leading_separator_is_left_alone(): void {
		// `Acme\Deps\WP_Post` with no `\` and no `use` in front is a relative name resolved
		// against the current namespace; rewriting it would change what it points at.
		self::assertSame(
			'$x = Acme\\Deps\\WP_Post::class;',
			$this->unprefixer()->unprefix( '$x = Acme\\Deps\\WP_Post::class;' )
		);
	}

	public function test_the_prefix_itself_is_not_stripped_when_nothing_follows_it(): void {
		self::assertSame( 'namespace Acme\\Deps;', $this->unprefixer()->unprefix( 'namespace Acme\\Deps;' ) );
	}

	public function test_a_different_prefix_is_ignored(): void {
		self::assertSame(
			'new \\Other\\Deps\\WP_Post();',
			$this->unprefixer()->unprefix( 'new \\Other\\Deps\\WP_Post();' )
		);
	}

	public function test_it_handles_a_single_segment_prefix(): void {
		$unprefixer = new SymbolUnprefixer( 'Acme', self::CLASSES, self::NAMESPACES );

		self::assertSame( 'new \\WP_Post();', $unprefixer->unprefix( 'new \\Acme\\WP_Post();' ) );
		self::assertSame( 'new \\Acme\\WPSEO_Utils();', $unprefixer->unprefix( 'new \\Acme\\WPSEO_Utils();' ) );
	}

	public function test_an_empty_symbol_set_leaves_everything_prefixed(): void {
		$unprefixer = new SymbolUnprefixer( self::PREFIX );

		self::assertSame( 'new \\Acme\\Deps\\WP_Post();', $unprefixer->unprefix( 'new \\Acme\\Deps\\WP_Post();' ) );
	}

	// --- shapes that occur in real scoped output ----------------------------------------------

	public function test_it_rewrites_every_occurrence_in_a_file(): void {
		$scoped = <<<'PHP'
		<?php
		namespace Acme\Deps\Vendor\Lib;

		use Acme\Deps\WP_Post;
		use Acme\Deps\WPSEO\Utils;
		use Acme\Deps\Automattic\WooCommerce\Admin\Features;

		class Consumer {
			public function post(): \Acme\Deps\WP_Post { }
			public function utils(): \Acme\Deps\WPSEO\Utils { }
			public function query(): \Acme\Deps\WP_Query { }
		}
		PHP;

		$expected = <<<'PHP'
		<?php
		namespace Acme\Deps\Vendor\Lib;

		use WP_Post;
		use Acme\Deps\WPSEO\Utils;
		use Automattic\WooCommerce\Admin\Features;

		class Consumer {
			public function post(): \WP_Post { }
			public function utils(): \Acme\Deps\WPSEO\Utils { }
			public function query(): \WP_Query { }
		}
		PHP;

		self::assertSame( $expected, $this->unprefixer()->unprefix( $scoped ) );
	}

	public function test_a_use_statement_with_extra_whitespace_still_matches(): void {
		self::assertSame( "use\n\tWP_Post;", $this->unprefixer()->unprefix( "use\n\tAcme\\Deps\\WP_Post;" ) );
	}

	public function test_invoking_it_is_the_same_as_unprefixing(): void {
		$unprefixer = $this->unprefixer();

		self::assertSame( $unprefixer->unprefix( '\\Acme\\Deps\\WP_Post' ), $unprefixer( '\\Acme\\Deps\\WP_Post' ) );
	}

	public function test_a_prefix_containing_regex_metacharacters_is_quoted(): void {
		// Not a legal namespace, but the pattern must never be able to inject into the regex.
		$unprefixer = new SymbolUnprefixer( 'A.c', self::CLASSES, self::NAMESPACES );

		self::assertSame( '\\WP_Post', $unprefixer->unprefix( '\\A.c\\WP_Post' ) );
		self::assertSame( '\\AXc\\WP_Post', $unprefixer->unprefix( '\\AXc\\WP_Post' ) );
	}

	public function test_a_unicode_symbol_is_matched(): void {
		$unprefixer = new SymbolUnprefixer( self::PREFIX, array( 'Ünïcode' ) );

		self::assertSame( '\\Ünïcode', $unprefixer->unprefix( '\\Acme\\Deps\\Ünïcode' ) );
	}
}
