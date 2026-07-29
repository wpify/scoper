<?php
/**
 * Braced namespace blocks, including the nameless one.
 *
 * `namespace { }` is the global namespace and has no name node at all - reading `->toString()` on
 * it unconditionally is a fatal, and the declarations inside it DO belong in the symbol list.
 */

namespace Fixture\Braced {

	class BracedWidget {
	}

	function braced_function(): void {
	}
}

namespace {

	class Fixture_Global_From_Braced {
	}

	function fixture_global_from_braced(): void {
	}

	const FIXTURE_GLOBAL_FROM_BRACED = 1;

	define( 'FIXTURE_DEFINED_IN_BRACED_GLOBAL', true );
}
