<?php
/**
 * One line's worth of money.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class TotalsLine
 *
 * A line of a {@see Totals}: a price in minor units, how many, and — when
 * an invoice mixes rates — the rate this line is taxed at.
 *
 * @since 1.2.0
 */
final readonly class TotalsLine {

	/**
	 * Describe a line.
	 *
	 * @since 1.2.0
	 *
	 * @param string                $description What was sold.
	 * @param int                   $unit_price  Price each, in minor units: pence,
	 *                                           cents, yen.
	 * @param int                   $quantity    How many.
	 * @param int|float|string|null $rate        The VAT rate for this line as a
	 *                                           percentage, or null for the
	 *                                           rate the Totals was given.
	 * @param string                $note        A quieter line under the
	 *                                           description.
	 */
	public function __construct(
		public string $description,
		public int $unit_price,
		public int $quantity = 1,
		public int|float|string|null $rate = null,
		public string $note = '',
	) {}

	/**
	 * Price times quantity, in minor units.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 *
	 * @throws \OverflowException When the product is beyond an integer.
	 */
	public function amount(): int {
		$amount = $this->unit_price * $this->quantity;

		// PHP turns an integer product that overflows into a float, which
		// is the rounding money must never meet.
		if ( ! is_int( $amount ) ) {
			throw new \OverflowException( 'The amount of "' . $this->description . '" is too large to hold exactly.' );
		}

		return $amount;
	}
}
