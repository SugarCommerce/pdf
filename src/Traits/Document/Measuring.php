<?php
/**
 * Measuring text before it is drawn.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Font;

/**
 * Widths, wrapping and fitting, in whichever face will draw each word.
 *
 * @since 1.0.0
 */
trait Measuring {

	/**
	 * The height {@see block()} would take, without drawing anything.
	 *
	 * For sizing a panel, or keeping a block on one page, before its
	 * contents go in.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text    Text.
	 * @param float      $width   Column width.
	 * @param float|null $size    Font size.
	 * @param Font       $font    Typeface.
	 * @param float|null $leading Distance between baselines.
	 *
	 * @return float
	 */
	public function block_height( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica, ?float $leading = null ): float {
		$size ??= $this->font_size;

		return count( $this->wrap( $text, $font, $size, $width ) ) * ( $leading ?? $size * 1.35 );
	}

	/**
	 * Text broken into the lines that fit a width.
	 *
	 * The same breaks {@see block()} makes: at spaces, at the newlines
	 * given, and through a word wider than the column.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text  Text.
	 * @param float      $width Column width.
	 * @param float|null $size  Font size.
	 * @param Font       $font  Typeface.
	 *
	 * @return list<string>
	 */
	public function lines( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica ): array {
		return $this->wrap( $text, $font, $size ?? $this->font_size, $width );
	}

	/**
	 * The width of text as this document will draw it.
	 *
	 * In the face each word will be drawn in — the family's, a base font,
	 * or the fallback — and after transcoding, so `€` in Helvetica counts
	 * as the one character it prints as rather than the three bytes it is
	 * in UTF-8.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text Text.
	 * @param float|null $size Font size.
	 * @param Font       $font Typeface.
	 *
	 * @return float
	 */
	public function width( string $text, ?float $size = null, Font $font = Font::Helvetica ): float {
		$size ??= $this->font_size;
		$total  = 0.0;

		foreach ( $this->pieces( $text, $font ) as [ $face, $piece ] ) {
			$total += null === $face ? $font->width_of( self::winansi( $piece ), $size ) : $face->width_of( $piece, $size );
		}

		return $total;
	}

	/**
	 * Text shortened with an ellipsis to fit a width, measured as drawn.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text  Text.
	 * @param float      $width Available width.
	 * @param float|null $size  Font size.
	 * @param Font       $font  Typeface.
	 *
	 * @return string The text unchanged when it fits.
	 */
	public function fit( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica ): string {
		$size ??= $this->font_size;

		if ( $this->width( $text, $size, $font ) <= $width ) {
			return $text;
		}

		$budget = $width - $this->width( '...', $size, $font );
		$kept   = '';
		$used   = 0.0;

		foreach ( $this->advances( $text, $font, $size ) as [ $character, $advance ] ) {
			$used += $advance;

			if ( $used > $budget ) {
				break;
			}

			$kept .= $character;
		}

		// Checked as drawn, as a cut piece is: the shortened last word may
		// resolve to another face than the whole word was measured in.
		while ( '' !== $kept && $this->width( rtrim( $kept ) . '...', $size, $font ) > $width ) {
			$kept = mb_substr( $kept, 0, -1, 'UTF-8' );
		}

		return rtrim( $kept ) . '...';
	}

	/**
	 * Break text to fit a width, honouring embedded newlines.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text  Text.
	 * @param Font   $font  Typeface.
	 * @param float  $size  Font size.
	 * @param float  $width Available width.
	 *
	 * @return list<string>
	 */
	private function wrap( string $text, Font $font, float $size, float $width ): array {
		$lines = array();
		$space = $this->width( ' ', $size, $font );

		foreach ( explode( "\n", $text ) as $paragraph ) {
			$paragraph = rtrim( $paragraph );

			if ( '' === $paragraph ) {
				$lines[] = '';
				continue;
			}

			$line = '';
			$used = 0.0;

			// Each word is measured once and the line's width kept as a
			// sum: there is no kerning in the base fonts, so a line is
			// exactly its words and the spaces between them. Re-measuring
			// the whole line for every word is quadratic in its length.
			foreach ( explode( ' ', $paragraph ) as $word ) {
				$advance = $this->width( $word, $size, $font );

				if ( '' === $line && $advance <= $width ) {
					$line = $word;
					$used = $advance;
					continue;
				}

				if ( '' !== $line && $used + $space + $advance <= $width ) {
					$line .= ' ' . $word;
					$used += $space + $advance;
					continue;
				}

				if ( '' !== $line ) {
					$lines[] = $line;
				}

				// A single word wider than the column — a long URL, say —
				// must be cut or it runs off the page.
				$line = $advance > $width ? $this->cut( $word, $font, $size, $width, $lines ) : $word;
				$used = $this->width( $line, $size, $font );
			}

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Cut a word wider than the column into column-wide pieces.
	 *
	 * Walks the word once, measuring a character at a time and cutting on
	 * character boundaries. Re-measuring the remainder after every cut —
	 * the obvious loop — is quadratic, and a few hundred kilobytes of
	 * unbroken text in a customer-supplied field then holds a worker for
	 * minutes. Cutting mid-character would also break the UTF-8, and one
	 * broken sequence turns every accent on the line into `?`.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $word  The word, wider than `$width`.
	 * @param Font         $font  Typeface.
	 * @param float        $size  Font size.
	 * @param float        $width Available width.
	 * @param list<string> $lines Completed lines; every full piece is
	 *                            appended here.
	 *
	 * @return string The last, partial piece, which later words may join.
	 */
	private function cut( string $word, Font $font, float $size, float $width, array &$lines ): string {
		$piece = '';
		$used  = 0.0;

		foreach ( $this->advances( $word, $font, $size ) as [ $character, $advance ] ) {
			// Never emit an empty piece: a single character wider than the
			// column stands on a line of its own, which also guarantees
			// the walk makes progress.
			if ( '' !== $piece && $used + $advance > $width ) {
				[ $lines[], $piece ] = $this->settle( $piece, $font, $size, $width );

				$used = $this->width( $piece, $size, $font );
			}

			$piece .= $character;
			$used  += $advance;
		}

		return $piece;
	}

	/**
	 * A cut piece, checked as it will be drawn.
	 *
	 * The characters were measured in the face their whole word resolves
	 * to, and a piece is resolved again on its own: the head of a long
	 * Polish URL is plain ASCII and is drawn in Helvetica, not the Noto
	 * it was measured in. Where that makes it wider, characters move on
	 * to the next piece until it fits. Usually there is nothing to move.
	 *
	 * @since 1.3.0
	 *
	 * @param string $piece The piece.
	 * @param Font   $font  Role.
	 * @param float  $size  Font size.
	 * @param float  $width Column width.
	 *
	 * @return array{0: string, 1: string} The piece that fits, and what moves on.
	 */
	private function settle( string $piece, Font $font, float $size, float $width ): array {
		$carry = '';

		while ( mb_strlen( $piece, 'UTF-8' ) > 1 && $this->width( $piece, $size, $font ) > $width ) {
			$carry = mb_substr( $piece, -1, null, 'UTF-8' ) . $carry;
			$piece = mb_substr( $piece, 0, -1, 'UTF-8' );
		}

		return array( $piece, $carry );
	}

	/**
	 * Each character of a text with the width it will be drawn at.
	 *
	 * Measured in the face its word resolves to, so cutting a word or
	 * shortening a value to fit agrees with how the line is drawn.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text Text.
	 * @param Font   $font Role.
	 * @param float  $size Font size.
	 *
	 * @return \Generator<int, array{0: string, 1: float}> One at a time: a
	 *         value of half a megabyte is half a million of them.
	 */
	private function advances( string $text, Font $font, float $size ): \Generator {
		$known = array();

		foreach ( $this->pieces( $text, $font ) as [ $face, $piece ] ) {
			$id = null === $face ? 0 : spl_object_id( $face );

			foreach ( self::characters( $piece ) as $character ) {
				yield array(
					$character,
					$known[ $id ][ $character ] ??= null === $face ? $font->width_of( self::winansi( $character ), $size ) : $face->width_of( $character, $size ),
				);
			}
		}
	}
}
