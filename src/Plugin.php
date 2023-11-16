<?php

namespace Wpify\Scoper;

use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class Plugin implements PluginInterface, EventSubscriberInterface {

	public const SCOPER_INSTALL_CMD = 'scoper-install-cmd';
	public const SCOPER_UPDATE_CMD = 'scoper-update-cmd';

	protected $composer;
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
			ScriptEvents::POST_INSTALL_CMD => 'execute',
			ScriptEvents::POST_UPDATE_CMD  => 'execute',
		);
	}

	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
		$this->io       = $io;
		$extra          = $composer->getPackage()->getExtra();
		$prefix         = null;
		$configValues   = array(
			'folder'       => $this->path( getcwd(), 'deps' ),
			'temp'         => $this->path( getcwd(), 'tmp-' . substr( str_shuffle( md5( microtime() ) ), 0, 10 ) ),
			'prefix'       => $prefix,
			'globals'      => array( 'wordpress', 'woocommerce' ),
			'composerjson' => 'composer-deps.json',
			'composerlock' => 'composer-deps.lock',
		);

		if ( ! empty( $extra['wpify-scoper']['folder'] ) ) {
			$configValues['folder']       = $this->path( getcwd(), $extra['wpify-scoper']['folder'] );
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
			$configValues['temp'] = $this->path( getcwd(), $extra['wpify-scoper']['temp'] );
		}

		$this->folder       = $configValues['folder'];
		$this->prefix       = $configValues['prefix'];
		$this->globals      = $configValues['globals'];
		$this->tempDir      = $configValues['temp'];
		$this->composerjson = $configValues['composerjson'];
		$this->composerlock = $configValues['composerlock'];
	}

	public function deactivate( Composer $composer, IOInterface $io ) {
	}

	public function uninstall( Composer $composer, IOInterface $io ) {
	}

	public function getCapabilities() {
		return array(
			CommandProvider::class => self::class,
		);
	}

	public function path( ...$parts ) {
		$path = join( DIRECTORY_SEPARATOR, $parts );

		return str_replace( DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path );
	}

	public function execute( Event $event ) {
		$extra = $event->getComposer()->getPackage()->getExtra();
		if (
			isset( $extra['wpify-scoper']['autorun'] ) &&
			$extra['wpify-scoper']['autorun'] === false &&
			( $event->getName() === ScriptEvents::POST_UPDATE_CMD || $event->getName() === ScriptEvents::POST_INSTALL_CMD )
		) {
			return;
		}

		if ( ! empty( $this->prefix ) ) {
			$source           = $this->path( $this->tempDir, 'source' );
			$destination      = $this->path( $this->tempDir, 'destination' );
			$scoperConfig     = $this->createScoperConfig( $this->tempDir, $source, $destination );
			$composerJsonPath = $this->path( $source, 'composer.json' );
			$composerLockPath = $this->path( $source, 'composer.lock' );

			if ( file_exists( $this->path( getcwd(), $this->composerjson ) ) ) {
				$composerJson = json_decode( file_get_contents( $this->path( getcwd(), $this->composerjson ) ), false );
			} else {
				$composerJson = (object) array(
					'require' => (object) array(),
					'scripts' => (object) array(),
				);
				$this->createJson( $this->path( getcwd(), $this->composerjson ), $composerJson );
			}

			if ( empty( $composerJson->scripts ) ) {
				$composerJson->scripts = (object) array();
			}

			$postinstall     = file_get_contents( __DIR__ . '/../scripts/postinstall.php' );
			$postinstall     = str_replace( '%%source%%', $source, $postinstall );
			$postinstall     = str_replace( '%%destination%%', $destination, $postinstall );
			$postinstall     = str_replace( '%%cwd%%', getcwd(), $postinstall );
			$postinstall     = str_replace( '%%composer_lock%%', $this->composerlock, $postinstall );
			$postinstall     = str_replace( '%%deps%%', $this->folder, $postinstall );
			$postinstall     = str_replace( '%%temp%%', $this->tempDir, $postinstall );
			$postinstall     = str_replace( '%%prefix%%', $this->prefix, $postinstall );
			$postinstallPath = $this->path( $this->tempDir, 'postinstall.php' );
			file_put_contents( $postinstallPath, $postinstall );

			$scriptName = $event->getName();
			if ( $event->getName() === self::SCOPER_UPDATE_CMD ) {
				$scriptName = ScriptEvents::POST_UPDATE_CMD;
			}
			if ( $event->getName() === self::SCOPER_INSTALL_CMD ) {
				$scriptName = ScriptEvents::POST_INSTALL_CMD;
			}

			$composerJson->scripts->{$scriptName} = array(
				'php-scoper.phar add-prefix --output-dir="' . $destination . '" --force --config="' . $scoperConfig . '"',
				'composer dump-autoload --working-dir="' . $destination . '" --ignore-platform-reqs --optimize',
				'php "' . $postinstallPath . '"',
			);

			$this->createJson( $composerJsonPath, $composerJson );

			if ( file_exists( $this->path( getcwd(), $this->composerlock ) ) ) {
				copy( $this->path( getcwd(), $this->composerlock ), $composerLockPath );
			}

			$command = 'install';
			if (
				$event->getName() === ScriptEvents::POST_UPDATE_CMD ||
				$event->getName() === self::SCOPER_UPDATE_CMD
			) {
				$command = 'update';
			}

			$this->runInstall( $source, $command );
		}
	}

	private function createScoperConfig( string $path, string $source, string $destination ) {
		$inc_path    = $this->createPath( array( 'config', 'scoper.inc.php' ) );
		$config_path = $this->createPath( array( 'config', 'scoper.config.php' ) );
		$custom_path = $this->createPath( array( 'scoper.custom.php' ), true );
		$final_path  = $this->path( $path, 'scoper.inc.php' );
		$symbols_dir = $this->createPath( [ 'symbols' ] );

		$this->createFolder( $path );
		$this->createFolder( $source );
		$this->createFolder( $destination );

		$config = require_once $config_path;

		if ( ! is_array( $config ) ) {
			exit;
		}

		$config['prefix']            = $this->prefix;
		$config['source']            = $source;
		$config['destination']       = $destination;
		$config['exclude-constants'] = array( 'NULL', 'TRUE', 'FALSE' );

		if ( in_array( 'wordpress', $this->globals ) ) {
			$config = array_merge_recursive(
				$config,
				require $this->path( $symbols_dir, 'wordpress.php' )
			);
		}

		if ( in_array( 'woocommerce', $this->globals ) ) {
			$config = array_merge_recursive(
				$config,
				require $this->path( $symbols_dir, 'woocommerce.php' )
			);
		}

		if ( in_array( 'plugin-update-checker', $this->globals ) ) {
			$config = array_merge_recursive(
				$config,
				require $this->path( $symbols_dir, 'plugin-update-checker.php' )
			);
		}

		if ( file_exists( $custom_path ) ) {
			copy( $custom_path, $this->path( $path, 'scoper.custom.php' ) );
		}

		copy( $inc_path, $this->path( $path, 'scoper.inc.php' ) );
		file_put_contents( $this->path( $path, 'scoper.config.php' ), '<?php return ' . var_export( $config, true ) . ';' );

		return $final_path;
	}

	private function createPath( array $parts, bool $in_root = false ) {
		$vendor = strpos( dirname( __DIR__ ), 'vendor' . DIRECTORY_SEPARATOR . 'wpify' . DIRECTORY_SEPARATOR . 'scoper' );

		if ( ! $in_root || ! is_int( $vendor ) ) {
			return dirname( __DIR__ ) . DIRECTORY_SEPARATOR . join( DIRECTORY_SEPARATOR, $parts );
		}

		return getcwd() . DIRECTORY_SEPARATOR . join( DIRECTORY_SEPARATOR, $parts );
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

	private function runInstall( string $path, string $command = 'install' ) {
		$output      = new ConsoleOutput();
		$application = new Application();

		return $application->run( new ArrayInput( array(
			'command'                => $command,
			'--working-dir'          => $path,
			'--ignore-platform-reqs' => true,
			'--optimize-autoloader'  => true,
		) ), $output );
	}
}
