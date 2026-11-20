<?php
/**
 * Reading a TrueType font's tables.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\TrueType;

/**
 * The table directory, the licence bits, the glyph locations and
 * outlines, the horizontal and vertical metrics and the names, each
 * checked against the length of the file before it is read.
 *
 * @since 1.3.0
 */
trait Reading {

	/**
	 * The table directory.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 *
	 * @throws \InvalidArgumentException When a table lies outside the file.
	 */
	private function directory( string $label ): array {
		$count  = $this->u16( 4 );
		$size   = strlen( $this->data );
		$tables = array();

		if ( 12 + ( $count * 16 ) > $size ) {
			throw new \InvalidArgumentException( $label . ' is cut short: its table directory runs past the end of the file.' );
		}

		for ( $index = 0; $index < $count; $index++ ) {
			$record = 12 + ( $index * 16 );
			$tag    = substr( $this->data, $record, 4 );
			$offset = $this->u32( $record + 8 );
			$length = $this->u32( $record + 12 );

			if ( $offset + $length > $size ) {
				throw new \InvalidArgumentException( $label . ' is damaged: its ' . trim( $tag ) . ' table runs past the end of the file.' );
			}

			$tables[ $tag ] = array( $offset, $length );
		}

		return $tables;
	}

	/**
	 * Refuse a font whose licence bits say it may not be embedded.
	 *
	 * OS/2 `fsType`: bit 1 is "restricted licence embedding", bit 9
	 * "bitmap embedding only", bit 8 "no subsetting" — and a subset is the
	 * only way this embeds anything.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When embedding is not allowed.
	 */
	private function check_licence( string $label ): void {
		if ( ! isset( $this->tables['OS/2'] ) || $this->tables['OS/2'][1] < 10 ) {
			return;
		}

		$type = $this->u16( $this->tables['OS/2'][0] + 8 );

		if ( 0 !== ( $type & 0x0002 ) || 0 !== ( $type & 0x0200 ) ) {
			throw new \InvalidArgumentException( $label . '\'s licence does not allow it to be embedded in a document (OS/2 fsType ' . $type . ').' );
		}

		if ( 0 !== ( $type & 0x0100 ) ) {
			throw new \InvalidArgumentException( $label . '\'s licence does not allow it to be subset, and embedding the whole font in every document is not something this does (OS/2 fsType ' . $type . ').' );
		}
	}

	/**
	 * Glyph offsets into `glyf`, short or long as `head` says.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return list<int>
	 *
	 * @throws \InvalidArgumentException When loca is too short or points outside glyf.
	 */
	private function read_loca( string $label ): array {
		[ $offset, $length ] = $this->tables['loca'];

		$long    = 1 === $this->u16( $this->tables['head'][0] + 50 );
		$entries = $this->glyph_count + 1;

		if ( $length < $entries * ( $long ? 4 : 2 ) ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its loca table has fewer entries than it has glyphs.' );
		}

		$values = array_values( (array) unpack( ( $long ? 'N' : 'n' ) . $entries, $this->data, $offset ) );
		$loca   = array();
		$limit  = $this->tables['glyf'][1];

		foreach ( $values as $value ) {
			$value  = $long ? (int) $value : (int) $value * 2;
			$loca[] = min( $value, $limit );
		}

		return $loca;
	}

	/**
	 * Advance widths and left side bearings for every glyph.
	 *
	 * `hmtx` holds `numberOfHMetrics` full pairs, then a bearing alone for
	 * each glyph after them, which shares the last advance. The bearing is
	 * kept: a rasteriser positions an outline by it, and an italic `f`
	 * whose ink starts left of its origin says so there.
	 *
	 * @since 1.3.0
	 *
	 * @return array{0: list<int>, 1: list<int>}
	 */
	private function read_metrics(): array {
		[ $offset, $length ] = $this->tables['hmtx'];

		$metrics  = max( 1, min( $this->u16( $this->tables['hhea'][0] + 34 ), $this->glyph_count ) );
		$pairs    = min( $metrics, intdiv( $length, 4 ) );
		$advances = array();
		$bearings = array();
		$last     = 0;

		if ( $pairs > 0 ) {
			$values = array_values( (array) unpack( 'n' . ( $pairs * 2 ), $this->data, $offset ) );

			for ( $index = 0; $index < $pairs; $index++ ) {
				$last       = (int) $values[ $index * 2 ];
				$advances[] = $last;
				$bearings[] = self::signed( (int) $values[ ( $index * 2 ) + 1 ] );
			}
		}

		$rest = min( $this->glyph_count - $pairs, intdiv( max( 0, $length - ( $pairs * 4 ) ), 2 ) );

		if ( $rest > 0 ) {
			foreach ( (array) unpack( 'n' . $rest, $this->data, $offset + ( $pairs * 4 ) ) as $bearing ) {
				$advances[] = $last;
				$bearings[] = self::signed( (int) $bearing );
			}
		}

		// A table shorter than it claims: the missing glyphs take the last
		// advance and no bearing, rather than reading past the table.
		while ( count( $advances ) < $this->glyph_count ) {
			$advances[] = $last;
			$bearings[] = 0;
		}

		return array( $advances, $bearings );
	}

	/**
	 * Vertical metrics, scaled to 1/1000 em.
	 *
	 * Three tables disagree about an ascender, and each is right about
	 * something: `hhea` has the designer's line spacing, OS/2's `sTypo*`
	 * what a modern layout engine is told to use, `usWin*` the clipping
	 * bounds Windows enforces. OS/2's typographic values win where they
	 * are set, `hhea` otherwise. Cap height and x-height exist from OS/2
	 * version 2; below that they are estimated from the ascender.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function read_vertical_metrics(): void {
		$scale = 1000 / $this->units_per_em;
		$head  = $this->tables['head'][0];
		$hhea  = $this->tables['hhea'][0];

		$ascender   = self::signed( $this->u16( $hhea + 4 ) ) * $scale;
		$descender  = self::signed( $this->u16( $hhea + 6 ) ) * $scale;
		$cap_height = 0.0;
		$x_height   = 0.0;
		$weight     = 400;
		$italic     = false;

		if ( isset( $this->tables['OS/2'] ) && $this->tables['OS/2'][1] >= 78 ) {
			[ $os2, $length ] = $this->tables['OS/2'];

			$weight = $this->u16( $os2 + 4 );
			$italic = 0 !== ( $this->u16( $os2 + 62 ) & 0x0001 );
			$typo_a = self::signed( $this->u16( $os2 + 68 ) ) * $scale;
			$typo_d = self::signed( $this->u16( $os2 + 70 ) ) * $scale;

			$ascender  = 0.0 !== $typo_a ? $typo_a : $ascender;
			$descender = 0.0 !== $typo_d ? $typo_d : $descender;

			if ( $this->u16( $os2 ) >= 2 && $length >= 90 ) {
				$x_height   = self::signed( $this->u16( $os2 + 86 ) ) * $scale;
				$cap_height = self::signed( $this->u16( $os2 + 88 ) ) * $scale;
			}
		}

		// macStyle, for a font whose OS/2 table is missing or wrong.
		$style = $this->u16( $head + 44 );

		$this->ascender     = $ascender > 0 ? $ascender : 800.0;
		$this->descender    = $descender < 0 ? $descender : -200.0;
		$this->cap_height   = $cap_height > 0 ? $cap_height : $this->ascender * 0.92;
		$this->x_height     = $x_height > 0 ? $x_height : $this->cap_height * 0.73;
		$this->weight       = max( 1, min( 1000, $weight ) );
		$this->bold         = 0 !== ( $style & 0x0001 ) || $this->weight >= 600;
		$this->italic       = 0 !== ( $style & 0x0002 ) || $italic;
		$this->bbox         = array(
			self::signed( $this->u16( $head + 36 ) ) * $scale,
			self::signed( $this->u16( $head + 38 ) ) * $scale,
			self::signed( $this->u16( $head + 40 ) ) * $scale,
			self::signed( $this->u16( $head + 42 ) ) * $scale,
		);
		$this->italic_angle = isset( $this->tables['post'] ) && $this->tables['post'][1] >= 16
			? self::signed32( $this->u32( $this->tables['post'][0] + 4 ) ) / 65536
			: 0.0;
		$this->fixed_pitch  = isset( $this->tables['post'] ) && $this->tables['post'][1] >= 16 && 0 !== $this->u32( $this->tables['post'][0] + 12 );
	}

	/**
	 * The PostScript name, cleaned for a PDF name object.
	 *
	 * A PDF name cannot hold spaces or delimiters, and an empty one is no
	 * name at all — so a font with no usable record is called by its
	 * family, then `Embedded`.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function postscript_name(): string {
		foreach ( array( 6, 4, 1 ) as $id ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) $this->name_record( $id ) );

			if ( '' !== $name ) {
				return substr( $name, 0, 63 );
			}
		}

		return 'Embedded';
	}

	/**
	 * A string from the `name` table, preferring the Windows Unicode record.
	 *
	 * @since 1.3.0
	 *
	 * @param int $id Name ID: 1 family, 4 full name, 6 PostScript name.
	 *
	 * @return string|null
	 */
	private function name_record( int $id ): ?string {
		if ( ! isset( $this->tables['name'] ) || $this->tables['name'][1] < 6 ) {
			return null;
		}

		[ $offset, $length ] = $this->tables['name'];

		$count   = $this->u16( $offset + 2 );
		$storage = $offset + $this->u16( $offset + 4 );
		$found   = null;

		for ( $index = 0; $index < $count && 6 + ( ( $index + 1 ) * 12 ) <= $length; $index++ ) {
			$record = $offset + 6 + ( $index * 12 );

			if ( $this->u16( $record + 6 ) !== $id ) {
				continue;
			}

			$platform = $this->u16( $record );
			$size     = $this->u16( $record + 8 );
			$at       = $storage + $this->u16( $record + 10 );

			if ( $at + $size > $offset + $length ) {
				continue;
			}

			$bytes = substr( $this->data, $at, $size );

			if ( 3 === $platform || 0 === $platform ) {
				return trim( (string) mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16BE' ) );
			}

			if ( 1 === $platform ) {
				$found ??= trim( (string) preg_replace( '/[^\x20-\x7E]/', '', $bytes ) );
			}
		}

		return $found;
	}

	/**
	 * A glyph's outline bytes, empty for a glyph with none (a space).
	 *
	 * @since 1.3.0
	 *
	 * @param int $glyph Glyph ID.
	 *
	 * @return string
	 */
	private function outline( int $glyph ): string {
		if ( $glyph < 0 || $glyph >= $this->glyph_count ) {
			return '';
		}

		$start = $this->loca[ $glyph ];
		$end   = $this->loca[ $glyph + 1 ];

		return $end > $start ? substr( $this->data, $this->tables['glyf'][0] + $start, $end - $start ) : '';
	}

	/**
	 * Where each component's glyph ID sits in a composite outline.
	 *
	 * After the 10-byte header, each component is flags, a glyph ID, two
	 * arguments (bytes or words, as flag 0x0001 says), then a scale of
	 * none, one, two or four values (0x0008, 0x0040, 0x0080). Flag 0x0020
	 * says another component follows.
	 *
	 * @since 1.3.0
	 *
	 * @param string $outline Glyph bytes.
	 *
	 * @return list<int> Byte offsets of the glyph IDs; empty for a simple glyph.
	 */
	private static function component_offsets( string $outline ): array {
		$length = strlen( $outline );

		if ( $length < 10 || self::signed( self::read16( $outline, 0 ) ) >= 0 ) {
			return array();
		}

		$found  = array();
		$cursor = 10;

		while ( $cursor + 4 <= $length ) {
			$flags   = self::read16( $outline, $cursor );
			$found[] = $cursor + 2;
			$cursor += 4 + ( 0 !== ( $flags & 0x0001 ) ? 4 : 2 );

			if ( 0 !== ( $flags & 0x0008 ) ) {
				$cursor += 2;
			} elseif ( 0 !== ( $flags & 0x0040 ) ) {
				$cursor += 4;
			} elseif ( 0 !== ( $flags & 0x0080 ) ) {
				$cursor += 8;
			}

			if ( 0 === ( $flags & 0x0020 ) ) {
				break;
			}
		}

		return $found;
	}
}
