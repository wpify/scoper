<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Wpify\Scoper\ManifestDelta;

#[CoversClass( ManifestDelta::class )]
final class ManifestDeltaTest extends TestCase {

	/**
	 * @param array<string, array<string, string>> $blocks
	 *
	 * @return array<string, array<string, string>>
	 */
	private function blocks( array $blocks ): array {
		return array(
			'require'     => $blocks['require'] ?? array(),
			'require-dev' => $blocks['require-dev'] ?? array(),
		);
	}

	private function decode( string $json ): stdClass {
		$decoded = json_decode( $json );

		self::assertInstanceOf( stdClass::class, $decoded );

		return $decoded;
	}

	// --- reading the blocks out of a manifest ---------------------------------------------------

	public function test_it_reads_both_blocks(): void {
		$manifest = $this->decode( '{"require":{"a/b":"^1.0"},"require-dev":{"c/d":"^2.0"}}' );

		self::assertSame(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ), 'require-dev' => array( 'c/d' => '^2.0' ) ) ),
			ManifestDelta::blocksOf( $manifest )
		);
	}

	public function test_a_manifest_without_either_block_reads_as_empty(): void {
		self::assertSame( $this->blocks( array() ), ManifestDelta::blocksOf( $this->decode( '{"name":"acme/plugin"}' ) ) );
	}

	/**
	 * A non-string constraint is not something Composer would have written, and coercing it would
	 * invent a change that never happened.
	 */
	public function test_a_non_string_constraint_is_ignored(): void {
		$manifest = $this->decode( '{"require":{"a/b":"^1.0","c/d":{"nested":true},"e/f":7}}' );

		self::assertSame( array( 'a/b' => '^1.0' ), ManifestDelta::blocksOf( $manifest )['require'] );
	}

	// --- computing the delta ---------------------------------------------------------------------

	public function test_nothing_changed_is_an_empty_delta(): void {
		$blocks = $this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) );

		self::assertTrue( ManifestDelta::between( $blocks, $blocks )->isEmpty() );
	}

	public function test_an_added_package_is_written_into_the_manifest(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array() ),
			$this->blocks( array( 'require' => array( 'guzzlehttp/guzzle' => '^7.9' ) ) )
		);

		self::assertFalse( $delta->isEmpty() );
		self::assertJsonStringEqualsJsonString(
			'{"require":{"guzzlehttp/guzzle":"^7.9"}}',
			$delta->applyTo( 'composer-deps.json', '{"require":{}}', false )
		);
	}

	public function test_a_removed_package_is_deleted_from_the_manifest(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0', 'c/d' => '^2.0' ) ) ),
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) )
		);

		self::assertJsonStringEqualsJsonString(
			'{"require":{"a/b":"^1.0"}}',
			$delta->applyTo( 'composer-deps.json', '{"require":{"a/b":"^1.0","c/d":"^2.0"}}', false )
		);
	}

	public function test_a_changed_constraint_is_updated(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) ),
			$this->blocks( array( 'require' => array( 'a/b' => '^2.0' ) ) )
		);

		self::assertJsonStringEqualsJsonString(
			'{"require":{"a/b":"^2.0"}}',
			$delta->applyTo( 'composer-deps.json', '{"require":{"a/b":"^1.0"}}', false )
		);
	}

	/**
	 * `composer require a/b --dev` on a package already in `require` moves it rather than adding a
	 * second entry. A delta that only looked for additions would leave it declared twice, which
	 * Composer rejects outright.
	 */
	public function test_a_package_moved_to_require_dev_is_not_left_in_both_blocks(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) ),
			$this->blocks( array( 'require-dev' => array( 'a/b' => '^1.0' ) ) )
		);

		$result = $delta->applyTo( 'composer-deps.json', '{"require":{"a/b":"^1.0"}}', false );

		self::assertJsonStringEqualsJsonString( '{"require-dev":{"a/b":"^1.0"}}', $result );
		self::assertSame( 1, substr_count( $result, '"a/b"' ) );
	}

	/**
	 * What Composer's own `remove` does: emptying a block should not leave `"require": {}` behind.
	 */
	public function test_emptying_a_block_removes_the_block(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) ),
			$this->blocks( array() )
		);

		self::assertJsonStringEqualsJsonString(
			'{"name":"acme/plugin"}',
			$delta->applyTo( 'composer-deps.json', '{"name":"acme/plugin","require":{"a/b":"^1.0"}}', false )
		);
	}

	public function test_the_two_blocks_are_tracked_independently(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) ),
			$this->blocks( array(
				'require'     => array( 'a/b' => '^1.0', 'c/d' => '^3.0' ),
				'require-dev' => array( 'e/f' => '^4.0' ),
			) )
		);

		self::assertJsonStringEqualsJsonString(
			'{"require":{"a/b":"^1.0","c/d":"^3.0"},"require-dev":{"e/f":"^4.0"}}',
			$delta->applyTo( 'composer-deps.json', '{"require":{"a/b":"^1.0"}}', false )
		);
	}

	// --- the rest of the file survives ------------------------------------------------------------

	/**
	 * The whole point of applying a delta rather than copying the workspace manifest back: the run
	 * has no business touching anything the user did not ask it to.
	 */
	public function test_every_other_key_and_the_formatting_are_left_alone(): void {
		$manifest = <<<'JSON'
{
    "require": {
        "a/b": "^1.0"
    },
    "config": {
        "platform": {
            "php": "8.2.0"
        }
    },
    "scripts": {
        "post-install-cmd": "echo hi"
    }
}
JSON;

		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) ),
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0', 'c/d' => '^2.0' ) ) )
		);

		$result = $delta->applyTo( 'composer-deps.json', $manifest, false );

		self::assertStringContainsString( '"post-install-cmd": "echo hi"', $result );
		self::assertStringContainsString( '"php": "8.2.0"', $result );
		self::assertStringContainsString( '"c/d": "^2.0"', $result );
	}

	public function test_it_can_sort_the_require_block(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'm/m' => '^1.0' ) ) ),
			$this->blocks( array( 'require' => array( 'm/m' => '^1.0', 'a/a' => '^1.0' ) ) )
		);

		$sorted = $delta->applyTo( 'composer-deps.json', '{"require":{"m/m":"^1.0"}}', true );

		self::assertLessThan( strpos( $sorted, 'm/m' ), (int) strpos( $sorted, 'a/a' ) );
	}

	/**
	 * A failed manipulation would publish a lock declaring a dependency the manifest does not.
	 */
	public function test_a_manifest_that_cannot_be_edited_is_an_error(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array() ),
			$this->blocks( array( 'require' => array( 'a/b' => '^1.0' ) ) )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot edit composer-deps.json, it is not a valid JSON object' );

		$delta->applyTo( 'composer-deps.json', 'not json at all', false );
	}

	// --- what it says on screen --------------------------------------------------------------------

	public function test_it_describes_what_it_changed(): void {
		$delta = ManifestDelta::between(
			$this->blocks( array( 'require' => array( 'old/one' => '^1.0' ) ) ),
			$this->blocks( array( 'require' => array( 'new/one' => '^7.9' ) ) )
		);

		self::assertSame( '+new/one ^7.9, -old/one', $delta->describe() );
	}
}
