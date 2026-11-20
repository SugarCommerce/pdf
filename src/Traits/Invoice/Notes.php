<?php
/**
 * The words under the figures.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Invoice;

use SugarCommerce\Pdf\{Align, Document, Font};
use SugarCommerce\Pdf\Templates\{Language, VatTreatment};

/**
 * The VAT notes a treatment needs, in the invoice's language, and the
 * seller's own notes and terms.
 *
 * @since 1.0.0
 */
trait Notes {

	/**
	 * The mandatory VAT wording.
	 *
	 * Printed prominently rather than buried in the small print, because
	 * its absence is what invalidates the recipient's deduction. Each note
	 * wraps inside the box, and the box is measured from what it holds.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function vat_notes( Document $pdf ): void {
		$notes = $this->vat->notes( $this->vat_wording() ?? false );

		if ( array() === $notes ) {
			return;
		}

		$brand   = $this->branding;
		$padding = 11.0;
		$indent  = 15.0;
		$inner   = $pdf->content_width() - ( $indent * 2 );
		$content = 0.0;

		foreach ( $notes as $note ) {
			$content += $pdf->block_height( $note, $inner, 8.5, Font::HelveticaBold, 12 );
		}

		// A block's height counts a full leading under its last line, but
		// the ink stops at the descender: for the base-14 faces ascender
		// plus descender is the point size, so the phantom is the leading
		// less the size. Left in, the text sits high in its own box.
		$height = $content - ( 12 - 8.5 ) + ( $padding * 2 );

		$pdf->gap( 24 );
		$pdf->break_if_needed( min( $pdf->page_room(), $height + 16 ) );

		$top    = $pdf->cursor();
		$bottom = $top - $height;

		$pdf->line( $pdf->left(), $top, $pdf->right(), $top, $brand->hairline(), 0.5 );
		$pdf->line( $pdf->left(), $bottom, $pdf->right(), $bottom, $brand->hairline(), 0.5 );
		$pdf->line( $pdf->right(), $top, $pdf->right(), $bottom, $brand->hairline(), 0.5 );

		// A heavier stub on the left edge draws the eye without needing
		// colour, which matters because this is the wording that makes
		// the invoice valid for the recipient's deduction.
		$pdf->line( $pdf->left(), $top, $pdf->left(), $bottom, $brand->ink(), 2 );

		$pdf->move_to( $top - $padding );

		foreach ( $notes as $index => $note ) {
			$pdf->block( $note, $pdf->left() + $indent, $inner, 8.5, 0 === $index ? Font::HelveticaBold : Font::Helvetica, $brand->ink(), Align::Left, 12 );
		}

		$pdf->move_to( min( $pdf->cursor(), $bottom ) );
	}

	/**
	 * Payment instructions, wrapped, with the payment code beside them.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function notes( Document $pdf ): void {
		$text = trim( $this->notes );
		$code = $this->payment_code;

		if ( '' === $text && null === $code ) {
			return;
		}

		// The code sits against the right edge with the wording beside it,
		// so the two read as one instruction. Sized to be scanned from a
		// printed page at arm's length.
		$square = 92.0;
		$gutter = 18.0;
		$width  = null !== $code ? $pdf->content_width() - $square - $gutter : $pdf->content_width();
		$brand  = $this->branding;

		$pdf->gap( 26 );
		$pdf->break_if_needed( min( $pdf->page_room(), max( null !== $code ? $square + 46 : 0.0, 34 + $pdf->block_height( $text, $width, 8.5, Font::Helvetica, 12 ) ) ) );

		$pdf->line( $pdf->left(), $pdf->cursor() + 12, $pdf->right(), $pdf->cursor() + 12, $brand->hairline(), 0.5 );
		$pdf->gap( 6 );
		$pdf->cell( $this->kind->notes_heading( $this->translator ), $pdf->left(), 200, 7, Font::HelveticaBold, $brand->muted() );
		$pdf->gap( 14 );

		$top   = $pdf->cursor();
		$drawn = false;

		// The code goes in first, from the top of the block, so a long note
		// neither pushes it down the page nor carries it onto the next.
		if ( null !== $code ) {
			$drawn = $pdf->qr( $code->payload, $pdf->right() - $square, $top - $square, $square, $brand->ink() );

			if ( $drawn ) {
				$caption = 'Scan to pay' === $code->caption ? $this->translator->translate( 'scan_to_pay', 'Scan to pay' ) : $code->caption;

				$pdf->text_at( $caption, $pdf->right() - $square, $top - $square - 11, 7, Font::Helvetica, $brand->muted(), Align::Center, $square );
			}
		}

		$page = $pdf->page_count();

		if ( '' !== $text ) {
			$pdf->block( $text, $pdf->left(), $width, 8.5, Font::Helvetica, $brand->ink(), Align::Left, 12 );
		}

		// Whichever ran longer decides where the page carries on — unless
		// the note went on to another page, which the code is not on.
		if ( $drawn && $pdf->page_count() === $page ) {
			$pdf->move_to( min( $pdf->cursor(), $top - $square - 18 ) );
		}
	}

	/**
	 * The second language of the VAT wording, if any.
	 *
	 * German wording is added when asked for and whenever the document
	 * itself is in a language with wording of its own — a German invoice
	 * needs the German note, and remembering to set both is a trap.
	 *
	 * @since 1.2.0
	 *
	 * @return Language|null
	 */
	private function vat_wording(): ?Language {
		if ( null !== $this->wording ) {
			return $this->wording;
		}

		if ( $this->german_notes ) {
			return Language::German;
		}

		$language = $this->language();

		return null !== $language && VatTreatment::has_wording( $language ) ? $language : null;
	}
}
