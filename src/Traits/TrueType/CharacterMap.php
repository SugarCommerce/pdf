<?php
/**
 * Which glyph draws a character.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\TrueType;

/**
 * The cmap table, formats 4 and 12, and the lookup through it.
 *
 * @since 1.3.0
 */
trait CharacterMap {

	/**
	 * Choose the Unicode character map and read its segments.
	 *
	 * Format 12 covers every plane and is preferred where the font has it;
	 * format 4 covers the Basic Multilingual Plane, which is every
	 * alphabet an invoice is likely to need. Windows and Unicode platform
	 * maps both qualify; the old Macintosh one is not Unicode.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return array{0: array{0: int, 1: int}, 1: list<array{0: int, 1: int, 2: int, 3?: int, 4?: int}>}
	 *
	 * @throws \InvalidArgumentException When there is no Unicode map in a supported format.
	 */
	private function read_cmap( string $label ): array {
		[ $offset, $length ] = $this->tables['cmap'];

		$count = $this->u16( $offset + 2 );
		$best  = null;
		$rank  = 0;

		for ( $index = 0; $index < $count && 4 + ( ( $index + 1 ) * 8 ) <= $length; $index++ ) {
			$record   = $offset + 4 + ( $index * 8 );
			$platform = $this->u16( $record );
			$encoding = $this->u16( $record + 2 );
			$at       = $offset + $this->u32( $record + 4 );

			if ( $at + 4 > $offset + $length ) {
				continue;
			}

			$format  = $this->u16( $at );
			$unicode = 0 === $platform || ( 3 === $platform && ( 1 === $encoding || 10 === $encoding ) );

			if ( ! $unicode || ( 4 !== $format && 12 !== $format ) ) {
				continue;
			}

			$score = 12 === $format ? 2 : 1;

			if ( $score > $rank ) {
				$best = array( $format, $at );
				$rank = $score;
			}
		}

		if ( null === $best ) {
			throw new \InvalidArgumentException( $label . ' has no Unicode character map in format 4 or 12, so its characters cannot be found.' );
		}

		$end = $offset + $length;

		return array( $best, 4 === $best[0] ? $this->format_4( $best[1], $end, $label ) : $this->format_12( $best[1], $end, $label ) );
	}

	/**
	 * Segments of a format 4 map.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $at    Subtable offset.
	 * @param int    $end   End of the cmap table.
	 * @param string $label Font name for errors.
	 *
	 * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> End, start, delta, range offset, its position.
	 *
	 * @throws \InvalidArgumentException When the segments run past the table.
	 */
	private function format_4( int $at, int $end, string $label ): array {
		$segments = intdiv( $this->u16( $at + 6 ), 2 );
		$ends     = $at + 14;
		$starts   = $ends + ( $segments * 2 ) + 2;
		$deltas   = $starts + ( $segments * 2 );
		$ranges   = $deltas + ( $segments * 2 );

		if ( $ranges + ( $segments * 2 ) > $end ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its character map runs past its table.' );
		}

		$found = array();

		for ( $index = 0; $index < $segments; $index++ ) {
			$found[] = array(
				$this->u16( $ends + ( $index * 2 ) ),
				$this->u16( $starts + ( $index * 2 ) ),
				$this->u16( $deltas + ( $index * 2 ) ),
				$this->u16( $ranges + ( $index * 2 ) ),
				$ranges + ( $index * 2 ),
			);
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $found;
	}

	/**
	 * Groups of a format 12 map.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $at    Subtable offset.
	 * @param int    $end   End of the cmap table.
	 * @param string $label Font name for errors.
	 *
	 * @return list<array{0: int, 1: int, 2: int}> End, start, first glyph.
	 *
	 * @throws \InvalidArgumentException When the groups run past the table.
	 */
	private function format_12( int $at, int $end, string $label ): array {
		$groups = $this->u32( $at + 12 );

		if ( $at + 16 + ( $groups * 12 ) > $end ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its character map runs past its table.' );
		}

		$found = array();

		for ( $index = 0; $index < $groups; $index++ ) {
			$record  = $at + 16 + ( $index * 12 );
			$found[] = array( $this->u32( $record + 4 ), $this->u32( $record ), $this->u32( $record + 8 ) );
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $found;
	}

	/**
	 * Look a code point up in the chosen map.
	 *
	 * A binary search for the first segment ending at or after the code,
	 * then the format's own arithmetic. Format 4's is the odd one: a
	 * segment either adds a delta to the code, or points into an array of
	 * glyph IDs by an offset measured from where the offset itself sits.
	 *
	 * @since 1.3.0
	 *
	 * @param int $code Code point.
	 *
	 * @return int Glyph ID, 0 for none.
	 */
	private function lookup( int $code ): int {
		$low  = 0;
		$high = count( $this->segments ) - 1;

		while ( $low < $high ) {
			$middle = intdiv( $low + $high, 2 );

			if ( $this->segments[ $middle ][0] < $code ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		$segment = $this->segments[ $low ] ?? null;

		if ( null === $segment || $segment[0] < $code || $segment[1] > $code ) {
			return 0;
		}

		if ( 12 === $this->cmap[0] ) {
			return $segment[2] + ( $code - $segment[1] );
		}

		[ , $start, $delta, $range, $position ] = $segment + array(
			3 => 0,
			4 => 0,
		);

		if ( 0 === $range ) {
			return ( $code + $delta ) & 0xFFFF;
		}

		$at = $position + $range + ( ( $code - $start ) * 2 );

		if ( $at + 2 > $this->tables['cmap'][0] + $this->tables['cmap'][1] ) {
			return 0;
		}

		$glyph = $this->u16( $at );

		return 0 === $glyph ? 0 : ( $glyph + $delta ) & 0xFFFF;
	}
}
