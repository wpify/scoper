<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use Composer\IO\BufferIO;
use Composer\Util\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Wpify\Scoper\ReleaseSource;
use Wpify\Scoper\UpdateNotifier;

#[CoversClass( UpdateNotifier::class )]
final class UpdateNotifierTest extends TestCase {

	protected function tearDown(): void {
		Platform::clearEnv( UpdateNotifier::DISABLE_ENV );
		Platform::clearEnv( 'COMPOSER_DISABLE_NETWORK' );
	}

	/**
	 * BufferIO hard-codes its input as non-interactive, which is the one state in which the
	 * notifier does nothing at all, so the interactivity has to be overridden to test anything.
	 */
	private function io( bool $interactive = true, int $verbosity = OutputInterface::VERBOSITY_NORMAL ): BufferIO {
		return new class( $interactive, $verbosity ) extends BufferIO {
			public function __construct( private readonly bool $interactive, int $verbosity ) {
				parent::__construct( '', $verbosity );
			}

			public function isInteractive(): bool {
				return $this->interactive;
			}
		};
	}

	private function source( ?string $latest ): ReleaseSource {
		return new class( $latest ) implements ReleaseSource {
			public function __construct( private readonly ?string $latest ) {
			}

			public function latestStable(): ?string {
				return $this->latest;
			}
		};
	}

	/**
	 * Throws if it is ever consulted, which is how the short-circuits are proven to happen before
	 * anything would reach the network.
	 */
	private function unreachableSource(): ReleaseSource {
		return new class() implements ReleaseSource {
			public function latestStable(): ?string {
				throw new RuntimeException( 'the release source must not be consulted' );
			}
		};
	}

	public function test_it_reports_a_newer_patch_release(): void {
		$io = $this->io();

		( new UpdateNotifier( $io, $this->source( '4.0.1' ), '4.0.0', true ) )->notify();

		self::assertStringContainsString( 'version 4.0.1 is available, you have 4.0.0.', $io->getOutput() );
		self::assertStringContainsString( 'update with "composer global update wpify/scoper".', $io->getOutput() );
	}

	public function test_a_project_local_install_is_told_to_update_without_global(): void {
		$io = $this->io();

		( new UpdateNotifier( $io, $this->source( '4.1.0' ), '4.0.0', false ) )->notify();

		self::assertStringContainsString( 'update with "composer update wpify/scoper".', $io->getOutput() );
		self::assertStringNotContainsString( 'global', $io->getOutput() );
	}

	public function test_a_new_major_links_the_upgrade_guide_instead_of_a_command(): void {
		$io = $this->io();

		( new UpdateNotifier( $io, $this->source( '5.0.0' ), '4.0.0', true ) )->notify();

		self::assertStringContainsString( 'version 5.0.0 is available, you have 4.0.0.', $io->getOutput() );
		self::assertStringContainsString( 'docs/upgrading-to-5.md', $io->getOutput() );
		// `composer update` cannot cross a major, so it must not be offered here.
		self::assertStringNotContainsString( 'update with', $io->getOutput() );
	}

	#[DataProvider( 'silent_cases' )]
	public function test_it_stays_silent( ?string $installed, ?string $latest ): void {
		$io = $this->io();

		( new UpdateNotifier( $io, $this->source( $latest ), $installed, true ) )->notify();

		self::assertSame( '', $io->getOutput() );
	}

	/**
	 * @return array<string, array{0: string|null, 1: string|null}>
	 */
	public static function silent_cases(): array {
		return array(
			'already on the newest release'   => array( '4.0.1', '4.0.1' ),
			'ahead of the newest release'     => array( '4.0.2', '4.0.1' ),
			'installed from a dev checkout'   => array( 'dev-master', '4.0.1' ),
			'installed from a feature branch' => array( 'dev-feat/x', '4.0.1' ),
			'deliberately running an RC'      => array( '4.1.0-RC1', '4.1.0' ),
			'deliberately running a beta'     => array( '5.0.0-beta1', '5.0.0' ),
			'version not known to Composer'   => array( null, '4.0.1' ),
			'no release could be determined'  => array( '4.0.0', null ),
		);
	}

	public function test_a_non_interactive_run_never_reaches_the_network(): void {
		$io = $this->io( interactive: false );

		( new UpdateNotifier( $io, $this->unreachableSource(), '4.0.0', true ) )->notify();

		self::assertSame( '', $io->getOutput() );
	}

	public function test_the_disable_env_var_never_reaches_the_network(): void {
		Platform::putEnv( UpdateNotifier::DISABLE_ENV, '1' );

		$io = $this->io();

		( new UpdateNotifier( $io, $this->unreachableSource(), '4.0.0', true ) )->notify();

		self::assertSame( '', $io->getOutput() );
	}

	public function test_composer_disable_network_never_reaches_the_network(): void {
		Platform::putEnv( 'COMPOSER_DISABLE_NETWORK', '1' );

		$io = $this->io();

		( new UpdateNotifier( $io, $this->unreachableSource(), '4.0.0', true ) )->notify();

		self::assertSame( '', $io->getOutput() );
	}

	public function test_a_failing_release_source_is_swallowed(): void {
		$io = $this->io();

		( new UpdateNotifier( $io, $this->unreachableSource(), '4.0.0', true ) )->notify();

		self::assertSame( '', $io->getOutput() );
	}

	public function test_a_failing_release_source_explains_itself_under_verbose(): void {
		$io = $this->io( verbosity: OutputInterface::VERBOSITY_VERBOSE );

		( new UpdateNotifier( $io, $this->unreachableSource(), '4.0.0', true ) )->notify();

		self::assertStringContainsString( 'the update check failed', $io->getOutput() );
		self::assertStringContainsString( 'the release source must not be consulted', $io->getOutput() );
	}
}
