<?php
/**
 * The verdicts of the reference validators, which no test written from
 * the same reading of the standards can stand in for.
 *
 * - veraPDF, the PDF Association's reference implementation, marks every
 *   golden document — each fixture made archival, and each e-invoice —
 *   against PDF/A-3b.
 * - The UN/CEFACT CII D16B schema and the Factur-X Schematron of the
 *   profile it declares mark the XML of every profile, VAT treatment and
 *   kind of invoice.
 *
 * Both come from `bin/validators`, which CI runs first; on a workbench
 * without them these skip and say how to fetch them.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Tests\Support\{Fixtures, Validators};
use SugarCommerce\Pdf\Templates\{Discount, DocumentKind, FacturXProfile, Party, Totals, TotalsLine, VatTreatment};

final class ConformanceTest extends TestCase {

	public function test_verapdf_passes_every_golden_document_as_pdf_a_3b(): void {
		$verapdf = Validators::verapdf();
		$folder  = Validators::scratch();
		$files   = array();

		foreach ( Fixtures::all() as $name => $template ) {
			$files[ $name ] = $folder . '/' . $name . '.pdf';
			file_put_contents( $files[ $name ], Fixtures::bytes( $template, true ) );
		}

		foreach ( Fixtures::einvoices() as $name => $einvoice ) {
			$files[ $name ] = $folder . '/' . $name . '.pdf';
			file_put_contents( $files[ $name ], Fixtures::einvoice_bytes( $einvoice ) );
		}

		[ , $report ] = Validators::run( array_merge( array( $verapdf, '--flavour', '3b', '--format', 'mrr' ), array_values( $files ) ) );

		Validators::remove( $folder );

		$dom = new \DOMDocument();

		$this->assertTrue( @$dom->loadXML( substr( $report, (int) strpos( $report, '<?xml' ) ) ), 'veraPDF gave no report: ' . substr( $report, 0, 2000 ) );

		$xpath   = new \DOMXPath( $dom );
		$verdict = array();

		foreach ( $xpath->query( '//job' ) ?: array() as $job ) {
			$name   = basename( (string) $xpath->evaluate( 'string(item/name)', $job ), '.pdf' );
			$report = $xpath->query( 'validationReport', $job )?->item( 0 );

			if ( ! $report instanceof \DOMElement ) {
				$verdict[ $name ] = 'not validated: ' . trim( (string) $xpath->evaluate( 'string(.)', $job ) );
				continue;
			}

			$failed = array();

			foreach ( $xpath->query( './/rule[@status="failed"]', $report ) ?: array() as $rule ) {
				$failed[] = $xpath->evaluate( 'string(@clause)', $rule ) . '-' . $xpath->evaluate( 'string(@testNumber)', $rule ) . ' ' . trim( (string) $xpath->evaluate( 'string(description)', $rule ) );
			}

			$this->assertStringContainsStringIgnoringCase( 'PDF/A-3B', $report->getAttribute( 'profileName' ), $name );

			$verdict[ $name ] = 'true' === $report->getAttribute( 'isCompliant' ) ? 'compliant' : implode( '; ', $failed );
		}

		ksort( $verdict );

		$expected = array_fill_keys( array_keys( $files ), 'compliant' );
		ksort( $expected );

		$this->assertSame( $expected, $verdict );
	}

	public function test_the_xml_passes_the_schema_and_the_profile_rules(): void {
		$command = Validators::facturx();
		$folder  = Validators::scratch();
		$seller  = Fixtures::seller();
		$buyer   = Fixtures::german_buyer();
		$files   = array();

		$cases = array(
			'standard'       => array( VatTreatment::Standard, new Totals( array( new TotalsLine( 'Drum Kit — licence', 14900, note: 'One year' ), new TotalsLine( 'Keys', 8900, 2 ), new TotalsLine( 'Book', 2000, 1, 9 ) ), 'EUR', 23, array( Discount::percent( 'Discount (SPRING10)', 10 ), Discount::fixed( 'Voucher', 500 ) ) ) ),
			'zero-rated'     => array( VatTreatment::Standard, new Totals( array( new TotalsLine( 'Book', 3000 ), new TotalsLine( 'Licence', 1000, rate: 20 ) ), 'GBP', 0 ) ),
			'fractional'     => array( VatTreatment::Standard, new Totals( array( new TotalsLine( 'Service', 10000, 3 ) ), 'USD', '8.875' ) ),
			'yen'            => array( VatTreatment::Standard, new Totals( array( new TotalsLine( 'Plug-in', 12000 ) ), 'JPY', 10 ) ),
			'reverse-charge' => array( VatTreatment::ReverseCharge, new Totals( array( new TotalsLine( 'Mixing', 124000 ) ), 'EUR', 0, array( Discount::fixed( 'Loyalty', 4000 ) ) ) ),
			'intra'          => array( VatTreatment::IntraCommunitySupply, new Totals( array( new TotalsLine( 'Hardware', 50000, 3 ) ), 'EUR' ) ),
			'export'         => array( VatTreatment::Export, new Totals( array( new TotalsLine( 'Hardware', 50000 ) ), 'CHF' ) ),
			'small-business' => array( VatTreatment::SmallBusiness, new Totals( array( new TotalsLine( 'Lesson', 4000, 4 ) ), 'EUR' ) ),
			'exempt'         => array( VatTreatment::Exempt, new Totals( array( new TotalsLine( 'Course', 30000 ) ), 'EUR' ) ),
		);

		$kinds = array( DocumentKind::Invoice, DocumentKind::CreditNote, DocumentKind::DebitNote, DocumentKind::Proforma, DocumentKind::SelfBilling );

		foreach ( $cases as $case => [ $vat, $totals ] ) {
			foreach ( $kinds as $kind ) {
				foreach ( FacturXProfile::cases() as $profile ) {
					// The intra-community case carries the delivery it needs;
					// the rest are written without one, and must pass without.
					$extras = VatTreatment::IntraCommunitySupply === $vat ? array( 'delivered' => new \DateTimeImmutable( '2026-11-17' ) ) : array();

					[ $invoice, $details ] = Fixtures::einvoice(
						$totals,
						DocumentKind::SelfBilling === $kind ? new Party( 'Session Player Ltd', array( '1 High Street', 'Cork' ), tax_id: 'IE7654321Y', country: 'IE' ) : $buyer,
						$profile,
						array(
							'kind'      => $kind,
							'vat'       => $vat,
							'reference' => 'INV-2026-0001',
							'from'      => $seller,
						),
						$extras + ( DocumentKind::Invoice === $kind ? array() : array( 'payment_terms' => '', 'buyer_reference' => 'PO-1', 'prepaid' => 100 ) )
					);

					$file = $folder . '/' . $case . '-' . $kind->value . '-' . strtolower( str_replace( ' ', '', $profile->conformance_level() ) ) . '.xml';

					file_put_contents( $file, $invoice->factur_x_xml( $details ) );
					$files[] = $file;
				}
			}
		}

		foreach ( Fixtures::einvoices() as $name => [ $invoice, $details ] ) {
			$files[] = $folder . '/' . $name . '.xml';
			file_put_contents( (string) end( $files ), $invoice->factur_x_xml( $details ) );
		}

		[ $code, $output ] = Validators::run( array_merge( $command, $files ) );

		Validators::remove( $folder );

		$this->assertSame( 0, $code, $output );
		$this->assertSame( count( $files ), substr_count( $output, 'PASS ' ), $output );
	}
}
