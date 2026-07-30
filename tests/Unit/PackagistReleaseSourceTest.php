<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wpify\Scoper\PackagistReleaseSource;

#[CoversClass( PackagistReleaseSource::class )]
final class PackagistReleaseSourceTest extends TestCase {

	private function fixture(): string {
		$contents = file_get_contents( dirname( __DIR__ ) . '/fixtures/packagist/wpify-scoper-p2.json' );

		self::assertIsString( $contents );

		return $contents;
	}

	public function test_it_picks_the_newest_stable_release_not_the_newest_entry(): void {
		// The fixture lists 5.0.0-beta1 and 4.1.0-RC1 above 4.0.1, and lists nothing in order.
		self::assertSame( '4.0.1', PackagistReleaseSource::newestStable( $this->fixture() ) );
	}

	#[DataProvider( 'unusable_responses' )]
	public function test_it_returns_null_for( string $json ): void {
		self::assertNull( PackagistReleaseSource::newestStable( $json ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_responses(): array {
		return array(
			'malformed json'            => array( '{"packages": ' ),
			'not an object'             => array( '"a string"' ),
			'no packages key'           => array( '{}' ),
			'a different package only'  => array( '{"packages":{"other/thing":[{"version":"9.9.9"}]}}' ),
			'versions is not a list'    => array( '{"packages":{"wpify/scoper":"nope"}}' ),
			'no versions at all'        => array( '{"packages":{"wpify/scoper":[]}}' ),
			'nothing stable published'  => array( '{"packages":{"wpify/scoper":[{"version":"1.0.0-alpha1"},{"version":"1.0.0-RC2"}]}}' ),
			'entries without a version' => array( '{"packages":{"wpify/scoper":[{"type":"library"}]}}' ),
			'unparseable version'       => array( '{"packages":{"wpify/scoper":[{"version":"not a version"}]}}' ),
		);
	}

	public function test_an_unparseable_entry_does_not_hide_a_usable_one(): void {
		$json = '{"packages":{"wpify/scoper":[{"version":"not a version"},{"version":"4.0.2"}]}}';

		self::assertSame( '4.0.2', PackagistReleaseSource::newestStable( $json ) );
	}
}
