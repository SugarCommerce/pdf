<?php
/**
 * Payment codes: the EPC payload, and the code as drawn.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Templates\PaymentCode;
use SugarCommerce\Qr\QR;

final class PaymentCodeTest extends TestCase {

	public function test_an_epc_payload_is_written_field_for_field(): void {
		$code = PaymentCode::epc( 'Northwind Audio Ltd', 'ie29 aibk 9311 5212 3456 78', 124050, 'AIBKIE2D', 'INV-2026-0043' );

		$this->assertNotNull( $code );
		$this->assertSame(
			"BCD\n002\n1\nSCT\nAIBKIE2D\nNorthwind Audio Ltd\nIE29AIBK93115212345678\nEUR1240.50\n\n\nINV-2026-0043",
			$code->payload
		);
		$this->assertSame( 'Scan to pay', $code->caption );
	}

	public function test_no_amount_leaves_it_for_the_payer(): void {
		$code = PaymentCode::epc( 'Northwind', 'IE29AIBK93115212345678' );

		$this->assertSame( "BCD\n002\n1\nSCT\n\nNorthwind\nIE29AIBK93115212345678\n\n\n\n", $code?->payload );
	}

	/** @return array<string, array{0: int, 1: string}> */
	public static function amounts(): array {
		return array(
			'one cent'    => array( 1, 'EUR0.01' ),
			'whole euros' => array( 500, 'EUR5.00' ),
			'the ceiling' => array( 99_999_999_999, 'EUR999999999.99' ),
		);
	}

	#[DataProvider( 'amounts' )]
	public function test_amounts_are_written_in_euro_to_two_places( int $cents, string $expected ): void {
		$this->assertSame( $expected, explode( "\n", (string) PaymentCode::epc( 'N', 'IE29AIBK93115212345678', $cents )?->payload )[7] );
	}

	/** @return array<string, array{0: string, 1: string, 2: int|null, 3: string, 4: string}> */
	public static function refusals(): array {
		return array(
			'no name'            => array( ' ', 'IE29AIBK93115212345678', null, '', '' ),
			'name over 70'       => array( str_repeat( 'a', 71 ), 'IE29AIBK93115212345678', null, '', '' ),
			'not an IBAN'        => array( 'N', 'not an iban', null, '', '' ),
			'IBAN too short'     => array( 'N', 'IE29AIBK931', null, '', '' ),
			'nothing'            => array( 'N', 'IE29AIBK93115212345678', 0, '', '' ),
			'negative'           => array( 'N', 'IE29AIBK93115212345678', -100, '', '' ),
			'over the ceiling'   => array( 'N', 'IE29AIBK93115212345678', 100_000_000_000, '', '' ),
			'reference over 140' => array( 'N', 'IE29AIBK93115212345678', null, '', str_repeat( 'r', 141 ) ),
			'not a BIC'          => array( 'N', 'IE29AIBK93115212345678', null, 'AIB', '' ),
		);
	}

	#[DataProvider( 'refusals' )]
	public function test_details_that_cannot_make_a_valid_code_make_none( string $name, string $iban, ?int $cents, string $bic, string $reference ): void {
		$this->assertNull( PaymentCode::epc( $name, $iban, $cents, $bic, $reference ) );
	}

	public function test_a_newline_cannot_shift_the_fields(): void {
		$lines = explode( "\n", (string) PaymentCode::epc( "Northwind\nEvil", 'IE29AIBK93115212345678', reference: "INV\n1" )?->payload );

		$this->assertCount( 11, $lines );
		$this->assertSame( 'Northwind Evil', $lines[5] );
		$this->assertSame( 'INV 1', $lines[10] );
	}

	/**
	 * Read the squares back off the page and compare them with the
	 * matrix sugarcommerce/qr produces — module for module, the right way
	 * up, inside its quiet zone.
	 */
	public function test_the_drawn_code_is_the_matrix_module_for_module(): void {
		$data = "BCD\n002\n1\nSCT\n\nNorthwind\nIE29AIBK93115212345678\nEUR12.50\n\n\nINV-1";
		$pdf  = new Document();

		$this->assertTrue( $pdf->qr( $data, 100, 200, 116 ) );

		$matrix = QR::matrix( $data );
		$count  = count( $matrix );
		$module = 116 / ( $count + 8 );
		$drawn  = array_fill( 0, $count, array_fill( 0, $count, false ) );

		preg_match_all( '/(-?[\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+) re/', $pdf->render( array( 'CreationDate' => 0 ) ), $rects, PREG_SET_ORDER );

		foreach ( $rects as [ , $x, $y, $w, $h ] ) {
			$this->assertEqualsWithDelta( $module, (float) $h, 0.001 );

			$row   = (int) round( ( 200 + 116 - (float) $y ) / $module ) - 4 - 1;
			$first = (int) round( ( (float) $x - 100 ) / $module ) - 4;
			$span  = (int) round( (float) $w / $module );

			for ( $c = $first; $c < $first + $span; $c++ ) {
				$this->assertArrayHasKey( $c, $drawn[ $row ], 'A module was drawn outside the code.' );
				$drawn[ $row ][ $c ] = true;
			}
		}

		$this->assertSame( $matrix, $drawn );
	}

	public function test_a_code_that_cannot_be_made_is_not_drawn(): void {
		$pdf = new Document();

		$this->assertFalse( $pdf->qr( '', 0, 0, 90 ) );
		$this->assertFalse( $pdf->qr( '   ', 0, 0, 90 ) );
		$this->assertFalse( $pdf->qr( str_repeat( 'x', 3000 ), 0, 0, 90 ) );
		$this->assertFalse( $pdf->qr( 'x', 0, 0, 90, level: 'Z' ) );
		$this->assertFalse( $pdf->qr( 'x', 0, 0, 0 ) );
		$this->assertStringNotContainsString( ' re', $pdf->render( array( 'CreationDate' => 0 ) ) );
	}
}
