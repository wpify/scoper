<?php

// What a project that does not depend on the plugin looks like: the exact situation in which
// InstalledVersions throws for a globally installed plugin.
return array(
	'root'     => array( 'name' => 'acme/site' ),
	'versions' => array(
		'psr/log' => array( 'pretty_version' => '3.0.2' ),
	),
);
