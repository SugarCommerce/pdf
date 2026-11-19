<?php
/**
 * Sample: an invoice worked out from minor units with a SEPA payment code,
 * and the credit note that reverses part of it.
 */
declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

use SugarCommerce\Pdf\Templates\{Branding, Discount, DocumentKind, Invoice, Party, PaymentCode, Totals, TotalsLine};

$branding = new Branding( 'Northwind Audio', 'Sample packs and presets', address: [ '12 Example Street', 'Dublin 2', 'Ireland' ] );
$seller   = new Party( 'Northwind Audio Ltd', tax_id: 'IE1234567X', registration: '654321' );
$buyer    = new Party( 'Ada Lovelace', [ '4 Marine Parade', 'Brighton BN2 1TL' ], email: 'ada@example.com' );

$totals = new Totals(
	lines: [
		new TotalsLine( 'Drum Kit Vol. 2 — studio licence', 14900, note: 'Licence period: one year' ),
		new TotalsLine( 'Vintage Keys', 8900, 2 ),
	],
	currency: 'EUR',
	rate: 23,
	discounts: [ Discount::percent( 'Discount (SPRING10)', 10 ) ],
);

$invoice = new Invoice(
	branding: $branding,
	number: 'INV-2026-0042',
	from: $seller,
	to: $buyer,
	items: $totals->items(),
	totals: $totals->rows(),
	total: $totals->total(),
	details: [ 'Issued' => '18 November 2026', 'Due' => '2 December 2026' ],
	notes: 'Pay by SEPA transfer to IE29 AIBK 9311 5212 3456 78, quoting INV-2026-0042.',
	supply_date: '18 November 2026',
	payment_code: PaymentCode::epc( 'Northwind Audio Ltd', 'IE29 AIBK 9311 5212 3456 78', $totals->gross(), reference: 'INV-2026-0042' ),
);

$refund = new Totals( [ new TotalsLine( 'Vintage Keys — one licence returned', 8900 ) ], 'EUR', 23 );

$credit = new Invoice(
	branding: $branding,
	number: 'CN-2026-0007',
	from: $seller,
	to: $buyer,
	items: $refund->items(),
	totals: $refund->rows(),
	total: $refund->total( DocumentKind::CreditNote->total_label() ),
	details: [ 'Issued' => '19 November 2026' ],
	supply_date: '18 November 2026',
	kind: DocumentKind::CreditNote,
	reference: 'INV-2026-0042',
);

foreach ( [ 'invoice' => $invoice, 'credit note' => $credit ] as $name => $document ) {
	$missing = $document->compliance_warnings_verifying();

	echo $name, ': ', [] === $missing ? 'nothing missing' : implode( ' ', $missing ), "\n";
}

$invoice->save( $argv[1] ?? 'invoice.pdf' );
$credit->save( $argv[2] ?? 'credit-note.pdf' );

echo "invoice and credit note written\n";
