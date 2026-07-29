<?php declare( strict_types=1 );

namespace Wpify\Scoper;

/**
 * Undoes the prefixing php-scoper applied to symbols that were meant to stay global.
 *
 * php-scoper prefixes a handful of references it cannot prove are excluded - most of them inside
 * string literals and `use` statements. This walks the scoped file and strips the prefix back off
 * every reference whose symbol is covered by `exclude-classes` or `exclude-namespaces`.
 *
 * Lives in its own file with no dependencies because {@see ScoperConfigFactory} copies it into the
 * temp directory next to the generated scoper.inc.php: the php-scoper phar runs that config with
 * its own autoloader, which knows nothing about this package.
 */
final class SymbolUnprefixer {

	/**
	 * Matches `\{prefix}\Some\Symbol` and `use {prefix}\Some\Symbol`.
	 *
	 * The symbol is captured whole - up to the end of the last identifier - so that the callback can
	 * anchor the match on both sides instead of the unanchored str_replace() this replaces, which
	 * happily turned `\{prefix}\WPSEO\Utils` into `\WPSEO\Utils` because `WP` is an excluded
	 * WordPress class.
	 */
	private readonly string $pattern;

	/**
	 * @var array<string, true> Lowercased symbol names.
	 */
	private readonly array $classes;

	/**
	 * @var array<string, true> Lowercased namespace names.
	 */
	private readonly array $namespaces;

	/**
	 * @param string        $prefix            The namespace php-scoper prefixes everything with.
	 * @param array<mixed>  $excludeClasses    Symbols that name one class, interface, trait or enum.
	 * @param array<mixed>  $excludeNamespaces Namespaces whose whole subtree stays global.
	 */
	public function __construct(
		private readonly string $prefix,
		array $excludeClasses = array(),
		array $excludeNamespaces = array(),
	) {
		// php-scoper matches symbols case-insensitively (SymbolRegistry::normalizeNames()), so the
		// tables are lowercased and the captured symbol is lowercased before the lookup.
		$this->classes    = self::lookup( $excludeClasses );
		$this->namespaces = self::lookup( $excludeNamespaces );

		$this->pattern = '/(use\s+|\\\\)' . preg_quote( $prefix, '/' )
			. '\\\\([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*)/';
	}

	public function __invoke( string $content ): string {
		return $this->unprefix( $content );
	}

	public function unprefix( string $content ): string {
		if ( ! str_contains( $content, $this->prefix ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			$this->pattern,
			function ( array $matches ): string {
				return $this->isExcluded( (string) $matches[2] ) ? $matches[1] . $matches[2] : $matches[0];
			},
			$content
		);
	}

	/**
	 * True when the symbol has to stay in the global namespace.
	 */
	public function isExcluded( string $symbol ): bool {
		$symbol = strtolower( ltrim( $symbol, '\\' ) );

		// An exclude-classes entry names one symbol and nothing below it.
		if ( isset( $this->classes[ $symbol ] ) ) {
			return true;
		}

		// An exclude-namespaces entry covers the namespace and everything under it, but only on a
		// segment boundary: `Foo\Bar` must not match `Foo\Barbecue`.
		$candidate = '';

		foreach ( explode( '\\', $symbol ) as $segment ) {
			$candidate = '' === $candidate ? $segment : $candidate . '\\' . $segment;

			if ( isset( $this->namespaces[ $candidate ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed> $symbols
	 *
	 * @return array<string, true>
	 */
	private static function lookup( array $symbols ): array {
		$lookup = array();

		foreach ( $symbols as $symbol ) {
			if ( is_string( $symbol ) && '' !== $symbol ) {
				$lookup[ strtolower( $symbol ) ] = true;
			}
		}

		return $lookup;
	}
}
