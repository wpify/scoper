<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Json\JsonManipulator;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * The scoped dependencies a `require` or `remove` run added, changed or deleted.
 *
 * A run never rewrites the scoped manifest. It resolves a copy of it inside the workspace, lets the
 * nested Composer edit that copy, and applies only the difference back - so the user's formatting,
 * key order and every block the run has no business touching survive untouched. See
 * [ADR 0002](../docs/adr/0002-require-resolves-in-the-workspace-and-writes-back-a-delta.md).
 */
final class ManifestDelta {

	/**
	 * The blocks a scoped dependency can live in.
	 *
	 * @var list<string>
	 */
	private const BLOCKS = array( 'require', 'require-dev' );

	/**
	 * @param array<string, array<string, string>> $added   Block name to package name to constraint.
	 * @param array<string, list<string>>          $removed Block name to package names.
	 */
	private function __construct(
		private readonly array $added,
		private readonly array $removed,
	) {
	}

	/**
	 * Compares the scoped manifest as it was before the run with the workspace manifest after it.
	 *
	 * Both directions, in both blocks: `composer require foo/bar --dev` on a package that is
	 * already in `require` does not add a second entry, it moves the existing one - so a run that
	 * only looked for additions would leave the package declared twice.
	 *
	 * @param array<string, array<string, string>> $before
	 * @param array<string, array<string, string>> $after
	 */
	public static function between( array $before, array $after ): self {
		$added   = array();
		$removed = array();

		foreach ( self::BLOCKS as $block ) {
			$was = $before[ $block ] ?? array();
			$now = $after[ $block ] ?? array();

			foreach ( $now as $package => $constraint ) {
				if ( ! isset( $was[ $package ] ) || $was[ $package ] !== $constraint ) {
					$added[ $block ][ $package ] = $constraint;
				}
			}

			foreach ( array_keys( $was ) as $package ) {
				if ( ! isset( $now[ $package ] ) ) {
					$removed[ $block ][] = $package;
				}
			}
		}

		return new self( $added, $removed );
	}

	/**
	 * Reads the `require` and `require-dev` blocks out of a decoded manifest.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function blocksOf( stdClass $manifest ): array {
		$blocks = array();

		foreach ( self::BLOCKS as $block ) {
			$node       = $manifest->{$block} ?? null;
			$constraints = array();

			if ( $node instanceof stdClass ) {
				foreach ( get_object_vars( $node ) as $package => $constraint ) {
					// A non-string constraint is not something Composer would have written, and
					// coercing it would invent a change that never happened.
					if ( is_string( $constraint ) ) {
						$constraints[ $package ] = $constraint;
					}
				}
			}

			$blocks[ $block ] = $constraints;
		}

		return $blocks;
	}

	public function isEmpty(): bool {
		return array() === $this->added && array() === $this->removed;
	}

	/**
	 * Applies the delta to the raw bytes of the scoped manifest.
	 *
	 * Removals first: a package that moved between `require` and `require-dev` would otherwise be
	 * in both blocks for the length of one statement, and `JsonManipulator` works on the string
	 * rather than on a tree, so that intermediate state is what the next call would read.
	 *
	 * @param string $path          Only for the error message; nothing is read from disk here.
	 * @param string $json          The current contents of the scoped manifest.
	 * @param bool   $sortPackages  The scoped manifest's own `config.sort-packages`.
	 *
	 * @throws RuntimeException When a manipulation fails, which would leave the published lock
	 *                          declaring a dependency the manifest does not.
	 */
	public function applyTo( string $path, string $json, bool $sortPackages ): string {
		try {
			$manipulator = new JsonManipulator( $json );
		} catch ( InvalidArgumentException $exception ) {
			// The run validated this file when it started, so getting here means it was edited
			// underneath us. Say which file, because the constructor's own message does not.
			throw new RuntimeException(
				sprintf( 'wpify-scoper: cannot edit %s, it is not a valid JSON object.', $path ),
				0,
				$exception
			);
		}

		foreach ( $this->removed as $block => $packages ) {
			foreach ( $packages as $package ) {
				// removeSubNode() reports false when the entry is not there, which is not a
				// failure: the delta says it was there before the run, and it is gone now either
				// way. Only a manipulation that was asked to write something has to succeed.
				$manipulator->removeSubNode( $block, $package );
			}

			// What Composer's own `remove` does, and for the same reason: emptying a block should
			// not leave `"require": {}` behind in the file.
			$manipulator->removeMainKeyIfEmpty( $block );
		}

		foreach ( $this->added as $block => $constraints ) {
			foreach ( $constraints as $package => $constraint ) {
				if ( ! $manipulator->addLink( $block, $package, $constraint, $sortPackages ) ) {
					throw new RuntimeException( sprintf(
						'wpify-scoper: cannot record %s "%s" in the %s block of %s.',
						$package,
						$constraint,
						$block,
						$path
					) );
				}
			}
		}

		return $manipulator->getContents();
	}

	/**
	 * A one-line summary for the console, e.g. `+guzzlehttp/guzzle ^7.9, -monolog/monolog`.
	 */
	public function describe(): string {
		$parts = array();

		foreach ( $this->added as $constraints ) {
			foreach ( $constraints as $package => $constraint ) {
				$parts[] = '+' . $package . ' ' . $constraint;
			}
		}

		foreach ( $this->removed as $packages ) {
			foreach ( $packages as $package ) {
				$parts[] = '-' . $package;
			}
		}

		return implode( ', ', $parts );
	}
}
