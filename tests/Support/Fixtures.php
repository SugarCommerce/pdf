<?php
/**
 * The documents the golden files are made from.
 *
 * Each one exercises something a reader of the finished page can check:
 * the seller block, a wrapped note, a credit note's reference, a
 * payment code, a table that runs to a third page.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\FontFamily;
use SugarCommerce\Pdf\Templates\{Branding, Discount, DocumentKind, Invoice, Language, LineItem, Party, PaymentCode, Receipt, Statement, Totals, TotalsLine, VatLine, VatTreatment};

/**
 * Class Fixtures
 */
final class Fixtures {

	/**
	 * The creation date every golden file carries: 18 November 2026.
	 */
	public const CREATED = 1795000000;

	/**
	 * Every fixture, by name.
	 *
	 * @return array<string, Invoice|Receipt|Statement>
	 */
	public static function all(): array {
		return array(
			'invoice'          => self::invoice(),
			'reverse-charge'   => self::reverse_charge(),
			'credit-note'      => self::credit_note(),
			'proforma'         => self::proforma(),
			'delivery-note'    => self::delivery_note(),
			'mixed-rates'      => self::mixed_rates(),
			'long'             => self::long(),
			'french'           => self::french(),
			'receipt'          => self::receipt(),
			'statement'        => self::statement(),
			'international'    => self::international(),
			'typeface'         => self::typeface(),
		);
	}

	/**
	 * A fixture's bytes, the same on every run.
	 *
	 * @param Invoice|Receipt|Statement $template Fixture.
	 *
	 * @return string
	 */
	public static function bytes( Invoice|Receipt|Statement $template ): string {
		$metadata = $template instanceof Invoice ? $template->metadata() : array();

		return $template->render()->render( $metadata + array( 'CreationDate' => self::CREATED ) );
	}

	/**
	 * The fixture rendered, for its drawn text.
	 *
	 * @param Invoice|Receipt|Statement $template Fixture.
	 *
	 * @return Document
	 */
	public static function document( Invoice|Receipt|Statement $template ): Document {
		return $template->render();
	}

	/**
	 * The seller every fixture shares.
	 *
	 * @return Party
	 */
	public static function seller(): Party {
		return new Party(
			'Northwind Audio Ltd',
			array( '12 Example Street', 'Dublin 2, D02 X285', 'Ireland' ),
			email: 'accounts@northwind.example',
			tax_id: 'IE1234567X',
			registration: '654321',
		);
	}

	/**
	 * The branding every fixture shares.
	 *
	 * @return Branding
	 */
	public static function branding(): Branding {
		return new Branding(
			'Northwind Audio',
			'Sample packs and presets',
			logo_path: 'M10,0 L70,0 Q80,0 80,10 L80,60 Q80,70 70,70 L10,70 Q0,70 0,60 L0,10 Q0,0 10,0 Z',
			logo_box: 70,
			footnotes: array( 'Northwind Audio Ltd is registered in Ireland, company number 654321.' ),
		);
	}

	/**
	 * A standard-rated invoice worked out from minor units, with a
	 * discount and a stamp.
	 *
	 * @return Invoice
	 */
	public static function invoice(): Invoice {
		$totals = new Totals(
			lines: array(
				new TotalsLine( 'Drum Kit Vol. 2 — studio licence', 14900, note: 'Licence period: 18 November 2026 to 17 November 2027' ),
				new TotalsLine( 'Vintage Keys', 8900, 2 ),
				new TotalsLine( 'Sample clearance report', 4500 ),
			),
			currency: 'EUR',
			rate: 23,
			discounts: array( Discount::percent( 'Discount (SPRING10)', 10 ) ),
		);

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0042',
			from: self::seller(),
			to: new Party( 'Ada Lovelace', array( '4 Marine Parade', 'Brighton BN2 1TL', 'United Kingdom' ), email: 'ada@example.com' ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			details: array(
				'Issued'  => '18 November 2026',
				'Paid by' => 'Card ending 4242',
			),
			notes: 'Thank you for your order. Questions about this invoice go to accounts@northwind.example, quoting the number above.',
			status: 'Paid',
			vat_lines: $totals->vat_lines(),
			supply_date: '18 November 2026',
		);
	}

	/**
	 * A reverse-charge invoice to a German business, with the German
	 * wording and a SEPA payment code.
	 *
	 * @return Invoice
	 */
	public static function reverse_charge(): Invoice {
		$totals = new Totals( array( new TotalsLine( 'Mixing and mastering, 12 tracks', 124000 ) ), 'EUR' );

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0043',
			from: self::seller(),
			to: new Party( 'Klangwerk GmbH', array( 'Torstraße 109', '10119 Berlin', 'Germany' ), tax_id: 'DE811907980' ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			details: array(
				'Issued' => '18 November 2026',
				'Due'    => '2 December 2026',
			),
			notes: "Pay by SEPA transfer to IE29 AIBK 9311 5212 3456 78, quoting INV-2026-0043.\nThe code beside this note fills the transfer in.",
			vat: VatTreatment::ReverseCharge,
			vat_lines: array( new VatLine( '0%', $totals->format( $totals->net() ), $totals->format( 0 ) ) ),
			supply_date: '1 November 2026',
			wording: Language::German,
			payment_code: PaymentCode::epc( 'Northwind Audio Ltd', 'IE29 AIBK 9311 5212 3456 78', $totals->gross(), reference: 'INV-2026-0043' ),
		);
	}

	/**
	 * A credit note reversing part of an invoice.
	 *
	 * @return Invoice
	 */
	public static function credit_note(): Invoice {
		$totals = new Totals( array( new TotalsLine( 'Vintage Keys — refund of one licence', 8900 ) ), 'EUR', 23 );

		return new Invoice(
			branding: self::branding(),
			number: 'CN-2026-0007',
			from: self::seller(),
			to: new Party( 'Ada Lovelace', array( '4 Marine Parade', 'Brighton BN2 1TL', 'United Kingdom' ) ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total( DocumentKind::CreditNote->total_label() ),
			details: array( 'Issued' => '19 November 2026' ),
			supply_date: '18 November 2026',
			kind: DocumentKind::CreditNote,
			reference: 'INV-2026-0042',
		);
	}

	/**
	 * A proforma, which must say it is not a VAT invoice.
	 *
	 * @return Invoice
	 */
	public static function proforma(): Invoice {
		$totals = new Totals( array( new TotalsLine( 'Custom sound design, 40 presets', 180000 ) ), 'GBP', 20 );

		return new Invoice(
			branding: self::branding(),
			number: 'PF-2026-0003',
			from: self::seller(),
			to: new Party( 'Acme Recordings Ltd', array( 'Studio 4, 118 Brick Lane', 'London E1 6RL' ) ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total( DocumentKind::Proforma->total_label() ),
			details: array( 'Valid until' => '18 December 2026' ),
			kind: DocumentKind::Proforma,
			payment_code: new PaymentCode( 'https://pay.northwind.example/PF-2026-0003' ),
		);
	}

	/**
	 * A delivery note, which shows no money at all.
	 *
	 * @return Invoice
	 */
	public static function delivery_note(): Invoice {
		return new Invoice(
			branding: self::branding(),
			number: 'DN-2026-0012',
			from: self::seller(),
			to: new Party( 'Acme Recordings Ltd', array( 'Studio 4, 118 Brick Lane', 'London E1 6RL' ) ),
			items: array(
				new LineItem( 'Field recorder, boxed', '€499.00', '1', '€499.00', 'Serial number FR-2207-1183' ),
				new LineItem( 'Windshield', '€38.00', '2', '€19.00' ),
			),
			totals: array( 'Subtotal' => '€537.00' ),
			total: array( 'Total due' => '€537.00' ),
			details: array( 'Despatched' => '18 November 2026' ),
			kind: DocumentKind::DeliveryNote,
		);
	}

	/**
	 * Two rates on one invoice, so the breakdown is required.
	 *
	 * @return Invoice
	 */
	public static function mixed_rates(): Invoice {
		$totals = new Totals(
			lines: array(
				new TotalsLine( 'Production course, video', 9900 ),
				new TotalsLine( 'Production course, printed workbook', 2500, rate: 0 ),
			),
			currency: 'GBP',
			rate: 20,
			discounts: array( Discount::fixed( 'Voucher', 1000 ) ),
		);

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0044',
			from: self::seller(),
			to: new Party( 'Acme Recordings Ltd', array( 'Studio 4, 118 Brick Lane', 'London E1 6RL' ), tax_id: 'GB123456789' ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			vat_lines: $totals->vat_lines(),
			supply_date: '18 November 2026',
		);
	}

	/**
	 * Forty long lines with notes, and a payment note of three paragraphs:
	 * the table runs on with its header repeated, and nothing runs off
	 * the page.
	 *
	 * @return Invoice
	 */
	public static function long(): Invoice {
		$lines = array();

		for ( $i = 1; $i <= 40; $i++ ) {
			$lines[] = new TotalsLine(
				sprintf( 'Session %02d — mixing, editing and a long description of exactly what was done that runs to a second line', $i ),
				12500,
				note: 'Hours: 2.5 at €50.00, booked 2026-11-' . str_pad( (string) ( ( $i % 28 ) + 1 ), 2, '0', STR_PAD_LEFT )
			);
		}

		$totals = new Totals( $lines, 'EUR', 23 );
		$note   = str_repeat( 'Payment is due within fourteen days by bank transfer to IE29 AIBK 9311 5212 3456 78. Please quote the invoice number as the reference so the payment can be matched. ', 3 );

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0045',
			from: self::seller(),
			to: new Party( 'Acme Recordings Ltd', array( 'Studio 4, 118 Brick Lane', 'London E1 6RL' ) ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			notes: $note . "\n\n" . $note . "\n\nhttps://pay.northwind.example/invoices/INV-2026-0045/a-very-long-link-with-no-spaces-in-it-at-all-that-must-be-cut-somewhere",
			supply_date: '30 November 2026',
		);
	}

	/**
	 * An intra-community supply, in French, with the French wording.
	 *
	 * @return Invoice
	 */
	public static function french(): Invoice {
		$totals = new Totals( array( new TotalsLine( 'Enregistreur de terrain', 49900, 3 ) ), 'EUR', 0, locale: '' );

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0046',
			from: self::seller(),
			to: new Party( 'Studio Belleville SARL', array( '21 rue de Belleville', '75019 Paris', 'France' ), tax_id: 'FR40303265045' ),
			items: $totals->items(),
			totals: $totals->rows( Language::French ),
			total: $totals->total( DocumentKind::Invoice->total_label( Language::French ) ),
			vat: VatTreatment::IntraCommunitySupply,
			vat_lines: array( new VatLine( '0%', $totals->format( $totals->net() ), $totals->format( 0 ) ) ),
			supply_date: '18 novembre 2026',
			translator: Language::French,
		);
	}

	/**
	 * An A5 receipt that names who was paid.
	 *
	 * @return Receipt
	 */
	public static function receipt(): Receipt {
		return new Receipt(
			branding: self::branding(),
			number: 'R-2026-0042',
			to: new Party( 'Ada Lovelace', array( 'ada@example.com' ) ),
			items: array(
				new LineItem( 'Drum Kit Vol. 2 — studio licence', '€149.00', note: 'Licence period: one year' ),
				new LineItem( 'Vintage Keys', '€178.00', '2', '€89.00' ),
			),
			total: '€402.21',
			details: array(
				'Date'    => '18 November 2026',
				'Paid by' => 'Card ending 4242',
			),
			breakdown: array(
				'Subtotal'   => '€327.00',
				'VAT at 23%' => '€75.21',
			),
			from: self::seller(),
		);
	}

	/**
	 * A statement of account.
	 *
	 * @return Statement
	 */
	public static function statement(): Statement {
		$rows = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = array( sprintf( '2026-11-%02d', $i ), 'Order ' . ( 1000 + $i ), 'INV-' . ( 2000 + $i ), '€10.00', '€' . ( $i * 10 ) . '.00' );
		}

		return new Statement(
			branding: self::branding(),
			account: new Party( 'Acme Recordings Ltd', array( 'Studio 4, 118 Brick Lane', 'London E1 6RL' ) ),
			period: 'November 2026',
			rows: $rows,
			closing: array( 'Closing balance' => '€120.00' ),
			reference: 'Account ACME-01',
			from: self::seller(),
		);
	}

	/**
	 * Names and items in Cyrillic, Greek and Polish, in sterling, set in
	 * Helvetica with the bundled fallback drawing the words it cannot.
	 *
	 * @return Invoice
	 */
	public static function international(): Invoice {
		$totals = new Totals(
			lines: array(
				new TotalsLine( 'Łódź field recordings — Wąchock session', 12000, note: 'Nagrania terenowe, zaśpiewane przez chór' ),
				new TotalsLine( 'Ελληνικά δείγματα: Θεσσαλονίκη', 8000, 2 ),
				new TotalsLine( 'Пакет семплів «Карпати»', 4500 ),
			),
			currency: 'GBP',
			rate: 0,
		);

		return new Invoice(
			branding: self::branding(),
			number: 'INV-2026-0050',
			from: self::seller(),
			to: new Party( 'Ярослав Ковальчук', array( 'вул. Хрещатик, 22', 'Київ 01001', 'Україна' ), email: 'yaroslav@example.com' ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			notes: 'Дякуємо! Ευχαριστούμε! Dziękujemy! Payment in £ or € to the account on file.',
			vat: VatTreatment::Export,
			supply_date: '18 November 2026',
		);
	}

	/**
	 * A whole invoice set in the bundled family, accents and all.
	 *
	 * @return Invoice
	 */
	public static function typeface(): Invoice {
		$brand  = self::branding();
		$totals = new Totals(
			lines: array(
				new TotalsLine( 'Dvořák string library — 24-bit', 21900, note: 'Šťastný nový rok' ),
				new TotalsLine( 'Kırıkkale percussion', 6900 ),
			),
			currency: 'EUR',
			rate: 21,
		);

		return new Invoice(
			branding: new Branding( $brand->name, $brand->tagline, '#1F5FBF', $brand->logo_path, $brand->logo_box, footnotes: $brand->footnotes, typeface: FontFamily::noto_sans() ),
			number: 'INV-2026-0051',
			from: self::seller(),
			to: new Party( 'Zoë Dvořáková', array( 'Václavské náměstí 1', '110 00 Praha 1', 'Česko' ), tax_id: 'CZ12345678' ),
			items: $totals->items(),
			totals: $totals->rows(),
			total: $totals->total(),
			supply_date: '18 November 2026',
			status: 'Due',
		);
	}
}
