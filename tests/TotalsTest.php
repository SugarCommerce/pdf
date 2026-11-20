<?php
/**
 * Totals: the arithmetic, in minor units, and what it formats to.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Models\Templates\Discount;
use SugarCommerce\Pdf\Models\Templates\DocumentKind;
use SugarCommerce\Pdf\Models\Templates\LineItem;
use SugarCommerce\Pdf\Models\Templates\Totals;
use SugarCommerce\Pdf\Models\Templates\TotalsLine;
use SugarCommerce\Pdf\Models\Templates\VatLine;
use SugarCommerce\Pdf\Templates\Language;

final class TotalsTest extends TestCase {

	public function test_the_lines_add_up_in_minor_units(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 14900 ), new TotalsLine( 'B', 8900, 2 ) ), 'EUR', 23 );

		$this->assertSame( 32700, $totals->subtotal() );
		$this->assertSame( 32700, $totals->net() );
		$this->assertSame( 7521, $totals->tax() );
		$this->assertSame( 40221, $totals->gross() );
	}

	/**
	 * Three lines of €1.05 at 10%: a cent and a half of tax on each line
	 * rounds up three times (33 cents), on the total once (32). The VAT
	 * Directive describes the total.
	 */
	public function test_tax_is_taken_on_the_total_not_per_line(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 105 ), new TotalsLine( 'B', 105 ), new TotalsLine( 'C', 105 ) ), 'EUR', 10 );

		$this->assertSame( 32, $totals->tax() );
	}

	public function test_half_a_unit_rounds_away_from_zero(): void {
		$this->assertSame( 32, ( new Totals( array( new TotalsLine( 'A', 315 ) ), 'EUR', 10 ) )->tax() );
		$this->assertSame( 31, ( new Totals( array( new TotalsLine( 'A', 314 ) ), 'EUR', 10 ) )->tax() );
		$this->assertSame( -32, ( new Totals( array( new TotalsLine( 'A', -315 ) ), 'EUR', 10 ) )->tax() );
	}

	/** @return array<string, array{0: string, 1: int, 2: string}> */
	public static function exponents(): array {
		return array(
			'euro, two places'  => array( 'EUR', 123456, '€1,234.56' ),
			'yen, none'         => array( 'JPY', 123456, '¥123,456' ),
			'dinar, three'      => array( 'KWD', 123456, '123.456' ),
			'lower case code'   => array( 'gbp', 5, '£0.05' ),
		);
	}

	#[DataProvider( 'exponents' )]
	public function test_amounts_are_written_with_the_currencys_own_exponent( string $currency, int $amount, string $expected ): void {
		$this->assertStringContainsString( $expected, ( new Totals( array(), $currency ) )->format( $amount ) );
	}

	public function test_a_locale_writes_the_amount_its_way(): void {
		if ( ! class_exists( \NumberFormatter::class ) ) {
			$this->markTestSkipped( 'Needs ext-intl.' );
		}

		$this->assertSame( "1.234,56\u{a0}€", ( new Totals( array(), 'EUR', locale: 'de' ) )->format( 123456 ) );
	}

	public function test_discounts_come_off_before_tax(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 10000 ) ), 'GBP', 20, array( Discount::fixed( 'Voucher', 1000 ) ) );

		$this->assertSame( 1000, $totals->discount() );
		$this->assertSame( 9000, $totals->net() );
		$this->assertSame( 1800, $totals->tax() );
		$this->assertSame( 10800, $totals->gross() );
	}

	public function test_a_percentage_discount_is_of_the_subtotal_and_rounds_once(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 999 ) ), 'EUR', 0, array( Discount::percent( 'Ten', 10 ), Discount::percent( 'Ten more', '10' ) ) );

		$this->assertSame( array( 100, 100 ), $totals->discount_amounts() );
		$this->assertSame( 799, $totals->net() );
	}

	public function test_discounts_never_take_the_total_below_nothing(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 500 ) ), 'EUR', 20, array( Discount::fixed( 'Big', 400 ), Discount::fixed( 'Bigger', 400 ), Discount::fixed( 'Negative', -50 ) ) );

		$this->assertSame( array( 400, 100, 0 ), $totals->discount_amounts() );
		$this->assertSame( 0, $totals->gross() );
	}

	/**
	 * A discount on an invoice that mixes rates is shared across them in
	 * proportion — and the shares add up to the discount to the cent.
	 */
	public function test_a_discount_is_shared_across_rates_exactly(): void {
		$totals = new Totals(
			array(
				new TotalsLine( 'Standard', 32700 ),
				new TotalsLine( 'Zero', 1999, rate: 0 ),
			),
			'EUR',
			23,
			array( Discount::fixed( 'Voucher', 3970 ) )
		);

		$groups = $totals->by_rate();

		$this->assertSame( array( 'net' => 28959, 'tax' => 6661 ), $groups[23] );
		$this->assertSame( array( 'net' => 1770, 'tax' => 0 ), $groups[0] );
		$this->assertSame( $totals->net(), $groups[23]['net'] + $groups[0]['net'] );
	}

	public function test_shares_add_up_for_awkward_proportions(): void {
		for ( $discount = 1; $discount <= 200; $discount += 7 ) {
			$totals = new Totals(
				array( new TotalsLine( 'A', 333, rate: 20 ), new TotalsLine( 'B', 333, rate: 5 ), new TotalsLine( 'C', 334, rate: 0 ) ),
				'EUR',
				discounts: array( Discount::fixed( 'D', $discount ) )
			);

			$this->assertSame( 1000 - $discount, array_sum( array_column( $totals->by_rate(), 'net' ) ), (string) $discount );
		}
	}

	public function test_rows_have_the_subtotal_each_discount_and_each_charged_rate(): void {
		$totals = new Totals(
			array( new TotalsLine( 'Video', 9900 ), new TotalsLine( 'Book', 2500, rate: 0 ) ),
			'GBP',
			20,
			array( Discount::fixed( 'Voucher', 1000 ) )
		);

		$this->assertSame(
			array(
				array( 'Subtotal', '£124.00' ),
				array( 'Voucher', '-£10.00' ),
				array( 'VAT at 20%', '£18.20' ),
			),
			$totals->rows()
		);
		$this->assertSame( array( array( 'Total due', '£132.20' ) ), $totals->total() );
	}

	public function test_rows_are_translated(): void {
		$rows = ( new Totals( array( new TotalsLine( 'A', 1000 ) ), 'EUR', 19 ) )->rows( Language::German );

		$this->assertSame( 'Zwischensumme', $rows[0][0] );
		$this->assertSame( 'MwSt. 19%', $rows[1][0] );
	}

	public function test_the_vat_breakdown_lists_every_rate_once_there_are_several(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 10000 ), new TotalsLine( 'B', 5000, rate: '5.5' ) ), 'EUR', 20 );

		$this->assertEquals(
			array( new VatLine( '20%', '€100.00', '€20.00' ), new VatLine( '5.5%', '€50.00', '€2.75' ) ),
			$totals->vat_lines()
		);
	}

	public function test_one_rate_of_zero_has_nothing_to_break_down(): void {
		$totals = new Totals( array( new TotalsLine( 'A', 10000 ) ), 'EUR' );

		$this->assertSame( array(), $totals->vat_lines() );
		$this->assertSame( array( array( 'Subtotal', '€100.00' ) ), $totals->rows() );
	}

	public function test_items_are_formatted_from_the_same_integers(): void {
		$items = ( new Totals( array( new TotalsLine( 'Keys', 8900, 2, note: 'Two seats' ) ), 'EUR' ) )->items();

		$this->assertEquals( array( new LineItem( 'Keys', '€178.00', '2', '€89.00', 'Two seats' ) ), $items );
	}

	/** @return array<string, array{0: int|float|string}> */
	public static function bad_rates(): array {
		return array(
			'negative'     => array( -1 ),
			'over 100'     => array( 101 ),
			'not a number' => array( 'twenty' ),
			'infinite'     => array( INF ),
			'not a number float' => array( NAN ),
		);
	}

	#[DataProvider( 'bad_rates' )]
	public function test_a_rate_that_is_not_a_percentage_is_refused( int|float|string $rate ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Totals( array(), 'EUR', $rate );
	}

	public function test_a_line_rate_and_a_discount_percentage_are_checked_too(): void {
		try {
			new Totals( array( new TotalsLine( 'A', 1, rate: 120 ) ), 'EUR' );
			$this->fail( 'A line rate of 120% was accepted.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		$this->expectException( \InvalidArgumentException::class );
		new Totals( array(), 'EUR', 0, array( Discount::percent( 'Odd', 'lots' ) ) );
	}

	public function test_an_unknown_currency_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Totals( array(), 'XYZ' );
	}

	public function test_an_amount_too_large_to_hold_exactly_is_refused_not_rounded(): void {
		$this->expectException( \OverflowException::class );

		( new Totals( array( new TotalsLine( 'A', PHP_INT_MAX, 2 ) ), 'EUR' ) )->subtotal();
	}

	public function test_a_sum_too_large_is_refused(): void {
		$this->expectException( \OverflowException::class );

		( new Totals( array( new TotalsLine( 'A', PHP_INT_MAX ), new TotalsLine( 'B', 1 ) ), 'EUR' ) )->subtotal();
	}

	public function test_rates_are_written_as_people_write_them(): void {
		$this->assertSame( '20', Totals::percentage( 20 ) );
		$this->assertSame( '19.5', Totals::percentage( '19.50' ) );
		$this->assertSame( '5.125', Totals::percentage( 5.125 ) );
		$this->assertSame( '0', Totals::percentage( 0 ) );
	}

	public function test_each_kind_has_a_total_label(): void {
		$this->assertSame( 'Total credited', DocumentKind::CreditNote->total_label() );
		$this->assertSame( 'Total due', DocumentKind::Invoice->total_label() );
		$this->assertSame( 'Total paid', DocumentKind::Receipt->total_label() );
		$this->assertSame( 'Erstattungsbetrag', DocumentKind::CreditNote->total_label( Language::German ) );
	}
}
