<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Validated, immutable view of one `composer wpify-scoper` invocation.
 *
 * Everything the pipeline needs to know about *what* to do is resolved here, once, at the command
 * line boundary - the same division of labour {@see Configuration} applies to *where* things live.
 * {@see Scoper} therefore no longer re-checks the action it was handed.
 */
final class ScoperRequest {

	/**
	 * Which options each action accepts.
	 *
	 * An allowlist rather than a pass-through: the nested Composer run *replaces* the pipeline's
	 * install step, so `--no-update` and `--no-install` would leave the workspace with no
	 * composer.lock and no vendor/, and the run would fail much later with an error about neither.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED_OPTIONS = array(
		'install' => array( 'no-dev' ),
		'update'  => array( 'no-dev' ),
		'require' => array( 'dev', 'with-all-dependencies', 'fixed', 'dry-run' ),
		'remove'  => array( 'dev', 'with-all-dependencies', 'dry-run' ),
	);

	/**
	 * @param list<string> $packages Package specs as typed, e.g. `guzzlehttp/guzzle:^7.0`.
	 */
	private function __construct(
		public readonly Action $action,
		public readonly array $packages,
		public readonly bool $useDevDependencies,
		public readonly bool $dev,
		public readonly bool $withAllDependencies,
		public readonly bool $fixed,
		public readonly bool $dryRun,
	) {
	}

	/**
	 * @throws RuntimeException When the action, the packages and the options do not go together.
	 */
	public static function fromInput( InputInterface $input ): self {
		$action   = self::parseAction( $input->getArgument( 'action' ) );
		$packages = self::parsePackages( $input->getArgument( 'packages' ) );

		if ( $action->takesPackages() && array() === $packages ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: %1$s needs at least one package, for example "composer wpify-scoper %1$s guzzlehttp/guzzle".',
				$action->value
			) );
		}

		if ( ! $action->takesPackages() && array() !== $packages ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: %s does not take package names, it acts on everything in the scoped manifest. Did you mean "composer wpify-scoper require %s"?',
				$action->value,
				implode( ' ', $packages )
			) );
		}

		self::rejectForeignOptions( $input, $action );

		return new self(
			action: $action,
			packages: $packages,
			// Only install and update read it, and only they declare --no-dev.
			useDevDependencies: ! ( $action->takesPackages() || self::flag( $input, 'no-dev' ) ),
			dev: self::flag( $input, 'dev' ),
			withAllDependencies: self::flag( $input, 'with-all-dependencies' ),
			fixed: self::flag( $input, 'fixed' ),
			dryRun: self::flag( $input, 'dry-run' ),
		);
	}

	/**
	 * The request behind a `composer install` / `composer update` that scoped as a side effect,
	 * and behind the pseudo-events of the deprecated binary.
	 *
	 * @throws RuntimeException When handed an action that needs package names.
	 */
	public static function forAction( Action $action, bool $useDevDependencies ): self {
		if ( $action->takesPackages() ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: the %s action needs package names, use ScoperRequest::fromInput().',
				$action->value
			) );
		}

		return new self(
			action: $action,
			packages: array(),
			useDevDependencies: $useDevDependencies,
			dev: false,
			withAllDependencies: false,
			fixed: false,
			dryRun: false,
		);
	}

	/**
	 * The block of the scoped manifest this request writes to.
	 */
	public function targetBlock(): string {
		return $this->dev ? 'require-dev' : 'require';
	}

	/**
	 * The arguments for the nested Composer run, which is the step that resolves the scoped
	 * manifest inside the workspace.
	 *
	 * @return non-empty-list<string>
	 */
	public function composerArguments( string $source ): array {
		$arguments = array( $this->action->value );

		foreach ( $this->packages as $package ) {
			$arguments[] = $package;
		}

		$arguments[] = '--working-dir=' . $source;
		$arguments[] = '--optimize-autoloader';

		if ( ! $this->action->takesPackages() ) {
			if ( ! $this->useDevDependencies ) {
				$arguments[] = '--no-dev';
			}

			return $arguments;
		}

		if ( $this->dev ) {
			$arguments[] = '--dev';
		}

		// Composer's own long name for -W. The short form is not forwarded: it means
		// --update-with-all-dependencies there, which is the same thing under an older name.
		if ( $this->withAllDependencies ) {
			$arguments[] = '--with-all-dependencies';
		}

		if ( $this->fixed ) {
			$arguments[] = '--fixed';
		}

		if ( $this->dryRun ) {
			$arguments[] = '--dry-run';
		}

		return $arguments;
	}

	/**
	 * The package names, without the `:constraint` half of the spec.
	 *
	 * @return list<string>
	 */
	public function packageNames(): array {
		return array_values( array_map(
			static fn ( string $package ): string => strtolower( explode( ':', $package, 2 )[0] ),
			$this->packages
		) );
	}

	private static function parseAction( mixed $argument ): Action {
		$value  = is_string( $argument ) ? $argument : '';
		$action = Action::tryFrom( $value );

		if ( null === $action ) {
			throw new RuntimeException( sprintf(
				'wpify-scoper: unknown action "%s", expected one of %s.',
				$value,
				implode( ', ', Action::names() )
			) );
		}

		return $action;
	}

	/**
	 * @return list<string>
	 */
	private static function parsePackages( mixed $argument ): array {
		if ( ! is_array( $argument ) ) {
			return array();
		}

		$packages = array();

		foreach ( $argument as $package ) {
			if ( is_string( $package ) && '' !== $package ) {
				$packages[] = $package;
			}
		}

		return $packages;
	}

	/**
	 * Rejects an option that belongs to a different action.
	 *
	 * Symfony parses every declared option for every action, so `install --dev` would otherwise be
	 * accepted and silently ignored - and `install guzzlehttp/guzzle` used to be a "too many
	 * arguments" error before the variadic packages argument existed.
	 *
	 * @throws RuntimeException
	 */
	private static function rejectForeignOptions( InputInterface $input, Action $action ): void {
		$allowed = self::ALLOWED_OPTIONS[ $action->value ];

		foreach ( self::ALLOWED_OPTIONS as $options ) {
			foreach ( $options as $option ) {
				if ( in_array( $option, $allowed, true ) || ! self::flag( $input, $option ) ) {
					continue;
				}

				throw new RuntimeException( sprintf(
					'wpify-scoper: --%s is not valid for the %s action, only for %s.',
					$option,
					$action->value,
					implode( ' and ', self::actionsAllowing( $option ) )
				) );
			}
		}
	}

	/**
	 * @return non-empty-list<string>
	 */
	private static function actionsAllowing( string $option ): array {
		$actions = array();

		foreach ( self::ALLOWED_OPTIONS as $name => $options ) {
			if ( in_array( $option, $options, true ) ) {
				$actions[] = $name;
			}
		}

		// Every option in the table belongs to at least one action, so this cannot be empty; the
		// fallback is only there to make that true for PHPStan as well as for a reader.
		return array() === $actions ? array( '(none)' ) : $actions;
	}

	private static function flag( InputInterface $input, string $option ): bool {
		return true === $input->getOption( $option );
	}
}
