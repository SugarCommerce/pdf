<?php
/**
 * Why an invoice cannot be sent as an e-invoice.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class FacturXException
 *
 * Thrown rather than writing XML a buyer's system would reject: the
 * rejection arrives days later, against the seller's name. Every problem
 * found is listed, one sentence each, so they can all be fixed at once.
 *
 * @since 1.5.0
 */
final class FacturXException extends \DomainException {

	/**
	 * Every problem found.
	 *
	 * @param list<string> $problems One sentence each.
	 */
	public function __construct( public readonly array $problems ) {
		parent::__construct( 'This cannot be sent as an e-invoice: ' . implode( ' ', $problems ) );
	}
}
