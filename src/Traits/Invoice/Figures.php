<?php
/**
 * The lines and the money.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Invoice;

use SugarCommerce\Pdf\{Align, Document, Font, Table};
use SugarCommerce\Pdf\Templates\{Rows, VatTreatment};

/**
 * The item table, the VAT breakdown and the totals.
 *
 * @since 1.0.0
 */
trait Figures {

	/**
	 * The line-item table.
	 *
	 * Descriptions wrap rather than ending in `...`, and an item's note
	 * goes on its own quieter line under the description, where it reads
	 * as detail rather than as part of what was sold.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function items( Document $pdf ): void {
		if ( array() === $this->items ) {
			return;
		}

		$money    = $this->kind->shows_money();
		$has_unit = false;

		foreach ( $this->items as $item ) {
			if ( '' !== $item->unit_price ) {
				$has_unit = true;
				break;
			}
		}

		$t = $this->translator;

		[ $headers, $widths, $aligns ] = match ( true ) {
			! $money  => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ) ),
				array( 0.85, 0.15 ),
				array( 1 => Align::Center ),
			),
			$has_unit => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ), $t->translate( 'unit_price', 'Unit price' ), $t->translate( 'amount', 'Amount' ) ),
				array( 0.50, 0.10, 0.20, 0.20 ),
				array(
					1 => Align::Center,
					2 => Align::Right,
					3 => Align::Right,
				),
			),
			default   => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ), $t->translate( 'amount', 'Amount' ) ),
				array( 0.66, 0.14, 0.20 ),
				array(
					1 => Align::Center,
					2 => Align::Right,
				),
			),
		};

		$table = ( new Table( $headers ) )->widths( $widths )->align( $aligns )->row_height( 22 )->wrap();

		foreach ( $this->items as $item ) {
			$table->row(
				match ( true ) {
					! $money  => array( $item->description, $item->quantity ),
					$has_unit => array( $item->description, $item->quantity, $item->unit_price, $item->amount ),
					default   => array( $item->description, $item->quantity, $item->amount ),
				},
				$item->note
			);
		}

		$table->draw( $pdf, 9, $this->branding->wash(), $this->branding->muted() );
	}

	/**
	 * Whether the per-rate breakdown earns its place on the page.
	 *
	 * It exists because §14 UStG and Article 226 want the taxable amount
	 * against each rate when rates are mixed. At a single standard rate it
	 * repeats the totals block — the same two figures again — so it is
	 * left out. A non-standard treatment keeps it at one rate: the 0% row
	 * is what evidences the zero rating.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function shows_vat_breakdown(): bool {
		return $this->kind->shows_money()
			&& array() !== $this->vat_lines
			&& ( count( $this->vat_lines ) > 1 || VatTreatment::Standard !== $this->vat );
	}

	/**
	 * Per-rate VAT breakdown.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function vat_breakdown( Document $pdf ): void {
		if ( ! $this->shows_vat_breakdown() ) {
			return;
		}

		$brand = $this->branding;
		$rows  = count( $this->vat_lines );

		// Heading, header and every row together, so the heading is never
		// left at the foot of one page with its table on the next.
		$pdf->gap( 18 );
		$pdf->break_if_needed( min( $pdf->page_room(), 14 + ( 18 * ( $rows + 2 ) ) ) );

		$pdf->cell( $this->translator->translate( 'vat_breakdown', 'VAT BREAKDOWN' ), $pdf->left(), $pdf->content_width() * 0.55, 7, Font::HelveticaBold, $brand->muted() );
		$pdf->gap( 14 );

		$table = ( new Table( array(
			$this->translator->translate( 'rate', 'Rate' ),
			$this->translator->translate( 'net', 'Net' ),
			$this->translator->translate( 'vat', 'VAT' ),
		) ) )
			->widths( array( 0.4, 0.3, 0.3 ) )
			->align( array(
				1 => Align::Right,
				2 => Align::Right,
			) )
			->row_height( 18 );

		foreach ( $this->vat_lines as $line ) {
			$table->row( array( '' !== $line->label ? $line->label . ' (' . $line->rate . ')' : $line->rate, $line->net, $line->vat ) );
		}

		// The cursor stays wherever the table left it — near the top of a
		// new page when it broke. Clamping it back to where this page
		// started dragged everything after a page late.
		$table->draw( $pdf, 8.5, $brand->wash() );
	}

	/**
	 * Subtotals, the headline total, and the kind's standing wording.
	 *
	 * Kept together: the whole block is measured first and moves to the
	 * next page as one when it does not fit, so a total is never printed
	 * on a page of its own away from the rows that make it up.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function totals( Document $pdf ): void {
		$brand    = $this->branding;
		$standing = $this->kind->standing_note( $this->translator );
		$rows     = Rows::pairs( $this->totals );
		$total    = Rows::pairs( $this->total );
		$money    = $this->kind->shows_money();

		if ( ! $money ) {
			$rows  = array();
			$total = array();
		}

		$height = ( 14 + ( 15 * count( $rows ) ) )
			+ ( array() !== $total ? 26 + ( 26 * count( $total ) ) : 0 )
			+ ( '' !== $standing ? 6 + $pdf->block_height( $standing, $pdf->content_width(), 8.5, Font::Helvetica, 12 ) : 0 );

		$pdf->break_if_needed( min( $pdf->page_room(), $height ) );

		$width = $pdf->content_width() * 0.42;
		$x     = $pdf->right() - $width;

		$pdf->gap( 14 );

		foreach ( $rows as [ $label, $value ] ) {
			$pdf->cell( $label, $x, $width, 9, Font::Helvetica, $brand->muted() );
			$pdf->cell( $value, $x, $width, 9, Font::Helvetica, $brand->ink(), Align::Right );
			$pdf->gap( 15 );
		}

		if ( array() !== $total ) {
			// A rule and a change of weight, rather than a filled block. It
			// reads as clearly, prints the same on any device, and cannot
			// look subtly wrong the way a band of colour with text in it can.
			$pdf->gap( 6 );
			$pdf->line( $x, $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 1 );
			$pdf->gap( 20 );

			foreach ( $total as [ $label, $value ] ) {
				$baseline = $pdf->cursor();

				$pdf->text_at( $label, $x, $baseline, 11, Font::HelveticaBold, $brand->ink() );
				$pdf->text_at( $value, $x, $baseline, 14, Font::HelveticaBold, $brand->ink(), Align::Right, $width );

				$pdf->gap( 12 );
				$pdf->line( $x, $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->hairline(), 0.5 );
				$pdf->gap( 14 );
			}
		}

		if ( '' !== $standing ) {
			$pdf->gap( 6 );
			$pdf->block( $standing, $pdf->left(), $pdf->content_width(), 8.5, Font::Helvetica, $brand->muted(), Align::Left, 12 );
		}
	}
}
