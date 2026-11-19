<?php
/**
 * VAT treatment and its mandatory invoice wording.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Enum VatTreatment
 *
 * Where VAT is not charged at the normal domestic rate, the invoice has
 * to say why — and in most of the EU it has to say why in specific
 * words. An invoice missing that wording can be refused as evidence for
 * the recipient's input-tax deduction, which turns a formatting
 * oversight into their tax problem and your support ticket.
 *
 * Germany is the strictest common case: §14 UStG lists the mandatory
 * particulars, and §14a adds the reverse-charge note. Each case here
 * carries the customary English and German wording together, because a
 * cross-border invoice normally shows both.
 *
 * **This is not tax advice.** Treatments, thresholds and wording change,
 * and which one applies depends on facts this library cannot see — where
 * both parties belong, whether the customer is in business, whether the
 * supply is goods or services. Confirm with an accountant; this only
 * makes sure the note you chose is printed correctly and prominently.
 *
 * @since 1.0.0
 */
enum VatTreatment {

	/**
	 * Ordinary domestic VAT at the applicable rate.
	 */
	case Standard;

	/**
	 * Cross-border B2B supply where the customer accounts for the VAT.
	 *
	 * Requires both parties' VAT numbers on the invoice. Article 196 of
	 * Directive 2006/112/EC for services; §13b UStG in Germany.
	 */
	case ReverseCharge;

	/**
	 * Zero-rated intra-community supply of goods.
	 *
	 * Article 138 of Directive 2006/112/EC.
	 */
	case IntraCommunitySupply;

	/**
	 * Supply to a customer outside the EU.
	 */
	case Export;

	/**
	 * Small-business exemption — §19 UStG in Germany.
	 */
	case SmallBusiness;

	/**
	 * Exempt for another reason.
	 */
	case Exempt;

	/**
	 * The wording that must appear on the invoice.
	 *
	 * The English always; with a second language, that language's wording
	 * after it. The document carries both because the person reading it
	 * and the authority checking it are often not the same person.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 A Language for the second language.
	 *
	 * @param bool|Language $also A language whose wording to add, or true
	 *                            for German, as before other languages
	 *                            existed.
	 *
	 * @return list<string> One line per element, or empty for standard VAT.
	 */
	public function notes( bool|Language $also = false ): array {
		$notes = match ( $this ) {
			self::Standard => array(),

			self::ReverseCharge => array(
				'Reverse charge: VAT to be accounted for by the recipient.',
				'Article 196, Council Directive 2006/112/EC.',
			),

			self::IntraCommunitySupply => array(
				'Zero-rated intra-community supply.',
				'Article 138, Council Directive 2006/112/EC.',
			),

			self::Export => array(
				'Zero-rated export outside the European Union.',
			),

			self::SmallBusiness => array(
				'No VAT charged under the small-business scheme.',
			),

			self::Exempt => array(
				'Exempt from VAT.',
			),
		};

		$language = true === $also ? Language::German : ( $also instanceof Language ? $also : null );

		if ( null === $language ) {
			return $notes;
		}

		return array_merge( $notes, $this->wording( $language ) );
	}

	/**
	 * What a document in a language calls the treatment, citing the
	 * statute its own law uses.
	 *
	 * German, French, Italian, Spanish and Dutch, which is where a
	 * tax authority expects its own words. The other languages have none,
	 * and the English wording stands alone.
	 *
	 * **Not legal advice, and worth a professional's eye before you rely
	 * on it.** These are the standard formulations and the articles they
	 * cite, which are published and formulaic — but a wrong phrase in a
	 * language you do not read is worse than an English one you do.
	 *
	 * @since 1.2.0
	 *
	 * @param Language $language The language.
	 *
	 * @return list<string> Empty for standard VAT and for a language with no
	 *                      wording of its own.
	 */
	public function wording( Language $language ): array {
		if ( self::Standard === $this ) {
			return array();
		}

		$wording = array(
			'de' => array(
				'ReverseCharge'        => array( 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG).' ),
				'IntraCommunitySupply' => array( 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b UStG).' ),
				'Export'               => array( 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a UStG).' ),
				'SmallBusiness'        => array( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' ),
				'Exempt'               => array( 'Steuerfrei.' ),
			),
			'fr' => array(
				'ReverseCharge'        => array( 'Autoliquidation — TVA due par le preneur.', 'Article 283-2 du CGI ; article 196 de la directive 2006/112/CE.' ),
				'IntraCommunitySupply' => array( 'Livraison intracommunautaire exonérée.', 'Article 262 ter I du CGI ; article 138 de la directive 2006/112/CE.' ),
				'Export'               => array( 'Exportation exonérée de TVA (article 262 I du CGI).' ),
				'SmallBusiness'        => array( 'TVA non applicable, article 293 B du CGI.' ),
				'Exempt'               => array( 'Opération exonérée de TVA.' ),
			),
			'it' => array(
				'ReverseCharge'        => array( 'Inversione contabile — IVA assolta dal committente.', 'Articolo 17 del DPR 633/72 ; articolo 196 della direttiva 2006/112/CE.' ),
				'IntraCommunitySupply' => array( 'Cessione intracomunitaria non imponibile.', 'Articolo 41 del DL 331/93 ; articolo 138 della direttiva 2006/112/CE.' ),
				'Export'               => array( 'Operazione non imponibile (articolo 8 del DPR 633/72).' ),
				'SmallBusiness'        => array( 'Operazione in regime forfettario, IVA non applicata.' ),
				'Exempt'               => array( 'Operazione esente IVA.' ),
			),
			'es' => array(
				'ReverseCharge'        => array( 'Inversión del sujeto pasivo — IVA a declarar por el destinatario.', 'Artículo 84 de la Ley 37/1992 ; artículo 196 de la Directiva 2006/112/CE.' ),
				'IntraCommunitySupply' => array( 'Entrega intracomunitaria exenta.', 'Artículo 25 de la Ley 37/1992 ; artículo 138 de la Directiva 2006/112/CE.' ),
				'Export'               => array( 'Exportación exenta de IVA (artículo 21 de la Ley 37/1992).' ),
				'SmallBusiness'        => array( 'Operación sin IVA por régimen de franquicia.' ),
				'Exempt'               => array( 'Operación exenta de IVA.' ),
			),
			'nl' => array(
				'ReverseCharge'        => array( 'Btw verlegd — te voldoen door de afnemer.', 'Artikel 12 lid 3 Wet OB ; artikel 196 richtlijn 2006/112/EG.' ),
				'IntraCommunitySupply' => array( 'Intracommunautaire levering, 0% btw.', 'Tabel II post a6 Wet OB ; artikel 138 richtlijn 2006/112/EG.' ),
				'Export'               => array( 'Uitvoer buiten de EU, 0% btw.' ),
				'SmallBusiness'        => array( 'Geen btw in rekening gebracht — kleineondernemersregeling.' ),
				'Exempt'               => array( 'Vrijgesteld van btw.' ),
			),
		);

		return $wording[ $language->value ][ $this->name ] ?? array();
	}

	/**
	 * Whether a language has wording of its own for these treatments.
	 *
	 * @since 1.2.0
	 *
	 * @param Language $language The language.
	 *
	 * @return bool
	 */
	public static function has_wording( Language $language ): bool {
		return array() !== self::ReverseCharge->wording( $language );
	}

	/**
	 * Whether both parties' VAT numbers must appear on the invoice.
	 *
	 * Mandatory for reverse charge and intra-community supply — the
	 * recipient's number is what evidences their status, and without it
	 * the zero rating is not supportable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function requires_both_vat_numbers(): bool {
		return in_array( $this, array( self::ReverseCharge, self::IntraCommunitySupply ), true );
	}
}
