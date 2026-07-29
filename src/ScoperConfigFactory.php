<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use RuntimeException;

/**
 * Assembles the php-scoper configuration for one run and writes it into the temp directory.
 */
final class ScoperConfigFactory {

	/**
	 * The symbol lists shipped with the plugin, keyed by their extra.wpify-scoper.globals name.
	 */
	private const BUNDLED_SYMBOLS = array(
		'action-scheduler' => 'action-scheduler.php',
		'woocommerce'      => 'woocommerce.php',
		'wordpress'        => 'wordpress.php',
		'wp-cli'           => 'wp-cli.php',
	);

	/**
	 * Names that used to be accepted in globals, with the reason they no longer are.
	 *
	 * Naming one is a warning and a no-op, never an error: projects that still list it have
	 * to keep installing until they get around to removing the line.
	 */
	private const REMOVED_SYMBOLS = array(
		'plugin-update-checker' => 'plugin-update-checker is now scoped like every other dependency - the list only ever held dead PUC v4 class names, under a key this plugin neutralises anyway',
	);

	public function __construct(
		private readonly Configuration $config,
		private readonly IOInterface $io,
		private readonly Filesystem $filesystem,
		private readonly string $pluginDir,
	) {
	}

	/**
	 * Writes scoper.inc.php, scoper.config.php and (when present) scoper.custom.php into the temp
	 * directory.
	 *
	 * @return string Path of the scoper.inc.php php-scoper has to be pointed at.
	 */
	public function create( string $source, string $destination ): string {
		$tempDir    = $this->config->tempDir;
		$incPath    = $this->pluginDir . '/config/scoper.inc.php';
		$configPath = $this->pluginDir . '/config/scoper.config.php';
		$finalPath  = $tempDir . '/scoper.inc.php';

		$this->filesystem->ensureDirectoryExists( $tempDir );

		$config = $this->assemble( $configPath, $source, $destination );

		$customPath = $this->customizationsPath();

		if ( null !== $customPath ) {
			$this->io->write( sprintf( '<info>wpify-scoper:</info> using the customizations from %s', $customPath ) );
			$this->copy( $customPath, $tempDir . '/scoper.custom.php' );
		} elseif ( $this->io->isVerbose() ) {
			$this->io->write( sprintf( '<info>wpify-scoper:</info> no scoper.custom.php found in %s', $this->config->rootDir ) );
		}

		$this->copy( $incPath, $finalPath );

		// The patcher's only collaborator. php-scoper runs scoper.inc.php from inside its phar,
		// so the class has to sit next to it and be required by path.
		$this->copy( $this->pluginDir . '/src/SymbolUnprefixer.php', $tempDir . '/SymbolUnprefixer.php' );

		$this->write( $tempDir . '/scoper.config.php', '<?php return ' . var_export( $config, true ) . ';' );

		return $finalPath;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function assemble( string $configPath, string $source, string $destination ): array {
		// Plain require: require_once returns true instead of the config on a second include.
		$config = require $configPath;

		if ( ! is_array( $config ) ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: %s must return an array, got %s.',
				$configPath,
				get_debug_type( $config )
			) );
		}

		$named = array();

		foreach ( $config as $key => $value ) {
			// php-scoper reads its configuration by name, so a positional entry is a typo the
			// user would otherwise only find out about as a silently ignored setting.
			if ( ! is_string( $key ) ) {
				throw new RuntimeException( sprintf(
					'wpify-scoper: %s has the non-string key %s; every php-scoper option is named.',
					$configPath,
					var_export( $key, true )
				) );
			}

			$named[ $key ] = $value;
		}

		$config = $named;

		$config['prefix']      = $this->config->prefix;
		$config['source']      = $source;
		$config['destination'] = $destination;

		/**
		 * Seeded so that an empty symbol set still produces every key the patcher reads: an
		 * undefined `exclude-classes` used to surface as a TypeError from inside a php-scoper
		 * patcher, mid-scope, with a stack trace nobody could act on.
		 *
		 * @var array<string, list<string>> $exclusions
		 */
		$exclusions = array(
			'exclude-classes'    => array(),
			'exclude-constants'  => array( 'NULL', 'TRUE', 'FALSE' ),
			'exclude-functions'  => array(),
			'exclude-namespaces' => array(),
		);

		$this->warnAboutUnknownGlobals();

		// Iterated in the order the lists are declared, not the order the project happens to
		// list them in, so that the generated config does not depend on the composer.json.
		foreach ( self::BUNDLED_SYMBOLS as $name => $file ) {
			if ( ! in_array( $name, $this->config->globals, true ) ) {
				continue;
			}

			foreach ( $this->symbols( $file ) as $key => $values ) {
				$exclusions[ $key ] = array_merge( $exclusions[ $key ] ?? array(), $values );
			}
		}

		// The lists genuinely overlap - WooCommerce bundles all of Action Scheduler, wp-cli
		// repeats a good part of WordPress - so a plain concatenation would double thousands of
		// entries and slow every patcher lookup down for nothing.
		foreach ( $exclusions as $key => $values ) {
			$config[ $key ] = array_values( array_unique( $values ) );
		}

		return $config;
	}

	/**
	 * Loads one shipped symbol list.
	 *
	 * Validated rather than trusted: the files are generated, and a generator that broke halfway
	 * would otherwise hand php-scoper a config it fails on far downstream.
	 *
	 * @return array<string, list<string>>
	 */
	private function symbols( string $file ): array {
		$path    = $this->pluginDir . '/symbols/' . $file;
		$symbols = require $path;

		if ( ! is_array( $symbols ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: the symbol list %s must return an array.', $path ) );
		}

		$validated = array();

		foreach ( $symbols as $key => $values ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'exclude-' ) ) {
				throw new RuntimeException( sprintf(
					'wpify-scoper: the symbol list %s has the unexpected key %s, expected an "exclude-*" one.',
					$path,
					var_export( $key, true )
				) );
			}

			if ( ! is_array( $values ) ) {
				throw new RuntimeException( sprintf(
					'wpify-scoper: %s in the symbol list %s must be an array of symbol names, got %s.',
					$key,
					$path,
					get_debug_type( $values )
				) );
			}

			$names = array();

			foreach ( $values as $value ) {
				if ( ! is_string( $value ) ) {
					throw new RuntimeException( sprintf(
						'wpify-scoper: %s in the symbol list %s holds a %s where a symbol name was expected.',
						$key,
						$path,
						get_debug_type( $value )
					) );
				}

				$names[] = $value;
			}

			$validated[ $key ] = $names;
		}

		return $validated;
	}

	/**
	 * Locates the project's scoper.custom.php.
	 *
	 * The project root is checked first; the plugin directory stays a fallback so that a checkout
	 * of the plugin itself keeps working. The old implementation picked between the two by looking
	 * for the literal string "vendor/wpify/scoper" in its own path, which silently ignored the
	 * user's file for a custom vendor-dir or a symlinked path repository.
	 */
	private function customizationsPath(): ?string {
		foreach ( array( $this->config->rootDir, $this->pluginDir ) as $directory ) {
			$candidate = $directory . '/scoper.custom.php';

			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Warns about every globals entry that names no shipped symbol list.
	 *
	 * Deliberately not an error: an unknown name has always been ignored silently, and making
	 * it fatal now would break the projects that still list a name this release removed.
	 */
	private function warnAboutUnknownGlobals(): void {
		foreach ( $this->config->globals as $name ) {
			if ( isset( self::BUNDLED_SYMBOLS[ $name ] ) ) {
				continue;
			}

			if ( isset( self::REMOVED_SYMBOLS[ $name ] ) ) {
				$this->io->writeError( sprintf(
					'<warning>wpify-scoper: extra.wpify-scoper.globals entry "%s" is deprecated and ignored - %s. Remove it from your composer.json.</warning>',
					$name,
					self::REMOVED_SYMBOLS[ $name ]
				) );

				continue;
			}

			$this->io->writeError( sprintf(
				'<warning>wpify-scoper: unknown extra.wpify-scoper.globals entry "%s", ignoring it. Known values: %s.</warning>',
				$name,
				implode( ', ', array_keys( self::BUNDLED_SYMBOLS ) )
			) );
		}
	}

	private function copy( string $from, string $to ): void {
		if ( ! @copy( $from, $to ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot copy %s to %s.', $from, $to ) );
		}
	}

	private function write( string $path, string $contents ): void {
		if ( false === @file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( sprintf( 'wpify-scoper: cannot write %s.', $path ) );
		}
	}
}
