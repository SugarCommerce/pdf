<?php
/**
 * What stops an invoice being a valid e-invoice.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\FacturXWriter;

use SugarCommerce\Money\Currencies;
use SugarCommerce\Pdf\Templates\{FacturX, FacturXProfile, Invoice, Rows, VatTreatment};

/**
 * The particulars a profile requires, and the figures checked against
 * each other the way the Schematron checks them.
 *
 * @since 1.5.0
 */
trait Checks {

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
}
