<?php declare( strict_types=1 );

namespace Wpify\Scoper;

/**
 * Runs the external processes the pipeline depends on.
 *
 * Exists as an interface purely so that the orchestration in {@see Scoper} can be exercised
 * without spawning Composer.
 */
interface ComposerRunner {

	/**
	 * Runs the Composer binary that is driving the current run.
	 *
	 * @param list<string> $arguments  Arguments after the binary, starting with the sub-command.
	 * @param string       $workingDir Directory to run the process in.
	 *
	 * @return int The process exit code.
	 */
	public function composer( array $arguments, string $workingDir ): int;

	/**
	 * Runs a script or phar with the same PHP binary that is running Composer.
	 *
	 * @param list<string> $arguments  Arguments after the PHP binary, starting with the script.
	 * @param string       $workingDir Directory to run the process in.
	 *
	 * @return int The process exit code.
	 */
	public function php( array $arguments, string $workingDir ): int;

	/**
	 * Standard error of the last process.
	 *
	 * Empty when the process ran attached to a TTY, in which case the user has already seen it.
	 */
	public function getErrorOutput(): string;
}
