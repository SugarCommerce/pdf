<?php
/**
 * The Cross Industry Invoice XML Factur-X carries.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

use SugarCommerce\Money\{Currencies, Money};
use SugarCommerce\Pdf\Archival;

/**
 * Class FacturXWriter
 *
 * Checks an invoice against what an e-invoice must carry, and writes it
 * as UN/CEFACT Cross Industry Invoice D16B XML in one of the four
 * {@see FacturXProfile}s. Reached through {@see Invoice::factur_x()}.
 *
 * The order of every element is CII's, not this file's: the schema
 * declares sequences, so a reader stops at the first element out of
 * place and rejects the file. Optional elements are left out rather than
 * written empty — some validators flag "present but empty" louder than
 * absent.
 *
 * @since 1.5.0
 */
final class FacturXWriter {

	/**
	 * The file name Factur-X requires, exactly.
	 */
	public const FILE_NAME = 'factur-x.xml';

	/**
	 * Unit of measure for a quantity: UN/ECE Recommendation 20's "one".
	 */
	private const UNIT = 'C62';

	/**
	 * What stops the invoice being sent as an e-invoice.
	 *
	 * The particulars an e-invoice must carry are stricter than the ones a
	 * printed invoice must, and a validator enforces every one: each check
	 * names the EN 16931 rule it answers to.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice as printed.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<string> One sentence each; empty when it can be sent.
	 */
	public static function problems( Invoice $invoice, FacturX $details ): array {
		if ( null === $invoice->kind->factur_x_type_code() ) {
			return array( 'A ' . strtolower( $invoice->kind->title() ) . ' is not an invoice, so it has no e-invoice form. Factur-X covers invoices, credit and debit notes, proformas and self-billed invoices.' );
		}

		[ $seller, $buyer ] = self::parties( $invoice );

		$profile  = $details->profile;
		$totals   = $details->totals;
		$problems = array();

		if ( '' === trim( $invoice->number ) ) {
			$problems[] = 'It has no number (BR-02).';
		}

		if ( '' === trim( $seller->name ) ) {
			$problems[] = 'The seller has no name (BR-06).';
		}

		if ( '' === trim( $buyer->name ) ) {
			$problems[] = 'The buyer has no name (BR-07).';
		}

		if ( '' === trim( $seller->tax_id ) ) {
			$problems[] = 'The seller has no VAT number, which an e-invoice must carry (BR-CO-26).';
		}

		if ( '' === self::country( $seller->country ) ) {
			$problems[] = 'The seller has no country. EN 16931 requires one in the address (BR-09): set Party(country:), which is not printed.';
		}

		if ( FacturXProfile::Minimum !== $profile && '' === self::country( $buyer->country ) ) {
			$problems[] = 'The buyer has no country. The ' . $profile->conformance_level() . ' profile carries their address, and EN 16931 requires a country in it (BR-11).';
		}

		// EN 16931 caps every document amount at two decimals (BR-DEC-09 to
		// BR-DEC-20) whatever the currency's own minor unit. Refused rather
		// than rounded: rounding would make the XML disagree with the page
		// by up to half a fils, invisibly, until somebody reconciled them.
		if ( Currencies::decimals( $totals->currency ) > 2 ) {
			$problems[] = 'An e-invoice cannot be written in ' . $totals->currency . ': it has ' . Currencies::decimals( $totals->currency ) . ' decimal places and EN 16931 allows two (BR-DEC-09 to BR-DEC-20), so the amounts would lose a digit the currency uses.';
		}

		if ( $invoice->vat->requires_both_vat_numbers() && '' === trim( $buyer->tax_id ) ) {
			$problems[] = VatTreatment::ReverseCharge === $invoice->vat
				? "Reverse charge needs the buyer's VAT number (BR-AE-02)."
				: "An intra-community supply needs the buyer's VAT number (BR-IC-02).";
		}

		// The zero rate on an intra-community supply rests on the goods
		// having crossed a border, so the document says when — and where,
		// which is the buyer's country (required above) or `delivered_to`.
		if ( VatTreatment::IntraCommunitySupply === $invoice->vat && FacturXProfile::Minimum !== $profile && null === $details->delivered ) {
			$problems[] = 'An intra-community supply needs the date the goods or services were supplied (BR-IC-11): set FacturX(delivered:).';
		}

		if ( '' !== trim( $details->delivered_to ) && '' === self::country( $details->delivered_to ) ) {
			$problems[] = '"' . $details->delivered_to . '" is not a two-letter country code, which is what FacturX(delivered_to:) takes (BR-IC-12).';
		}

		try {
			$problems = array_merge( $problems, self::figure_problems( $invoice, $details ) );
		} catch ( \OverflowException $error ) {
			$problems[] = $error->getMessage();
		}

		return $problems;
	}

	/**
	 * The invoice as Factur-X XML.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice as printed.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return string UTF-8 XML.
	 *
	 * @throws FacturXException When {@see problems()} finds any.
	 */
	public static function xml( Invoice $invoice, FacturX $details ): string {
		$problems = self::problems( $invoice, $details );

		if ( array() !== $problems ) {
			throw new FacturXException( $problems );
		}

		[ $seller, $buyer ] = self::parties( $invoice );

		$profile = $details->profile;
		$minimum = FacturXProfile::Minimum === $profile;
		$totals  = $details->totals;
		$amount  = static fn( int $minor ): string => Money::decimal( $minor, $totals->currency, false );
		$lines   = $profile->has_lines() ? self::lines( $invoice, $details ) : array();

		$agreement = array_merge(
			'' !== trim( $details->buyer_reference ) ? array( array( 'ram:BuyerReference', trim( $details->buyer_reference ) ) ) : array(),
			array(
				array( 'ram:SellerTradeParty', self::party( $seller, true, $minimum ) ),
				array( 'ram:BuyerTradeParty', self::party( $buyer, false, $minimum ) ),
			)
		);

		$summation = array_merge(
			$minimum ? array() : array( array( 'ram:LineTotalAmount', $amount( $totals->subtotal() ) ) ),
			! $minimum && $totals->discount() > 0 ? array( array( 'ram:AllowanceTotalAmount', $amount( $totals->discount() ) ) ) : array(),
			array(
				array( 'ram:TaxBasisTotalAmount', $amount( $totals->net() ) ),
				array( 'ram:TaxTotalAmount', $amount( $totals->tax() ), array( 'currencyID' => $totals->currency ) ),
				array( 'ram:GrandTotalAmount', $amount( $totals->gross() ) ),
			),
			! $minimum && 0 !== $details->prepaid ? array( array( 'ram:TotalPrepaidAmount', $amount( $details->prepaid ) ) ) : array(),
			array( array( 'ram:DuePayableAmount', $amount( $details->payable() ) ) )
		);

		$settlement = array_merge(
			array( array( 'ram:InvoiceCurrencyCode', $totals->currency ) ),
			$minimum ? array() : self::taxes( $invoice, $details ),
			$minimum ? array() : self::allowances( $invoice, $details ),
			$minimum ? array() : self::terms( $details ),
			array( array( 'ram:SpecifiedTradeSettlementHeaderMonetarySummation', $summation ) ),
			! $minimum && $invoice->kind->requires_reference() && '' !== trim( $invoice->reference )
				? array( array( 'ram:InvoiceReferencedDocument', array( array( 'ram:IssuerAssignedID', trim( $invoice->reference ) ) ) ) )
				: array()
		);

		$transaction = array_merge(
			$lines,
			array(
				array( 'ram:ApplicableHeaderTradeAgreement', $agreement ),
				array( 'ram:ApplicableHeaderTradeDelivery', $minimum ? array() : self::delivery( $details, $buyer ) ),
				array( 'ram:ApplicableHeaderTradeSettlement', $settlement ),
			)
		);

		$root = array(
			'rsm:CrossIndustryInvoice',
			array(
				array( 'rsm:ExchangedDocumentContext', array( array( 'ram:GuidelineSpecifiedDocumentContextParameter', array( array( 'ram:ID', $profile->value ) ) ) ) ),
				array(
					'rsm:ExchangedDocument',
					array(
						array( 'ram:ID', trim( $invoice->number ) ),
						array( 'ram:TypeCode', (string) $invoice->kind->factur_x_type_code() ),
						array( 'ram:IssueDateTime', array( self::date( $details->issued ) ) ),
					),
				),
				array( 'rsm:SupplyChainTradeTransaction', $transaction ),
			),
			array(
				'xmlns:rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
				'xmlns:ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
				'xmlns:udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
			),
		);

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::element( $root, 0 );
	}

	/**
	 * The Factur-X half of the XMP packet.
	 *
	 * PDF/A only permits XMP properties the file declares a schema for, so
	 * this is the extension schema from the specification, verbatim, and
	 * then the four `fx:` properties a validator reads to know what is
	 * attached without opening it.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturXProfile $profile The profile the XML declares.
	 *
	 * @return string Two `rdf:Description` elements.
	 */
	public static function xmp( FacturXProfile $profile ): string {
		$property = static fn( string $name, string $description ): string => '          <rdf:li rdf:parseType="Resource"><pdfaProperty:name>' . $name
			. '</pdfaProperty:name><pdfaProperty:valueType>Text</pdfaProperty:valueType><pdfaProperty:category>external</pdfaProperty:category><pdfaProperty:description>'
			. $description . "</pdfaProperty:description></rdf:li>\n";

		return '    <rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">' . "\n"
			. "      <pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType=\"Resource\">\n"
			. "        <pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>\n"
			. "        <pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>\n"
			. "        <pdfaSchema:prefix>fx</pdfaSchema:prefix>\n"
			. "        <pdfaSchema:property><rdf:Seq>\n"
			. $property( 'DocumentFileName', 'The name of the embedded XML document' )
			. $property( 'DocumentType', 'The type of the hybrid document in capital letters, e.g. INVOICE or ORDER' )
			. $property( 'Version', 'The actual version of the standard applying to the embedded XML document' )
			. $property( 'ConformanceLevel', 'The conformance level of the embedded XML document' )
			. "        </rdf:Seq></pdfaSchema:property>\n"
			. "      </rdf:li></rdf:Bag></pdfaExtension:schemas>\n"
			. "    </rdf:Description>\n"
			. '    <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">' . "\n"
			. "      <fx:DocumentType>INVOICE</fx:DocumentType>\n"
			. '      <fx:DocumentFileName>' . self::FILE_NAME . "</fx:DocumentFileName>\n"
			. "      <fx:Version>1.0</fx:Version>\n"
			. '      <fx:ConformanceLevel>' . $profile->conformance_level() . "</fx:ConformanceLevel>\n"
			. '    </rdf:Description>';
	}

	/* ─── Checks ────────────────────────────────────────────────────── */

	/**
	 * Where the figures disagree with themselves, with the profile, or with
	 * the page.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice as printed.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<string>
	 *
	 * @throws \OverflowException When a total is too large to hold exactly.
	 */
	private static function figure_problems( Invoice $invoice, FacturX $details ): array {
		$profile  = $details->profile;
		$totals   = $details->totals;
		$problems = array();

		if ( $totals->net() < 0 ) {
			$problems[] = 'The invoice is for less than nothing. A refund is a credit note with positive amounts, not a minus sign.';
		}

		if ( $details->prepaid < 0 || $details->prepaid > $totals->gross() ) {
			$problems[] = 'The amount already paid, ' . $totals->format( $details->prepaid ) . ', is not between nothing and the invoice\'s ' . $totals->format( $totals->gross() ) . '.';
		}

		// Only a standard-rated invoice charges VAT: under every other
		// treatment the liability is elsewhere or there is none, and the
		// category code the XML gives says so.
		foreach ( array_keys( $totals->by_rate() ) as $rate ) {
			if ( VatTreatment::Standard !== $invoice->vat && 0.0 !== (float) $rate ) {
				$problems[] = 'Under this VAT treatment no VAT is charged, and the figures charge it at ' . $rate . '%.';
			}
		}

		if ( FacturXProfile::Minimum !== $profile && $details->payable() > 0 && null === $details->due && '' === trim( $details->payment_terms ) ) {
			$problems[] = 'Something is left to pay, so the e-invoice needs a due date or payment terms (BR-CO-25): set FacturX(due:) or FacturX(payment_terms:).';
		}

		if ( $profile->has_lines() ) {
			if ( array() === $totals->lines ) {
				$problems[] = 'It has no lines, and the ' . $profile->conformance_level() . ' profile lists them (BR-16).';
			}

			foreach ( $totals->lines as $index => $line ) {
				if ( $line->unit_price < 0 ) {
					$problems[] = 'Line ' . ( $index + 1 ) . ' ("' . $line->description . '") has a negative price, which EN 16931 does not allow (BR-27). Give it as a Discount.';
				}

				if ( '' === trim( $line->description ) ) {
					$problems[] = 'Line ' . ( $index + 1 ) . ' has no description (BR-25).';
				}
			}

			if ( count( $invoice->items ) !== count( $totals->lines ) ) {
				$problems[] = 'The page lists ' . count( $invoice->items ) . ' lines and the figures ' . count( $totals->lines ) . ': print the items from the same Totals.';
			}
		}

		// The page and the XML must be the same invoice. The headline total
		// is the figure a customer and an auditor both read first.
		$printed = array_column( Rows::pairs( $invoice->total ), 1 );
		$expect  = array( $totals->format( $totals->gross() ), $totals->format( $details->payable() ) );

		if ( array() === $printed ) {
			$problems[] = 'The page prints no total, so nothing shows the XML\'s ' . $expect[0] . ' to a person.';
		} elseif ( array() === array_intersect( $printed, $expect ) ) {
			$problems[] = 'The page shows the total as ' . implode( ', ', $printed ) . ' and the figures make it ' . $expect[0] . ': print the totals from the same Totals.';
		}

		return $problems;
	}

	/* ─── Pieces ────────────────────────────────────────────────────── */

	/**
	 * The seller and the buyer, in that order.
	 *
	 * On a self-billed invoice the customer raises the supplier's invoice,
	 * so the document's own party is the buyer and the one it is addressed
	 * to is the seller.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice.
	 *
	 * @return array{0: Party, 1: Party}
	 */
	private static function parties( Invoice $invoice ): array {
		return DocumentKind::SelfBilling === $invoice->kind
			? array( $invoice->to, $invoice->seller() )
			: array( $invoice->seller(), $invoice->to );
	}

	/**
	 * A two-letter country code, upper case, or empty when it is not one.
	 *
	 * @since 1.5.0
	 *
	 * @param string $code As given.
	 *
	 * @return string
	 */
	private static function country( string $code ): string {
		$code = strtoupper( trim( $code ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * A trade party: name, registration, address, email and VAT number.
	 *
	 * The Minimum profile carries the seller's address and VAT number and
	 * only the buyer's name.
	 *
	 * @since 1.5.0
	 *
	 * @param Party $party   The party.
	 * @param bool  $seller  Whether it is the seller.
	 * @param bool  $minimum Whether the profile is Minimum.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function party( Party $party, bool $seller, bool $minimum ): array {
		$nodes = array( array( 'ram:Name', trim( $party->name ) ) );

		if ( $seller && '' !== trim( $party->registration ) ) {
			$nodes[] = array( 'ram:SpecifiedLegalOrganization', array( array( 'ram:ID', trim( $party->registration ) ) ) );
		}

		if ( $seller || ! $minimum ) {
			$lines   = array_values( array_filter( array_map( static fn( mixed $line ): string => trim( (string) $line ), $party->address ), static fn( string $line ): bool => '' !== $line ) );
			$address = array();

			// Three lines is what the format has; anything beyond the third
			// joins it rather than being lost.
			foreach ( array( 'ram:LineOne', 'ram:LineTwo', 'ram:LineThree' ) as $index => $name ) {
				$line = 2 === $index ? implode( ', ', array_slice( $lines, 2 ) ) : ( $lines[ $index ] ?? '' );

				if ( '' !== $line ) {
					$address[] = array( $name, $line );
				}
			}

			$address[] = array( 'ram:CountryID', self::country( $party->country ) );
			$nodes[]   = array( 'ram:PostalTradeAddress', $address );
		}

		if ( ! $minimum && '' !== trim( $party->email ) ) {
			$nodes[] = array( 'ram:URIUniversalCommunication', array( array( 'ram:URIID', trim( $party->email ), array( 'schemeID' => 'EM' ) ) ) );
		}

		if ( '' !== trim( $party->tax_id ) && ( $seller || ! $minimum ) ) {
			$nodes[] = array( 'ram:SpecifiedTaxRegistration', array( array( 'ram:ID', trim( $party->tax_id ), array( 'schemeID' => 'VA' ) ) ) );
		}

		return $nodes;
	}

	/**
	 * Every line: what it is, its price, how many and its VAT.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function lines( Invoice $invoice, FacturX $details ): array {
		$totals = $details->totals;
		$nodes  = array();

		foreach ( array_values( $totals->lines ) as $index => $line ) {
			$rate = Totals::percentage( $line->rate ?? $totals->rate );

			$document = array( array( 'ram:LineID', (string) ( $index + 1 ) ) );

			if ( '' !== trim( $line->note ) ) {
				$document[] = array( 'ram:IncludedNote', array( array( 'ram:Content', trim( $line->note ) ) ) );
			}

			$nodes[] = array(
				'ram:IncludedSupplyChainTradeLineItem',
				array(
					array( 'ram:AssociatedDocumentLineDocument', $document ),
					array( 'ram:SpecifiedTradeProduct', array( array( 'ram:Name', trim( $line->description ) ) ) ),
					array( 'ram:SpecifiedLineTradeAgreement', array( array( 'ram:NetPriceProductTradePrice', array( array( 'ram:ChargeAmount', Money::decimal( $line->unit_price, $totals->currency, false ) ) ) ) ) ),
					array( 'ram:SpecifiedLineTradeDelivery', array( array( 'ram:BilledQuantity', (string) $line->quantity, array( 'unitCode' => self::UNIT ) ) ) ),
					array(
						'ram:SpecifiedLineTradeSettlement',
						array(
							array( 'ram:ApplicableTradeTax', self::category_nodes( $invoice->vat, $rate ) ),
							array( 'ram:SpecifiedTradeSettlementLineMonetarySummation', array( array( 'ram:LineTotalAmount', Money::decimal( $line->amount(), $totals->currency, false ) ) ) ),
						),
					),
				),
			);
		}

		return $nodes;
	}

	/**
	 * The VAT breakdown: per rate, the taxable amount, the tax and why.
	 *
	 * A category other than standard carries its reason — a system that
	 * sees a zero with no reason files it as a mistake.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function taxes( Invoice $invoice, FacturX $details ): array {
		$totals = $details->totals;
		$nodes  = array();

		foreach ( $totals->by_rate() as $rate => $figures ) {
			[ $code, $reason, $reason_code ] = self::category( $invoice->vat, (string) $rate );

			$nodes[] = array(
				'ram:ApplicableTradeTax',
				array_merge(
					array(
						array( 'ram:CalculatedAmount', Money::decimal( $figures['tax'], $totals->currency, false ) ),
						array( 'ram:TypeCode', 'VAT' ),
					),
					'' !== $reason ? array( array( 'ram:ExemptionReason', $reason ) ) : array(),
					array(
						array( 'ram:BasisAmount', Money::decimal( $figures['net'], $totals->currency, false ) ),
						array( 'ram:CategoryCode', $code ),
					),
					'' !== $reason_code ? array( array( 'ram:ExemptionReasonCode', $reason_code ) ) : array(),
					array( array( 'ram:RateApplicablePercent', self::rate( (string) $rate ) ) )
				),
			);
		}

		return $nodes;
	}

	/**
	 * The discounts, one allowance per rate they were shared across.
	 *
	 * EN 16931 gives every document-level allowance a VAT category and
	 * rate (BR-32, BR-33), so a discount on an invoice with two rates is
	 * two allowances — the same shares {@see Totals} took off each rate.
	 *
	 * @since 1.5.0
	 *
	 * @param Invoice $invoice The invoice.
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function allowances( Invoice $invoice, FacturX $details ): array {
		$totals = $details->totals;

		if ( 0 === $totals->discount() ) {
			return array();
		}

		$before = array();

		foreach ( $totals->lines as $line ) {
			$rate             = Totals::percentage( $line->rate ?? $totals->rate );
			$before[ $rate ]  = ( $before[ $rate ] ?? 0 ) + $line->amount();
		}

		$labels = array_filter( array_map( static fn( Discount $discount ): string => trim( $discount->label ), $totals->discounts ), static fn( string $label ): bool => '' !== $label );
		$reason = array() !== $labels ? implode( ', ', $labels ) : 'Discount';
		$nodes  = array();

		foreach ( $totals->by_rate() as $rate => $figures ) {
			$share = ( $before[ (string) $rate ] ?? 0 ) - $figures['net'];

			if ( $share <= 0 ) {
				continue;
			}

			$nodes[] = array(
				'ram:SpecifiedTradeAllowanceCharge',
				array(
					array( 'ram:ChargeIndicator', array( array( 'udt:Indicator', 'false' ) ) ),
					array( 'ram:ActualAmount', Money::decimal( $share, $totals->currency, false ) ),
					array( 'ram:Reason', $reason ),
					array( 'ram:CategoryTradeTax', self::category_nodes( $invoice->vat, (string) $rate ) ),
				),
			);
		}

		return $nodes;
	}

	/**
	 * Payment terms and the due date, where there are any.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details What the e-invoice adds.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function terms( FacturX $details ): array {
		$terms = array();

		if ( '' !== trim( $details->payment_terms ) ) {
			$terms[] = array( 'ram:Description', trim( $details->payment_terms ) );
		}

		if ( null !== $details->due ) {
			$terms[] = array( 'ram:DueDateDateTime', array( self::date( $details->due ) ) );
		}

		return array() === $terms ? array() : array( array( 'ram:SpecifiedTradePaymentTerms', $terms ) );
	}

	/**
	 * Where the goods went and when.
	 *
	 * To the buyer's country unless told otherwise — which is where a
	 * delivery goes, and what an intra-community supply's zero rate rests
	 * on (BR-IC-12). Always stated, because the delivery group is required
	 * and an empty element is one validators flag. The party comes before
	 * the event — CII's order.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details What the e-invoice adds.
	 * @param Party   $buyer   The buyer.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function delivery( FacturX $details, Party $buyer ): array {
		$nodes   = array();
		$country = self::country( '' !== $details->delivered_to ? $details->delivered_to : $buyer->country );

		if ( '' !== $country ) {
			$nodes[] = array( 'ram:ShipToTradeParty', array( array( 'ram:PostalTradeAddress', array( array( 'ram:CountryID', $country ) ) ) ) );
		}

		if ( null !== $details->delivered ) {
			$nodes[] = array( 'ram:ActualDeliverySupplyChainEvent', array( array( 'ram:OccurrenceDateTime', array( self::date( $details->delivered ) ) ) ) );
		}

		return $nodes;
	}

	/**
	 * The VAT category, rate and reason as a line or an allowance states them.
	 *
	 * @since 1.5.0
	 *
	 * @param VatTreatment $vat  The treatment.
	 * @param string       $rate The rate as written.
	 *
	 * @return list<array<int, mixed>>
	 */
	private static function category_nodes( VatTreatment $vat, string $rate ): array {
		return array(
			array( 'ram:TypeCode', 'VAT' ),
			array( 'ram:CategoryCode', self::category( $vat, $rate )[0] ),
			array( 'ram:RateApplicablePercent', self::rate( $rate ) ),
		);
	}

	/**
	 * The UNTDID 5305 category for a treatment and rate, why, and the
	 * VATEX code for why where there is one.
	 *
	 * The reason is the wording the page prints, in English.
	 *
	 * @since 1.5.0
	 *
	 * @param VatTreatment $vat  The treatment.
	 * @param string       $rate The rate as written.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function category( VatTreatment $vat, string $rate ): array {
		$reason = implode( ' ', $vat->notes() );

		return match ( $vat ) {
			VatTreatment::Standard             => array( 0.0 === (float) $rate ? 'Z' : 'S', '', '' ),
			VatTreatment::ReverseCharge        => array( 'AE', $reason, 'VATEX-EU-AE' ),
			VatTreatment::IntraCommunitySupply => array( 'K', $reason, 'VATEX-EU-IC' ),
			VatTreatment::Export               => array( 'G', $reason, 'VATEX-EU-G' ),
			VatTreatment::SmallBusiness,
			VatTreatment::Exempt               => array( 'E', $reason, '' ),
		};
	}

	/**
	 * A percentage as the XML writes it: `20.00`, `5.50`, `8.875`.
	 *
	 * @since 1.5.0
	 *
	 * @param string $rate As {@see Totals::percentage()} writes it.
	 *
	 * @return string
	 */
	private static function rate( string $rate ): string {
		[ $whole, $fraction ] = array_pad( explode( '.', $rate, 2 ), 2, '' );

		return $whole . '.' . str_pad( $fraction, 2, '0' );
	}

	/**
	 * A date, as format 102 writes it: `20261118`.
	 *
	 * In the date's own time zone — the day the seller issued it, not the
	 * day it already was in UTC.
	 *
	 * @since 1.5.0
	 *
	 * @param \DateTimeInterface $date The date.
	 *
	 * @return array<int, mixed> A `udt:DateTimeString` element.
	 */
	private static function date( \DateTimeInterface $date ): array {
		return array( 'udt:DateTimeString', $date->format( 'Ymd' ), array( 'format' => '102' ) );
	}

	/**
	 * An element and everything in it, indented.
	 *
	 * @since 1.5.0
	 *
	 * @param array<int, mixed> $node  Name, then text or a list of child
	 *                                 nodes, then attributes.
	 * @param int               $depth Indentation level.
	 *
	 * @return string
	 */
	private static function element( array $node, int $depth ): string {
		[ $name, $content ] = $node;

		$attributes = '';

		foreach ( $node[2] ?? array() as $attribute => $value ) {
			$attributes .= ' ' . $attribute . '="' . Archival::escape( (string) $value ) . '"';
		}

		$indent = str_repeat( '  ', $depth );

		if ( is_string( $content ) ) {
			return $indent . '<' . $name . $attributes . '>' . Archival::escape( self::clean( $content ) ) . '</' . $name . ">\n";
		}

		if ( array() === $content ) {
			return $indent . '<' . $name . $attributes . "/>\n";
		}

		$inner = '';

		foreach ( $content as $child ) {
			$inner .= self::element( $child, $depth + 1 );
		}

		return $indent . '<' . $name . $attributes . ">\n" . $inner . $indent . '</' . $name . ">\n";
	}

	/**
	 * Text XML 1.0 can hold: valid UTF-8, without the control characters
	 * it forbids.
	 *
	 * @since 1.5.0
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function clean( string $text ): string {
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub( $text, 'UTF-8' ) );
	}
}
