<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Composer;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use RuntimeException;

/**
 * Validated, immutable view of `extra.wpify-scoper` plus the paths derived from it.
 *
 * Everything the pipeline needs to know about *where* things live is resolved here, once,
 * so that no other class has to call getcwd() or guess at the project layout.
 */
final class Configuration {

	/**
	 * A valid PHP namespace: one or more identifiers separated by backslashes.
	 */
	private const PREFIX_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

	/**
	 * @var list<string>
	 */
	public const DEFAULT_GLOBALS = array( 'wordpress', 'woocommerce', 'action-scheduler', 'wp-cli' );

	/**
	 * @param list<string> $globals
	 */
	private function __construct(
		public readonly string $rootDir,
		public readonly string $vendorDir,
		public readonly string $prefix,
		public readonly string $folder,
		public readonly array $globals,
		public readonly string $composerJson,
		public readonly string $composerLock,
		public readonly string $tempDir,
		public readonly bool $autorun,
	) {
	}

	/**
	 * True when the project actually configures this plugin.
	 *
	 * The plugin is usually installed globally, so it is activated for every Composer project on
	 * the machine - including COMPOSER_HOME itself and the nested install. Projects that do not
	 * configure it must stay a completely silent no-op.
	 *
	 * @param array<string, mixed> $extra The root package's `extra` block.
	 */
	public static function isConfigured( array $extra ): bool {
		return isset( $extra['wpify-scoper'] ) && is_array( $extra['wpify-scoper'] );
	}

	/**
	 * @return self|null Null when the root package does not configure the plugin.
	 */
	public static function fromComposer( Composer $composer ): ?self {
		$extra = $composer->getPackage()->getExtra();

		if ( ! self::isConfigured( $extra ) ) {
			return null;
		}

		$rootDir   = self::resolveRootDir( $composer );
		$vendorDir = $composer->getConfig()->get( 'vendor-dir' );

		// An empty vendor-dir falls back to $rootDir/vendor in fromExtra().
		return self::fromExtra( $extra, $rootDir, is_string( $vendorDir ) ? $vendorDir : '' );
	}

	/**
	 * @param array<string, mixed> $extra     The root package's `extra` block.
	 * @param string               $rootDir   Absolute path to the directory holding the root composer.json.
	 * @param string               $vendorDir Absolute path to the project's vendor directory.
	 *
	 * @throws RuntimeException When the configuration is present but invalid.
	 */
	public static function fromExtra( array $extra, string $rootDir, string $vendorDir ): self {
		$filesystem = new Filesystem();
		$rootDir    = $filesystem->normalizePath( $rootDir );
		$settings   = $extra['wpify-scoper'] ?? null;

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$folder = self::resolve( $filesystem, $rootDir, self::stringOrNull( $settings['folder'] ?? null ) ?? 'deps' );

		$composerJson = self::stringOrNull( $settings['composerjson'] ?? null ) ?? 'composer-deps.json';
		$composerLock = self::stringOrNull( $settings['composerlock'] ?? null )
			?? preg_replace( '/\.json$/', '.lock', $composerJson );

		$temp = self::stringOrNull( $settings['temp'] ?? null ) ?? 'tmp-' . bin2hex( random_bytes( 5 ) );

		$globals = self::DEFAULT_GLOBALS;

		// A `globals` that is absent, empty or not a list falls back to the defaults, exactly as
		// the `! empty()` this replaces did: an empty list has never meant "scope everything".
		if ( isset( $settings['globals'] ) && is_array( $settings['globals'] ) && array() !== $settings['globals'] ) {
			$globals = array_values( array_map( self::stringOf( ... ), $settings['globals'] ) );
		}

		return new self(
			rootDir: $rootDir,
			vendorDir: $filesystem->normalizePath( '' === $vendorDir ? $rootDir . '/vendor' : $vendorDir ),
			prefix: self::validatePrefix( $settings['prefix'] ?? null, $rootDir ),
			folder: $folder,
			globals: $globals,
			composerJson: self::resolve( $filesystem, $rootDir, $composerJson ),
			composerLock: self::resolve( $filesystem, $rootDir, (string) $composerLock ),
			tempDir: self::resolve( $filesystem, $rootDir, $temp ),
			// Preserved verbatim from the previous implementation: only a literal `false` opts out.
			autorun: ! ( array_key_exists( 'autorun', $settings ) && false === $settings['autorun'] ),
		);
	}

	public function sourceDir(): string {
		return $this->tempDir . '/source';
	}

	public function destinationDir(): string {
		return $this->tempDir . '/destination';
	}

	/**
	 * Absolute path of the directory holding the root composer.json.
	 *
	 * `getConfigSource()->getName()` is the path Composer itself resolved, so this stays correct
	 * under `--working-dir`, under `COMPOSER=/somewhere/else.json` and for `composer global`.
	 */
	private static function resolveRootDir( Composer $composer ): string {
		$configFile = $composer->getConfig()->getConfigSource()->getName();
		$directory  = realpath( dirname( $configFile ) );

		return false !== $directory ? $directory : Platform::getCwd();
	}

	/**
	 * Validates the configured prefix.
	 *
	 * Only ever called when extra.wpify-scoper is present, so a missing or malformed prefix is a
	 * configuration error rather than an opt-out.
	 */
	private static function validatePrefix( mixed $prefix, string $rootDir ): string {
		$composerJson = $rootDir . '/composer.json';

		if ( ! is_string( $prefix ) || '' === $prefix ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: extra.wpify-scoper.prefix is missing in %s. Set it to a valid PHP namespace, for example "MyPlugin\\\\Deps".',
				$composerJson
			) );
		}

		if ( 1 !== preg_match( self::PREFIX_PATTERN, $prefix ) ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: extra.wpify-scoper.prefix "%s" in %s is not a valid PHP namespace. Expected identifiers separated by backslashes, for example "MyPlugin\\\\Deps".',
				$prefix,
				$composerJson
			) );
		}

		return $prefix;
	}

	private static function resolve( Filesystem $filesystem, string $rootDir, string $path ): string {
		return $filesystem->normalizePath(
			$filesystem->isAbsolutePath( $path ) ? $path : $rootDir . '/' . $path
		);
	}

	private static function stringOrNull( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Coerces one `globals` entry to a string.
	 *
	 * Anything that is not a scalar cannot name a symbol list, so it becomes the empty string and
	 * gets the "unknown globals entry" warning from {@see ScoperConfigFactory} like any other typo.
	 */
	private static function stringOf( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
