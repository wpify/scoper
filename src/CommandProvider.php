<?php declare( strict_types=1 );

namespace Wpify\Scoper;

use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

/**
 * Exposes `composer wpify-scoper` through the plugin's CommandProvider capability.
 *
 * Deliberately a class of its own: Composer instantiates the declared capability class with
 * `new $class($ctorArgs)` and then asserts it implements the capability interface, so pointing the
 * capability at {@see Plugin} would make every `composer list` throw.
 *
 * No constructor: Composer passes one array argument holding `composer`, `io` and `plugin`, none of
 * which this needs, and PHP discards arguments passed to a class that declares no constructor.
 */
final class CommandProvider implements ComposerCommandProvider {

	/**
	 * @return list<\Composer\Command\BaseCommand>
	 */
	public function getCommands(): array {
		return array( new ScoperCommand() );
	}
}
