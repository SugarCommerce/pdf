<?php
/**
 * A font cut down to the glyphs a document draws.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\TrueType;

/**
 * The subset, and the font file written around it with a fresh table
 * directory and checksums.
 *
 * @since 1.3.0
 */
trait Subsetting {

	/**
	 * A new font holding only some glyphs, numbered in the order given.
	 *
	 * The order is the caller's: text is written to the page before the
	 * subset exists, as glyph positions in it, so the font is built to fit
	 * those positions. `.notdef` stays at 0, as every font must have it;
	 * the glyphs asked for follow in order; then every glyph a composite
	 * among them is built from, and theirs in turn, where they cannot
	 * disturb the positions already written.
	 *
	 * Only the tables a PDF reader draws from are kept — head, hhea, maxp,
	 * hmtx, loca, glyf, and the hinting programs when there are any. The
	 * character map is left out: under Identity-H the PDF itself says
	 * which glyph each code is.
	 *
	 * @since 1.3.0
	 *
	 * @param list<int> $requested Glyph IDs, in the order they were first used.
	 *
	 * @return array{0: string, 1: array<int, int>} The font, and old glyph ID => new.
	 */
	public function subset( array $requested ): array {
		$ordered = array( 0 );
		$mapping = array( 0 => 0 );

		foreach ( $requested as $glyph ) {
			if ( $glyph >= 0 && $glyph < $this->glyph_count && ! isset( $mapping[ $glyph ] ) ) {
				$mapping[ $glyph ] = count( $ordered );
				$ordered[]         = $glyph;
			}
		}

		// Breadth first over a list that grows as it is walked, so a
		// component that is itself a composite brings its own along.
		for ( $index = 0; $index < count( $ordered ); $index++ ) {
			foreach ( $this->components( $ordered[ $index ] ) as $component ) {
				if ( $component < $this->glyph_count && ! isset( $mapping[ $component ] ) ) {
					$mapping[ $component ] = count( $ordered );
					$ordered[]             = $component;
				}
			}
		}

		$glyf = '';
		$loca = pack( 'N', 0 );
		$hmtx = '';

		foreach ( $ordered as $old ) {
			$outline = $this->outline( $old );
			$offsets = self::component_offsets( $outline );

			// The components still name glyphs by their old IDs; point them
			// at the new ones, or the accent lands on the wrong letter.
			foreach ( $offsets as $offset ) {
				$outline = substr_replace( $outline, pack( 'n', $mapping[ self::read16( $outline, $offset ) ] ?? 0 ), $offset, 2 );
			}

			// Four-byte aligned, as the loca offsets assume.
			$glyf .= $outline . str_repeat( "\0", ( 4 - ( strlen( $outline ) % 4 ) ) % 4 );
			$loca .= pack( 'N', strlen( $glyf ) );
			$hmtx .= pack( 'nn', $this->advances[ $old ] ?? 0, ( $this->bearings[ $old ] ?? 0 ) & 0xFFFF );
		}

		$count = count( $ordered );

		// Long offsets, and the checksum adjustment cleared until the whole
		// file exists to be summed.
		$head = substr_replace( $this->table( 'head' ), pack( 'n', 1 ), 50, 2 );
		$head = substr_replace( $head, "\0\0\0\0", 8, 4 );
		$hhea = substr_replace( $this->table( 'hhea' ), pack( 'n', $count ), 34, 2 );
		$maxp = substr_replace( $this->table( 'maxp' ), pack( 'n', $count ), 4, 2 );

		$tables = array(
			'glyf' => $glyf,
			'head' => $head,
			'hhea' => $hhea,
			'hmtx' => $hmtx,
			'loca' => $loca,
			'maxp' => $maxp,
		);

		// A hinted glyph's instructions call functions in fpgm and read
		// values from cvt; dropping those makes a rasteriser that runs the
		// hints fail on every glyph. Small, so kept when present.
		foreach ( array( 'cvt ', 'fpgm', 'prep' ) as $hinting ) {
			if ( isset( $this->tables[ $hinting ] ) ) {
				$tables[ $hinting ] = $this->table( $hinting );
			}
		}

		$font = self::assemble( $tables );

		// The whole file, including this field, must sum to 0xB1B0AFBA.
		$adjustment = ( 0xB1B0AFBA - self::checksum( $font ) ) & 0xFFFFFFFF;
		$position   = strpos( $font, 'head' );
		$at         = self::read32( $font, (int) $position + 8 ) + 8;

		return array( substr_replace( $font, pack( 'N', $adjustment ), $at, 4 ), $mapping );
	}

	/**
	 * Table bytes as the file has them, or empty when it has none.
	 *
	 * @since 1.3.0
	 *
	 * @param string $tag Four-character table tag.
	 *
	 * @return string
	 */
	public function table( string $tag ): string {
		if ( ! isset( $this->tables[ $tag ] ) ) {
			return '';
		}

		return substr( $this->data, $this->tables[ $tag ][0], $this->tables[ $tag ][1] );
	}

	/**
	 * The tags of every table the file has.
	 *
	 * @since 1.3.0
	 *
	 * @return list<string>
	 */
	public function tags(): array {
		return array_keys( $this->tables );
	}

	/**
	 * A font file with only the tables given, the rest dropped.
	 *
	 * What the bundled family was made with: the shaping and kerning
	 * tables (GSUB, GPOS, GDEF) are for engines this writer is not, and
	 * glyph names in `post` are for nobody — together a third of the file.
	 *
	 * @since 1.3.0
	 *
	 * @param list<string>          $keep    Tags to keep.
	 * @param array<string, string> $replace Tag => replacement bytes.
	 *
	 * @return string
	 */
	public function without_tables( array $keep, array $replace = array() ): string {
		$tables = array();

		foreach ( $keep as $tag ) {
			if ( isset( $replace[ $tag ] ) ) {
				$tables[ $tag ] = $replace[ $tag ];
			} elseif ( isset( $this->tables[ $tag ] ) ) {
				$tables[ $tag ] = $this->table( $tag );
			}
		}

		$tables['head'] = substr_replace( $tables['head'] ?? $this->table( 'head' ), "\0\0\0\0", 8, 4 );
		$font           = self::assemble( $tables );
		$at             = self::read32( $font, (int) strpos( $font, 'head' ) + 8 ) + 8;

		return substr_replace( $font, pack( 'N', ( 0xB1B0AFBA - self::checksum( $font ) ) & 0xFFFFFFFF ), $at, 4 );
	}

	/**
	 * An sfnt file from its tables, sorted by tag as readers expect.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, string> $tables Tag => bytes.
	 *
	 * @return string
	 */
	private static function assemble( array $tables ): string {
		ksort( $tables, SORT_STRING );

		$count    = count( $tables );
		$power    = 1;
		$selector = 0;

		while ( $power * 2 <= $count ) {
			$power *= 2;
			++$selector;
		}

		$range     = $power * 16;
		$header    = pack( 'Nnnnn', 0x00010000, $count, $range, $selector, ( $count * 16 ) - $range );
		$directory = '';
		$body      = '';
		$offset    = 12 + ( $count * 16 );

		foreach ( $tables as $tag => $bytes ) {
			$padded     = $bytes . str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
			$directory .= str_pad( $tag, 4 ) . pack( 'NNN', self::checksum( $padded ), $offset, strlen( $bytes ) );
			$body      .= $padded;
			$offset    += strlen( $padded );
		}

		return $header . $directory . $body;
	}

	/**
	 * A table checksum: the sum of its big-endian 32-bit words.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes Bytes, padded with zeros to a multiple of four.
	 *
	 * @return int
	 */
	private static function checksum( string $bytes ): int {
		$bytes .= str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
		$sum    = 0;

		foreach ( str_split( $bytes, 32768 ) as $chunk ) {
			foreach ( (array) unpack( 'N*', $chunk ) as $word ) {
				$sum = ( $sum + (int) $word ) & 0xFFFFFFFF;
			}
		}

		return $sum;
	}
}
