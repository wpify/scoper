<?php

namespace Acme\Lib;

use WPSEO\Utils;
use POBox\Mailer;

/**
 * References the risky namespaces both fully-qualified and via `use`, which is
 * exactly the shape the unanchored patcher mangles.
 */
class Consumer {
	public function viaUse(): Utils {
		return new Utils();
	}

	public function viaFqn(): \WPSEO\Utils {
		return new \WPSEO\Utils();
	}

	public function mailer(): \POBox\Mailer {
		return new \POBox\Mailer();
	}

	public function wordpress(): string {
		return (string) \get_option( 'blogname' ) . \WP_Post::class;
	}
}
