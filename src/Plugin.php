<?php

namespace WpifyScoper;

use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Plugin implements PluginInterface, EventSubscriberInterface {
	/** @var Composer */
	protected $composer;

	/** @var IOInterface */
	protected $io;

	/** @var string */
	private $folder;

	/** @var string */
	private $prefix;

	/** @var array */
	private $globals;

	/** @var array */
	private $packages;

	public static function getSubscribedEvents() {
		return array(
			PluginEvents::COMMAND => array(
				array( 'handleCommand' ),
			)
		);
	}

	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
		$this->io       = $io;

		$extra         = $composer->getPackage()->getExtra();
		$config_values = array(
			'folder'   => getcwd() . '/vendor-scoped',
			'prefix'   => 'WordPressScoped',
			'globals'  => array( 'wordpress' ),
			'packages' => array(),
		);

		if ( ! empty( $extra['wordpress-scoper']['folder'] ) ) {
			$config_values['folder'] = getcwd() . '/' . $extra['wordpress-scoper']['folder'];
		}

		if ( ! empty( $extra['wordpress-scoper']['prefix'] ) ) {
			$config_values['prefix'] = $extra['wordpress-scoper']['prefix'];
		}

		if ( ! empty( $extra['wordpress-scoper']['globals'] ) && is_array( $extra['wordpress-scoper']['globals'] ) ) {
			$config_values['globals'] = $extra['wordpress-scoper']['globals'];
		}

		if ( ! empty( $extra['wordpress-scoper']['packages'] ) && is_array( $extra['wordpress-scoper']['packages'] ) ) {
			$config_values['packages'] = $extra['wordpress-scoper']['packages'];
		}

		$this->folder   = $config_values['folder'];
		$this->prefix   = $config_values['prefix'];
		$this->globals  = $config_values['globals'];
		$this->packages = $config_values['packages'];
	}

	public function deactivate( Composer $composer, IOInterface $io ) {
		// TODO: Implement deactivate() method.
	}

	public function uninstall( Composer $composer, IOInterface $io ) {
		// TODO: Implement uninstall() method.
	}

	public function handleCommand( CommandEvent $event ) {
		if ( $event->getCommandName() === 'install' ) {
			$this->handleInstall( $event );
		}
	}

	public function handleInstall( CommandEvent $event ) {
		if ( ! empty( $this->packages ) ) {
			$this->createJson( $this->folder . '/composer.json', array( 'require' => $this->packages ) );
			$this->runInstall( $this->folder );
		}
	}

	private function createJson( string $path, array $content ) {
		$this->createFolder( dirname( $path ) );
		$json = json_encode( $content );
		file_put_contents( $path, $json );
	}

	private function createFolder( string $path ) {
		if ( ! file_exists( $path ) ) {
			mkdir( $path, 0755, true );
		}
	}

	private function runInstall( string $path ) {
		$input       = new ArrayInput( array(
			'command'                => 'install',
			'--working-dir'          => $path,
			'--ignore-platform-reqs' => true,
			'--optimize-autoloader'  => true,
		) );
		$output      = new ConsoleOutput();
		$application = new Application();
		$application->run( $input, $output );
	}
}