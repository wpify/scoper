<?php

namespace Wpify\Scoper;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use \Composer\Plugin\Capability\CommandProvider;

class Plugin implements PluginInterface, Capable, CommandProvider {
	public function activate( Composer $composer, IOInterface $io ) {
	}

	public function deactivate( Composer $composer, IOInterface $io ) {
	}

	public function uninstall( Composer $composer, IOInterface $io ) {
	}

	public function getCapabilities() {
		return array(
			CommandProvider::class => self::class,
		);
	}

	public function getCommands() {
		return array( new ScopeCommand );
	}
}
