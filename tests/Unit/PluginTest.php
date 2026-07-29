<?php declare( strict_types=1 );

namespace Wpify\Scoper\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\RootPackage;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Wpify\Scoper\CommandProvider;
use Wpify\Scoper\Configuration;
use Wpify\Scoper\Plugin;
use Wpify\Scoper\ScoperCommand;

/**
 * The Composer wiring.
 *
 * This plugin is normally installed globally, which activates it for every Composer project on the
 * machine. Anything it gets wrong here it gets wrong everywhere.
 */
#[CoversClass( Plugin::class )]
#[CoversClass( CommandProvider::class )]
final class PluginTest extends TestCase {

	/**
	 * @param array<string, mixed> $extra
	 */
	private function composer( array $extra ): Composer {
		$package = new RootPackage( 'acme/plugin', '1.0.0.0', '1.0.0' );
		$package->setExtra( $extra );

		$config = new Config( false, sys_get_temp_dir() );

		// Composer\Factory always sets one; Configuration reads the resolved path off it so that
		// it stays correct under --working-dir, COMPOSER= and `composer global`.
		$config->setConfigSource( new JsonConfigSource( new JsonFile( sys_get_temp_dir() . '/composer.json' ) ) );

		$composer = new Composer();
		$composer->setPackage( $package );
		$composer->setConfig( $config );

		return $composer;
	}

	public function test_it_is_a_composer_plugin(): void {
		$plugin = new Plugin();

		self::assertInstanceOf( PluginInterface::class, $plugin );
		self::assertInstanceOf( Capable::class, $plugin );
	}

	/**
	 * Composer instantiates the declared capability class and then asserts it implements the
	 * capability interface. Pointing the capability at a class that does not - which is what the
	 * plugin used to do - makes every `composer list` in the project throw.
	 */
	public function test_the_declared_capability_class_implements_the_capability(): void {
		$capabilities = ( new Plugin() )->getCapabilities();

		self::assertArrayHasKey( ComposerCommandProvider::class, $capabilities );

		$class    = $capabilities[ ComposerCommandProvider::class ];
		$provider = new $class( array( 'composer' => null, 'io' => null, 'plugin' => null ) );

		self::assertInstanceOf( ComposerCommandProvider::class, $provider );
	}

	public function test_the_capability_provides_the_scoper_command(): void {
		$commands = ( new CommandProvider() )->getCommands();

		self::assertCount( 1, $commands );
		self::assertInstanceOf( ScoperCommand::class, $commands[0] );
		self::assertSame( 'wpify-scoper', $commands[0]->getName() );
	}

	public function test_it_subscribes_to_both_script_events(): void {
		$events = Plugin::getSubscribedEvents();

		self::assertSame(
			array( ScriptEvents::POST_INSTALL_CMD => 'execute', ScriptEvents::POST_UPDATE_CMD => 'execute' ),
			$events
		);
	}

	// --- the global-install contract ------------------------------------------------------------

	public function test_a_project_that_does_not_configure_the_plugin_yields_no_configuration(): void {
		self::assertNull( Configuration::fromComposer( $this->composer( array() ) ) );
		self::assertNull( Configuration::fromComposer( $this->composer( array( 'other-plugin' => array( 'x' => 1 ) ) ) ) );
	}

	public function test_activating_on_an_unconfigured_project_is_silent(): void {
		$io = new BufferIO( '', OutputInterface::VERBOSITY_VERY_VERBOSE );

		( new Plugin() )->activate( $this->composer( array() ), $io );

		self::assertSame( '', $io->getOutput() );
	}

	public function test_activating_on_a_configured_project_reports_the_resolved_settings(): void {
		$io = new BufferIO( '', OutputInterface::VERBOSITY_VERY_VERBOSE );

		( new Plugin() )->activate(
			$this->composer( array( 'wpify-scoper' => array( 'prefix' => 'Acme\\Deps' ) ) ),
			$io
		);

		$output = $io->getOutput();

		self::assertStringContainsString( 'prefix "Acme\\Deps"', $output );
		self::assertStringContainsString( 'folder "', $output );
	}

	public function test_a_configured_project_yields_a_configuration(): void {
		$config = Configuration::fromComposer(
			$this->composer( array( 'wpify-scoper' => array( 'prefix' => 'Acme\\Deps' ) ) )
		);

		self::assertNotNull( $config );
		self::assertSame( 'Acme\\Deps', $config->prefix );
	}

	// --- the re-entrancy guard --------------------------------------------------------------------

	public function test_the_running_flag_is_not_set_outside_a_run(): void {
		self::assertFalse( \Wpify\Scoper\Scoper::isRunning() );
	}
}
