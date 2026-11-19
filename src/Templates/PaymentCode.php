<?php
/**
 * A code printed on a document to be scanned.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class PaymentCode
 *
 * The square somebody points a phone at, and the words under it.
 *
 * A payment code turns "read these sixteen digits off the page and type
 * them into your banking app" into one scan, which is where the mistakes
 * come from otherwise: a transposed IBAN digit is a payment that bounces
 * a week later, and a missing reference is a payment nobody can match to
 * an invoice.
 *
 * The EPC code, which European banking apps scan for a SEPA credit
 * transfer, has a specification and its own constructor. Anything else —
 * a link to a payment page, a page that verifies the document — is just
 * a string, so this carries the payload it is given.
 *
 * @since 1.2.0
 */
final readonly class PaymentCode {

	/**
	 * Describe a code.
	 *
	 * @since 1.2.0
	 *
	 * @param string $payload What the scanner reads: a URL, an EPC payload,
	 *                        whatever the reader's app expects.
	 * @param string $caption The line under the code, so somebody who cannot
	 *                        scan it knows what it was for.
	 */
	public function __construct(
		public string $payload,
		public string $caption = 'Scan to pay',
	) {}

	/**
	 * A SEPA credit transfer, as European banking apps read it.
	 *
	 * EPC069-12. The rules that bite are enforced rather than documented:
	 * the amount is euro only, between 0.01 and 999,999,999.99, the name is
	 * at most 70 characters and the reference 140, and a banking app given
	 * more than that shows an error rather than a payment.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $beneficiary Who is paid, as the bank knows them.
	 * @param string   $iban        Their account; spaces are ignored.
	 * @param int|null $cents       The amount in euro cents, or null to leave it
	 *                              for the payer to type — what an invoice with
	 *                              a part payment against it wants.
	 * @param string   $bic         The bank's BIC; optional inside the EEA.
	 * @param string   $reference   What the payer sees and what you match the
	 *                              payment against: the invoice number.
	 * @param string   $caption     The line under the code.
	 *
	 * @return self|null Null where the details cannot make a valid code.
	 */
	public static function epc(
		string $beneficiary,
		string $iban,
		?int $cents = null,
		string $bic = '',
		string $reference = '',
		string $caption = 'Scan to pay'
	): ?self {
		$name    = self::single_line( $beneficiary );
		$account = strtoupper( (string) preg_replace( '/\s+/', '', $iban ) );
		$note    = self::single_line( $reference );
		$bank    = strtoupper( (string) preg_replace( '/\s+/', '', $bic ) );

		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > 70 || mb_strlen( $note, 'UTF-8' ) > 140 ) {
			return null;
		}

		// Two letters, two check digits, then up to thirty letters and
		// digits: the shape every IBAN has, whatever its country's length.
		if ( 1 !== preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $account ) ) {
			return null;
		}

		if ( '' !== $bank && 1 !== preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bank ) ) {
			return null;
		}

		$money = '';

		if ( null !== $cents ) {
			if ( $cents < 1 || $cents > 99_999_999_999 ) {
				return null;
			}

			$money = 'EUR' . intdiv( $cents, 100 ) . '.' . str_pad( (string) ( $cents % 100 ), 2, '0', STR_PAD_LEFT );
		}

		// The order is the specification's, and the empty lines are load
		// bearing: an app counts them to find the fields it wants.
		$payload = implode(
			"\n",
			array(
				'BCD', // Service tag.
				'002', // Version.
				'1',   // UTF-8.
				'SCT', // SEPA credit transfer.
				$bank,
				$name,
				$account,
				$money,
				'',    // Purpose code.
				'',    // Structured reference.
				$note, // Unstructured reference.
			)
		);

		return new self( $payload, $caption );
	}

	/**
	 * Text on one line, trimmed: a newline inside a field would shift
	 * every field after it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', mb_scrub( $text, 'UTF-8' ) ) );
	}
}
