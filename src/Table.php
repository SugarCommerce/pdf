<?php
/**
 * Tabular layout with pagination.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Table
 *
 * Column layout with measured widths, per-column alignment, zebra
 * striping and — the part that matters for a statement — headers that
 * repeat on every page.
 *
 * A three-page statement whose column headings appear only on page one
 * is unreadable from page two onward, and that is the default behaviour
 * of every hand-rolled table loop.
 *
 * Typical use:
 *
 *   ( new Table( [ 'Description', 'Qty', 'Amount' ] ) )
 *       ->widths( [ 0.6, 0.15, 0.25 ] )
 *       ->align( [ 2 => Align::Right, 1 => Align::Center ] )
 *       ->rows( $rows )
 *       ->draw( $pdf );
 *
 * @since 1.0.0
 */
final class Table {

	/**
	 * Body rows.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $rows = array();

	/**
	 * A quieter line under a row's first cell, by row index.
	 *
	 * @var array<int, string>
	 */
	private array $notes = array();

	/**
	 * Column widths as fractions of the content width.
	 *
	 * @var list<float>
	 */
	private array $widths = array();

	/**
	 * Per-column alignment.
	 *
	 * @var array<int, Align>
	 */
	private array $aligns = array();

	/**
	 * Whether alternate rows are shaded.
	 */
	private bool $striped = false;

	/**
	 * Whether cells wrap rather than being cut short.
	 */
	private bool $wrapped = false;

	/**
	 * Row height in points.
	 */
	private float $row_height = 18.0;

	/**
	 * Padding either side of a cell's text.
	 */
	private const PADDING = 6.0;

	/**
	 * Start a table with optional column headings.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $headers Column headings.
	 */
	public function __construct( private readonly array $headers = array() ) {}

	/**
	 * Set column widths as fractions of the content width.
	 *
	 * Fractions rather than points, so the same table lays out correctly
	 * on A4 and Letter without being redefined.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, float> $fractions Values summing to roughly 1.0.
	 *
	 * @return self
	 */
	public function widths( array $fractions ): self {
		$this->widths = array_values( $fractions );

		return $this;
	}

	/**
	 * Set per-column alignment.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, Align> $aligns Column index => alignment.
	 *
	 * @return self
	 */
	public function align( array $aligns ): self {
		$this->aligns = $aligns;

		return $this;
	}

	/**
	 * Add a row.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The note.
	 *
	 * @param array<int, string> $cells Cell values.
	 * @param string             $note  A quieter line set under the first
	 *                                  cell — hours, a licence period, a
	 *                                  date range — rather than run on
	 *                                  after it, where it reads as part of
	 *                                  what was sold.
	 *
	 * @return self
	 */
	public function row( array $cells, string $note = '' ): self {
		$this->rows[] = array_values( array_map( 'strval', $cells ) );

		if ( '' !== trim( $note ) ) {
			$this->notes[ count( $this->rows ) - 1 ] = $note;
		}

		return $this;
	}

	/**
	 * Add several rows.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array<int, string>> $rows Rows.
	 *
	 * @return self
	 */
	public function rows( array $rows ): self {
		foreach ( $rows as $cells ) {
			$this->row( (array) $cells );
		}

		return $this;
	}

	/**
	 * Shade alternate rows.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled Whether to stripe.
	 *
	 * @return self
	 */
	public function striped( bool $enabled = true ): self {
		$this->striped = $enabled;

		return $this;
	}

	/**
	 * Wrap long cells onto more lines instead of cutting them short.
	 *
	 * A row grows to its tallest cell. A row that fits on a page is never
	 * split across two: it moves to the next page whole, under the
	 * repeated header. Only a row taller than a whole page is split, line
	 * by line, rather than running off the bottom.
	 *
	 * Off by default, where a cell too long for its column ends in `...`
	 * and every row is the same height — right for a ledger, wrong for an
	 * item somebody described at length.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $enabled Whether to wrap.
	 *
	 * @return self
	 */
	public function wrap( bool $enabled = true ): self {
		$this->wrapped = $enabled;

		return $this;
	}

	/**
	 * Set the row height.
	 *
	 * With {@see wrap()}, the height of a row of one line; each further
	 * line adds its leading.
	 *
	 * @since 1.0.0
	 *
	 * @param float $points Height.
	 *
	 * @return self
	 */
	public function row_height( float $points ): self {
		$this->row_height = $points;

		return $this;
	}

	/**
	 * How many rows the table holds.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->rows );
	}

	/**
	 * Draw the table, paginating and repeating headers as needed.
	 *
	 * @since 1.0.0
	 *
	 * @param Document   $pdf         Target document.
	 * @param float|null $size        Font size.
	 * @param Color|null $header_fill Header background.
	 * @param Color|null $note_color  Colour of the notes under first cells
	 *                                (since 1.2.0); mid grey when null.
	 *
	 * @return Document The document, for chaining.
	 */
	public function draw( Document $pdf, ?float $size = null, ?Color $header_fill = null, ?Color $note_color = null ): Document {
		$size     ??= $pdf->font_size;
		$widths     = $this->resolve_widths( $pdf->content_width() );
		$note_color ??= Color::grey( 115 );

		$this->draw_header( $pdf, $widths, $size, $header_fill );

		// The most a page holds under its header: a row taller than this
		// cannot be kept whole anywhere, so it is the one kind that splits.
		$page_body = $pdf->page_room() - ( array() === $this->headers ? 0.0 : $this->row_height );

		foreach ( $this->rows as $index => $cells ) {
			$columns = $this->layout_row( $pdf, $cells, $this->notes[ $index ] ?? '', $widths, $size, $note_color );
			$height  = $this->row_height + self::extra( $columns );

			// Repeat the header whenever the table spills onto a new page,
			// or everything past page one is a column of unlabelled
			// numbers. Room for one more row is kept, as it always was, so
			// a row is never left alone at the foot of a page; a row too
			// tall for any page only needs room to start.
			$needed = $height <= $page_body ? $height + $this->row_height : $this->row_height * 2;

			if ( $pdf->remaining() < $needed ) {
				$pdf->page_break();
				$this->draw_header( $pdf, $widths, $size, $header_fill );
			}

			while ( true ) {
				[ $segment, $columns ] = self::take( $columns, max( $this->row_height, $pdf->remaining() ), $this->row_height );
				$segment_height        = $this->row_height + self::extra( $segment );

				if ( $this->striped && 1 === $index % 2 ) {
					$pdf->rect( $pdf->left(), $pdf->cursor() - $segment_height, $pdf->content_width(), $segment_height, Color::grey( 248 ) );
				}

				$this->draw_lines( $pdf, $segment, $widths, $size );
				$pdf->gap( $segment_height );

				if ( ! self::has_lines( $columns ) ) {
					break;
				}

				$pdf->page_break();
				$this->draw_header( $pdf, $widths, $size, $header_fill );
			}
		}

		return $pdf;
	}

	/**
	 * Draw the header band.
	 *
	 * @since 1.0.0
	 *
	 * @param Document    $pdf    Target document.
	 * @param list<float> $widths Resolved column widths in points.
	 * @param float       $size   Font size.
	 * @param Color|null  $fill   Background colour.
	 *
	 * @return void
	 */
	private function draw_header( Document $pdf, array $widths, float $size, ?Color $fill ): void {
		if ( array() === $this->headers ) {
			return;
		}

		if ( null !== $fill ) {
			$pdf->rect( $pdf->left(), $pdf->cursor() - $this->row_height, $pdf->content_width(), $this->row_height, $fill );
		}

		$top   = $pdf->cursor();
		$cells = array();

		foreach ( array_values( $this->headers ) as $index => $header ) {
			$inner           = ( $widths[ $index ] ?? 0 ) - ( self::PADDING * 2 );
			$cells[ $index ] = array( array( $pdf->fit( (string) $header, $inner, $size, Font::HelveticaBold ), $size, Font::HelveticaBold, Color::grey( 30 ), 0.0, false ) );
		}

		$this->draw_lines( $pdf, $cells, $widths, $size );
		$pdf->gap( $this->row_height );

		// Rules above and below the heading row read as a header without
		// needing a filled band, and survive being printed in mono.
		$pdf->line( $pdf->left(), $top, $pdf->right(), $top, Color::grey( 60 ), 0.75 );
		$pdf->line( $pdf->left(), $pdf->cursor(), $pdf->right(), $pdf->cursor(), Color::grey( 60 ), 0.75 );
	}

	/**
	 * Lay one row out as lines, column by column.
	 *
	 * Each line is `[ text, size, font, colour, advance, is_note ]`, the
	 * advance being its distance below the line before it — zero for a
	 * column's first line, which sits where a one-line row's text sits.
	 *
	 * @since 1.2.0
	 *
	 * @param Document           $pdf        Target document, for measuring.
	 * @param array<int, string> $cells      Cell values.
	 * @param string             $note       Line under the first cell.
	 * @param list<float>        $widths     Column widths in points.
	 * @param float              $size       Font size.
	 * @param Color              $note_color Colour of the note.
	 *
	 * @return array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>>
	 */
	private function layout_row( Document $pdf, array $cells, string $note, array $widths, float $size, Color $note_color ): array {
		$leading = round( $size * 1.28, 2 );
		$small   = round( $size * 0.83, 2 );
		$columns = array();

		foreach ( array_values( $cells ) as $index => $cell ) {
			$inner = ( $widths[ $index ] ?? 0 ) - ( self::PADDING * 2 );
			$lines = $this->wrapped
				? $pdf->lines( (string) $cell, max( 1.0, $inner ), $size )
				: array( $pdf->fit( str_replace( array( "\r", "\n" ), ' ', (string) $cell ), $inner, $size ) );

			$columns[ $index ] = array();

			foreach ( $lines as $number => $line ) {
				$columns[ $index ][] = array( $line, $size, Font::Helvetica, null, 0 === $number ? 0.0 : $leading, false );
			}
		}

		if ( '' !== $note && isset( $columns[0] ) ) {
			$inner = ( $widths[0] ?? 0 ) - ( self::PADDING * 2 );
			$lines = $this->wrapped
				? $pdf->lines( $note, max( 1.0, $inner ), $small )
				: array( $pdf->fit( str_replace( array( "\r", "\n" ), ' ', $note ), $inner, $small ) );

			foreach ( $lines as $number => $line ) {
				$columns[0][] = array( $line, $small, Font::Helvetica, $note_color, 0 === $number ? $size + 0.5 : $small + 2, true );
			}
		}

		return $columns;
	}

	/**
	 * Draw a row's lines at the cursor, leaving the cursor where it was.
	 *
	 * @since 1.2.0
	 *
	 * @param Document                                                                                   $pdf     Target document.
	 * @param array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>> $columns Lines by column.
	 * @param list<float>                                                                                $widths  Column widths in points.
	 * @param float                                                                                      $size    Font size of the row.
	 *
	 * @return void
	 */
	private function draw_lines( Document $pdf, array $columns, array $widths, float $size ): void {
		$x     = $pdf->left();
		$first = $pdf->cursor() - Font::Helvetica->baseline_in_band( $this->row_height, $size );

		foreach ( $widths as $index => $width ) {
			if ( $width > 0 ) {
				$baseline = $first;

				foreach ( $columns[ $index ] ?? array() as [ $text, $line_size, $font, $color, $advance, $is_note ] ) {
					$baseline -= $advance;

					$pdf->text_at(
						$text,
						$x + self::PADDING,
						$baseline,
						$line_size,
						$font,
						$color,
						$is_note ? Align::Left : ( $this->aligns[ $index ] ?? Align::Left ),
						$width - ( self::PADDING * 2 )
					);
				}
			}

			$x += $width;
		}
	}

	/**
	 * How much taller than a one-line row these lines make a row.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>> $columns Lines by column.
	 *
	 * @return float
	 */
	private static function extra( array $columns ): float {
		$tallest = 0.0;

		foreach ( $columns as $lines ) {
			$tallest = max( $tallest, array_sum( array_column( $lines, 4 ) ) );
		}

		return $tallest;
	}

	/**
	 * Whether any column still has lines to draw.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>> $columns Lines by column.
	 *
	 * @return bool
	 */
	private static function has_lines( array $columns ): bool {
		foreach ( $columns as $lines ) {
			if ( array() !== $lines ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split off the lines that fit a height, column by column.
	 *
	 * Always takes at least each column's first line, so a split row
	 * makes progress on every page however little room there is.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>> $columns    Lines by column.
	 * @param float                                                                                      $available  Height there is.
	 * @param float                                                                                      $row_height Height of a one-line row.
	 *
	 * @return array{0: array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>>, 1: array<int, list<array{0: string, 1: float, 2: Font, 3: Color|null, 4: float, 5: bool}>>}
	 */
	private static function take( array $columns, float $available, float $row_height ): array {
		$taken = array();
		$left  = array();

		foreach ( $columns as $index => $lines ) {
			$used            = $row_height;
			$taken[ $index ] = array();
			$left[ $index ]  = array();

			foreach ( $lines as $number => $line ) {
				if ( 0 !== $number && $used + $line[4] > $available ) {
					$left[ $index ] = array_slice( $lines, $number );
					break;
				}

				$used             += $line[4];
				$taken[ $index ][] = $line;
			}

			// The first line of what is left sits at the top of its row.
			if ( array() !== $left[ $index ] ) {
				$left[ $index ][0][4] = 0.0;
			}
		}

		return array( $taken, $left );
	}

	/**
	 * Turn width fractions into points.
	 *
	 * @since 1.0.0
	 *
	 * @param float $available Content width in points.
	 *
	 * @return list<float>
	 */
	private function resolve_widths( float $available ): array {
		$columns = max( count( $this->headers ), array() === $this->rows ? 0 : count( $this->rows[0] ) );

		if ( 0 === $columns ) {
			return array();
		}

		if ( array() === $this->widths ) {
			return array_fill( 0, $columns, $available / $columns );
		}

		$total = array_sum( $this->widths );

		if ( $total <= 0 ) {
			return array_fill( 0, $columns, $available / $columns );
		}

		return array_map(
			static fn( float $fraction ): float => ( $fraction / $total ) * $available,
			$this->widths
		);
	}
}
