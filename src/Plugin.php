<?php

namespace Wpify\Scoper;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, Capable, CommandProvider {
	protected $composer;
	protected $io;

	public function activate(Composer $composer, IOInterface $io)
	{
		$this->composer = $composer;
		$this->io = $io;
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
		return array( new ScopeCommand( 'scope' ) );
	}
}
