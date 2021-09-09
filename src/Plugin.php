<?php

namespace Wpify\Scoper;

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

	/** @var string */
	private $composerjson;

	/** @var string */
	private $composerlock;

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
			'folder'       => getcwd() . DIRECTORY_SEPARATOR . 'deps',
			'temp'         => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpify-scopper' . DIRECTORY_SEPARATOR . $prefix,
			'prefix'       => $prefix,
			'globals'      => array( 'wordpress' ),
			'composerjson' => 'composer-deps.json',
			'composerlock' => 'composer-deps.lock',
		);

		if ( ! empty( $extra['wpify-scoper']['folder'] ) ) {
			$configValues['folder']       = getcwd() . DIRECTORY_SEPARATOR . $extra['wpify-scoper']['folder'];
			$configValues['composerjson'] = 'composer-' . $extra['wpify-scoper']['folder'] . '.json';
			$configValues['composerlock'] = 'composer-' . $extra['wpify-scoper']['folder'] . '.lock';
		}

		if ( ! empty( $extra['wpify-scoper']['composerjson'] ) ) {
			$configValues['composerjson'] = $extra['wpify-scoper']['composerjson'];
			$configValues['composerlock'] = preg_replace( '/\.json$/', '.lock', $extra['wpify-scoper']['composerjson'] );
		}

		if ( ! empty( $extra['wpify-scoper']['composerlock'] ) ) {
			$configValues['composerlock'] = $extra['wpify-scoper']['composerlock'];
		}

		if ( ! empty( $extra['wpify-scoper']['prefix'] ) ) {
			$configValues['prefix'] = $extra['wpify-scoper']['prefix'];
		}

		if ( ! empty( $extra['wpify-scoper']['globals'] ) && is_array( $extra['wpify-scoper']['globals'] ) ) {
			$configValues['globals'] = $extra['wpify-scoper']['globals'];
		}

		if ( ! empty( $extra['wpify-scoper']['temp'] ) ) {
			$configValues['temp'] = getcwd() . DIRECTORY_SEPARATOR . $extra['wpify-scoper']['temp'];
		}

		$this->folder       = $configValues['folder'];
		$this->prefix       = $configValues['prefix'];
		$this->globals      = $configValues['globals'];
		$this->tempDir      = $configValues['temp'];
		$this->composerjson = $configValues['composerjson'];
		$this->composerlock = $configValues['composerlock'];
	}

	public function toCamelCase( string $source = '' ) {
		return str_replace( ' ', '', ucwords( preg_replace( '/[^a-zA-Z0-9]+/', ' ', $source ) ) );
	}

	public function deactivate( Composer $composer, IOInterface $io ) {
		// TODO: Implement deactivate() method.
	}

	public function uninstall( Composer $composer, IOInterface $io ) {
		// TODO: Implement uninstall() method.
	}

	public function handleScoping( Event $event ) {
		if ( ! empty( $this->prefix ) ) {
			$source            = $this->tempDir . DIRECTORY_SEPARATOR . 'source';
			$destination       = $this->tempDir . DIRECTORY_SEPARATOR . 'destination';
			$destinationVendor = $destination . DIRECTORY_SEPARATOR . 'vendor';
			$scoperConfig      = $this->createScoperConfig( $this->tempDir, $source, $destination );

			$commands = array(
				'php-scoper add-prefix --output-dir=' . $destination . ' --force --config=' . $scoperConfig,
				'composer dump-autoload --working-dir=' . $destination . ' --ignore-platform-reqs --optimize',
				'cp "' . $source . DIRECTORY_SEPARATOR . 'composer.lock" "' . getcwd() . DIRECTORY_SEPARATOR . $this->composerlock . '"',
				'rm -rf ' . $this->folder,
				'mv ' . $destinationVendor . ' ' . $this->folder,
				'rm -rf ' . $this->tempDir,
			);

			$composerJsonPath = $source . DIRECTORY_SEPARATOR . 'composer.json';
			$composerLockPath = $source . DIRECTORY_SEPARATOR . 'composer.lock';

			if ( file_exists( getcwd() . DIRECTORY_SEPARATOR . $this->composerjson ) ) {
				$composerJson = json_decode( file_get_contents( getcwd() . DIRECTORY_SEPARATOR . $this->composerjson ), false );
			} else {
				$composerJson = (object) array(
					'require' => (object) array(),
					'scripts' => (object) array(),
				);
				$this->createJson( getcwd() . DIRECTORY_SEPARATOR . $this->composerjson, $composerJson );
			}

			if ( empty( $composerJson->scripts ) ) {
				$composerJson->scripts = (object) array();
			}

			$composerJson->scripts->{'post-install-cmd'} = $commands;
			$composerJson->scripts->{'post-update-cmd'} = $commands;

			$this->createJson( $composerJsonPath, $composerJson );

			if ( file_exists( getcwd() . DIRECTORY_SEPARATOR . $this->composerlock ) ) {
				copy( getcwd() . DIRECTORY_SEPARATOR . $this->composerlock, $composerLockPath );
			}

			$this->runInstall( $source );

			if ( file_exists( $composerLockPath ) ) {
				copy( $composerLockPath, getcwd() . DIRECTORY_SEPARATOR . $this->composerlock );
			}
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

	private function createJson( string $path, $content ) {
		$this->createFolder( dirname( $path ) );
		$json = json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		file_put_contents( $path, $json );
	}

	private function runInstall( string $path ) {
		$output      = new ConsoleOutput();
		$application = new Application();

		return $application->run( new ArrayInput( array(
			'command'                => 'update',
			'--working-dir'          => $path,
			'--ignore-platform-reqs' => true,
			'--optimize-autoloader'  => true,
		) ), $output );
	}
}
