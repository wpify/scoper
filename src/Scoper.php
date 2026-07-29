<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use OutOfBoundsException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Drives the scoping pipeline: nested install, php-scoper, dump-autoload, fixups.
 *
 * The steps used to be shell commands written into a `scripts` block of the user's
 * composer-deps.json and executed by a Composer instance run in-process.
 */
final class Scoper {

	/**
	 * Set for the duration of a run, so that the nested Composer process - which loads the
	 * globally installed copy of this plugin - cannot start a run of its own.
	 *
	 * A static flag would not do: the nested install is a separate process.
	 */
	public const RUNNING_ENV = 'WPIFY_SCOPER_RUNNING';

	private readonly Filesystem $filesystem;

	private readonly string $pluginDir;

	public function __construct(
		private readonly Configuration $config,
		private readonly IOInterface $io,
		private readonly ComposerRunner $runner,
		?string $pluginDir = null,
	) {
		$this->filesystem = new Filesystem();
		$this->pluginDir  = $pluginDir ?? dirname( __DIR__ );
	}

	/**
	 * @param string $command            Either `install` or `update`.
	 * @param bool   $useDevDependencies Whether the scoped dependency set includes require-dev.
	 *
	 * @return int Exit code, 0 on success.
	 */
	public function run( string $command, bool $useDevDependencies ): int {
		if ( ! in_array( $command, array( 'install', 'update' ), true ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: unsupported command "%s", expected install or update.', $command ) );
		}

		if ( self::isRunning() ) {
			$this->io->writeError(
				'<warning>wpify-scoper: already running (' . self::RUNNING_ENV . ' is set), skipping this nested invocation. Remove extra.wpify-scoper from your scoped manifest to silence this.</warning>'
			);

			return 0;
		}

		$source      = $this->config->sourceDir();
		$destination = $this->config->destinationDir();
		$scoper      = $this->phpScoperPhar();

		$inherited = $this->clearInheritedEnv();

		Platform::putEnv( self::RUNNING_ENV, '1' );

		try {
			$this->filesystem->ensureDirectoryExists( $source );
			$this->filesystem->ensureDirectoryExists( $destination );

			$this->writeManifest( $source );

			$scoperConfig = ( new ScoperConfigFactory( $this->config, $this->io, $this->filesystem, $this->pluginDir ) )
				->create( $source, $destination );

			$this->io->write( sprintf(
				'<info>wpify-scoper:</info> running composer %s for %s, scoping it with the prefix %s into %s',
				$command,
				$this->config->composerJson,
				$this->config->prefix,
				$this->config->folder
			) );

			$arguments = array( $command, '--working-dir=' . $source, '--optimize-autoloader' );

			if ( ! $useDevDependencies ) {
				$arguments[] = '--no-dev';
			}

			$this->mustSucceed( $this->runner->composer( $arguments, $source ), 'composer ' . $command );

			$this->mustSucceed(
				$this->runner->php(
					array( $scoper, 'add-prefix', '--output-dir=' . $destination, '--force', '--config=' . $scoperConfig ),
					// The working directory the nested Composer used to run this step from.
					$source
				),
				'php-scoper add-prefix'
			);

			$this->mustSucceed(
				$this->runner->composer( array( 'dump-autoload', '--working-dir=' . $destination, '--optimize' ), $destination ),
				'composer dump-autoload'
			);

			( new ScopedTreeInstaller( $this->config, $this->io ) )->install( $destination );

			$this->removeTempDir();

			return 0;
		} catch ( Throwable $throwable ) {
			// The temp folder is deliberately kept: a failed swap leaves the project's previous
			// dependencies in a backup inside it, and deleting it here would destroy the very tree
			// the error message tells the user how to recover.
			$this->io->writeError( sprintf(
				'<warning>wpify-scoper: the run failed, the workspace %s was kept - remove it once you no longer need it.</warning>',
				$this->config->tempDir
			) );

			throw $throwable;
		} finally {
			Platform::clearEnv( self::RUNNING_ENV );

			foreach ( $inherited as $name => $value ) {
				Platform::putEnv( $name, $value );
			}
		}
	}

	/**
	 * Drops the environment variables that would send the nested Composer somewhere else.
	 *
	 * `COMPOSER` names the manifest file Composer reads, so it would look for the project's
	 * composer-deps.json inside the temp workspace; `COMPOSER_VENDOR_DIR` names the directory it
	 * installs into, and php-scoper's finder only ever looks at the workspace's own vendor/. Both
	 * are inherited by the child process, which is how a run that works everywhere else fails for
	 * the one developer who exports them.
	 *
	 * @return array<string, string> The values to put back afterwards.
	 */
	private function clearInheritedEnv(): array {
		$saved = array();

		foreach ( array( 'COMPOSER', 'COMPOSER_VENDOR_DIR' ) as $name ) {
			$value = Platform::getEnv( $name );

			if ( is_string( $value ) && '' !== $value ) {
				$saved[ $name ] = $value;

				Platform::clearEnv( $name );
			}
		}

		return $saved;
	}

	private function removeTempDir(): void {
		if ( is_dir( $this->config->tempDir ) && ! $this->filesystem->removeDirectory( $this->config->tempDir ) ) {
			$this->io->writeError( sprintf(
				'<warning>wpify-scoper: cannot remove the temporary folder %s</warning>',
				$this->config->tempDir
			) );
		}
	}

	public static function isRunning(): bool {
		$flag = Platform::getEnv( self::RUNNING_ENV );

		return is_string( $flag ) && '' !== $flag;
	}

	/**
	 * Derives the manifest the nested Composer resolves against, and writes it into the temp
	 * directory.
	 *
	 * The user's composer-deps.json is only ever read. It used to be rewritten on every run with a
	 * `scripts` block full of absolute host paths, which churned the file and clobbered anything
	 * the user had written there - a hand-maintained `pre-autoload-dump` in particular. Everything
	 * else the user declares, `scripts` included, is passed through untouched.
	 */
	private function writeManifest( string $source ): void {
		$manifest = $this->readManifest();

		// The nested install loads the globally installed copy of this plugin. Without this the
		// nested root package would configure it, and the run would recurse.
		if ( isset( $manifest->extra ) && $manifest->extra instanceof stdClass ) {
			unset( $manifest->extra->{'wpify-scoper'} );
		}

		$this->writeJson( $source . '/composer.json', $manifest );

		if ( file_exists( $this->config->composerLock ) ) {
			if ( ! @copy( $this->config->composerLock, $source . '/composer.lock' ) ) {
				throw new RuntimeException( sprintf( 'wpify-scoper: cannot copy %s.', $this->config->composerLock ) );
			}
		}
	}

	/**
	 * Decoded as objects, not as associative arrays: an empty JSON object has to survive the
	 * round-trip as `{}` rather than turning into `[]`, which Composer rejects.
	 */
	private function readManifest(): stdClass {
		if ( ! file_exists( $this->config->composerJson ) ) {
			$manifest          = new stdClass();
			$manifest->require = new stdClass();

			$this->writeJson( $this->config->composerJson, $manifest );

			return $manifest;
		}

		$contents = @file_get_contents( $this->config->composerJson );

		if ( false === $contents ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot read %s.', $this->config->composerJson ) );
		}

		$manifest = json_decode( $contents );

		if ( ! $manifest instanceof stdClass ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: %s is not a valid JSON object (%s).',
				$this->config->composerJson,
				json_last_error_msg()
			) );
		}

		return $manifest;
	}

	private function writeJson( string $path, stdClass $content ): void {
		$this->filesystem->ensureDirectoryExists( dirname( $path ) );

		$json = json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json || false === @file_put_contents( $path, $json ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot write %s.', $path ) );
		}
	}

	private function mustSucceed( int $exitCode, string $step ): void {
		if ( 0 === $exitCode ) {
			return;
		}

		$errorOutput = trim( $this->runner->getErrorOutput() );

		throw new RuntimeException( sprintf(
			'wpify-scoper: %s failed with exit code %d.%s',
			$step,
			$exitCode,
			'' === $errorOutput ? '' : PHP_EOL . $errorOutput
		) );
	}

	/**
	 * Locates the php-scoper phar.
	 *
	 * Resolved up front so that a broken installation fails before anything has been written.
	 */
	private function phpScoperPhar(): string {
		$candidates = array(
			// wpify/php-scoper as a sibling of this package, i.e. any normal install.
			dirname( $this->pluginDir ) . '/php-scoper/bin/php-scoper.phar',
			// A checkout of this repository, with its own vendor directory.
			$this->pluginDir . '/vendor/wpify/php-scoper/bin/php-scoper.phar',
			// A project-local install while the plugin itself was loaded from somewhere else.
			$this->config->vendorDir . '/wpify/php-scoper/bin/php-scoper.phar',
		);

		if ( class_exists( InstalledVersions::class ) ) {
			try {
				$candidates[] = InstalledVersions::getInstallPath( 'wpify/php-scoper' ) . '/bin/php-scoper.phar';
			} catch ( OutOfBoundsException ) {
				// Not registered in this runtime; the paths above are the answer.
			}
		}

		foreach ( $candidates as $candidate ) {
			$resolved = realpath( $candidate );

			if ( false !== $resolved ) {
				return $resolved;
			}
		}

		throw new RuntimeException( sprintf(
			'wpify-scoper: php-scoper was not found. Is wpify/php-scoper installed? Looked in: %s',
			implode( ', ', $candidates )
		) );
	}
}
