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

use SugarCommerce\Money\Money;
use SugarCommerce\Pdf\Traits\FacturXWriter\{Checks, Pieces};

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
 * The document is put together here; the checks and the pieces it is put
 * together from are traits in Traits/FacturXWriter.
 *
 * @since 1.5.0
 */
final class FacturXWriter {

	use Checks;
	use Pieces;

	/**
	 * The file name Factur-X requires, exactly.
	 */
	public const string FILE_NAME = 'factur-x.xml';

	/**
	 * Unit of measure for a quantity: UN/ECE Recommendation 20's "one".
	 */
	private const string UNIT = 'C62';

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
}
