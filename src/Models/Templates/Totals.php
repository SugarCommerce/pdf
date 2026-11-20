<?php
/**
 * Working an invoice's figures out from whole minor units.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Models\Templates;

use SugarCommerce\Money\{Currencies, Money};
use SugarCommerce\Pdf\Templates\Language;
use SugarCommerce\Pdf\Templates\Translator;

/**
 * Class Totals
 *
 * Lines, a rate, discounts and a currency, and every figure that follows
 * from them — as integers until the moment they are printed.
 *
 * The templates take amounts as strings, deliberately, and that is still
 * the way to print figures some other system worked out. But a caller
 * adding up floats to get those strings is where an invoice's total
 * stops matching the sum of its lines. This does the arithmetic once, in
 * pence and cents, and hands back the items, the subtotal rows, the VAT
 * breakdown and the total, all formatted from the same integers:
 *
 *   $totals = new Totals(
 *       lines:     [ new TotalsLine( 'Drum Kit Vol. 2', 14900 ), new TotalsLine( 'Vintage Keys', 8900, 2 ) ],
 *       currency:  'EUR',
 *       rate:      23,
 *       discounts: [ Discount::percent( 'Discount (SPRING)', 10 ) ],
 *   );
 *
 *   new Invoice( …, items: $totals->items(), totals: $totals->rows(),
 *       total: $totals->total(), vat_lines: $totals->vat_lines() );
 *
 * The rules, each pinned by a test:
 *
 * - **Tax is taken per rate on the total, not per line.** The two differ
 *   by a cent on some invoices, and the total is what the VAT Directive
 *   describes.
 * - **Half a unit rounds away from zero**, through
 *   {@see Money::percentage()}, which does it in integers.
 * - **Discounts come off before tax.** A percentage is of the subtotal.
 *   With mixed rates the discount is shared across them in proportion,
 *   largest remainder first, so the shares add up to the discount exactly.
 * - **The exponent is the currency's**: two places for the euro, none for
 *   the yen, three for the Kuwaiti dinar.
 *
 * @since 1.2.0
 */
final readonly class Totals {

	/**
	 * The currency, upper case.
	 */
	public string $currency;

	/**
	 * Describe the figures.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, TotalsLine> $lines     The lines, in order.
	 * @param string                 $currency  ISO 4217 code. Every amount is
	 *                                          in it: an invoice is in one
	 *                                          currency.
	 * @param int|float|string       $rate      The VAT rate as a percentage,
	 *                                          for lines that do not name
	 *                                          their own. Zero where none is
	 *                                          charged.
	 * @param array<int, Discount>   $discounts Reductions before tax.
	 * @param string                 $locale    Format for a locale, e.g. `de`
	 *                                          for `1.234,56 €`, through
	 *                                          ext-intl. Empty for the fixed
	 *                                          `€1,234.56`, which renders the
	 *                                          same bytes on every server.
	 *
	 * @throws \InvalidArgumentException For a currency this does not know, a
	 *                                   rate or percentage outside 0–100, or
	 *                                   a line or discount of the wrong type.
	 */
	public function __construct(
		public array $lines,
		string $currency,
		public int|float|string $rate = 0,
		public array $discounts = array(),
		public string $locale = '',
	) {
		$code = strtoupper( trim( $currency ) );

		if ( ! Currencies::supports( $code ) ) {
			throw new \InvalidArgumentException( 'Not a currency this knows: "' . $currency . '".' );
		}

		$this->currency = $code;

		self::percentage( $rate );

		foreach ( $lines as $line ) {
			if ( ! $line instanceof TotalsLine ) {
				throw new \InvalidArgumentException( 'Every line must be a TotalsLine.' );
			}

			if ( null !== $line->rate ) {
				self::percentage( $line->rate );
			}
		}

		foreach ( $discounts as $discount ) {
			if ( ! $discount instanceof Discount ) {
				throw new \InvalidArgumentException( 'Every discount must be a Discount.' );
			}

			if ( null !== $discount->percent ) {
				self::percentage( $discount->percent );
			}
		}
	}

	/* ─── Integers ──────────────────────────────────────────────────── */

	/**
	 * The lines added up, before discounts.
	 *
	 * @since 1.2.0
	 *
	 * @return int Minor units.
	 */
	public function subtotal(): int {
		$total = 0;

		foreach ( $this->lines as $line ) {
			$total = self::add( $total, $line->amount() );
		}

		return $total;
	}

	/**
	 * Each discount's amount, in order, as a reduction.
	 *
	 * Together never more than the subtotal: a discount that would take
	 * an invoice below nothing stops at nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return list<int> Minor units.
	 */
	public function discount_amounts(): array {
		$subtotal = $this->subtotal();
		$left     = max( 0, $subtotal );
		$amounts  = array();

		foreach ( $this->discounts as $discount ) {
			$amount    = null !== $discount->percent
				? Money::percentage( $subtotal, (float) self::percentage( $discount->percent ) )
				: $discount->amount;
			$amount    = max( 0, min( $amount, $left ) );
			$left     -= $amount;
			$amounts[] = $amount;
		}

		return $amounts;
	}

	/**
	 * The discounts added up.
	 *
	 * @since 1.2.0
	 *
	 * @return int Minor units.
	 */
	public function discount(): int {
		return array_sum( $this->discount_amounts() );
	}

	/**
	 * The taxable amount: the subtotal less discounts.
	 *
	 * @since 1.2.0
	 *
	 * @return int Minor units.
	 */
	public function net(): int {
		return $this->subtotal() - $this->discount();
	}

	/**
	 * The VAT, per rate, added up.
	 *
	 * @since 1.2.0
	 *
	 * @return int Minor units.
	 */
	public function tax(): int {
		return array_sum( array_column( $this->by_rate(), 'tax' ) );
	}

	/**
	 * Net plus tax: what the customer pays.
	 *
	 * @since 1.2.0
	 *
	 * @return int Minor units.
	 */
	public function gross(): int {
		return self::add( $this->net(), $this->tax() );
	}

	/**
	 * The taxable amount and the tax at each rate, in the order the rates
	 * first appear.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{net: int, tax: int}> Keyed by the rate as
	 *                                                  written, e.g. `19.5`.
	 */
	public function by_rate(): array {
		$groups = array();

		foreach ( $this->lines as $line ) {
			$rate              = self::percentage( $line->rate ?? $this->rate );
			$groups[ $rate ] ??= 0;
			$groups[ $rate ]   = self::add( $groups[ $rate ], $line->amount() );
		}

		$shares = self::share( $this->discount(), $groups );
		$result = array();

		foreach ( $groups as $rate => $net ) {
			$net             -= $shares[ $rate ];
			$result[ $rate ]  = array(
				'net' => $net,
				'tax' => Money::percentage( $net, (float) $rate ),
			);
		}

		return $result;
	}

	/* ─── For the templates ─────────────────────────────────────────── */

	/**
	 * The line items, formatted.
	 *
	 * @since 1.2.0
	 *
	 * @return list<LineItem>
	 */
	public function items(): array {
		$items = array();

		foreach ( $this->lines as $line ) {
			$items[] = new LineItem(
				$line->description,
				$this->format( $line->amount() ),
				(string) $line->quantity,
				$this->format( $line->unit_price ),
				$line->note
			);
		}

		return $items;
	}

	/**
	 * The rows above the total: the subtotal, each discount, and the VAT at
	 * each rate that charges any.
	 *
	 * A VAT row with nothing in it is a row too many, so a zero rate adds
	 * none — its wording and its line in the breakdown say what it is.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source for `Subtotal` and `VAT at`.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public function rows( Translator $translator = Language::English ): array {
		$rows = array( array( $translator->translate( 'subtotal', 'Subtotal' ), $this->format( $this->subtotal() ) ) );

		foreach ( $this->discount_amounts() as $index => $amount ) {
			$rows[] = array( $this->discounts[ $index ]->label, $this->format( -$amount ) );
		}

		foreach ( $this->by_rate() as $rate => $figures ) {
			if ( 0.0 !== (float) $rate ) {
				$rows[] = array( self::vat_at( (string) $rate, $translator ), $this->format( $figures['tax'] ) );
			}
		}

		return $rows;
	}

	/**
	 * The headline total.
	 *
	 * @since 1.2.0
	 *
	 * @param string $label e.g. `$kind->total_label()`.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public function total( string $label = 'Total due' ): array {
		return array( array( $label, $this->format( $this->gross() ) ) );
	}

	/**
	 * The per-rate VAT breakdown.
	 *
	 * Empty for one rate of zero, where there is nothing to break down;
	 * every rate, zero included, once there are several.
	 *
	 * @since 1.2.0
	 *
	 * @return list<VatLine>
	 */
	public function vat_lines(): array {
		$groups = $this->by_rate();

		if ( count( $groups ) === 1 && 0.0 === (float) array_key_first( $groups ) ) {
			return array();
		}

		$lines = array();

		foreach ( $groups as $rate => $figures ) {
			$lines[] = new VatLine( $rate . '%', $this->format( $figures['net'] ), $this->format( $figures['tax'] ) );
		}

		return $lines;
	}

	/**
	 * An amount in this currency, as the templates print it.
	 *
	 * @since 1.2.0
	 *
	 * @param int $amount Minor units.
	 *
	 * @return string e.g. `€1,234.50`, `¥1,000`.
	 */
	public function format( int $amount ): string {
		return Money::format(
			$amount,
			array(
				'currency' => $this->currency,
				'locale'   => $this->locale,
			)
		);
	}

	/**
	 * A rate as it is written: `20`, `19.5`, never `20.000`.
	 *
	 * @since 1.2.0
	 *
	 * @param int|float|string $rate A percentage.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When it is not a number from 0 to 100.
	 */
	public static function percentage( int|float|string $rate ): string {
		if ( ! is_numeric( $rate ) || ! is_finite( (float) $rate ) || (float) $rate < 0 || (float) $rate > 100 ) {
			throw new \InvalidArgumentException( 'A rate is a percentage from 0 to 100, not ' . var_export( $rate, true ) . '.' );
		}

		$written = rtrim( rtrim( number_format( (float) $rate, 3, '.', '' ), '0' ), '.' );

		return '' === $written ? '0' : $written;
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * The row label for a rate.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $rate       Rate as written.
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	private static function vat_at( string $rate, Translator $translator ): string {
		return str_replace( '{rate}', $rate . '%', $translator->translate( 'vat_at', 'VAT at {rate}' ) );
	}

	/**
	 * Share a discount across rates in proportion to their amounts.
	 *
	 * Largest remainder: each rate takes the whole part of its share, and
	 * what is left goes a unit at a time to the largest fractions, so the
	 * shares add up to the discount exactly.
	 *
	 * @since 1.2.0
	 *
	 * @param int                $discount The whole discount.
	 * @param array<string, int> $groups   Rate => amount before discount.
	 *
	 * @return array<string, int>
	 */
	private static function share( int $discount, array $groups ): array {
		$total  = array_sum( $groups );
		$shares = array_fill_keys( array_keys( $groups ), 0 );

		if ( 0 === $discount || 0 === $total ) {
			return $shares;
		}

		$fractions = array();

		foreach ( $groups as $rate => $amount ) {
			$product = $discount * $amount;

			// Beyond an integer the share is worked out in floating point;
			// the remainders below still make the shares add up.
			if ( ! is_int( $product ) ) {
				$shares[ $rate ]    = (int) floor( (float) $product / $total );
				$fractions[ $rate ] = 0;
				continue;
			}

			// Floor division, so every remainder is positive and what is
			// left to hand out never is: intdiv() rounds a negative share
			// towards zero, which over-allocates.
			$share = intdiv( $product, $total );

			if ( 0 !== $product % $total && ( $product < 0 ) !== ( $total < 0 ) ) {
				--$share;
			}

			$shares[ $rate ]    = $share;
			$fractions[ $rate ] = abs( $product - ( $share * $total ) );
		}

		$left = $discount - array_sum( $shares );

		// Stable: equal fractions keep the order the rates appeared in.
		$order = array_keys( $fractions );
		usort(
			$order,
			static fn( int|string $a, int|string $b ): int => $fractions[ $b ] <=> $fractions[ $a ] ?: array_search( $a, array_keys( $groups ), true ) <=> array_search( $b, array_keys( $groups ), true )
		);

		for ( $i = 0; $left > 0 && array() !== $order; $i = ( $i + 1 ) % count( $order ) ) {
			++$shares[ $order[ $i ] ];
			--$left;
		}

		return $shares;
	}

	/**
	 * Add two amounts, refusing to round.
	 *
	 * @since 1.2.0
	 *
	 * @param int $a Minor units.
	 * @param int $b Minor units.
	 *
	 * @return int
	 *
	 * @throws \OverflowException When the sum is beyond an integer.
	 */
	private static function add( int $a, int $b ): int {
		$sum = $a + $b;

		if ( ! is_int( $sum ) ) {
			throw new \OverflowException( 'A total is too large to hold exactly.' );
		}

		return $sum;
	}
}
