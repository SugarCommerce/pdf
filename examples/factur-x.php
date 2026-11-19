<?php
/**
 * Sample: a Factur-X / ZUGFeRD e-invoice in the EN 16931 profile — the
 * invoice as PDF/A-3b, carrying factur-x.xml for the buyer's system.
 *
 *   php examples/factur-x.php   # writes factur-x-invoice.pdf beside it
 */
declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

use SugarCommerce\Pdf\Templates\{Branding, Discount, FacturX, FacturXException, FacturXProfile, Invoice, Party, Totals, TotalsLine};

$totals = new Totals(
	lines: [
		new TotalsLine( 'Drum Kit Vol. 2 — studio licence', 14900, note: 'Licence period: one year' ),
		new TotalsLine( 'Vintage Keys', 8900, 2 ),
		new TotalsLine( 'Sound design handbook, printed', 3500, rate: 9 ),
	],
	currency: 'EUR',
	rate: 23,
	discounts: [ Discount::percent( 'Discount (SPRING10)', 10 ) ],
);

$invoice = new Invoice(
	branding: new Branding( 'Northwind Audio', 'Sample packs and presets' ),
	number: 'INV-2026-0060',
	from: new Party( 'Northwind Audio Ltd', [ '12 Example Street', 'Dublin 2', 'Ireland' ], tax_id: 'IE1234567X', registration: '654321', country: 'IE' ),
	to: new Party( 'Klangwerk GmbH', [ 'Torstraße 109', '10119 Berlin', 'Germany' ], tax_id: 'DE811907980', country: 'DE' ),
	items: $totals->items(),
	totals: $totals->rows(),
	total: $totals->total(),
	vat_lines: $totals->vat_lines(),
	details: [ 'Issued' => '18 November 2026', 'Due' => '18 December 2026' ],
	supply_date: '17 November 2026',
);

$details = new FacturX(
	$totals,
	issued: new DateTimeImmutable( '2026-11-18' ),
	profile: FacturXProfile::En16931,
	due: new DateTimeImmutable( '2026-12-18' ),
	payment_terms: 'Within 30 days, by transfer to IE29 AIBK 9311 5212 3456 78.',
	buyer_reference: 'PO-77812',
);

try {
	$bytes = $invoice->factur_x( $details )->save( __DIR__ . '/factur-x-invoice.pdf', $invoice->metadata() );

	echo 'Wrote factur-x-invoice.pdf (', $bytes, " bytes)\n";
} catch ( FacturXException $error ) {
	foreach ( $error->problems as $problem ) {
		echo $problem, "\n";
	}
}
