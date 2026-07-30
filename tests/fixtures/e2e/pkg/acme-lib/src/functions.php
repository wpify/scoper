<?php

namespace Acme\Lib;

/**
 * A vendor function: it MUST be prefixed. It calls get_option(), a WordPress
 * function that MUST stay global.
 */
function acme_site_name(): string {
	return (string) \get_option( 'blogname' );
}
