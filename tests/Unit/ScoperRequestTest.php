<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Wpify\Scoper\Action;
use Wpify\Scoper\ScoperRequest;

#[CoversClass( ScoperRequest::class )]
#[CoversClass( Action::class )]
final class ScoperRequestTest extends TestCase {

	private const SOURCE = '/tmp/tmp-abc/source';

	/**
	 * The definition ScoperCommand declares, so the parsing these tests exercise is the parsing
	 * the command actually performs.
	 */
	private function definition(): InputDefinition {
		return new InputDefinition( array(
			new InputArgument( 'action', InputArgument::REQUIRED ),
			new InputArgument( 'packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL ),
			new InputOption( 'no-dev', null, InputOption::VALUE_NONE ),
			new InputOption( 'dev', null, InputOption::VALUE_NONE ),
			new InputOption( 'with-all-dependencies', 'W', InputOption::VALUE_NONE ),
			new InputOption( 'fixed', null, InputOption::VALUE_NONE ),
			new InputOption( 'dry-run', null, InputOption::VALUE_NONE ),
		) );
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function request( array $parameters ): ScoperRequest {
		return ScoperRequest::fromInput( new ArrayInput( $parameters, $this->definition() ) );
	}

	// --- the actions ------------------------------------------------------------------------------

	public function test_it_parses_every_action(): void {
		self::assertSame( Action::Install, $this->request( array( 'action' => 'install' ) )->action );
		self::assertSame( Action::Update, $this->request( array( 'action' => 'update' ) )->action );
		self::assertSame( Action::Require, $this->request( array( 'action' => 'require', 'packages' => array( 'a/b' ) ) )->action );
		self::assertSame( Action::Remove, $this->request( array( 'action' => 'remove', 'packages' => array( 'a/b' ) ) )->action );
	}

	public function test_an_unknown_action_names_the_ones_that_exist(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'unknown action "instal", expected one of install, update, require, remove' );

		$this->request( array( 'action' => 'instal' ) );
	}

	public function test_only_require_and_remove_touch_the_manifest(): void {
		self::assertFalse( Action::Install->mutatesManifest() );
		self::assertFalse( Action::Update->mutatesManifest() );
		self::assertTrue( Action::Require->mutatesManifest() );
		self::assertTrue( Action::Remove->mutatesManifest() );
	}

	// --- packages ----------------------------------------------------------------------------------

	#[DataProvider( 'actionsThatTakePackages' )]
	public function test_require_and_remove_need_at_least_one_package( string $action ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $action . ' needs at least one package' );

		$this->request( array( 'action' => $action ) );
	}

	/**
	 * @return array<int, array{string}>
	 */
	public static function actionsThatTakePackages(): array {
		return array( array( 'require' ), array( 'remove' ) );
	}

	/**
	 * Adding the variadic argument turned `wpify-scoper install foo/bar` from a Symfony "too many
	 * arguments" error into something that parses, so the packages would be silently ignored.
	 */
	#[DataProvider( 'actionsThatTakeNoPackages' )]
	public function test_install_and_update_reject_package_names( string $action ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $action . ' does not take package names' );

		$this->request( array( 'action' => $action, 'packages' => array( 'guzzlehttp/guzzle' ) ) );
	}

	/**
	 * @return array<int, array{string}>
	 */
	public static function actionsThatTakeNoPackages(): array {
		return array( array( 'install' ), array( 'update' ) );
	}

	public function test_it_strips_the_constraint_off_a_package_spec(): void {
		$request = $this->request( array(
			'action'   => 'require',
			'packages' => array( 'GuzzleHttp/Guzzle:^7.0', 'monolog/monolog' ),
		) );

		self::assertSame( array( 'guzzlehttp/guzzle', 'monolog/monolog' ), $request->packageNames() );
	}

	// --- the flags belong to the action they were declared for ---------------------------------------

	public function test_install_rejects_a_require_flag(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '--fixed is not valid for the install action, only for require' );

		$this->request( array( 'action' => 'install', '--fixed' => true ) );
	}

	public function test_require_rejects_no_dev(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '--no-dev is not valid for the require action, only for install and update' );

		$this->request( array( 'action' => 'require', 'packages' => array( 'a/b' ), '--no-dev' => true ) );
	}

	public function test_remove_rejects_fixed(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '--fixed is not valid for the remove action, only for require' );

		$this->request( array( 'action' => 'remove', 'packages' => array( 'a/b' ), '--fixed' => true ) );
	}

	// --- what the nested Composer is told -------------------------------------------------------------

	public function test_install_resolves_with_dev_dependencies_by_default(): void {
		self::assertSame(
			array( 'install', '--working-dir=' . self::SOURCE, '--optimize-autoloader' ),
			$this->request( array( 'action' => 'install' ) )->composerArguments( self::SOURCE )
		);
	}

	public function test_no_dev_is_forwarded(): void {
		self::assertContains(
			'--no-dev',
			$this->request( array( 'action' => 'update', '--no-dev' => true ) )->composerArguments( self::SOURCE )
		);
	}

	public function test_require_forwards_the_packages_and_its_flags(): void {
		$arguments = $this->request( array(
			'action'                  => 'require',
			'packages'                => array( 'guzzlehttp/guzzle:^7.0', 'monolog/monolog' ),
			'--dev'                   => true,
			'--with-all-dependencies' => true,
			'--fixed'                 => true,
		) )->composerArguments( self::SOURCE );

		self::assertSame(
			array(
				'require',
				'guzzlehttp/guzzle:^7.0',
				'monolog/monolog',
				'--working-dir=' . self::SOURCE,
				'--optimize-autoloader',
				'--dev',
				'--with-all-dependencies',
				'--fixed',
			),
			$arguments
		);
	}

	public function test_remove_forwards_the_packages(): void {
		$arguments = $this->request( array(
			'action'   => 'remove',
			'packages' => array( 'a/b' ),
			'--dry-run' => true,
		) )->composerArguments( self::SOURCE );

		self::assertSame(
			array( 'remove', 'a/b', '--working-dir=' . self::SOURCE, '--optimize-autoloader', '--dry-run' ),
			$arguments
		);
	}

	/**
	 * --no-dev belongs to install and update, so a require run must never emit it - it would mean
	 * something else entirely there.
	 */
	public function test_require_never_emits_no_dev(): void {
		$arguments = $this->request( array( 'action' => 'require', 'packages' => array( 'a/b' ) ) )
			->composerArguments( self::SOURCE );

		self::assertNotContains( '--no-dev', $arguments );
	}

	// --- the block a require run writes to --------------------------------------------------------------

	public function test_it_targets_require_by_default(): void {
		self::assertSame( 'require', $this->request( array( 'action' => 'require', 'packages' => array( 'a/b' ) ) )->targetBlock() );
	}

	public function test_dev_targets_require_dev(): void {
		self::assertSame(
			'require-dev',
			$this->request( array( 'action' => 'require', 'packages' => array( 'a/b' ), '--dev' => true ) )->targetBlock()
		);
	}

	// --- the script event path ----------------------------------------------------------------------------

	public function test_a_script_event_request_carries_no_packages_and_no_flags(): void {
		$request = ScoperRequest::forAction( Action::Update, false );

		self::assertSame( Action::Update, $request->action );
		self::assertSame( array(), $request->packages );
		self::assertFalse( $request->useDevDependencies );
		self::assertFalse( $request->dryRun );
		self::assertContains( '--no-dev', $request->composerArguments( self::SOURCE ) );
	}

	public function test_a_script_event_cannot_ask_for_require(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'the require action needs package names' );

		ScoperRequest::forAction( Action::Require, true );
	}
}
