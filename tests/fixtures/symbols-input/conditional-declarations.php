<?php
/**
 * Declarations buried inside conditionals, function bodies and else branches.
 *
 * This is the shape WordPress actually uses, and every one of these was a real extractor bug at
 * some point: a class behind `class_exists()`, a class in an `else`, and - the one that broke
 * production - a `define()` inside a function body.
 */

if ( ! class_exists( 'Fixture_Guarded' ) ) {
	class Fixture_Guarded {
	}
}

if ( defined( 'FIXTURE_NEVER' ) ) {
	class Fixture_From_If {
	}
} else {
	class Fixture_From_Else {
	}
}

if ( ! function_exists( 'fixture_guarded_function' ) ) {
	function fixture_guarded_function(): string {
		return 'guarded';
	}
}

/**
 * The regression that commit a59d577 fixed: wp_initial_constants() and friends define most of
 * WordPress's constants from inside a function body, several levels below the top level.
 */
function fixture_initial_constants(): void {
	define( 'FIXTURE_DEFINED_IN_FUNCTION', true );

	if ( ! defined( 'FIXTURE_DEEPLY_DEFINED' ) ) {
		foreach ( array( 1 ) as $ignored ) {
			define( 'FIXTURE_DEEPLY_DEFINED', $ignored );
		}
	}
}

/**
 * A nested declaration: the inner function only exists once the outer one has run, and nothing
 * else in the tree would report it.
 */
function fixture_export(): void {
	function fixture_cdata( string $text ): string {
		return '<![CDATA[' . $text . ']]>';
	}
}

class_alias( 'Fixture_Guarded', 'Fixture_Guarded_Alias' );

// A dynamic alias target cannot be resolved and must be skipped.
class_alias( 'Fixture_Guarded', $undefined_alias_name );
