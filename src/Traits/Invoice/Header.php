<?php
/**
 * The top of an invoice.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Invoice;

use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Models\Templates\Party;
use SugarCommerce\Pdf\Templates\Rows;

/**
 * The masthead, the stamp across it, the two parties and the rows of
 * detail beside them.
 *
 * @since 1.0.0
 */
trait Header {

	/**
	 * Logo, wordmark and document title.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function masthead( Document $pdf ): void {
		$brand = $this->branding;
		$top   = $pdf->height() - 48;
		$x     = $pdf->left();

		$mark = $brand->logo( $pdf, $x, $top, 34, $brand->accent_color() );
		$x   += $mark > 0 ? $mark + 12 : 0;

		$pdf->text_at( strtoupper( $brand->name ), $x, $top - 14, 15, Font::HelveticaBold, $brand->accent_color() );

		if ( '' !== $brand->tagline ) {
			$pdf->text_at( $brand->tagline, $x, $top - 27, 8, Font::Helvetica, $brand->muted() );
		}

		// Title, reference and status stack down the right edge. Each
		// needs its own band — overlaying them puts the stamp across the
		// reference number, which is the one thing on an invoice a
		// customer has to quote back. A long title ("ORDER CONFIRMATION")
		// steps down in size rather than running into the wordmark.
		$title = $this->kind->title( $this->translator );
		$room  = $pdf->content_width() * 0.58;
		$size  = min( 26.0, 26.0 * $room / max( 1.0, $pdf->width( $title, 26, Font::HelveticaBold ) ) );

		$pdf->text_at( $title, $pdf->left(), $top - 20, $size, Font::HelveticaBold, $brand->ink(), Align::Right, $pdf->content_width() );
		$pdf->text_at( $this->number, $pdf->left(), $top - 36, 9.5, Font::Helvetica, $brand->muted(), Align::Right, $pdf->content_width() );

		$rule_y = $top - 66;

		if ( '' !== $this->status ) {
			$this->stamp( $pdf, $top - 58 );
			$rule_y = $top - 78;
		}

		$pdf->move_to( $rule_y );
		$pdf->line( $pdf->left(), $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 0.75 );
		$pdf->move_to( $pdf->cursor() - 28 );
	}

	/**
	 * A status stamp such as PAID or OVERDUE.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 * @param float    $y   Baseline for the stamp box.
	 *
	 * @return void
	 */
	private function stamp( Document $pdf, float $y ): void {
		$brand  = $this->branding;
		$label  = strtoupper( $this->status );
		$width  = $pdf->width( $label, 8, Font::HelveticaBold ) + 22;
		$height = 16.0;
		$left   = $pdf->right() - $width;
		$bottom = $y - 4;

		// Outlined rather than filled: reversed-out white text on a light
		// accent is unreadable, and on a mono palette it disappears
		// entirely.
		$pdf->line( $left, $bottom, $left + $width, $bottom, $brand->ink(), 0.75 );
		$pdf->line( $left, $bottom + $height, $left + $width, $bottom + $height, $brand->ink(), 0.75 );
		$pdf->line( $left, $bottom, $left, $bottom + $height, $brand->ink(), 0.75 );
		$pdf->line( $left + $width, $bottom, $left + $width, $bottom + $height, $brand->ink(), 0.75 );

		$pdf->text_at(
			$label,
			$left,
			$bottom + ( ( $height - $pdf->cap_height( 8, Font::HelveticaBold ) ) / 2 ),
			8,
			Font::HelveticaBold,
			$brand->ink(),
			Align::Center,
			$width
		);
	}

	/**
	 * Seller, recipient and document details, side by side.
	 *
	 * The seller's name, address and VAT number are mandatory particulars
	 * in their own right (Article 226 (5)), so they have a column of their
	 * own rather than relying on the wordmark, which is a trading name and
	 * often not the legal one.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function parties( Document $pdf ): void {
		$brand   = $this->branding;
		$top     = $pdf->cursor();
		$details = $this->detail_rows();
		$gutter  = 16.0;
		$wide    = $pdf->content_width();
		$detail  = array() !== $details ? $wide * 0.36 : 0.0;
		$column  = ( $wide - $detail - ( array() !== $details ? $gutter * 2 : $gutter ) ) / 2;

		$bottom = min(
			$this->party( $pdf, $this->translator->translate( 'from', 'FROM' ), $this->seller(), $pdf->left(), $column, $top ),
			$this->party( $pdf, $this->kind->recipient_heading( $this->translator ), $this->to, $pdf->left() + $column + $gutter, $column, $top )
		);

		if ( array() !== $details ) {
			$x = $pdf->right() - $detail;

			$pdf->text_at( $this->translator->translate( 'details', 'DETAILS' ), $x, $top, 7, Font::HelveticaBold, $brand->muted() );

			$y = $top - 16;

			foreach ( $details as [ $label, $value ] ) {
				$room = $detail - $pdf->width( $label, 9 ) - 8;

				$pdf->text_at( $label, $x, $y, 9, Font::Helvetica, $brand->muted() );
				$pdf->text_at( $pdf->fit( $value, max( 0.0, $room ), 9, Font::HelveticaBold ), $x, $y, 9, Font::HelveticaBold, $brand->ink(), Align::Right, $detail );
				$y -= 13;
			}

			$bottom = min( $bottom, $y );
		}

		$pdf->move_to( $bottom - 18 );
	}

	/**
	 * One party's column: heading, name, then the address block, wrapped.
	 *
	 * @since 1.2.0
	 *
	 * @param Document $pdf     Target.
	 * @param string   $heading e.g. `FROM`, `BILLED TO`.
	 * @param Party    $party   Who.
	 * @param float    $x       Left edge.
	 * @param float    $width   Column width.
	 * @param float    $top     Baseline of the heading.
	 *
	 * @return float The baseline below the last line.
	 */
	private function party( Document $pdf, string $heading, Party $party, float $x, float $width, float $top ): float {
		$brand = $this->branding;

		$pdf->text_at( $heading, $x, $top, 7, Font::HelveticaBold, $brand->muted() );

		// block() takes the top of the first line; the name's baseline is
		// sixteen points under the heading's, as it always was.
		$pdf->move_to( $top - 16 + $pdf->ascender( 10.5, Font::HelveticaBold ) );
		$pdf->block( $party->name, $x, $width, 10.5, Font::HelveticaBold, $brand->ink(), Align::Left, 13 );

		// The address's first baseline is thirteen points under the name's
		// last one.
		$pdf->move_to( $pdf->cursor() - $pdf->ascender( 10.5, Font::HelveticaBold ) + $pdf->ascender( 9 ) );
		$pdf->block( implode( "\n", $party->translated_lines( $this->translator ) ), $x, $width, 9, Font::Helvetica, $brand->muted(), Align::Left, 11.5 );

		return $pdf->cursor() - $pdf->ascender( 9 ) + 11.5;
	}

	/**
	 * The rows of the details column: the caller's, then the date of
	 * supply and the reference when they are not among them.
	 *
	 * @since 1.2.0
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function detail_rows(): array {
		$rows   = Rows::pairs( $this->details );
		$labels = array_map( static fn( array $row ): string => mb_strtolower( $row[0] ), $rows );

		// The supply date is a mandatory particular in its own right and
		// is the one most often left off, so it is added rather than
		// relying on the caller to remember it.
		$supply = $this->translator->translate( 'date_of_supply', 'Date of supply' );

		if ( '' !== trim( $this->supply_date ) && ! in_array( mb_strtolower( $supply ), $labels, true ) ) {
			$rows[] = array( $supply, $this->supply_date );
		}

		$reference = $this->kind->reference_label( $this->translator );

		if ( '' !== trim( $this->reference ) && ! in_array( mb_strtolower( $reference ), $labels, true ) ) {
			$rows[] = array( $reference, $this->reference );
		}

		return array_values( $rows );
	}
}
