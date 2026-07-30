<?php

namespace Acme\Lib;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Regression guard for segment-wise namespace matching.
 *
 * Both roots are in exclude-namespaces, so these references MUST come out
 * un-prefixed (global) even though the symbol referenced is a CHILD of the
 * listed namespace. Whole-string equality matching would wrongly prefix them,
 * which fatals HPOS order metaboxes and every SMTP send in production.
 */
class Integration {
	public function hpos(): CustomOrdersTableController {
		return new CustomOrdersTableController();
	}

	public function hposFqn(): \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController {
		return new \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController();
	}

	public function mailer(): PHPMailer {
		return new \PHPMailer\PHPMailer\PHPMailer();
	}
}
