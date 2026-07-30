<?php

namespace Acme\Lib;

use WP_Post;
use WP_Query;

/**
 * Exercises the three things the scoper has to get right:
 *  - the class itself MUST be prefixed
 *  - WordPress symbols (WP_Post, WP_Query, get_option) MUST stay global
 *  - WPSEO_Utils MUST stay prefixed: it merely starts with "WP", which is an
 *    excluded class name, so the unanchored patcher wrongly strips it (finding H1).
 */
class Greeter {
	public function title( WP_Post $post ): string {
		return (string) get_option( 'blogname' ) . ' - ' . $post->post_title;
	}

	public function query(): WP_Query {
		return new WP_Query( array( 'post_type' => 'post' ) );
	}

	public function helper(): WPSEO_Utils {
		return new WPSEO_Utils();
	}

	public function alsoRisky(): POStuff {
		return new POStuff();
	}
}
