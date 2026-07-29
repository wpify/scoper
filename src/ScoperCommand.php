<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer wpify-scoper install|update [--no-dev]`.
 *
 * Composer instantiates every plugin command on `composer list`, so the constructor and
 * configure() must stay free of side effects.
 */
final class ScoperCommand extends BaseCommand {

	protected function configure(): void {
		$this->setName( 'wpify-scoper' )
			->setDescription( 'Scopes the dependencies declared in composer-deps.json.' )
			->addArgument( 'action', InputArgument::REQUIRED, 'Either "install" or "update".' )
			->addOption( 'no-dev', null, InputOption::VALUE_NONE, 'Skip the dev dependencies of the scoped manifest.' )
			->setHelp(
				<<<'HELP'
The <info>wpify-scoper</info> command resolves the dependencies declared in
<comment>composer-deps.json</comment> in a temporary directory, rewrites them with php-scoper under
the namespace configured in <comment>extra.wpify-scoper.prefix</comment>, and moves the result into
<comment>extra.wpify-scoper.folder</comment>.

<info>composer wpify-scoper install</info>   installs the locked scoped dependency set
<info>composer wpify-scoper update</info>    re-resolves it and rewrites composer-deps.lock
HELP
			);
	}

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$io     = $this->getIO();
		$argument = $input->getArgument( 'action' );
		$action   = is_string( $argument ) ? $argument : '';

		if ( ! in_array( $action, array( 'install', 'update' ), true ) ) {
			$io->writeError( sprintf( '<error>wpify-scoper: unknown action "%s", expected install or update.</error>', $action ) );

			return 1;
		}

		$composer      = $this->requireComposer();
		$configuration = Configuration::fromComposer( $composer );

		if ( null === $configuration ) {
			$io->writeError( '<error>wpify-scoper: no extra.wpify-scoper block in composer.json, nothing to scope.</error>' );

			return 1;
		}

		return ( new Scoper( $configuration, $io, new ProcessComposerRunner( $io ) ) )
			->run( $action, ! (bool) $input->getOption( 'no-dev' ) );
	}
}
