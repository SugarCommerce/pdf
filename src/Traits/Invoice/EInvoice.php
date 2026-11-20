<?php
/**
 * The invoice as a Factur-X / ZUGFeRD e-invoice.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Invoice;

use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Attachment;
use SugarCommerce\Pdf\Models\Templates\FacturX;
use SugarCommerce\Pdf\Templates\FacturXException;
use SugarCommerce\Pdf\Templates\FacturXWriter;

/**
 * What stops it being one, its CII XML, and the PDF/A-3 file that
 * carries the XML.
 *
 * @since 1.0.0
 */
trait EInvoice {

	/**
	 * What stops this invoice being sent as a Factur-X e-invoice.
	 *
	 * Stricter than {@see compliance_warnings()}: an e-invoice is checked
	 * by a machine against EN 16931, and each sentence names the rule.
	 * The page and the XML must also be the same invoice — the total
	 * printed must be the one the figures make.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return list<string> One sentence each; empty when it can be sent.
	 */
	public function factur_x_problems( FacturX $details ): array {
		return FacturXWriter::problems( $this, $details );
	}

	/**
	 * The invoice as Factur-X XML: UN/CEFACT Cross Industry Invoice D16B.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return string UTF-8 XML.
	 *
	 * @throws FacturXException Listing every problem, when there are any.
	 */
	public function factur_x_xml( FacturX $details ): string {
		return FacturXWriter::xml( $this, $details );
	}

	/**
	 * The invoice as a Factur-X / ZUGFeRD e-invoice: the page a person
	 * reads, as PDF/A-3b, carrying `factur-x.xml` for the buyer's system.
	 *
	 * ```php
	 * $details = new FacturX( $totals, new DateTimeImmutable( '2026-11-18' ), due: new DateTimeImmutable( '2026-12-18' ) );
	 *
	 * $invoice->factur_x( $details )->save( 'INV-2026-0042.pdf', $invoice->metadata() );
	 * ```
	 *
	 * Refused, rather than written to fail a validator, when the XML has
	 * a problem ({@see factur_x_problems()}) or when the page could not
	 * print a name as written — the XML would then say one thing and the
	 * page another.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return Document Ready to save, render or send.
	 *
	 * @throws FacturXException Listing every problem, when there are any.
	 */
	public function factur_x( FacturX $details ): Document {
		$xml = $this->factur_x_xml( $details );
		$pdf = $this->render( true );

		if ( array() !== $pdf->substitutions() ) {
			throw new FacturXException(
				array_map(
					static fn( string $text ): string => '"' . $text . '" could not be printed as written, so the page would not say what the XML does: set the invoice in a family that has these letters.',
					$pdf->substitutions()
				)
			);
		}

		return $pdf
			->xmp( FacturXWriter::xmp( $details->profile ) )
			->attach( new Attachment( FacturXWriter::FILE_NAME, $xml, 'text/xml', 'Factur-X invoice', $details->profile->relationship(), $details->issued->getTimestamp() ) );
	}
}
