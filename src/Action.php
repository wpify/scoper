<?php declare( strict_types=1 );

namespace Wpify\Scoper;

/**
 * What a run does.
 *
 * A backed enum rather than the plain strings {@see Plugin}'s pseudo-events use: those are passed
 * to `Composer\Script\Event::__construct()`, whose `$name` is typed `string`, and changing them
 * would be a BC break. Nothing outside this repository ever sees these values except as the
 * `action` argument of `composer wpify-scoper`, where they are already strings.
 */
enum Action: string {

	case Install = 'install';

	case Update = 'update';

	case Require = 'require';

	case Remove = 'remove';

	/**
	 * True when the action edits the scoped manifest.
	 *
	 * `install` and `update` only ever read it; `require` and `remove` publish a
	 * {@see ManifestDelta} back into it once the run has succeeded.
	 */
	public function mutatesManifest(): bool {
		return match ( $this ) {
			self::Require, self::Remove => true,
			self::Install, self::Update => false,
		};
	}

	/**
	 * True when the action takes package names.
	 *
	 * The same set as {@see self::mutatesManifest()} today, but for a different reason, so the two
	 * are kept apart: this one is about the command line, that one is about what gets written.
	 */
	public function takesPackages(): bool {
		return match ( $this ) {
			self::Require, self::Remove => true,
			self::Install, self::Update => false,
		};
	}

	/**
	 * Every action name, for error messages.
	 *
	 * @return non-empty-list<string>
	 */
	public static function names(): array {
		return array( self::Install->value, self::Update->value, self::Require->value, self::Remove->value );
	}
}
