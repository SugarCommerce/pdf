<?php
/**
 * How much of an invoice a Factur-X file carries.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Models\Templates;

use SugarCommerce\Pdf\Models\Relationship;

/**
 * Enum FacturXProfile
 *
 * Factur-X 1.0 — ZUGFeRD 2 in Germany, the same specification — in the
 * four profiles a {@see Totals} can fill. The value is the identifier the
 * XML declares itself with.
 *
 * Minimum and Basic WL are not EN 16931 invoices: they carry the header
 * and the totals, and the page carries the detail. France accepts them
 * during its transition; Germany's B2B mandate does not. Basic and
 * EN 16931 carry every line and are what a buyer's system can book
 * without anyone reading the page.
 *
 * @since 1.5.0
 */
enum FacturXProfile: string {

	/**
	 * Header, parties and totals.
	 */
	case Minimum = 'urn:factur-x.eu:1p0:minimum';

	/**
	 * The same with the VAT breakdown, discounts, payment terms and the
	 * buyer's address: everything a ledger needs except the lines.
	 */
	case BasicWl = 'urn:factur-x.eu:1p0:basicwl';

	/**
	 * Every line, within a subset of EN 16931.
	 */
	case Basic = 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic';

	/**
	 * The European standard's core invoice, every line: what the German
	 * and French B2B mandates mean by an e-invoice.
	 */
	case En16931 = 'urn:cen.eu:en16931:2017';

	/**
	 * The profile as the XMP packet names it: `MINIMUM`, `BASIC WL`,
	 * `BASIC`, `EN 16931`.
	 *
	 * A validator reads the conformance level from the metadata, not from
	 * the XML — the packet is how a reader knows what is attached without
	 * opening it.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function conformance_level(): string {
		return match ( $this ) {
			self::Minimum => 'MINIMUM',
			self::BasicWl => 'BASIC WL',
			self::Basic   => 'BASIC',
			self::En16931 => 'EN 16931',
		};
	}

	/**
	 * Whether the XML lists the invoice's lines.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function has_lines(): bool {
		return self::Basic === $this || self::En16931 === $this;
	}

	/**
	 * How the XML relates to the page it is attached to.
	 *
	 * Factur-X keys it to the profile: Minimum and Basic WL carry only part
	 * of the invoice, so their XML is data the page draws on; from Basic up
	 * it is the whole invoice in another form. A validator checks which.
	 *
	 * @since 1.5.0
	 *
	 * @return Relationship
	 */
	public function relationship(): Relationship {
		return $this->has_lines() ? Relationship::Alternative : Relationship::Data;
	}
}
