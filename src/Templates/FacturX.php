<?php
/**
 * What an e-invoice states that the page does not.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class FacturX
 *
 * The machine-readable half of an invoice, beside {@see Invoice}.
 *
 * Germany's e-invoicing mandate is phasing in, France's follows, and both
 * want an invoice a system can read without looking at the picture.
 * Factur-X — ZUGFeRD in Germany, the same specification — says how: one
 * PDF/A-3 carrying the invoice as XML. The page is what a person reads;
 * the attachment is what the buyer's ledger reads.
 *
 * Every amount on an {@see Invoice} is a string, deliberately, and reading
 * "1.234,56 €" back into a number means guessing which mark is the
 * decimal point. So the figures come from the {@see Totals} the page was
 * printed from — the same integers, for the XML and the page alike — and
 * the dates come as dates, not as the words the page shows.
 *
 * @since 1.5.0
 */
final readonly class FacturX {

	/**
	 * Describe the e-invoice.
	 *
	 * @since 1.5.0
	 *
	 * @param Totals                  $totals          The figures the invoice was
	 *                                                 printed from: its lines,
	 *                                                 rates, discounts and currency.
	 * @param \DateTimeInterface      $issued          The date of issue, in the
	 *                                                 seller's own time zone.
	 * @param FacturXProfile          $profile         How much the XML carries.
	 * @param \DateTimeInterface|null $due             When payment is due.
	 * @param string                  $payment_terms   The terms, as a sentence. An
	 *                                                 invoice with something to pay
	 *                                                 needs this or `$due` (BR-CO-25).
	 * @param string                  $buyer_reference The buyer's own reference,
	 *                                                 which several authorities
	 *                                                 require an invoice to quote.
	 * @param \DateTimeInterface|null $delivered       When the goods or services
	 *                                                 were supplied. Required for an
	 *                                                 intra-community supply (BR-IC-11).
	 * @param string                  $delivered_to    The two-letter country they
	 *                                                 went to, when it is not the
	 *                                                 buyer's (BR-IC-12).
	 * @param int                     $prepaid         Minor units already paid, which
	 *                                                 come off what is due.
	 */
	public function __construct(
		public Totals $totals,
		public \DateTimeInterface $issued,
		public FacturXProfile $profile = FacturXProfile::En16931,
		public ?\DateTimeInterface $due = null,
		public string $payment_terms = '',
		public string $buyer_reference = '',
		public ?\DateTimeInterface $delivered = null,
		public string $delivered_to = '',
		public int $prepaid = 0,
	) {}

	/**
	 * What is left to pay: the gross less anything already paid.
	 *
	 * @since 1.5.0
	 *
	 * @return int Minor units.
	 */
	public function payable(): int {
		return $this->totals->gross() - $this->prepaid;
	}
}
