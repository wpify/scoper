<?php
/**
 * Every top-level declaration shape, in the global namespace.
 *
 * Fixture for the golden-file extractor test. Never loaded, only parsed.
 */

class Fixture_Widget {
	public function render(): void {
	}
}

abstract class Fixture_Abstract_Widget extends Fixture_Widget {
}

final class Fixture_Final_Widget extends Fixture_Abstract_Widget {
}

interface Fixture_Renderable {
	public function render(): void;
}

trait Fixture_Renders {
	public function render(): void {
	}
}

enum Fixture_Status: string {
	case Active   = 'active';
	case Archived = 'archived';
}

function fixture_render_widget( Fixture_Widget $widget ): void {
	$widget->render();
}

// Top-level `const`, as used by the sodium_compat polyfill.
const FIXTURE_CONST_A = 1;
const FIXTURE_CONST_B = 2, FIXTURE_CONST_C = 3;

define( 'FIXTURE_DEFINED_TOP_LEVEL', true );

// An anonymous class has no name and must not be collected.
$anonymous = new class extends Fixture_Widget {
};

// A dynamic define() name cannot be resolved and must be skipped rather than guessed at.
define( $anonymous::class . '_DYNAMIC', true );
