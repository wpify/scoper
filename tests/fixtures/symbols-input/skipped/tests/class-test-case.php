<?php
/**
 * Lives under tests/, so the extractor must skip the whole file.
 *
 * Test suites are never loaded in a WordPress request, so their symbols would be pure
 * over-exclusion - the extractor would tell php-scoper to leave a name global that the consumer's
 * own vendor tree is free to use.
 */

class Fixture_Must_Not_Be_Collected_Tests {
}

function fixture_must_not_be_collected_tests(): void {
}
