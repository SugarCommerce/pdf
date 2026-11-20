<?php
/**
 * Factur-X: what is refused before any XML is written, and what the XML
 * says when it is.
 *
 * The official schema and the profile Schematron run over every profile,
 * treatment and kind in ConformanceTest; these pin each rule and each
 * figure on its own, so a regression names what it broke.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Relationship;
use SugarCommerce\Pdf\Tests\Support\{Fixtures, Reader, Validators};
use SugarCommerce\Pdf\Models\Templates\Branding;
use SugarCommerce\Pdf\Models\Templates\Discount;
use SugarCommerce\Pdf\Models\Templates\DocumentKind;
use SugarCommerce\Pdf\Models\Templates\FacturX;
use SugarCommerce\Pdf\Templates\FacturXException;
use SugarCommerce\Pdf\Models\Templates\FacturXProfile;
use SugarCommerce\Pdf\Templates\Invoice;
use SugarCommerce\Pdf\Models\Templates\Party;
use SugarCommerce\Pdf\Models\Templates\Totals;
use SugarCommerce\Pdf\Models\Templates\TotalsLine;
use SugarCommerce\Pdf\Models\Templates\VatTreatment;

final class FacturXTest extends TestCase {

	/**
	 * The XML, parsed, with its namespaces registered.
	 *
	 * @param string $xml The XML.
	 *
	 * @return \DOMXPath
	 */
	private function parsed( string $xml ): \DOMXPath {
		$dom = new \DOMDocument();

		$this->assertTrue( $dom->loadXML( $xml ), 'not well-formed' );

		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' );
		$xpath->registerNamespace( 'ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100' );
		$xpath->registerNamespace( 'udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100' );

		return $xpath;
	}

	/**
	 * An e-invoice's XML, parsed.
	 *
	 * @param array{0: Invoice, 1: FacturX} $einvoice From Fixtures.
	 *
	 * @return \DOMXPath
	 */
	private function xml( array $einvoice ): \DOMXPath {
		return $this->parsed( $einvoice[0]->factur_x_xml( $einvoice[1] ) );
	}

	/**
	 * An amount element's value in minor units.
	 *
	 * @param \DOMXPath $xpath Parsed XML.
	 * @param string    $query XPath.
	 *
	 * @return int
	 */
	private static function cents( \DOMXPath $xpath, string $query ): int {
		return (int) round( (float) $xpath->evaluate( 'string(' . $query . ')' ) * 100 );
	}

	/**
	 * A simple standard-rated einvoice, varied.
	 *
	 * @param FacturXProfile       $profile The profile.
	 * @param array<string, mixed> $invoice Invoice arguments.
	 * @param array<string, mixed> $details FacturX arguments.
	 * @param Totals|null          $totals  The figures.
	 * @param Party|null           $buyer   The buyer.
	 *
	 * @return array{0: Invoice, 1: FacturX}
	 */
	private static function einvoice( FacturXProfile $profile = FacturXProfile::En16931, array $invoice = array(), array $details = array(), ?Totals $totals = null, ?Party $buyer = null ): array {
		return Fixtures::einvoice( $totals ?? new Totals( array( new TotalsLine( 'Plug-in licence', 9900 ) ), 'EUR', 23 ), $buyer ?? Fixtures::german_buyer(), $profile, $invoice, $details );
	}

	/* ─── Refusals ──────────────────────────────────────────────────── */

	public function test_every_fixture_has_nothing_to_refuse(): void {
		foreach ( Fixtures::einvoices() as $name => [ $invoice, $details ] ) {
			$this->assertSame( array(), $invoice->factur_x_problems( $details ), $name );
		}
	}

	/** @return array<string, array{0: callable(): array{0: Invoice, 1: FacturX}, 1: string}> */
	public static function refusals(): array {
		$nobody  = new Party( 'Klangwerk GmbH', array( 'Torstraße 109', '10119 Berlin' ), tax_id: 'DE811907980' );
		$seller  = Fixtures::seller();
		$no_vat  = new Party( $seller->name, $seller->address, country: 'IE' );
		$nowhere = new Party( $seller->name, $seller->address, tax_id: $seller->tax_id );

		return array(
			'a delivery note'                  => array( static fn() => self::einvoice( invoice: array( 'kind' => DocumentKind::DeliveryNote ) ), 'is not an invoice' ),
			'a quote'                          => array( static fn() => self::einvoice( invoice: array( 'kind' => DocumentKind::Quote ) ), 'is not an invoice' ),
			'no number'                        => array( static fn() => self::einvoice( invoice: array( 'number' => ' ' ) ), 'BR-02' ),
			'no seller VAT number'             => array( static fn() => self::einvoice( invoice: array( 'from' => $no_vat ) ), 'BR-CO-26' ),
			'no seller country'                => array( static fn() => self::einvoice( invoice: array( 'from' => $nowhere ) ), 'BR-09' ),
			'no seller country, Minimum'       => array( static fn() => self::einvoice( FacturXProfile::Minimum, invoice: array( 'from' => $nowhere ) ), 'BR-09' ),
			'no buyer country'                 => array( static fn() => self::einvoice( buyer: $nobody ), 'BR-11' ),
			'no seller name'                   => array( static fn() => self::einvoice( invoice: array( 'branding' => new Branding( '' ), 'from' => new Party( ' ', $seller->address, tax_id: $seller->tax_id, country: 'IE' ) ) ), 'BR-06' ),
			'a delivery country that is not one' => array( static fn() => self::einvoice( details: array( 'delivered_to' => 'Germany' ) ), 'BR-IC-12' ),
			'less than nothing'                => array( static fn() => self::einvoice( FacturXProfile::BasicWl, totals: new Totals( array( new TotalsLine( 'Refund', 1000, -1 ) ), 'EUR', 23 ) ), 'less than nothing' ),
			'no buyer name'                    => array( static fn() => self::einvoice( buyer: new Party( ' ', country: 'DE' ) ), 'BR-07' ),
			'three decimals'                   => array( static fn() => self::einvoice( totals: new Totals( array( new TotalsLine( 'x', 1000 ) ), 'KWD', 5 ) ), 'BR-DEC' ),
			'reverse charge, no buyer VAT'     => array( static fn() => self::einvoice( invoice: array( 'vat' => VatTreatment::ReverseCharge ), totals: new Totals( array( new TotalsLine( 'x', 1000 ) ), 'EUR' ), buyer: new Party( 'Klangwerk GmbH', country: 'DE' ) ), 'BR-AE-02' ),
			'intra-community, no buyer VAT'    => array( static fn() => self::einvoice( invoice: array( 'vat' => VatTreatment::IntraCommunitySupply ), details: array( 'delivered' => new \DateTimeImmutable() ), totals: new Totals( array( new TotalsLine( 'x', 1000 ) ), 'EUR' ), buyer: new Party( 'Klangwerk GmbH', country: 'DE' ) ), 'BR-IC-02' ),
			'intra-community, no delivery'     => array( static fn() => self::einvoice( invoice: array( 'vat' => VatTreatment::IntraCommunitySupply ), totals: new Totals( array( new TotalsLine( 'x', 1000 ) ), 'EUR' ) ), 'BR-IC-11' ),
			'VAT under reverse charge'         => array( static fn() => self::einvoice( invoice: array( 'vat' => VatTreatment::ReverseCharge ) ), 'no VAT is charged' ),
			'VAT on an export'                 => array( static fn() => self::einvoice( invoice: array( 'vat' => VatTreatment::Export ) ), 'no VAT is charged' ),
			'no due date or terms'             => array( static fn() => self::einvoice( details: array( 'due' => null, 'payment_terms' => '' ) ), 'BR-CO-25' ),
			'a negative price'                 => array( static fn() => self::einvoice( totals: new Totals( array( new TotalsLine( 'x', 1000 ), new TotalsLine( 'Credit', -100 ) ), 'EUR', 23 ) ), 'BR-27' ),
			'no lines'                         => array( static fn() => self::einvoice( totals: new Totals( array(), 'EUR', 23 ) ), 'BR-16' ),
			'a line with no description'       => array( static fn() => self::einvoice( totals: new Totals( array( new TotalsLine( ' ', 1000 ) ), 'EUR', 23 ) ), 'BR-25' ),
			'paid more than the total'         => array( static fn() => self::einvoice( details: array( 'prepaid' => 999999 ) ), 'already paid' ),
			'paid a negative amount'           => array( static fn() => self::einvoice( details: array( 'prepaid' => -1 ) ), 'already paid' ),
			'a page total from other figures'  => array( static fn() => self::einvoice( invoice: array( 'total' => array( 'Total due' => '€1.00' ) ) ), 'The page shows the total as €1.00' ),
			'a page with no total'             => array( static fn() => self::einvoice( invoice: array( 'total' => array() ) ), 'prints no total' ),
			'page lines from other figures'    => array( static fn() => self::einvoice( invoice: array( 'items' => array() ) ), 'The page lists 0 lines and the figures 1' ),
		);
	}

	/**
	 * @param callable(): array{0: Invoice, 1: FacturX} $make     The e-invoice.
	 * @param string                                    $expected Part of the problem.
	 */
	#[DataProvider( 'refusals' )]
	public function test_what_a_validator_would_reject_is_refused( callable $make, string $expected ): void {
		[ $invoice, $details ] = $make();

		$problems = $invoice->factur_x_problems( $details );

		$this->assertNotEmpty( array_filter( $problems, static fn( string $problem ): bool => str_contains( $problem, $expected ) ), implode( "\n", $problems ) );

		try {
			$invoice->factur_x_xml( $details );
			$this->fail( 'XML was written for an invoice with problems.' );
		} catch ( FacturXException $error ) {
			$this->assertSame( $problems, $error->problems );
			$this->assertStringContainsString( $expected, $error->getMessage() );
		}

		$this->expectException( FacturXException::class );

		$invoice->factur_x( $details );
	}

	public function test_minimum_does_not_need_what_it_does_not_carry(): void {
		[ $invoice, $details ] = self::einvoice( FacturXProfile::Minimum, details: array( 'due' => null, 'payment_terms' => '' ), buyer: new Party( 'Klangwerk GmbH' ) );

		$this->assertSame( array(), $invoice->factur_x_problems( $details ) );
	}

	public function test_every_problem_is_listed_at_once(): void {
		[ $invoice, $details ] = self::einvoice( invoice: array( 'number' => '' ), details: array( 'due' => null, 'payment_terms' => '' ), buyer: new Party( 'Nobody' ) );

		$this->assertCount( 3, $invoice->factur_x_problems( $details ) );
	}

	public function test_a_name_the_page_cannot_print_is_refused(): void {
		[ $invoice, $details ] = self::einvoice( buyer: new Party( 'شركة الصوت', array( 'Dubai' ), tax_id: 'AE100000000000003', country: 'AE' ) );

		$this->assertSame( array(), $invoice->factur_x_problems( $details ) );

		try {
			$invoice->factur_x( $details );
			$this->fail( 'An e-invoice was made whose page does not say what its XML does.' );
		} catch ( FacturXException $error ) {
			$this->assertStringContainsString( 'شركة الصوت', $error->getMessage() );
		}
	}

	/* ─── The XML ───────────────────────────────────────────────────── */

	public function test_each_profile_declares_itself(): void {
		foreach ( FacturXProfile::cases() as $profile ) {
			$xpath = $this->xml( self::einvoice( $profile ) );

			$this->assertSame( $profile->value, $xpath->evaluate( 'string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)' ) );
		}

		$this->assertSame( 'urn:cen.eu:en16931:2017', FacturXProfile::En16931->value );
		$this->assertSame( 'urn:factur-x.eu:1p0:minimum', FacturXProfile::Minimum->value );
	}

	public function test_each_kind_has_its_type_code(): void {
		$codes = array(
			'380' => DocumentKind::Invoice,
			'381' => DocumentKind::CreditNote,
			'383' => DocumentKind::DebitNote,
			'325' => DocumentKind::Proforma,
			'389' => DocumentKind::SelfBilling,
		);

		foreach ( $codes as $code => $kind ) {
			$this->assertSame( (string) $code, $kind->factur_x_type_code() );
			$this->assertSame( (string) $code, $this->xml( self::einvoice( invoice: array( 'kind' => $kind, 'reference' => 'INV-1' ) ) )->evaluate( 'string(//rsm:ExchangedDocument/ram:TypeCode)' ) );
		}

		foreach ( array( DocumentKind::Quote, DocumentKind::Receipt, DocumentKind::Reminder, DocumentKind::Remittance, DocumentKind::DeliveryNote, DocumentKind::PurchaseOrder, DocumentKind::OrderConfirmation ) as $kind ) {
			$this->assertNull( $kind->factur_x_type_code(), $kind->value );
		}
	}

	public function test_the_figures_add_up_as_en16931_checks_them(): void {
		[ $invoice, $details ] = Fixtures::einvoice_en16931();

		$xpath   = $this->xml( array( $invoice, $details ) );
		$totals  = $details->totals;
		$summary = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';

		$lines = 0;

		foreach ( $xpath->query( '//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount' ) ?: array() as $node ) {
			$lines += (int) round( (float) $node->textContent * 100 );
		}

		$allowances = 0;

		foreach ( $xpath->query( '//ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount' ) ?: array() as $node ) {
			$allowances += (int) round( (float) $node->textContent * 100 );
		}

		$tax = 0;

		foreach ( $xpath->query( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount' ) ?: array() as $node ) {
			$tax += (int) round( (float) $node->textContent * 100 );
		}

		$this->assertSame( $totals->subtotal(), $lines );
		$this->assertSame( $totals->subtotal(), self::cents( $xpath, $summary . 'ram:LineTotalAmount' ) );
		$this->assertSame( $totals->discount(), $allowances );
		$this->assertSame( $totals->discount(), self::cents( $xpath, $summary . 'ram:AllowanceTotalAmount' ) );
		$this->assertSame( $totals->net(), self::cents( $xpath, $summary . 'ram:TaxBasisTotalAmount' ) );
		$this->assertSame( $totals->tax(), $tax );
		$this->assertSame( $totals->tax(), self::cents( $xpath, $summary . 'ram:TaxTotalAmount' ) );
		$this->assertSame( 'EUR', $xpath->evaluate( 'string(' . $summary . 'ram:TaxTotalAmount/@currencyID)' ) );
		$this->assertSame( $totals->gross(), self::cents( $xpath, $summary . 'ram:GrandTotalAmount' ) );
		$this->assertSame( $totals->gross(), self::cents( $xpath, $summary . 'ram:DuePayableAmount' ) );
	}

	public function test_each_rate_has_its_breakdown_and_its_share_of_the_discount(): void {
		[ $invoice, $details ] = Fixtures::einvoice_en16931();

		$xpath = $this->xml( array( $invoice, $details ) );

		foreach ( $details->totals->by_rate() as $rate => $figures ) {
			$percent = number_format( (float) $rate, 2, '.', '' );
			$tax     = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:RateApplicablePercent="' . $percent . '"]';
			$lines   = 0;

			foreach ( $xpath->query( '//ram:IncludedSupplyChainTradeLineItem[.//ram:RateApplicablePercent="' . $percent . '"]//ram:LineTotalAmount' ) ?: array() as $node ) {
				$lines += (int) round( (float) $node->textContent * 100 );
			}

			$this->assertSame( 'S', $xpath->evaluate( 'string(' . $tax . '/ram:CategoryCode)' ) );
			$this->assertSame( $figures['net'], self::cents( $xpath, $tax . '/ram:BasisAmount' ), (string) $rate );
			$this->assertSame( $figures['tax'], self::cents( $xpath, $tax . '/ram:CalculatedAmount' ), (string) $rate );
			$this->assertSame( $lines - $figures['net'], self::cents( $xpath, '//ram:SpecifiedTradeAllowanceCharge[.//ram:RateApplicablePercent="' . $percent . '"]/ram:ActualAmount' ), 'BR-S-08 at ' . $rate );
		}

		$this->assertSame( 'Discount (SPRING10)', $xpath->evaluate( 'string(//ram:SpecifiedTradeAllowanceCharge[1]/ram:Reason)' ) );
		$this->assertSame( 'false', $xpath->evaluate( 'string(//ram:SpecifiedTradeAllowanceCharge[1]/ram:ChargeIndicator/udt:Indicator)' ) );
	}

	public function test_a_rate_whose_share_of_the_discount_is_nothing_has_no_allowance(): void {
		$totals = new Totals( array( new TotalsLine( 'Licence', 10000 ), new TotalsLine( 'Sticker', 1, rate: 0 ) ), 'EUR', 20, array( Discount::fixed( 'Voucher', 1 ) ) );
		$xpath  = $this->xml( self::einvoice( totals: $totals ) );

		$this->assertSame( 1.0, $xpath->evaluate( 'count(//ram:SpecifiedTradeAllowanceCharge)' ) );
		$this->assertSame( '20.00', $xpath->evaluate( 'string(//ram:SpecifiedTradeAllowanceCharge//ram:RateApplicablePercent)' ) );
	}

	public function test_a_line_says_what_how_many_at_what_price_and_its_note(): void {
		$xpath = $this->xml( Fixtures::einvoice_en16931() );
		$line  = '//ram:IncludedSupplyChainTradeLineItem';

		$this->assertSame( 3.0, $xpath->evaluate( 'count(' . $line . ')' ) );
		$this->assertSame( '2', $xpath->evaluate( 'string(' . $line . '[2]/ram:AssociatedDocumentLineDocument/ram:LineID)' ) );
		$this->assertSame( 'Vintage Keys', $xpath->evaluate( 'string(' . $line . '[2]//ram:SpecifiedTradeProduct/ram:Name)' ) );
		$this->assertSame( '89.00', $xpath->evaluate( 'string(' . $line . '[2]//ram:NetPriceProductTradePrice/ram:ChargeAmount)' ) );
		$this->assertSame( '2', $xpath->evaluate( 'string(' . $line . '[2]//ram:BilledQuantity)' ) );
		$this->assertSame( 'C62', $xpath->evaluate( 'string(' . $line . '[2]//ram:BilledQuantity/@unitCode)' ) );
		$this->assertSame( '178.00', $xpath->evaluate( 'string(' . $line . '[2]//ram:LineTotalAmount)' ) );
		$this->assertSame( 'Licence period: 18 November 2026 to 17 November 2027', $xpath->evaluate( 'string(' . $line . '[1]//ram:IncludedNote/ram:Content)' ) );
		$this->assertSame( '9.00', $xpath->evaluate( 'string(' . $line . '[3]//ram:RateApplicablePercent)' ) );
	}

	public function test_only_basic_and_en16931_list_lines(): void {
		foreach ( FacturXProfile::cases() as $profile ) {
			$count = $this->xml( self::einvoice( $profile ) )->evaluate( 'count(//ram:IncludedSupplyChainTradeLineItem)' );

			$this->assertSame( $profile->has_lines() ? 1.0 : 0.0, $count, $profile->name );
		}
	}

	public function test_minimum_carries_the_header_and_totals_alone(): void {
		$xpath = $this->xml( Fixtures::einvoice_minimum() );

		$this->assertSame( 0.0, $xpath->evaluate( 'count(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax)' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//ram:LineTotalAmount)' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//ram:BuyerTradeParty/ram:PostalTradeAddress)' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//ram:ApplicableHeaderTradeDelivery/*)' ) );
		$this->assertSame( 'IE', $xpath->evaluate( 'string(//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID)' ) );
		$this->assertSame( 'IE1234567X', $xpath->evaluate( 'string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"])' ) );
		$this->assertSame( '1497.00', $xpath->evaluate( 'string(//ram:DuePayableAmount)' ) );
	}

	public function test_the_parties_are_written_in_full(): void {
		$xpath  = $this->xml( Fixtures::einvoice_en16931() );
		$seller = '//ram:SellerTradeParty/';
		$buyer  = '//ram:BuyerTradeParty/';

		$this->assertSame( 'Northwind Audio Ltd', $xpath->evaluate( 'string(' . $seller . 'ram:Name)' ) );
		$this->assertSame( '654321', $xpath->evaluate( 'string(' . $seller . 'ram:SpecifiedLegalOrganization/ram:ID)' ) );
		$this->assertSame( '12 Example Street', $xpath->evaluate( 'string(' . $seller . 'ram:PostalTradeAddress/ram:LineOne)' ) );
		$this->assertSame( 'Dublin 2, D02 X285', $xpath->evaluate( 'string(' . $seller . 'ram:PostalTradeAddress/ram:LineTwo)' ) );
		$this->assertSame( 'Ireland', $xpath->evaluate( 'string(' . $seller . 'ram:PostalTradeAddress/ram:LineThree)' ) );
		$this->assertSame( 'accounts@northwind.example', $xpath->evaluate( 'string(' . $seller . 'ram:URIUniversalCommunication/ram:URIID[@schemeID="EM"])' ) );
		$this->assertSame( 'Klangwerk GmbH', $xpath->evaluate( 'string(' . $buyer . 'ram:Name)' ) );
		$this->assertSame( 'DE', $xpath->evaluate( 'string(' . $buyer . 'ram:PostalTradeAddress/ram:CountryID)' ) );
		$this->assertSame( 'DE811907980', $xpath->evaluate( 'string(' . $buyer . 'ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"])' ) );
		$this->assertSame( 'PO-77812', $xpath->evaluate( 'string(//ram:BuyerReference)' ) );
	}

	public function test_an_address_of_more_than_three_lines_keeps_every_line(): void {
		$buyer = new Party( 'Klangwerk GmbH', array( 'Haus B', 'Torstraße 109', '10119 Berlin', 'Germany' ), tax_id: 'DE811907980', country: 'de' );
		$xpath = $this->xml( self::einvoice( buyer: $buyer ) );

		$this->assertSame( '10119 Berlin, Germany', $xpath->evaluate( 'string(//ram:BuyerTradeParty//ram:LineThree)' ) );
		$this->assertSame( 'DE', $xpath->evaluate( 'string(//ram:BuyerTradeParty//ram:CountryID)' ) );
	}

	public function test_a_self_billed_invoice_puts_the_supplier_it_is_addressed_to_as_seller(): void {
		$supplier = new Party( 'Session Player Ltd', array( '1 High Street', 'Cork' ), tax_id: 'IE7654321Y', country: 'IE' );
		$xpath    = $this->xml( self::einvoice( invoice: array( 'kind' => DocumentKind::SelfBilling, 'to' => $supplier ) ) );

		$this->assertSame( 'Session Player Ltd', $xpath->evaluate( 'string(//ram:SellerTradeParty/ram:Name)' ) );
		$this->assertSame( 'Northwind Audio Ltd', $xpath->evaluate( 'string(//ram:BuyerTradeParty/ram:Name)' ) );
	}

	public function test_a_credit_note_cites_the_invoice_it_reverses(): void {
		$xpath = $this->xml( Fixtures::einvoice_basic_wl() );

		$this->assertSame( '381', $xpath->evaluate( 'string(//rsm:ExchangedDocument/ram:TypeCode)' ) );
		$this->assertSame( 'INV-2026-0060', $xpath->evaluate( 'string(//ram:InvoiceReferencedDocument/ram:IssuerAssignedID)' ) );
		$this->assertSame( 0.0, $this->xml( self::einvoice( FacturXProfile::Minimum, invoice: array( 'kind' => DocumentKind::CreditNote, 'reference' => 'INV-1' ) ) )->evaluate( 'count(//ram:InvoiceReferencedDocument)' ) );
	}

	/** @return array<string, array{0: VatTreatment, 1: string, 2: string}> */
	public static function categories(): array {
		return array(
			'reverse charge'  => array( VatTreatment::ReverseCharge, 'AE', 'VATEX-EU-AE' ),
			'intra-community' => array( VatTreatment::IntraCommunitySupply, 'K', 'VATEX-EU-IC' ),
			'export'          => array( VatTreatment::Export, 'G', 'VATEX-EU-G' ),
			'small business'  => array( VatTreatment::SmallBusiness, 'E', '' ),
			'exempt'          => array( VatTreatment::Exempt, 'E', '' ),
		);
	}

	#[DataProvider( 'categories' )]
	public function test_a_treatment_without_vat_states_its_category_and_reason( VatTreatment $vat, string $code, string $reason_code ): void {
		$xpath = $this->xml(
			self::einvoice(
				invoice: array( 'vat' => $vat ),
				details: array( 'delivered' => new \DateTimeImmutable( '2026-11-17' ) ),
				totals: new Totals( array( new TotalsLine( 'Hardware', 50000 ) ), 'EUR' )
			)
		);
		$tax   = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/';

		$this->assertSame( $code, $xpath->evaluate( 'string(' . $tax . 'ram:CategoryCode)' ) );
		$this->assertSame( $code, $xpath->evaluate( 'string(//ram:IncludedSupplyChainTradeLineItem//ram:CategoryCode)' ) );
		$this->assertSame( implode( ' ', $vat->notes() ), $xpath->evaluate( 'string(' . $tax . 'ram:ExemptionReason)' ) );
		$this->assertSame( $reason_code, $xpath->evaluate( 'string(' . $tax . 'ram:ExemptionReasonCode)' ) );
		$this->assertSame( '0.00', $xpath->evaluate( 'string(' . $tax . 'ram:RateApplicablePercent)' ) );
		$this->assertSame( '0.00', $xpath->evaluate( 'string(' . $tax . 'ram:CalculatedAmount)' ) );
	}

	public function test_a_zero_rate_on_a_standard_invoice_is_zero_rated_not_standard(): void {
		$xpath = $this->xml( self::einvoice( totals: new Totals( array( new TotalsLine( 'Book', 2000 ), new TotalsLine( 'Licence', 1000, rate: 20 ) ), 'GBP', 0 ) ) );

		$this->assertSame( 'Z', $xpath->evaluate( 'string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:RateApplicablePercent="0.00"]/ram:CategoryCode)' ) );
		$this->assertSame( 'S', $xpath->evaluate( 'string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:RateApplicablePercent="20.00"]/ram:CategoryCode)' ) );
		$this->assertSame( 0.0, $xpath->evaluate( 'count(//ram:ExemptionReason)' ) );
	}

	public function test_a_fractional_rate_keeps_its_digits(): void {
		$xpath = $this->xml( self::einvoice( totals: new Totals( array( new TotalsLine( 'x', 10000 ) ), 'USD', '8.875' ) ) );

		$this->assertSame( '8.875', $xpath->evaluate( 'string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent)' ) );
	}

	public function test_amounts_have_the_currency_s_own_decimals(): void {
		$xpath = $this->xml( self::einvoice( totals: new Totals( array( new TotalsLine( 'Plug-in', 12000 ) ), 'JPY', 10 ) ) );

		$this->assertSame( 'JPY', $xpath->evaluate( 'string(//ram:InvoiceCurrencyCode)' ) );
		$this->assertSame( '13200', $xpath->evaluate( 'string(//ram:GrandTotalAmount)' ) );
	}

	public function test_dates_are_the_seller_s_own_day(): void {
		$late  = new \DateTimeImmutable( '2026-11-18 23:30', new \DateTimeZone( 'America/New_York' ) );
		$xpath = $this->xml( self::einvoice( details: array( 'issued' => $late, 'due' => $late->modify( '+30 days' ), 'delivered' => $late ) ) );

		$this->assertSame( '20261118', $xpath->evaluate( 'string(//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString[@format="102"])' ) );
		$this->assertSame( '20261218', $xpath->evaluate( 'string(//ram:DueDateDateTime/udt:DateTimeString)' ) );
		$this->assertSame( '20261118', $xpath->evaluate( 'string(//ram:ActualDeliverySupplyChainEvent//udt:DateTimeString)' ) );
	}

	public function test_payment_terms_and_what_was_already_paid(): void {
		$xpath = $this->xml( self::einvoice( details: array( 'prepaid' => 2000, 'payment_terms' => 'Balance within 14 days' ) ) );

		$this->assertSame( 'Balance within 14 days', $xpath->evaluate( 'string(//ram:SpecifiedTradePaymentTerms/ram:Description)' ) );
		$this->assertSame( '20.00', $xpath->evaluate( 'string(//ram:TotalPrepaidAmount)' ) );
		$this->assertSame( '101.77', $xpath->evaluate( 'string(//ram:DuePayableAmount)' ) );
	}

	public function test_the_goods_go_to_the_buyer_s_country_unless_told_otherwise(): void {
		$this->assertSame( 'DE', $this->xml( self::einvoice() )->evaluate( 'string(//ram:ShipToTradeParty//ram:CountryID)' ) );
		$this->assertSame( 'AT', $this->xml( self::einvoice( details: array( 'delivered_to' => 'at' ) ) )->evaluate( 'string(//ram:ShipToTradeParty//ram:CountryID)' ) );
	}

	public function test_text_is_escaped_and_survives_as_written(): void {
		$buyer = new Party( "Smith & Jones <Ltd> \"Łódź\"\x01", array( 'a' ), tax_id: 'DE1', country: 'DE' );
		$xpath = $this->xml( self::einvoice( buyer: $buyer ) );

		$this->assertSame( 'Smith & Jones <Ltd> "Łódź"', $xpath->evaluate( 'string(//ram:BuyerTradeParty/ram:Name)' ) );
	}

	/**
	 * U+FFFE and U+FFFF are valid UTF-8 but not characters XML 1.0 allows:
	 * a buyer who typed one into their name made every e-invoice to them a
	 * file no parser would open.
	 */
	public function test_a_non_character_cannot_make_the_xml_unreadable(): void {
		$buyer    = new Party( "Smith\u{FFFF} \u{FFFE}Ltd", array( "a\u{FFFF}" ), tax_id: "DE1\u{FFFE}", country: 'DE', email: "b\u{FFFF}@example.com" );
		$einvoice = self::einvoice( buyer: $buyer );
		$dom      = new \DOMDocument();

		libxml_use_internal_errors( true );
		$loaded = $dom->loadXML( $einvoice[0]->factur_x_xml( $einvoice[1] ) );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		$this->assertTrue( $loaded, 'The XML is well-formed.' );
		$this->assertSame( "Smith\u{FFFD} \u{FFFD}Ltd", ( new \DOMXPath( $dom ) )->evaluate( 'string(//*[local-name()="BuyerTradeParty"]/*[local-name()="Name"])' ) );
	}

	/**
	 * CII declares sequences: the first element out of place is a
	 * rejection, so the order is pinned here as well as by the schema.
	 */
	public function test_the_settlement_is_in_the_order_cii_declares(): void {
		$xpath = $this->xml( self::einvoice( invoice: array( 'kind' => DocumentKind::CreditNote, 'reference' => 'INV-1' ), totals: new Totals( array( new TotalsLine( 'x', 1000 ) ), 'EUR', 20, array( Discount::fixed( 'Voucher', 100 ) ) ) ) );
		$names = array();

		foreach ( $xpath->query( '//ram:ApplicableHeaderTradeSettlement/*' ) ?: array() as $node ) {
			$names[] = $node->localName;
		}

		$this->assertSame( array( 'InvoiceCurrencyCode', 'ApplicableTradeTax', 'SpecifiedTradeAllowanceCharge', 'SpecifiedTradePaymentTerms', 'SpecifiedTradeSettlementHeaderMonetarySummation', 'InvoiceReferencedDocument' ), $names );

		$names = array();

		foreach ( $xpath->query( '//rsm:SupplyChainTradeTransaction/*' ) ?: array() as $node ) {
			$names[] = $node->localName;
		}

		$this->assertSame( array( 'IncludedSupplyChainTradeLineItem', 'ApplicableHeaderTradeAgreement', 'ApplicableHeaderTradeDelivery', 'ApplicableHeaderTradeSettlement' ), $names );
	}

	public function test_every_fixture_passes_the_cii_schema(): void {
		$xsd = Validators::xsd();

		foreach ( Fixtures::einvoices() as $name => [ $invoice, $details ] ) {
			$dom = new \DOMDocument();
			$dom->loadXML( $invoice->factur_x_xml( $details ) );

			libxml_use_internal_errors( true );
			$valid  = $dom->schemaValidate( $xsd );
			$errors = array_map( static fn( \LibXMLError $error ): string => trim( $error->message ), libxml_get_errors() );
			libxml_clear_errors();
			libxml_use_internal_errors( false );

			$this->assertTrue( $valid, $name . ': ' . implode( '; ', $errors ) );
		}
	}

	/* ─── The PDF ───────────────────────────────────────────────────── */

	public function test_the_document_is_archival_and_carries_the_xml(): void {
		foreach ( Fixtures::einvoices() as $name => [ $invoice, $details ] ) {
			$pdf = $invoice->factur_x( $details );

			$this->assertTrue( $pdf->is_archival(), $name );
			$this->assertCount( 1, $pdf->attachments(), $name );

			$file = $pdf->attachments()[0];

			$this->assertSame( 'factur-x.xml', $file->name );
			$this->assertSame( 'text/xml', $file->mime_type );
			$this->assertSame( $invoice->factur_x_xml( $details ), $file->contents );
			$this->assertSame( $details->profile->relationship(), $file->relationship, $name );
			$this->assertSame( $details->issued->getTimestamp(), $file->modified );
		}
	}

	public function test_the_relationship_follows_the_profile(): void {
		$this->assertSame( Relationship::Data, FacturXProfile::Minimum->relationship() );
		$this->assertSame( Relationship::Data, FacturXProfile::BasicWl->relationship() );
		$this->assertSame( Relationship::Alternative, FacturXProfile::Basic->relationship() );
		$this->assertSame( Relationship::Alternative, FacturXProfile::En16931->relationship() );

		$plain = Reader::plain( Fixtures::einvoice_bytes( Fixtures::einvoice_minimum() ) );

		$this->assertStringContainsString( '/AFRelationship /Data', $plain );
		$this->assertStringContainsString( '/AFRelationship /Alternative', Reader::plain( Fixtures::einvoice_bytes( Fixtures::einvoice_en16931() ) ) );
	}

	public function test_the_xmp_packet_declares_the_factur_x_extension_and_level(): void {
		foreach ( Fixtures::einvoices() as $name => $einvoice ) {
			$plain = Reader::plain( Fixtures::einvoice_bytes( $einvoice ) );

			$this->assertSame( 1, preg_match( '/<\?xpacket begin=.*?<\?xpacket end="w"\?>/s', $plain, $packet ), $name );

			$dom = new \DOMDocument();
			$this->assertTrue( $dom->loadXML( $packet[0] ), $name );

			$xpath = new \DOMXPath( $dom );
			$xpath->registerNamespace( 'fx', 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#' );
			$xpath->registerNamespace( 'pdfaExtension', 'http://www.aiim.org/pdfa/ns/extension/' );
			$xpath->registerNamespace( 'pdfaSchema', 'http://www.aiim.org/pdfa/ns/schema#' );
			$xpath->registerNamespace( 'pdfaProperty', 'http://www.aiim.org/pdfa/ns/property#' );
			$xpath->registerNamespace( 'pdfaid', 'http://www.aiim.org/pdfa/ns/id/' );
			$xpath->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );

			$this->assertSame( 'INVOICE', $xpath->evaluate( 'string(//fx:DocumentType)' ) );
			$this->assertSame( 'factur-x.xml', $xpath->evaluate( 'string(//fx:DocumentFileName)' ) );
			$this->assertSame( '1.0', $xpath->evaluate( 'string(//fx:Version)' ) );
			$this->assertSame( $einvoice[1]->profile->conformance_level(), $xpath->evaluate( 'string(//fx:ConformanceLevel)' ) );
			$this->assertSame( 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#', $xpath->evaluate( 'string(//pdfaSchema:namespaceURI)' ) );
			$this->assertSame( 'fx', $xpath->evaluate( 'string(//pdfaSchema:prefix)' ) );

			$declared = array();

			foreach ( $xpath->query( '//pdfaSchema:property/rdf:Seq/rdf:li/pdfaProperty:name' ) ?: array() as $node ) {
				$declared[] = $node->textContent;
			}

			sort( $declared );

			$this->assertSame( array( 'ConformanceLevel', 'DocumentFileName', 'DocumentType', 'Version' ), $declared, $name );
			$this->assertSame( '3', $xpath->evaluate( 'string(//pdfaid:part)' ) );
		}
	}

	public function test_the_page_is_the_invoice_the_xml_describes(): void {
		[ $invoice, $details ] = Fixtures::einvoice_basic();

		$text = Reader::squeezed( Reader::text( Fixtures::einvoice_bytes( array( $invoice, $details ) ) ) );

		foreach ( array( 'Wytwórnia Dźwięku Łódź Sp. z o.o.', 'PL7251234567', 'IE1234567X', 'INV-2026-0061', $details->totals->format( $details->totals->gross() ) ) as $expected ) {
			$this->assertStringContainsString( Reader::squeezed( $expected ), $text );
		}

		$this->assertSame( array(), $invoice->compliance_warnings_verifying( $invoice->factur_x( $details ) ) );
	}

	public function test_an_ordinary_render_is_not_archival(): void {
		[ $invoice ] = Fixtures::einvoice_en16931();

		$this->assertFalse( $invoice->render()->is_archival() );
		$this->assertTrue( $invoice->render( true )->is_archival() );
		$this->assertSame( array(), ( new Document() )->attachments() );
	}
}
