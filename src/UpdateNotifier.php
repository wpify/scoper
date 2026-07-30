<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Cache;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Throwable;

/**
 * Tells the user, at the end of a scoping run, that a newer release of this plugin exists.
 *
 * A courtesy, and it behaves like one: it never slows the run down noticeably, never warns, and
 * never fails it. Every path out of {@see self::notify()} that is not a notice is silence.
 */
final class UpdateNotifier {

	/**
	 * Set to any non-empty value to switch the check off for a machine or a shell.
	 */
	public const DISABLE_ENV = 'WPIFY_SCOPER_NO_UPDATE_CHECK';

	/**
	 * Where a user crossing a major version is sent.
	 *
	 * The major is interpolated. `.github/workflows/docs.yml` fails the build when the guide for
	 * the repository's current major is missing, which is what stops this from generating a 404.
	 */
	private const UPGRADE_GUIDE = 'https://github.com/wpify/scoper/blob/master/docs/upgrading-to-%d.md';

	public function __construct(
		private readonly IOInterface $io,
		private readonly ReleaseSource $source,
		private readonly ?string $installedVersion,
		private readonly bool $globalInstall,
	) {
	}

	/**
	 * Wires one up against the running Composer.
	 *
	 * Lives here rather than in the two entry points so that {@see Plugin} and {@see ScoperCommand}
	 * do not each grow a copy of the HttpDownloader and Cache construction.
	 */
	public static function create( Composer $composer, IOInterface $io, Configuration $configuration ): self {
		$config   = $composer->getConfig();
		$cacheDir = $config->get( 'cache-dir' );

		$cache = is_string( $cacheDir ) && '' !== $cacheDir
			? new Cache( $io, $cacheDir . '/wpify-scoper' )
			: null;

		return new self(
			$io,
			new PackagistReleaseSource( new HttpDownloader( $io, $config ), $cache ),
			PluginPackage::version(),
			! self::isInstalledUnder( $configuration->vendorDir ),
		);
	}

	public function notify(): void {
		try {
			$this->emit();
		} catch ( Throwable $throwable ) {
			// Packagist being down, a corporate proxy, a read-only cache dir: none of them are the
			// user's problem right now, and none of them are worth a line of output on a run that
			// otherwise did its job.
			if ( $this->io->isVerbose() ) {
				$this->io->writeError( sprintf(
					'<comment>wpify-scoper: the update check failed (%s).</comment>',
					$throwable->getMessage()
				) );
			}
		}
	}

	private function emit(): void {
		// Ordered so that nothing reaches the network until every reason to stay quiet is ruled
		// out.
		if ( ! $this->io->isInteractive() ) {
			return;
		}

		if ( self::envIsSet( self::DISABLE_ENV ) || self::envIsSet( 'COMPOSER_DISABLE_NETWORK' ) ) {
			return;
		}

		$installed = $this->installedVersion;

		// A dev checkout, or a deliberately installed RC. Both are ahead of stable by choice, and
		// an unknown version has nothing to compare against.
		if ( null === $installed || 'stable' !== VersionParser::parseStability( $installed ) ) {
			return;
		}

		$latest = $this->source->latestStable();

		if ( null === $latest || ! Comparator::greaterThan( $latest, $installed ) ) {
			return;
		}

		$this->io->writeError( sprintf(
			'<info>wpify-scoper:</info> version %s is available, you have %s.',
			$latest,
			$installed
		) );

		if ( self::major( $latest ) > self::major( $installed ) ) {
			// `composer update` cannot cross a major on its own, so telling the user to run it
			// would hand them a command that silently does nothing.
			$this->io->writeError( sprintf(
				'<info>wpify-scoper:</info> this is a new major version, see %s',
				sprintf( self::UPGRADE_GUIDE, self::major( $latest ) )
			) );

			return;
		}

		$this->io->writeError( sprintf(
			'<info>wpify-scoper:</info> update with "composer %supdate %s".',
			$this->globalInstall ? 'global ' : '',
			PluginPackage::NAME
		) );
	}

	/**
	 * Whether this copy of the plugin lives inside the project being scoped.
	 *
	 * Decides between `composer update` and `composer global update`. Anything that is not
	 * demonstrably project-local is treated as global, which is the documented install.
	 */
	private static function isInstalledUnder( string $vendorDir ): bool {
		$installPath = PluginPackage::installPath();
		$vendorDir   = realpath( $vendorDir );

		return null !== $installPath
			&& false !== $vendorDir
			&& str_starts_with( $installPath, $vendorDir . DIRECTORY_SEPARATOR );
	}

	/**
	 * @param non-empty-string $name
	 */
	private static function envIsSet( string $name ): bool {
		$value = Platform::getEnv( $name );

		return is_string( $value ) && '' !== $value;
	}

	private static function major( string $version ): int {
		return (int) ( explode( '.', $version )[0] );
	}
}
