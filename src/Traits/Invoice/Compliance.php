<?php
/**
 * The particulars an invoice must carry.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Invoice;

use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Templates\DocumentKind;
use SugarCommerce\Pdf\Models\Templates\VatTreatment;
use SugarCommerce\Pdf\Templates\Rows;

/**
 * Which mandatory particulars are missing, from the data and from the
 * page as drawn.
 *
 * @since 1.0.0
 */
trait Compliance {

	/**
	 * Mandatory particulars that are missing.
	 *
	 * Checks the invoice against the fields §14 UStG and Article 226 of
	 * the VAT Directive require. An invoice missing any of them can be
	 * refused as evidence for the recipient's input-tax deduction — which
	 * makes it their problem and your support ticket. Documents that are
	 * not tax documents — a quote, a delivery note — have none to miss.
	 *
	 * Not tax advice, and not exhaustive: it verifies the particulars this
	 * template can see, not whether the treatment you chose is correct.
	 * {@see compliance_warnings_verifying()} asks the drawn page as well.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Human-readable problems; empty when nothing is missing.
	 */
	public function compliance_warnings(): array {
		if ( ! $this->kind->is_tax_document() ) {
			return array();
		}

		$missing = array();
		$seller  = $this->seller();

		if ( '' === trim( $seller->name ) ) {
			$missing[] = 'The supplier name is missing.';
		}

		if ( '' === trim( $seller->tax_id ) ) {
			$missing[] = 'The supplier VAT or tax number is missing.';
		}

		if ( array() === self::filled( $seller->address ) ) {
			$missing[] = 'The supplier address is missing.';
		}

		if ( array() === self::filled( $this->to->address ) ) {
			$missing[] = 'The customer address is missing.';
		}

		if ( '' === trim( $this->number ) ) {
			$missing[] = 'A sequential invoice number is required.';
		}

		if ( '' === trim( $this->supply_date ) ) {
			$missing[] = 'The date of supply is required, separately from the invoice date.';
		}

		if ( $this->vat->requires_both_vat_numbers() && '' === trim( $this->to->tax_id ) ) {
			$missing[] = 'The customer VAT number is required for this VAT treatment.';
		}

		// A self-billed invoice is the supplier's invoice, raised by the
		// customer: the supplier's number is on it whatever the treatment.
		if ( DocumentKind::SelfBilling === $this->kind && '' === trim( $this->to->tax_id ) ) {
			$missing[] = "A self-billed invoice must show the supplier's VAT number.";
		}

		if ( $this->kind->requires_reference() && '' === trim( $this->reference ) ) {
			$missing[] = DocumentKind::CreditNote === $this->kind
				? 'A credit note must reference the invoice it reverses.'
				: 'A debit note must reference the document it adjusts.';
		}

		if ( count( $this->vat_lines ) > 1 ) {
			return $missing;
		}

		if ( VatTreatment::Standard === $this->vat && array() === $this->vat_lines && array() === $this->totals ) {
			$missing[] = 'The taxable amount and VAT must be shown per rate.';
		}

		return $missing;
	}

	/**
	 * Mandatory particulars missing from the page as drawn.
	 *
	 * {@see compliance_warnings()} inspects the data: it can tell you a
	 * reverse charge was declared and a VAT number was given, not that
	 * either reached the page. This asks the finished document, which is
	 * the question that matters — the recipient's deduction turns on what
	 * is printed, and a template that stopped drawing the seller's VAT
	 * number would pass every check that only reads the fields.
	 *
	 * Text is compared as a reader extracts it, so a name the base fonts
	 * cannot print as written — Cyrillic, a Polish ł — is reported too.
	 *
	 * Still not tax advice.
	 *
	 * @since 1.2.0
	 *
	 * @param Document|null $document The rendered document; rendered here when
	 *                                null.
	 *
	 * @return list<string> The data's warnings, then what the page lacks.
	 */
	public function compliance_warnings_verifying( ?Document $document = null ): array {
		$missing = $this->compliance_warnings();
		$page    = self::squeezed( implode( ' ', ( $document ?? $this->render() )->drawn_text() ) );
		$on_page = static fn( string $text ): bool => '' === self::squeezed( $text ) || str_contains( $page, self::squeezed( $text ) );

		foreach ( $this->vat->notes( $this->vat_wording() ?? false ) as $note ) {
			if ( ! $on_page( $note ) ) {
				$missing[] = 'The wording "' . $note . '" is required and did not reach the page.';
			}
		}

		$standing = $this->kind->standing_note( $this->translator );

		if ( ! $on_page( $standing ) ) {
			$missing[] = 'The wording "' . $standing . '" did not reach the page.';
		}

		// A delivery note prints no money, so there is no total to find.
		foreach ( $this->kind->shows_money() ? Rows::pairs( $this->total ) : array() as [ , $value ] ) {
			if ( ! $on_page( $value ) ) {
				$missing[] = 'The total "' . $value . '" did not reach the page.';
			}
		}

		if ( ! $this->kind->is_tax_document() ) {
			return $missing;
		}

		$seller = $this->seller();
		$checks = array(
			'The document number'      => $this->number,
			'The date of supply'       => $this->supply_date,
			'The supplier name'        => $seller->name,
			'The supplier VAT number'  => $seller->tax_id,
			'The customer name'        => $this->to->name,
			'The reference'            => $this->kind->requires_reference() ? $this->reference : '',
			'The customer VAT number'  => $this->vat->requires_both_vat_numbers() || DocumentKind::SelfBilling === $this->kind ? $this->to->tax_id : '',
		);

		foreach ( self::filled( $seller->address ) as $line ) {
			$checks[ 'The supplier address line "' . $line . '"' ] = $line;
		}

		foreach ( $checks as $what => $value ) {
			if ( ! $on_page( $value ) ) {
				$missing[] = str_contains( $what, '"' )
					? $what . ' did not reach the page.'
					: $what . ' "' . $value . '" did not reach the page.';
			}
		}

		return $missing;
	}
}
