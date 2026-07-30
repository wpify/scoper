<?php

namespace Acme\Lib;

/**
 * Named so that the excluded WordPress class "WP" is a strict prefix of it.
 * This class belongs to the vendor, so it MUST end up prefixed.
 */
class WPSEO_Utils {
	public function name(): string {
		return 'wpseo';
	}
}
