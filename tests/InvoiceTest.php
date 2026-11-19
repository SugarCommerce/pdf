<?php
/**
 * The invoice template as a reader of the page sees it: the seller block,
 * every kind, the VAT wording in each language, and the checks made
 * against what was actually drawn.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Templates\{Branding, DocumentKind, Invoice, Language, LineItem, Party, PaymentCode, Translations, VatLine, VatTreatment};
use SugarCommerce\Pdf\Tests\Support\{Fixtures, Reader};

final class InvoiceTest extends TestCase {

	private static function invoice( mixed ...$overrides ): Invoice {
		$arguments = array_merge(
			array(
				'branding'    => Fixtures::branding(),
				'number'      => 'INV-9',
				'from'        => Fixtures::seller(),
				'to'          => new Party( 'Klangwerk GmbH', array( 'Torstraße 109', '10119 Berlin' ), tax_id: 'DE811907980' ),
				'items'       => array( new LineItem( 'Mastering', '€100.00' ) ),
				'totals'      => array( 'Subtotal' => '€100.00', 'VAT at 23%' => '€23.00' ),
				'total'       => array( 'Total due' => '€123.00' ),
				'supply_date' => '1 November 2026',
			),
			$overrides
		);

		return new Invoice( ...$arguments );
	}

	private static function pdf( Invoice $invoice ): string {
		return $invoice->render()->render( array( 'CreationDate' => Fixtures::CREATED ) );
	}

	/* ─── The seller ───────────────────────────────────────────────── */

	/**
	 * The bug this release fixes: the seller's name, address, VAT and
	 * registration numbers were never printed, and nothing noticed.
	 */
	public function test_the_seller_is_on_the_page(): void {
		$text = Reader::squeezed( Reader::text( self::pdf( self::invoice() ) ) );

		foreach ( array( 'FROM', 'Northwind Audio Ltd', '12 Example Street', 'Dublin 2, D02 X285', 'Ireland', 'accounts@northwind.example', 'VAT IE1234567X', 'Company no. 654321' ) as $expected ) {
			$this->assertStringContainsString( Reader::squeezed( $expected ), $text, $expected );
		}
	}

	public function test_a_seller_with_no_address_of_its_own_prints_the_brandings(): void {
		$invoice = self::invoice(
			branding: new Branding( 'Northwind Audio', address: array( '1 Brand Street', 'Cork' ) ),
			from: new Party( '', tax_id: 'IE1' ),
		);
		$text    = Reader::text( self::pdf( $invoice ) );

		$this->assertStringContainsString( '1 Brand Street', $text );
		$this->assertStringContainsString( 'Northwind Audio', $invoice->seller()->name );
		$this->assertNotContains( 'The supplier address is missing.', $invoice->compliance_warnings() );
	}

	public function test_long_party_details_wrap_inside_their_column(): void {
		$invoice = self::invoice( from: new Party( 'Northwind Audio Limited, trading as Northwind Sample Packs and Presets', array( 'Unit 12, The Old Granary Business Park, Long Street Name, Dublin 2' ), tax_id: 'IE1234567X' ) );
		$pdf     = $invoice->render();

		// FROM is the first of two equal columns beside a details column
		// of 36%, with 16-point gutters between.
		$column = ( $pdf->content_width() - ( $pdf->content_width() * 0.36 ) - 32 ) / 2;
		$seen   = 0;

		foreach ( Reader::runs( $pdf->render( array( 'CreationDate' => 0 ) ) )[0] as $run ) {
			if ( abs( $run['x'] - $pdf->left() ) < 0.01 && $run['y'] > 600 && $run['y'] < 700 ) {
				$this->assertLessThanOrEqual( $pdf->left() + $column + 0.01, $run['x'] + Reader::font( $run['font'] )->width_of( $run['text'], $run['size'] ), $run['text'] );
				++$seen;
			}
		}

		$this->assertGreaterThan( 4, $seen );
		$this->assertSame( array(), $invoice->compliance_warnings_verifying() );
	}

	/* ─── Kinds ─────────────────────────────────────────────────────── */

	/** @return array<string, array{0: DocumentKind}> */
	public static function kinds(): array {
		$kinds = array();

		foreach ( DocumentKind::cases() as $kind ) {
			$kinds[ $kind->value ] = array( $kind );
		}

		return $kinds;
	}

	#[DataProvider( 'kinds' )]
	public function test_every_kind_renders_its_title_and_standing_wording( DocumentKind $kind ): void {
		$invoice = self::invoice( kind: $kind, reference: 'INV-1', to: new Party( 'Klangwerk GmbH', array( 'Berlin' ), tax_id: 'DE1' ) );
		$bytes   = self::pdf( $invoice );
		$text    = Reader::squeezed( Reader::text( $bytes ) );

		$this->assertStringContainsString( Reader::squeezed( $kind->title() ), $text );
		$this->assertStringContainsString( Reader::squeezed( $kind->standing_note() ), $text );
		$this->assertSame( array(), $invoice->compliance_warnings_verifying(), $kind->value );

		[ $code ] = Reader::run( Reader::tool( 'qpdf' ), array( '--check' ), $bytes );
		$this->assertSame( 0, $code );
	}

	public function test_a_delivery_note_shows_no_money(): void {
		$text = Reader::text( self::pdf( self::invoice( kind: DocumentKind::DeliveryNote ) ) );

		$this->assertStringNotContainsString( '€', $text );
		$this->assertStringNotContainsString( 'Total', $text );
		$this->assertStringContainsString( 'DELIVER TO', $text );
	}

	public function test_a_credit_note_cites_the_invoice_it_reverses(): void {
		$text = Reader::squeezed( Reader::text( self::pdf( self::invoice( kind: DocumentKind::CreditNote, reference: 'INV-2026-0042' ) ) ) );

		$this->assertStringContainsString( Reader::squeezed( 'Original invoice INV-2026-0042' ), $text );
	}

	public function test_a_credit_note_without_a_reference_is_reported(): void {
		$this->assertContains( 'A credit note must reference the invoice it reverses.', self::invoice( kind: DocumentKind::CreditNote )->compliance_warnings() );
		$this->assertContains( 'A debit note must reference the document it adjusts.', self::invoice( kind: DocumentKind::DebitNote )->compliance_warnings() );
	}

	public function test_a_self_billed_invoice_needs_the_suppliers_vat_number(): void {
		$warnings = self::invoice( kind: DocumentKind::SelfBilling, to: new Party( 'Supplier', array( 'Somewhere' ) ) )->compliance_warnings();

		$this->assertContains( "A self-billed invoice must show the supplier's VAT number.", $warnings );
	}

	public function test_documents_that_are_not_tax_documents_have_no_particulars_to_miss(): void {
		foreach ( array( DocumentKind::Quote, DocumentKind::Proforma, DocumentKind::DeliveryNote, DocumentKind::Remittance ) as $kind ) {
			$invoice = new Invoice( new Branding( 'B' ), '', new Party( '' ), new Party( '' ), array(), kind: $kind );

			$this->assertSame( array(), $invoice->compliance_warnings(), $kind->value );
		}
	}

	public function test_kind_titles_are_translated(): void {
		$text = Reader::text( self::pdf( self::invoice( kind: DocumentKind::CreditNote, reference: 'R', translator: Language::French ) ) );

		$this->assertStringContainsString( 'AVOIR', $text );
		$this->assertStringContainsString( "Facture d'origine", $text );
		$this->assertStringContainsString( 'DE', $text );
	}

	/* ─── VAT ───────────────────────────────────────────────────────── */

	/** @return array<string, array{0: Language, 1: string}> */
	public static function wordings(): array {
		return array(
			'German'  => array( Language::German, 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG).' ),
			'French'  => array( Language::French, 'Autoliquidation — TVA due par le preneur.' ),
			'Italian' => array( Language::Italian, 'Inversione contabile — IVA assolta dal committente.' ),
			'Spanish' => array( Language::Spanish, 'Inversión del sujeto pasivo — IVA a declarar por el destinatario.' ),
			'Dutch'   => array( Language::Dutch, 'Btw verlegd — te voldoen door de afnemer.' ),
		);
	}

	#[DataProvider( 'wordings' )]
	public function test_the_reverse_charge_is_worded_in_the_documents_language( Language $language, string $wording ): void {
		$invoice = self::invoice( vat: VatTreatment::ReverseCharge, translator: $language );
		$text    = Reader::squeezed( Reader::text( self::pdf( $invoice ) ) );

		$this->assertStringContainsString( Reader::squeezed( 'Reverse charge: VAT to be accounted for by the recipient.' ), $text );
		$this->assertStringContainsString( Reader::squeezed( $wording ), $text );
		$this->assertSame( array(), $invoice->compliance_warnings_verifying() );
	}

	public function test_the_wording_can_be_asked_for_in_another_language(): void {
		$text = Reader::text( self::pdf( self::invoice( vat: VatTreatment::Exempt, wording: Language::Spanish ) ) );

		$this->assertStringContainsString( 'Operación exenta de IVA.', $text );
	}

	public function test_languages_without_wording_of_their_own_print_the_english(): void {
		$this->assertSame( VatTreatment::Export->notes(), VatTreatment::Export->notes( Language::Swedish ) );
		$this->assertFalse( VatTreatment::has_wording( Language::Finnish ) );
		$this->assertTrue( VatTreatment::has_wording( Language::Dutch ) );
	}

	public function test_german_notes_still_means_german(): void {
		$this->assertSame( VatTreatment::SmallBusiness->notes( Language::German ), VatTreatment::SmallBusiness->notes( true ) );
		$this->assertContains( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.', VatTreatment::SmallBusiness->notes( true ) );
	}

	/**
	 * A breakdown at one standard rate repeats the totals verbatim, so it
	 * is left out; a zero rate keeps it, because the row evidences it.
	 */
	public function test_the_breakdown_appears_only_where_it_adds_something(): void {
		$one = array( new VatLine( '23%', '€100.00', '€23.00' ) );

		$this->assertFalse( self::invoice( vat_lines: $one )->shows_vat_breakdown() );
		$this->assertTrue( self::invoice( vat_lines: array( ...$one, new VatLine( '0%', '€5.00', '€0.00' ) ) )->shows_vat_breakdown() );
		$this->assertTrue( self::invoice( vat_lines: array( new VatLine( '0%', '€100.00', '€0.00' ) ), vat: VatTreatment::ReverseCharge )->shows_vat_breakdown() );
		$this->assertFalse( self::invoice( vat_lines: $one, kind: DocumentKind::DeliveryNote )->shows_vat_breakdown() );
	}

	/* ─── Checks against the drawn page ─────────────────────────────── */

	public function test_a_complete_invoice_passes_on_the_page_too(): void {
		foreach ( Fixtures::all() as $name => $template ) {
			if ( $template instanceof Invoice ) {
				$this->assertSame( array(), $template->compliance_warnings_verifying(), $name );
			}
		}
	}

	/**
	 * The point of checking the page: a document that does not draw the
	 * seller — the old template — passes every check of the data, and
	 * fails this one.
	 */
	public function test_a_page_without_the_seller_is_caught(): void {
		// No footnotes: the fixtures' footnote names the company too.
		$invoice = self::invoice( branding: new Branding( 'Northwind Audio' ) );
		$page    = new Document();

		foreach ( $invoice->render()->drawn_text() as $text ) {
			if ( ! in_array( $text, array( 'Northwind Audio Ltd', 'VAT IE1234567X', 'Dublin 2, D02 X285' ), true ) ) {
				$page->text_at( $text, 0, 0, 9 );
			}
		}

		$warnings = implode( "\n", $invoice->compliance_warnings_verifying( $page ) );

		$this->assertSame( array(), $invoice->compliance_warnings() );
		$this->assertStringContainsString( 'The supplier name "Northwind Audio Ltd" did not reach the page.', $warnings );
		$this->assertStringContainsString( 'The supplier VAT number "IE1234567X" did not reach the page.', $warnings );
		$this->assertStringContainsString( 'The supplier address line "Dublin 2, D02 X285" did not reach the page.', $warnings );
	}

	public function test_a_page_without_the_wording_number_or_total_is_caught(): void {
		$invoice  = self::invoice( vat: VatTreatment::ReverseCharge );
		$warnings = implode( "\n", $invoice->compliance_warnings_verifying( ( new Document() )->text( 'Nothing much' ) ) );

		$this->assertStringContainsString( 'Reverse charge: VAT to be accounted for by the recipient.', $warnings );
		$this->assertStringContainsString( 'The document number "INV-9"', $warnings );
		$this->assertStringContainsString( 'The total "€123.00"', $warnings );
		$this->assertStringContainsString( 'The date of supply', $warnings );
		$this->assertStringContainsString( 'The customer VAT number', $warnings );
	}

	public function test_a_name_the_base_fonts_cannot_print_is_reported(): void {
		$warnings = self::invoice( from: new Party( 'Ярослав Ltd', array( 'Kyiv' ), tax_id: 'UA1' ) )->compliance_warnings_verifying();

		$this->assertContains( 'The supplier name "Ярослав Ltd" did not reach the page.', $warnings );
	}

	public function test_the_footer_counts_as_the_page(): void {
		$invoice = self::invoice( branding: new Branding( 'B', footnotes: array( 'Footnote with INV-9 in it' ) ) );
		$drawn   = $invoice->render()->drawn_text();

		$this->assertContains( 'Footnote with INV-9 in it', $drawn );
	}

	/* ─── Payment ───────────────────────────────────────────────────── */

	public function test_the_payment_code_is_drawn_with_its_caption_translated(): void {
		$code  = PaymentCode::epc( 'Northwind Audio Ltd', 'IE29AIBK93115212345678', 12300, reference: 'INV-9' );
		$bytes = self::pdf( self::invoice( payment_code: $code, translator: Language::German ) );

		$this->assertStringContainsString( 'Zum Bezahlen scannen', Reader::text( $bytes ) );
		$this->assertGreaterThan( 100, substr_count( $bytes, ' re' ) );
	}

	public function test_a_caption_of_your_own_is_kept(): void {
		$text = Reader::text( self::pdf( self::invoice( payment_code: new PaymentCode( 'https://example.com/verify/INV-9', 'Scan to verify' ) ) ) );

		$this->assertStringContainsString( 'Scan to verify', $text );
	}

	/* ─── Metadata ──────────────────────────────────────────────────── */

	public function test_the_document_says_what_language_it_is_in(): void {
		$this->assertStringContainsString( '/Lang (de)', self::pdf( self::invoice( translator: Language::German ) ) );
		$this->assertStringContainsString( '/Lang (fr)', self::pdf( self::invoice( translator: Translations::wordpress( 'x', Language::French ) ) ) );
		$this->assertStringNotContainsString( '/Lang', self::pdf( self::invoice( translator: new Translations( static fn(): string => 'x' ) ) ) );
	}

	public function test_metadata_names_the_kind_and_the_seller(): void {
		$this->assertSame(
			array(
				'Title'   => 'CREDIT NOTE CN-1',
				'Author'  => 'Northwind Audio Ltd',
				'Subject' => 'CREDIT NOTE for Klangwerk GmbH',
			),
			self::invoice( kind: DocumentKind::CreditNote, number: 'CN-1' )->metadata()
		);
		$this->assertSame( 'Invoice for Klangwerk GmbH', self::invoice()->metadata()['Subject'] );
	}
}
