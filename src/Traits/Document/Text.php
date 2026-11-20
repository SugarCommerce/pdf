<?php
/**
 * Text on the page.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Color;
use SugarCommerce\Pdf\Models\Font;

/**
 * A line in the flow, in a box, in a band or at a point; columns; a
 * wrapped block that runs onto the next page; and the record of every
 * run drawn.
 *
 * @since 1.0.0
 */
trait Text {

	/**
	 * Write a line and advance the cursor.
	 *
	 * Wraps to the content width and paginates.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text  Text.
	 * @param float|null $size  Font size, or null for the document default.
	 * @param Font       $font  Typeface.
	 * @param Color|null $color Colour.
	 * @param Align      $align Alignment within the content column.
	 *
	 * @return self
	 */
	public function text(
		string $text,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		foreach ( $this->wrap( $text, $font, $size, $this->content_width() ) as $line ) {
			$this->break_if_needed( $this->leading );

			// The cursor marks the TOP of the line box; PDF positions
			// text by its baseline. Drawing at the cursor puts ascenders
			// above the margin and through anything drawn above.
			$this->text_at(
				$line,
				$this->margin,
				$this->y - $this->ascender( $size, $font ),
				$size,
				$font,
				$color,
				$align,
				$this->content_width()
			);

			$this->y -= $this->leading;
		}

		return $this;
	}

	/**
	 * Write a heading.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text  Text.
	 * @param float|null $size  Font size.
	 * @param Color|null $color Colour.
	 *
	 * @return self
	 */
	public function heading( string $text, ?float $size = null, ?Color $color = null ): self {
		return $this->text( $text, $size ?? $this->font_size + 2, Font::HelveticaBold, $color );
	}

	/**
	 * Write text at a horizontal offset on the current line, without
	 * advancing the cursor.
	 *
	 * Baseline handling is done for you — pass the column position and
	 * the width to align within.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text      Text.
	 * @param float      $x         Left edge of the box.
	 * @param float      $box_width Width to align within.
	 * @param float|null $size      Font size.
	 * @param Font       $font      Typeface.
	 * @param Color|null $color     Colour.
	 * @param Align      $align     Alignment.
	 *
	 * @return self
	 */
	public function cell(
		string $text,
		float $x,
		float $box_width,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		return $this->text_at( $text, $x, $this->y - $this->ascender( $size, $font ), $size, $font, $color, $align, $box_width );
	}

	/**
	 * Write text vertically centred in a band starting at the cursor.
	 *
	 * The band runs from the cursor down by `$band_height`. Use it for
	 * anything drawn on a filled rectangle — totals boxes, table rows,
	 * header strips — so the text sits optically centred rather than
	 * pinned to the top.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text        Text.
	 * @param float      $x           Left edge of the box.
	 * @param float      $box_width   Width to align within.
	 * @param float      $band_height Height of the band.
	 * @param float|null $size        Font size.
	 * @param Font       $font        Typeface.
	 * @param Color|null $color       Colour.
	 * @param Align      $align       Alignment.
	 *
	 * @return self
	 */
	public function banded(
		string $text,
		float $x,
		float $box_width,
		float $band_height,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		return $this->text_at(
			$text,
			$x,
			$this->y - $this->baseline_in_band( $band_height, $size, $font ),
			$size,
			$font,
			$color,
			$align,
			$box_width
		);
	}

	/**
	 * Write text at an absolute position, leaving the cursor alone.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text      Text.
	 * @param float      $x         Left edge of the box.
	 * @param float      $y         Baseline.
	 * @param float      $size      Font size.
	 * @param Font       $font      Typeface.
	 * @param Color|null $color     Colour.
	 * @param Align      $align     Alignment within `$box_width`.
	 * @param float      $box_width Width to align within.
	 *
	 * @return self
	 */
	public function text_at(
		string $text,
		float $x,
		float $y,
		float $size,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left,
		float $box_width = 0.0
	): self {
		if ( '' === $text ) {
			return $this;
		}

		$size    = self::number( $size );
		$pieces  = $this->pieces( $text, $font );
		$drawn   = '';
		$measure = 0.0;
		$show    = '';
		$current = '';
		$lossy   = false;

		foreach ( $pieces as [ $face, $piece, $replaced ] ) {
			$lossy = $lossy || $replaced;

			if ( null === $face ) {
				$bytes = self::winansi( $piece );

				if ( '' === $bytes ) {
					continue;
				}

				$resource  = $this->resource( $font );
				$operand   = '(' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $bytes ) . ')';
				$measure  += $font->width_of( $bytes, $size );
				$drawn    .= (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
			} else {
				$resource  = $this->resource( $face );
				$operand   = '<' . $this->subsets[ spl_object_id( $face ) ]->encode( $piece ) . '>';
				$measure  += $face->width_of( $piece, $size );
				$drawn    .= $piece;
			}

			// One text object for the whole run, switching font only where
			// the face changes: each show advances from where the last
			// ended, so the pieces sit together as one line.
			if ( $resource !== $current ) {
				$show   .= sprintf( "/%s %.2F Tf\n", $resource, $size );
				$current = $resource;
			}

			$show .= $operand . " Tj\n";
		}

		if ( '' === $show ) {
			return $this;
		}

		// What a reader will extract, not what was asked for: a name the
		// fonts could not hold has not reached the page as written.
		$this->drawn[] = $drawn;

		if ( $lossy && ! in_array( $text, $this->substituted, true ) ) {
			$this->substituted[] = $text;
		}

		// Alignment is computed from the measured width — the reason the
		// font metrics exist at all. The escaping backslashes are never
		// drawn, so they are not measured.
		if ( Align::Left !== $align && $box_width > 0 ) {
			$x += Align::Right === $align ? $box_width - $measure : ( $box_width - $measure ) / 2;
		}

		$color ??= Color::black();

		$this->current .= "q\n";

		if ( ! $color->is_black() ) {
			$this->current .= $color->operands() . " rg\n";
		}

		// The position goes after the first font is chosen, as it always
		// has: one Tf, one Td, one Tj for a run in a single font.
		$position       = sprintf( "%.2F %.2F Td\n", self::number( $x ), self::number( $y ) );
		$this->current .= "BT\n" . self::positioned( $show, $position ) . "ET\nQ\n";

		return $this;
	}

	/**
	 * Font selections and shows with the text position put in after the
	 * first selection.
	 *
	 * @since 1.3.0
	 *
	 * @param string $show     Font selections and shows.
	 * @param string $position The `Td` line.
	 *
	 * @return string
	 */
	private static function positioned( string $show, string $position ): string {
		$at = (int) strpos( $show, "Tf\n" ) + 3;

		return substr( $show, 0, $at ) . $position . substr( $show, $at );
	}

	/**
	 * Write a row of columns across the content width.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $cells  Cell text.
	 * @param array<int, float>  $widths Column widths. Empty splits evenly.
	 * @param array<int, Align>  $aligns Per-column alignment.
	 * @param Font               $font   Typeface.
	 * @param float|null         $size   Font size.
	 * @param Color|null         $color  Colour.
	 *
	 * @return self
	 */
	public function columns(
		array $cells,
		array $widths = array(),
		array $aligns = array(),
		Font $font = Font::Helvetica,
		?float $size = null,
		?Color $color = null
	): self {
		$size ??= $this->font_size;
		$cells  = array_values( $cells );
		$count  = count( $cells );

		if ( 0 === $count ) {
			return $this;
		}

		if ( array() === $widths ) {
			$widths = array_fill( 0, $count, $this->content_width() / $count );
		}

		$this->break_if_needed( $this->leading );

		$x = $this->margin;

		foreach ( $cells as $index => $cell ) {
			$column = $widths[ $index ] ?? ( $this->content_width() / $count );

			$this->text_at(
				$this->fit( (string) $cell, $column - 6, $size, $font ),
				$x,
				$this->y - $this->ascender( $size, $font ),
				$size,
				$font,
				$color,
				$aligns[ $index ] ?? Align::Left,
				$column - 6
			);

			$x += $column;
		}

		$this->y -= $this->leading;

		return $this;
	}

	/**
	 * Draw wrapped text in a column, advancing the cursor.
	 *
	 * Where {@see cell()} draws one line and anything past the box edge is
	 * simply not there — right for a table cell cut short on purpose,
	 * wrong for a note somebody wrote — this breaks the text into lines
	 * that fit, keeps the newlines it was given, and starts a new page
	 * when the next line would go below the bottom margin.
	 *
	 * The cursor marks the top of the first line and ends one line's
	 * leading below the last, as {@see text()} does.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text    Text; `\n` starts a new line.
	 * @param float      $x       Left edge of the column.
	 * @param float      $width   Column width.
	 * @param float|null $size    Font size, or null for the document default.
	 * @param Font       $font    Typeface.
	 * @param Color|null $color   Colour.
	 * @param Align      $align   Alignment of each line within the column.
	 * @param float|null $leading Distance between baselines; 1.35 times the
	 *                            size when null.
	 *
	 * @return self
	 */
	public function block(
		string $text,
		float $x,
		float $width,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left,
		?float $leading = null
	): self {
		$size  ??= $this->font_size;
		$step    = $leading ?? $size * 1.35;

		foreach ( $this->wrap( $text, $font, $size, $width ) as $line ) {
			$this->break_if_needed( $step );

			if ( '' !== $line ) {
				$this->text_at( $line, $x, $this->y - $this->ascender( $size, $font ), $size, $font, $color, $align, $width );
			}

			$this->y -= $step;
		}

		return $this;
	}

	/**
	 * Every run of text drawn so far, as a reader will extract it.
	 *
	 * The characters as they reached the page: a word drawn in an embedded
	 * font is recorded as written, one the base fonts printed is recorded
	 * as they printed it — `?` for a character nothing could draw. It
	 * includes what the footer draws on each page.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string>
	 */
	public function drawn_text(): array {
		return $this->with_footers()[0];
	}
}
