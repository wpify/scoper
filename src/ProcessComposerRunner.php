<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\IO\IOInterface;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Runs Composer and PHP as separate processes, the way Composer's own EventDispatcher does.
 *
 * Running the nested install in-process (`new Composer\Console\Application`) terminated the whole
 * PHP process, because Symfony's console application defaults to `autoExit`.
 */
final class ProcessComposerRunner implements ComposerRunner {

	private readonly ProcessExecutor $process;

	private ?string $php = null;

	private ?string $binary = null;

	public function __construct( private readonly IOInterface $io ) {
		$this->process = new ProcessExecutor( $io );
	}

	public function composer( array $arguments, string $workingDir ): int {
		return $this->run(
			array_merge( array( $this->phpBinary(), $this->composerBinary() ), $arguments, $this->ioArguments() ),
			$workingDir
		);
	}

	public function php( array $arguments, string $workingDir ): int {
		return $this->run( array_merge( array( $this->phpBinary() ), $arguments ), $workingDir );
	}

	public function getErrorOutput(): string {
		return $this->process->getErrorOutput();
	}

	/**
	 * @param non-empty-list<string> $command
	 */
	private function run( array $command, string $workingDir ): int {
		$this->io->write(
			sprintf( '<info>wpify-scoper:</info> %s', implode( ' ', array_map( array( ProcessExecutor::class, 'escape' ), $command ) ) ),
			true,
			IOInterface::VERBOSE
		);

		// executeTty() keeps the child attached to the terminal when there is one - progress bars
		// and authentication prompts keep working - and falls back to a piped run that streams
		// into $io and captures stderr when there is not (CI).
		return $this->process->executeTty( $command, $workingDir );
	}

	/**
	 * The PHP binary running Composer, so the nested run and php-scoper cannot end up on a
	 * different version than the one that resolved the dependencies.
	 */
	private function phpBinary(): string {
		if ( null === $this->php ) {
			$found = ( new PhpExecutableFinder() )->find( false );

			$this->php = false !== $found && '' !== $found ? $found : PHP_BINARY;
		}

		return $this->php;
	}

	/**
	 * The Composer binary running this plugin.
	 *
	 * COMPOSER_BINARY is set by Composer's own entry point; it is absent only when the pipeline is
	 * driven from bin/wpify-scoper, where PATH is the best available answer.
	 */
	private function composerBinary(): string {
		if ( null !== $this->binary ) {
			return $this->binary;
		}

		$binary = Platform::getEnv( 'COMPOSER_BINARY' );

		if ( ! is_string( $binary ) || '' === $binary ) {
			$binary = ( new ExecutableFinder() )->find( 'composer' );
		}

		if ( ! is_string( $binary ) || '' === $binary ) {
			throw new RuntimeException(
				'wpify-scoper: the Composer binary could not be located. Set COMPOSER_BINARY or make "composer" available on PATH.'
			);
		}

		return $this->binary = $binary;
	}

	/**
	 * Propagates the outer run's verbosity, colour and interactivity to the nested one.
	 *
	 * @return list<string>
	 */
	private function ioArguments(): array {
		$arguments = array();

		if ( $this->io->isDebug() ) {
			$arguments[] = '-vvv';
		} elseif ( $this->io->isVeryVerbose() ) {
			$arguments[] = '-vv';
		} elseif ( $this->io->isVerbose() ) {
			$arguments[] = '-v';
		}

		$arguments[] = $this->io->isDecorated() ? '--ansi' : '--no-ansi';

		if ( ! $this->io->isInteractive() ) {
			$arguments[] = '--no-interaction';
		}

		return $arguments;
	}
}
