<?php
/**
 * A namespaced file.
 *
 * Only the namespace itself is collected - everything declared inside it is already covered by
 * exclude-namespaces, so collecting the class names again would be pure noise.
 */

namespace Fixture\Vendor\Feature;

class NamespacedWidget {
}

interface NamespacedRenderable {
}

trait NamespacedRenders {
}

enum NamespacedStatus {
	case Active;
}

function namespaced_function(): void {
}

const NAMESPACED_CONST = 1;

// define() and class_alias() create GLOBAL symbols whatever namespace the call sits in, so these
// two are collected even here.
define( 'FIXTURE_DEFINED_IN_NAMESPACE', true );

class_alias( NamespacedWidget::class, 'Fixture_Namespaced_Alias' );
