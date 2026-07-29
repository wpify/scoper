<?php

namespace WPSEO;

/**
 * Root namespace "WPSEO" starts with "WP", which is an excluded WordPress class.
 * The unanchored patcher therefore strips the prefix off every fully-qualified
 * reference to it (finding H1). It MUST stay prefixed.
 */
class Utils {
	public function name(): string {
		return 'wpseo-utils';
	}
}
