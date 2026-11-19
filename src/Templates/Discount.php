<?php
/**
 * A reduction taken off the lines before tax.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class Discount
 *
 * A fixed amount or a percentage of the subtotal, with the label it is
 * printed under — usually the coupon code.
 *
 * Taken off before tax: a discount given at the time of supply reduces
 * the taxable amount (Article 79 of the VAT Directive), so the VAT is
 * worked out on what the customer actually pays.
 *
 * @since 1.2.0
 */
final readonly class Discount {

	/**
	 * Describe a discount. {@see Discount::fixed()} and
	 * {@see Discount::percent()} read better at the call site.
	 *
	 * @since 1.2.0
	 *
	 * @param string                $label   Printed against it, e.g. `Discount (SPRING)`.
	 * @param int                   $amount  A fixed reduction in minor units.
	 * @param int|float|string|null $percent A percentage of the subtotal instead.
	 */
	public function __construct(
		public string $label,
		public int $amount = 0,
		public int|float|string|null $percent = null,
	) {}

	/**
	 * A fixed amount off.
	 *
	 * @since 1.2.0
	 *
	 * @param string $label  Printed against it.
	 * @param int    $amount Minor units.
	 *
	 * @return self
	 */
	public static function fixed( string $label, int $amount ): self {
		return new self( $label, $amount );
	}

	/**
	 * A percentage of the subtotal off.
	 *
	 * @since 1.2.0
	 *
	 * @param string           $label   Printed against it.
	 * @param int|float|string $percent e.g. `10` for 10%.
	 *
	 * @return self
	 */
	public static function percent( string $label, int|float|string $percent ): self {
		return new self( $label, 0, $percent );
	}
}
