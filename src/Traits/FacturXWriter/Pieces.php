<?php
/**
 * The elements of the Cross Industry Invoice XML.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\FacturXWriter;

use SugarCommerce\Money\Money;
use SugarCommerce\Pdf\Output\Archival;
use SugarCommerce\Pdf\Templates\{Discount, DocumentKind, FacturX, Invoice, Party, Totals, VatTreatment};

/**
 * The parties, lines, taxes, allowances, terms and delivery, each in
 * CII's order, and the escaping every value goes through.
 *
 * @since 1.5.0
 */
trait Pieces {

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
