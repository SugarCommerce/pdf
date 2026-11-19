<?php
/**
 * What sort of document an invoice-shaped document is.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Enum DocumentKind
 *
 * An invoice, a credit note, a quote and a delivery note differ in their
 * wording and in which blocks appear, not in shape, so they share one
 * template rather than a dozen near-copies that drift apart.
 *
 * The value of each case is also the key its title is translated under.
 *
 * @since 1.2.0
 */
enum DocumentKind: string {

	/**
	 * A demand for payment, and the tax document for the sale.
	 */
	case Invoice = 'invoice';

	/**
	 * Issued to reverse all or part of an invoice. The original is never
	 * amended — sequential numbering forbids it — so a refund is a
	 * document of its own that cites the invoice it reverses.
	 */
	case CreditNote = 'credit_note';

	/**
	 * The counterpart to a credit note: charging more after the fact.
	 */
	case DebitNote = 'debit_note';

	/**
	 * A price offered, not a demand for payment.
	 */
	case Quote = 'quote';

	/**
	 * A pre-payment invoice. Not a tax invoice, and says so.
	 */
	case Proforma = 'proforma';

	/**
	 * A record that something has been paid.
	 */
	case Receipt = 'receipt';

	/**
	 * A reminder that an invoice is overdue.
	 */
	case Reminder = 'reminder';

	/**
	 * Sent when you pay someone, itemising which of their invoices the
	 * payment covers. Not a tax document — theirs is.
	 */
	case Remittance = 'remittance';

	/**
	 * Raised by the customer on the supplier's behalf, as a marketplace
	 * does. "Self-billing" must appear on the face of it.
	 */
	case SelfBilling = 'self_billing';

	/**
	 * Goes in the box with the goods. Shows what was sent and never what
	 * it cost.
	 */
	case DeliveryNote = 'delivery_note';

	/**
	 * Issued by the buyer, committing to a purchase.
	 */
	case PurchaseOrder = 'purchase_order';

	/**
	 * Sent by the seller to acknowledge an order before it is fulfilled.
	 */
	case OrderConfirmation = 'order_confirmation';

	/**
	 * The title across the top of the page.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	public function title( Translator $translator = Language::English ): string {
		return $translator->translate( $this->value, match ( $this ) {
			self::Invoice           => 'INVOICE',
			self::CreditNote        => 'CREDIT NOTE',
			self::DebitNote         => 'DEBIT NOTE',
			self::Quote             => 'QUOTE',
			self::Proforma          => 'PROFORMA',
			self::Receipt           => 'RECEIPT',
			self::Reminder          => 'REMINDER',
			self::Remittance        => 'REMITTANCE ADVICE',
			self::SelfBilling       => 'SELF-BILLED INVOICE',
			self::DeliveryNote      => 'DELIVERY NOTE',
			self::PurchaseOrder     => 'PURCHASE ORDER',
			self::OrderConfirmation => 'ORDER CONFIRMATION',
		} );
	}

	/**
	 * The usual label for the headline total.
	 *
	 * For `Totals::total()`; the template prints whatever label it is given.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	public function total_label( Translator $translator = Language::English ): string {
		return match ( $this ) {
			self::CreditNote                             => $translator->translate( 'total_credited', 'Total credited' ),
			self::DebitNote                              => $translator->translate( 'total_charged', 'Total charged' ),
			self::PurchaseOrder, self::OrderConfirmation => $translator->translate( 'order_total', 'Order total' ),
			self::Quote, self::Proforma                  => $translator->translate( 'total', 'Total' ),
			self::Receipt, self::Remittance              => $translator->translate( 'total_paid', 'Total paid' ),
			default                                      => $translator->translate( 'total_due', 'Total due' ),
		};
	}

	/**
	 * The heading over the party the document is addressed to.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	public function recipient_heading( Translator $translator = Language::English ): string {
		return match ( $this ) {
			self::Remittance                       => $translator->translate( 'paid_to', 'PAID TO' ),
			self::SelfBilling, self::PurchaseOrder => $translator->translate( 'supplier', 'SUPPLIER' ),
			self::DeliveryNote                     => $translator->translate( 'deliver_to', 'DELIVER TO' ),
			default                                => $translator->translate( 'billed_to', 'BILLED TO' ),
		};
	}

	/**
	 * The heading over the notes under the totals.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	public function notes_heading( Translator $translator = Language::English ): string {
		return match ( $this ) {
			self::Quote      => $translator->translate( 'terms', 'TERMS' ),
			self::Remittance => $translator->translate( 'payment_details', 'PAYMENT DETAILS' ),
			default          => $translator->translate( 'payment', 'PAYMENT' ),
		};
	}

	/**
	 * The label the referenced document is shown under.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string
	 */
	public function reference_label( Translator $translator = Language::English ): string {
		return match ( $this ) {
			self::CreditNote => $translator->translate( 'original_invoice', 'Original invoice' ),
			default          => $translator->translate( 'reference', 'Reference' ),
		};
	}

	/**
	 * Wording printed under the totals, where the kind needs it.
	 *
	 * Not decoration for two of them: a proforma that does not say it is
	 * not a VAT invoice can be taken for one, and HMRC requires the words
	 * "self-billing" on the face of a self-billed invoice.
	 *
	 * @since 1.2.0
	 *
	 * @param Translator $translator Wording source.
	 *
	 * @return string Empty when the kind has none.
	 */
	public function standing_note( Translator $translator = Language::English ): string {
		return match ( $this ) {
			self::CreditNote        => $translator->translate( 'credit_note_note', 'This credit note reverses the invoice referenced above. Retain both for your records.' ),
			self::DebitNote         => $translator->translate( 'debit_note_note', 'This debit note charges the additional amount shown against the document referenced above.' ),
			self::Quote             => $translator->translate( 'quote_note', 'This is a quotation, not a demand for payment. No VAT is due until an invoice is issued.' ),
			self::Proforma          => $translator->translate( 'proforma_note', 'Proforma invoice — not a VAT invoice. A tax invoice follows on payment.' ),
			self::Receipt           => $translator->translate( 'receipt_note', 'Paid in full. No further payment is due.' ),
			self::Remittance        => $translator->translate( 'remittance_note', 'This advice confirms payment of the invoices listed above. No action is required.' ),
			self::SelfBilling       => $translator->translate( 'self_billing_note', "Self-billing. This invoice was raised by the customer on the supplier's behalf under a self-billing agreement." ),
			self::DeliveryNote      => $translator->translate( 'delivery_note_note', 'Please check the contents against this note and report any discrepancy within 7 days.' ),
			self::OrderConfirmation => $translator->translate( 'order_confirmation_note', 'This confirms your order. An invoice follows on despatch.' ),
			default                 => '',
		};
	}

	/**
	 * Whether money appears at all.
	 *
	 * A delivery note travels with the goods, and the recipient's
	 * warehouse has no business seeing what the buyer paid. Leaving the
	 * columns out is what the document is for, not a styling choice.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function shows_money(): bool {
		return self::DeliveryNote !== $this;
	}

	/**
	 * Whether the mandatory particulars apply.
	 *
	 * A quote is not a tax document, so demanding a date of supply on one
	 * would be a false warning. A remittance reports a payment against
	 * someone else's invoice, and the particulars belong on theirs.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_tax_document(): bool {
		return in_array( $this, array( self::Invoice, self::CreditNote, self::DebitNote, self::Receipt, self::SelfBilling ), true );
	}

	/**
	 * Whether the document has to cite another one.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function requires_reference(): bool {
		return self::CreditNote === $this || self::DebitNote === $this;
	}
}
