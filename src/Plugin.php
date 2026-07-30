<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface {

	/**
	 * Pseudo-event names accepted by {@see self::execute()}.
	 *
	 * Kept as plain strings rather than a backed enum: they are passed to
	 * `Composer\Script\Event::__construct()`, whose `$name` is typed `string`, and bin/wpify-scoper
	 * has always exposed them that way. An enum would be a silent BC break for anything outside
	 * this repository that constructs such an event.
	 *
	 * @deprecated Use `composer wpify-scoper install|update [--no-dev]` instead.
	 */
	public const SCOPER_INSTALL_CMD        = 'scoper-install-cmd';
	public const SCOPER_INSTALL_NO_DEV_CMD = 'scoper-install-no-dev-cmd';
	public const SCOPER_UPDATE_CMD         = 'scoper-update-cmd';
	public const SCOPER_UPDATE_NO_DEV_CMD  = 'scoper-update-no-dev-cmd';

	protected ?Composer $composer = null;

	protected ?IOInterface $io = null;

	private ?Configuration $configuration = null;

	/**
	 * The message of the configuration error, when there is one.
	 *
	 * Composer does not guard `activate()`, so an exception thrown from there aborts every command
	 * in the project - including `composer require`, `composer remove` and everything else the user
	 * would reach for to fix the configuration. The error is therefore carried until the pipeline is
	 * actually asked to run, which is where it is actionable.
	 */
	private ?string $configurationError = null;

	/**
	 * @return array<string, string>
	 */
	public static function getSubscribedEvents(): array {
		return array(
			ScriptEvents::POST_INSTALL_CMD => 'execute',
			ScriptEvents::POST_UPDATE_CMD  => 'execute',
		);
	}

	/**
	 * @return array<class-string, class-string>
	 */
	public function getCapabilities(): array {
		return array(
			ComposerCommandProvider::class => CommandProvider::class,
		);
	}

	public function activate( Composer $composer, IOInterface $io ): void {
		$this->composer = $composer;
		$this->io       = $io;

		try {
			$this->configuration = Configuration::fromComposer( $composer );
		} catch ( RuntimeException $exception ) {
			$this->configurationError = $exception->getMessage();

			return;
		}

		if ( null === $this->configuration || ! $io->isVerbose() ) {
			return;
		}

		$io->write( sprintf(
			'<info>wpify-scoper:</info> prefix "%s", folder "%s", %s / %s, temp "%s"',
			$this->configuration->prefix,
			$this->configuration->folder,
			$this->configuration->composerJson,
			$this->configuration->composerLock,
			$this->configuration->tempDir
		) );
	}

	public function deactivate( Composer $composer, IOInterface $io ): void {
	}

	public function uninstall( Composer $composer, IOInterface $io ): void {
	}

	/**
	 * Joins path segments.
	 *
	 * @deprecated Retained only because it has always been public.
	 */
	public function path( string ...$parts ): string {
		$path = implode( DIRECTORY_SEPARATOR, $parts );

		return str_replace( DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path );
	}

	public function execute( Event $event ): void {
		if ( null !== $this->configurationError ) {
			throw new RuntimeException( $this->configurationError );
		}

		if ( null === $this->configuration || null === $this->io ) {
			return;
		}

		$name = $event->getName();

		$isScriptEvent = ScriptEvents::POST_INSTALL_CMD === $name || ScriptEvents::POST_UPDATE_CMD === $name;

		if ( $isScriptEvent && ! $this->configuration->autorun ) {
			return;
		}

		// The dev mode of the outer run decides whether the scoped set gets its dev dependencies;
		// the pseudo-events carry it in their name, because the Event they are wrapped in is
		// fabricated by bin/wpify-scoper and always reports dev mode as off.
		list( $command, $useDevDependencies ) = match ( $name ) {
			self::SCOPER_INSTALL_CMD        => array( 'install', true ),
			self::SCOPER_INSTALL_NO_DEV_CMD => array( 'install', false ),
			self::SCOPER_UPDATE_CMD         => array( 'update', true ),
			self::SCOPER_UPDATE_NO_DEV_CMD  => array( 'update', false ),
			ScriptEvents::POST_UPDATE_CMD   => array( 'update', $event->isDevMode() ),
			default                         => array( 'install', $event->isDevMode() ),
		};

		( new Scoper(
			$this->configuration,
			$this->io,
			new ProcessComposerRunner( $this->io ),
			null,
			UpdateNotifier::create( $event->getComposer(), $this->io, $this->configuration )
		) )->run( $command, $useDevDependencies );
	}
}
