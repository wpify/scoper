<?php

namespace WpifyScoper;

use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
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

	/** @var string */
	private $tempDir;

	public static function getSubscribedEvents() {
		return array(
			ScriptEvents::POST_INSTALL_CMD => 'handleScoping',
			ScriptEvents::POST_UPDATE_CMD  => 'handleScoping',
		);
	}

	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
		$this->io       = $io;

		$extra  = $composer->getPackage()->getExtra();
		$prefix = $this->toCamelCase( $composer->getPackage()->getName() );

		$configValues = array(
			'folder'   => getcwd() . DIRECTORY_SEPARATOR . 'vendor-scoped',
			'temp'     => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wordpress-scopper' . DIRECTORY_SEPARATOR . $prefix,
			'prefix'   => $prefix,
			'globals'  => array( 'wordpress' ),
			'packages' => array(),
		);

		if ( ! empty( $extra['wordpress-scoper']['folder'] ) ) {
			$configValues['folder'] = getcwd() . DIRECTORY_SEPARATOR . $extra['wordpress-scoper']['folder'];
		}

		if ( ! empty( $extra['wordpress-scoper']['prefix'] ) ) {
			$configValues['prefix'] = $extra['wordpress-scoper']['prefix'];
		}

		if ( ! empty( $extra['wordpress-scoper']['globals'] ) && is_array( $extra['wordpress-scoper']['globals'] ) ) {
			$configValues['globals'] = $extra['wordpress-scoper']['globals'];
		}

		if ( ! empty( $extra['wordpress-scoper']['packages'] ) && is_array( $extra['wordpress-scoper']['packages'] ) ) {
			$configValues['packages'] = $extra['wordpress-scoper']['packages'];
		}

		if ( ! empty( $extra['wordpress-scoper']['temp'] ) ) {
			$configValues['temp'] = getcwd() . DIRECTORY_SEPARATOR . $extra['wordpress-scoper']['temp'];
		}

		$this->folder   = $configValues['folder'];
		$this->prefix   = $configValues['prefix'];
		$this->globals  = $configValues['globals'];
		$this->packages = $configValues['packages'];
		$this->tempDir  = $configValues['temp'];
	}

	public function toCamelCase( string $source = '' ) {
		$text = preg_replace( '/[^a-zA-Z0-9]+/', ' ', $source );
		$text = ucwords( $text );
		$text = str_replace( ' ', '', $text );

		return $text;
	}

	public function deactivate( Composer $composer, IOInterface $io ) {
		// TODO: Implement deactivate() method.
	}

	public function uninstall( Composer $composer, IOInterface $io ) {
		// TODO: Implement uninstall() method.
	}

	public function handleScoping( Event $event ) {
		if ( ! empty( $this->packages ) ) {
			$source            = $this->tempDir . DIRECTORY_SEPARATOR . 'source';
			$destination       = $this->tempDir . DIRECTORY_SEPARATOR . 'destination';
			$destinationVendor = $destination . DIRECTORY_SEPARATOR . 'vendor';
			$scoperConfig      = $this->createScoperConfig( $this->tempDir, $source, $destination );

			$commands = array(
				'php-scoper add-prefix --output-dir=' . $destination . ' --force --config=' . $scoperConfig,
				'composer dump-autoload --working-dir=' . $destination . ' --ignore-platform-reqs --optimize',
				'rm -rf ' . $this->folder,
				'mv ' . $destinationVendor . ' ' . $this->folder,
				'rm -rf ' . $this->tempDir,
			);

			$composerJson = array(
				'require' => $this->packages,
				'scripts' => array(
					'post-install-cmd' => $commands,
					'post-update-cmd'  => $commands,
				),
			);

			$this->createJson( strval( $source . DIRECTORY_SEPARATOR . 'composer.json' ), $composerJson );
			$this->runInstall( $source );
		}
	}

	private function createScoperConfig( string $path, string $source, string $destination ) {
		$inc_path    = $this->createPath( array( __DIR__, '..', 'config', 'scoper.inc.php' ) );
		$config_path = $this->createPath( array( __DIR__, '..', 'config', 'scoper.config.php' ) );
		$custom_path = $this->createPath( array( getcwd(), 'scoper.custom.php' ) );
		$final_path  = $path . DIRECTORY_SEPARATOR . 'scoper.inc.php';
		$symbols_dir = realpath( __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'symbols' );

		$this->createFolder( $path );
		$this->createFolder( $source );
		$this->createFolder( $destination );

		$config                = require_once $config_path;
		$config['prefix']      = $this->prefix;
		$config['source']      = $source;
		$config['destination'] = $destination;
		$config['whitelist']   = array( 'NULL', 'TRUE', 'FALSE' );

		if ( in_array( 'wordpress', $this->globals ) ) {
			$config['whitelist'] = array_merge_recursive(
				$config['whitelist'],
				require $symbols_dir . DIRECTORY_SEPARATOR . 'wordpress.php'
			);
		}

		if ( in_array( 'woocommerce', $this->globals ) ) {
			$config['whitelist'] = array_merge_recursive(
				$config['whitelist'],
				require $symbols_dir . DIRECTORY_SEPARATOR . 'woocommerce.php'
			);
		}

		if ( file_exists( $custom_path ) ) {
			copy( $custom_path, $path . DIRECTORY_SEPARATOR . 'scoper.custom.php' );
		}

		copy( $inc_path, $path . DIRECTORY_SEPARATOR . 'scoper.inc.php' );
		file_put_contents( $path . DIRECTORY_SEPARATOR . 'scoper.config.php', '<?php return ' . var_export( $config, true ) . ';' );

		return $final_path;
	}

	private function createPath( array $parts ) {
		return DIRECTORY_SEPARATOR . join( DIRECTORY_SEPARATOR, $parts );
	}

	private function createFolder( string $path ) {
		if ( ! file_exists( $path ) ) {
			mkdir( $path, 0755, true );
		}
	}

	private function createJson( string $path, array $content ) {
		$this->createFolder( dirname( $path ) );
		$json = json_encode( $content );
		file_put_contents( $path, $json );
	}

	private function runInstall( string $path ) {
		$output      = new ConsoleOutput();
		$application = new Application();

		return $application->run( new ArrayInput( array(
			'command'                => 'install',
			'--working-dir'          => $path,
			'--ignore-platform-reqs' => true,
			'--optimize-autoloader'  => true,
		) ), $output );
	}
}
