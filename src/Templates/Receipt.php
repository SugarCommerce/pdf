<?php
/**
 * Receipt template.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

use SugarCommerce\Pdf\{Align, Color, Document, Font, Orientation, PageSize, Table};

/**
 * Class Receipt
 *
 * Confirmation that something has been paid — narrower and calmer than
 * an invoice, because nobody needs to act on it.
 *
 * The distinction matters more than it looks: an invoice is a demand and
 * a receipt is a record, and sending an invoice-shaped document after
 * payment reliably produces "have I already paid this?" support tickets.
 *
 * Defaults to A5, which suits a document with a handful of lines and
 * looks deliberate rather than like an invoice with the middle missing.
 *
 * @since 1.0.0
 */
final readonly class Receipt {

	/**
	 * Describe a receipt.
	 *
	 * @since 1.0.0
	 *
	 * @param Branding              $branding   Visual identity.
	 * @param string                $number     Receipt reference.
	 * @param Party                 $to         Who paid.
	 * @param array<int, LineItem>  $items      What was bought.
	 * @param string                $total      Amount paid, formatted.
	 * @param array<string, string> $details    Date, method, order reference.
	 * @param array<string, string> $breakdown  Subtotal and tax rows.
	 * @param string                $notes      Anything else worth saying.
	 * @param PageSize              $size       Paper size.
	 * @param Translator            $translator Source of the template's own
	 *                                          wording.
	 * @param Party|null            $from       Who was paid (since 1.2.0):
	 *                                          printed under the wordmark,
	 *                                          with the branding's address
	 *                                          when it has none.
	 */
	public function __construct(
		public Branding $branding,
		public string $number,
		public Party $to,
		public array $items,
		public string $total,
		public array $details = array(),
		public array $breakdown = array(),
		public string $notes = '',
		public PageSize $size = PageSize::A5,
		public Translator $translator = Language::English,
		public ?Party $from = null,
	) {}

	/**
	 * Lay the receipt out.
	 *
	 * @since 1.0.0
	 *
	 * @since 1.5.0 Archival, for PDF/A-3b.
	 *
	 * @param bool $archival Write it as PDF/A-3b: see {@see Document::archival()}.
	 *
	 * @return Document
	 */
	public function render( bool $archival = false ): Document {
		$pdf   = new Document( $this->size, Orientation::Portrait, margin: 40, font_size: 9, leading: 12 );

		if ( $archival ) {
			$pdf->archival();
		}

		$brand = $this->branding;

		$pdf->family( $brand->typeface );

		$top = $pdf->height() - 40;

		$mark = $brand->logo( $pdf, $pdf->left(), $top, 26, $brand->ink() );
		$x    = $pdf->left() + ( $mark > 0 ? $mark + 10 : 0 );

		$pdf->text_at( strtoupper( $brand->name ), $x, $top - 12, 12, Font::HelveticaBold, $brand->ink() );
		$pdf->text_at( $this->translator->translate( 'receipt', 'RECEIPT' ), $pdf->left(), $top - 12, 15, Font::HelveticaBold, $brand->ink(), Align::Right, $pdf->content_width() );
		$pdf->text_at( $this->number, $pdf->left(), $top - 25, 8.5, Font::Helvetica, $brand->muted(), Align::Right, $pdf->content_width() );

		// Who was paid: a receipt is a record, and a record that does not
		// say who issued it is not much of one.
		$rule_y = $top - 44;
		$lines  = $brand->seller_lines( $brand->seller( $this->from ), $this->translator );

		if ( array() !== $lines ) {
			$pdf->move_to( $top - 26 + $pdf->ascender( 7.5 ) );
			$pdf->block( implode( "\n", $lines ), $x, $pdf->content_width() * 0.55, 7.5, Font::Helvetica, $brand->muted(), Align::Left, 9.5 );
			$rule_y = min( $rule_y, $pdf->cursor() - 6 );
		}

		$pdf->move_to( $rule_y );
		$pdf->line( $pdf->left(), $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 0.75 );
		$pdf->move_to( $pdf->cursor() - 22 );

		// A receipt says "paid" plainly. Anything less definite gets read
		// as another request for money.
		$pdf->text( $this->translator->translate( 'paid_in_full', 'PAID IN FULL' ), 10, Font::HelveticaBold, $brand->ink() );
		$pdf->gap( 8 );

		$pdf->text( $this->to->name, 10, Font::HelveticaBold, $brand->ink() );
		$pdf->block( implode( "\n", $this->to->translated_lines( $this->translator ) ), $pdf->left(), $pdf->content_width(), 8.5, Font::Helvetica, $brand->muted(), Align::Left, $pdf->leading );

		if ( array() !== $this->details ) {
			$pdf->gap( 10 );

			foreach ( Rows::pairs( $this->details ) as [ $label, $value ] ) {
				$baseline = $pdf->cursor();

				$pdf->text_at( (string) $label, $pdf->left(), $baseline - 7, 8.5, Font::Helvetica, $brand->muted() );
				$pdf->text_at( (string) $value, $pdf->left(), $baseline - 7, 8.5, Font::HelveticaBold, $brand->ink(), Align::Right, $pdf->content_width() );

				$pdf->gap( 12 );
			}
		}

		$pdf->gap( 12 );

		$table = ( new Table( array( $this->translator->translate( 'description', 'Description' ), $this->translator->translate( 'amount', 'Amount' ) ) ) )
			->widths( array( 0.72, 0.28 ) )
			->align( array( 1 => Align::Right ) )
			->row_height( 18 );

		$table->wrap();

		foreach ( $this->items as $item ) {
			$quantity = '1' !== $item->quantity ? ' x' . $item->quantity : '';

			$table->row( array( $item->description . $quantity, $item->amount ), $item->note );
		}

		$table->draw( $pdf, 8.5 );

		$width = $pdf->content_width() * 0.5;
		$x     = $pdf->right() - $width;

		$pdf->gap( 12 );

		foreach ( Rows::pairs( $this->breakdown ) as [ $label, $value ] ) {
			$baseline = $pdf->cursor();

			$pdf->text_at( (string) $label, $x, $baseline, 8.5, Font::Helvetica, $brand->muted() );
			$pdf->text_at( (string) $value, $x, $baseline, 8.5, Font::Helvetica, $brand->ink(), Align::Right, $width );

			$pdf->gap( 13 );
		}

		$pdf->gap( 2 );
		$pdf->line( $x, $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 1 );
		$pdf->gap( 18 );

		$baseline = $pdf->cursor();
		$pdf->text_at( $this->translator->translate( 'paid', 'Paid' ), $x, $baseline, 10.5, Font::HelveticaBold, $brand->ink() );
		$pdf->text_at( $this->total, $x, $baseline, 13, Font::HelveticaBold, $brand->ink(), Align::Right, $width );

		if ( '' !== trim( $this->notes ) ) {
			$pdf->gap( 26 );
			$pdf->line( $pdf->left(), $pdf->cursor() + 12, $pdf->right(), $pdf->cursor() + 12, $brand->hairline(), 0.5 );
			$pdf->gap( 6 );
			$pdf->block( $this->notes, $pdf->left(), $pdf->content_width(), 8, Font::Helvetica, $brand->muted(), Align::Left, 11 );
		}

		return $pdf;
	}

	/**
	 * Save straight to a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Destination.
	 *
	 * @return int Bytes written.
	 */
	public function save( string $path ): int {
		return $this->render()->save( $path, array(
			'Title'  => $this->translator->translate( 'receipt', 'RECEIPT' ) . ' ' . $this->number,
			'Author' => $this->branding->seller( $this->from )->name,
		) );
	}
}
