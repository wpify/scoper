<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer wpify-scoper install|update|require|remove`.
 *
 * Composer instantiates every plugin command on `composer list`, so the constructor and
 * configure() must stay free of side effects.
 */
final class ScoperCommand extends BaseCommand {

	protected function configure(): void {
		$this->setName( 'wpify-scoper' )
			->setDescription( 'Scopes the dependencies declared in composer-deps.json.' )
			->addArgument( 'action', InputArgument::REQUIRED, 'One of "install", "update", "require" or "remove".' )
			->addArgument( 'packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages to require or remove, e.g. "guzzlehttp/guzzle:^7.0".' )
			->addOption( 'no-dev', null, InputOption::VALUE_NONE, 'install, update: skip the dev dependencies of the scoped manifest.' )
			->addOption( 'dev', null, InputOption::VALUE_NONE, 'require, remove: act on require-dev instead of require.' )
			->addOption( 'with-all-dependencies', 'W', InputOption::VALUE_NONE, 'require, remove: also update transitive dependencies that are already locked.' )
			->addOption( 'fixed', null, InputOption::VALUE_NONE, 'require: pin the exact resolved version instead of a caret constraint.' )
			->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'require, remove: resolve and report, write nothing.' )
			->setHelp(
				<<<'HELP'
The <info>wpify-scoper</info> command resolves the dependencies declared in
<comment>composer-deps.json</comment> in a temporary workspace, rewrites them with php-scoper under
the namespace configured in <comment>extra.wpify-scoper.prefix</comment>, and moves the result into
<comment>extra.wpify-scoper.folder</comment>.

<info>composer wpify-scoper install</info>                    installs the locked scoped dependency set
<info>composer wpify-scoper update</info>                     re-resolves it and rewrites composer-deps.lock
<info>composer wpify-scoper require guzzlehttp/guzzle</info>  adds a scoped dependency and re-scopes
<info>composer wpify-scoper remove guzzlehttp/guzzle</info>   drops one and re-scopes

<info>require</info> and <info>remove</info> edit only the entries you name in
<comment>composer-deps.json</comment>; the rest of the file is left byte for byte as it was. The
manifest and the lock are written together, and only once the scoping run has succeeded.

<info>--dry-run</info> reports what Composer would resolve. It says nothing about whether php-scoper
would then succeed, because nothing is installed for it to look at.
HELP
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$io = $this->getIO();

		try {
			$request = ScoperRequest::fromInput( $input );
		} catch ( RuntimeException $exception ) {
			$io->writeError( sprintf( '<error>%s</error>', $exception->getMessage() ) );

			return 1;
		}

		$composer      = $this->requireComposer();
		$configuration = Configuration::fromComposer( $composer );

		if ( null === $configuration ) {
			$io->writeError( '<error>wpify-scoper: no extra.wpify-scoper block in composer.json, nothing to scope.</error>' );

			return 1;
		}

		$this->warnAboutUnscopedCopies( $composer, $request, $io );

		return ( new Scoper(
			$configuration,
			$io,
			new ProcessComposerRunner( $io ),
			null,
			UpdateNotifier::create( $composer, $io, $configuration )
		) )->run( $request );
	}

	/**
	 * Warns when a package being scoped is also required in the root composer.json.
	 *
	 * Both trees end up autoloaded - the scoped copy from the deps folder, the unscoped one from
	 * vendor/ - so the class this tool exists to move out of the global namespace is back in it.
	 * Declaring both is occasionally deliberate, a dev-only tool in the root and the scoped copy
	 * for runtime, so this warns rather than refuses.
	 *
	 * The root package is already in memory, so it costs no I/O.
	 */
	private function warnAboutUnscopedCopies( Composer $composer, ScoperRequest $request, IOInterface $io ): void {
		if ( Action::Require !== $request->action ) {
			return;
		}

		$package  = $composer->getPackage();
		// Both are keyed by the lowercased package name, which is what packageNames() returns.
		$declared = array_merge( $package->getRequires(), $package->getDevRequires() );

		foreach ( $request->packageNames() as $name ) {
			if ( ! isset( $declared[ $name ] ) ) {
				continue;
			}

			$io->writeError( sprintf(
				'<warning>wpify-scoper: %1$s is also required in composer.json, so an unscoped copy will be autoloaded from vendor/ alongside the scoped one. Run "composer remove %1$s" unless that is deliberate.</warning>',
				$name
			) );
		}
	}
}
