<?php
/**
 * Small TrueType fonts made to order, for testing the reader and the
 * subsetter against every case a real font may or may not happen to have.
 *
 * The default font has seven glyphs at 2048 units per em:
 *
 *   0 .notdef   simple
 *   1 A         simple
 *   2 acute     simple, negative left side bearing
 *   3 Á         composite: A + acute, word arguments, a scale
 *   4 Ǻ         composite of a composite: Á + acute, byte arguments,
 *               an x/y scale on the second component
 *   5 B         simple
 *   6 space     no outline
 *
 * mapped from A, U+00B4, Á, Ǻ, B, space and — in the format 12 map — U+1F600
 * to B. Only the first three glyphs carry a full hmtx entry; the rest share
 * the last advance, as the format allows.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

/**
 * Class FontBuilder
 */
final class FontBuilder {

	/**
	 * Advances in font units for the three full hmtx entries.
	 */
	public const ADVANCES = array( 1024, 1366, 512 );

	/**
	 * Left side bearings for every glyph.
	 */
	public const BEARINGS = array( 50, 20, -30, 20, 20, 60, 0 );

	/**
	 * A font, with the parts a test wants changed.
	 *
	 * @param array<string, mixed> $options cmap (4, 12 or both), long_loca,
	 *                                      fs_type, tag, extra tables, drop
	 *                                      tables, upem, weight, italic, and
	 *                                      groups: more format 12 mappings,
	 *                                      unsorted: segments out of order.
	 *
	 * @return string
	 */
	public static function font( array $options = array() ): string {
		$options += array(
			'cmap'      => 'both',
			'long_loca' => false,
			'fs_type'   => 0,
			'tag'       => "\x00\x01\x00\x00",
			'extra'     => array(),
			'drop'      => array(),
			'upem'      => 2048,
			'weight'    => 700,
			'italic'    => true,
			'groups'    => array(),
			'unsorted'  => false,
		);

		$glyphs = self::glyphs();
		$glyf   = '';
		$loca   = array( 0 );

		foreach ( $glyphs as $glyph ) {
			$glyf  .= $glyph;
			$loca[] = strlen( $glyf );
		}

		$tables = array(
			'OS/2' => self::os2( (int) $options['fs_type'], (int) $options['weight'], (bool) $options['italic'] ),
			'cmap' => self::cmap( (string) $options['cmap'], (array) $options['groups'], (bool) $options['unsorted'] ),
			'glyf' => $glyf,
			'head' => self::head( (bool) $options['long_loca'], (int) $options['upem'] ),
			'hhea' => self::hhea( count( self::ADVANCES ) ),
			'hmtx' => self::hmtx(),
			'loca' => $options['long_loca'] ? pack( 'N*', ...$loca ) : pack( 'n*', ...array_map( static fn( int $offset ): int => intdiv( $offset, 2 ), $loca ) ),
			'maxp' => pack( 'Nn14', 0x00010000, count( $glyphs ), 8, 2, 2, 2, 2, 0, 0, 0, 0, 0, 0, 2, 2 ),
			'name' => self::name(),
			'post' => pack( 'NNnnNNNNN', 0x00030000, ( -12 * 65536 ) & 0xFFFFFFFF, 0, 0, 0, 0, 0, 0, 0 ),
		);

		foreach ( (array) $options['extra'] as $tag => $bytes ) {
			$tables[ $tag ] = $bytes;
		}

		foreach ( (array) $options['drop'] as $tag ) {
			unset( $tables[ $tag ] );
		}

		return self::assemble( $tables, (string) $options['tag'] );
	}

	/**
	 * The default font's glyph outlines, each padded to an even length.
	 *
	 * @return list<string>
	 */
	public static function glyphs(): array {
		// A one-point contour: enough to be a valid simple glyph.
		$simple = static fn( int $x, int $y ): string => pack( 'nnnnn', 1, $x, 0, $x + 10, $y ) . pack( 'nn', 0, 0 ) . "\x01" . pack( 'nn', $x, $y ) . "\0";

		// Á: A + acute. ARG_1_AND_2_ARE_WORDS | ARGS_ARE_XY_VALUES | ROUND |
		// MORE_COMPONENTS, then the acute with WE_HAVE_A_SCALE.
		$aacute = pack( 'nnnnn', 0xFFFF, 0, 0, 1366, 1800 )
			. pack( 'nnnn', 0x0001 | 0x0002 | 0x0004 | 0x0020, 1, 0, 0 )
			. pack( 'nnnnn', 0x0001 | 0x0002 | 0x0008, 2, 300, 200, 0x4000 );

		// Ǻ: Á (itself composite) with byte arguments and MORE_COMPONENTS,
		// then the acute with an x and y scale and instructions after it.
		$ring = pack( 'nnnnn', 0xFFFF, 0, 0, 1366, 2000 )
			. pack( 'nn', 0x0002 | 0x0020, 3 ) . pack( 'cc', 5, -5 )
			. pack( 'nn', 0x0002 | 0x0040 | 0x0100, 2 ) . pack( 'cc', 10, 100 ) . pack( 'nn', 0x4000, 0x3000 )
			. pack( 'n', 2 ) . "\xB0\x01";

		return array_map(
			static fn( string $glyph ): string => $glyph . ( strlen( $glyph ) % 2 ? "\0" : '' ),
			array(
				$simple( 100, 700 ),
				$simple( 20, 1400 ),
				$simple( 0, 1700 ),
				$aacute,
				$ring,
				$simple( 60, 1400 ),
				'',
			)
		);
	}

	/**
	 * The tables of a font file, by tag.
	 *
	 * @param string $font Font bytes.
	 *
	 * @return array<string, array{offset: int, length: int, checksum: int, bytes: string}>
	 */
	public static function tables( string $font ): array {
		$count  = unpack( 'n', $font, 4 )[1];
		$tables = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$record = unpack( 'a4tag/Nchecksum/Noffset/Nlength', $font, 12 + ( $index * 16 ) );

			$tables[ $record['tag'] ] = array(
				'offset'   => $record['offset'],
				'length'   => $record['length'],
				'checksum' => $record['checksum'],
				'bytes'    => substr( $font, $record['offset'], $record['length'] ),
			);
		}

		return $tables;
	}

	/**
	 * A table checksum, as the format defines it.
	 *
	 * @param string $bytes Table bytes.
	 *
	 * @return int
	 */
	public static function checksum( string $bytes ): int {
		$bytes .= str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
		$sum    = 0;

		foreach ( unpack( 'N*', $bytes ) ?: array() as $word ) {
			$sum = ( $sum + $word ) & 0xFFFFFFFF;
		}

		return $sum;
	}

	/**
	 * An sfnt from tables.
	 *
	 * @param array<string, string> $tables Tag => bytes.
	 * @param string                $tag    The four-byte version tag.
	 *
	 * @return string
	 */
	public static function assemble( array $tables, string $tag = "\x00\x01\x00\x00" ): string {
		ksort( $tables, SORT_STRING );

		$count     = count( $tables );
		$offset    = 12 + ( 16 * $count );
		$directory = '';
		$body      = '';

		foreach ( $tables as $name => $bytes ) {
			$directory .= str_pad( $name, 4 ) . pack( 'NNN', self::checksum( $bytes ), $offset + strlen( $body ), strlen( $bytes ) );
			$body      .= $bytes . str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
		}

		return $tag . pack( 'nnnn', $count, 0, 0, 0 ) . $directory . $body;
	}

	/**
	 * A head table.
	 *
	 * @param bool $long_loca 32-bit loca offsets.
	 * @param int  $upem      Units per em.
	 *
	 * @return string
	 */
	private static function head( bool $long_loca, int $upem ): string {
		return pack( 'NNNNnn', 0x00010000, 0x00010000, 0, 0x5F0F3CF5, 0, $upem )
			. str_repeat( "\0", 16 )
			. pack( 'nnnn', -100 & 0xFFFF, -400 & 0xFFFF, 2000, 2100 )
			. pack( 'nnnnn', 0x0001, 8, 2, $long_loca ? 1 : 0, 0 );
	}

	/**
	 * An hhea table.
	 *
	 * @param int $metrics numberOfHMetrics.
	 *
	 * @return string
	 */
	private static function hhea( int $metrics ): string {
		return pack( 'Nnnn', 0x00010000, 1900, -500 & 0xFFFF, 0 ) . str_repeat( "\0", 24 ) . pack( 'n', $metrics );
	}

	/**
	 * Three full entries, then bearings alone.
	 *
	 * @return string
	 */
	private static function hmtx(): string {
		$bytes = '';

		foreach ( self::BEARINGS as $glyph => $bearing ) {
			$bytes .= isset( self::ADVANCES[ $glyph ] ) ? pack( 'n', self::ADVANCES[ $glyph ] ) : '';
			$bytes .= pack( 'n', $bearing & 0xFFFF );
		}

		return $bytes;
	}

	/**
	 * An OS/2 table, version 4.
	 *
	 * @param int  $fs_type Embedding bits.
	 * @param int  $weight  usWeightClass.
	 * @param bool $italic  fsSelection bit 0.
	 *
	 * @return string
	 */
	private static function os2( int $fs_type, int $weight, bool $italic ): string {
		$table = pack( 'nnnnn', 4, 1000, $weight, 5, $fs_type ) . str_repeat( "\0", 96 - 10 );

		$table = substr_replace( $table, pack( 'n', $italic ? 0x0001 : 0x0040 ), 62, 2 );
		$table = substr_replace( $table, pack( 'nn', 1600, -400 & 0xFFFF ), 68, 4 );

		// sxHeight 1000, sCapHeight 1400.
		return substr_replace( $table, pack( 'nn', 1000, 1400 ), 86, 4 );
	}

	/**
	 * A name table with a Windows family and PostScript name.
	 *
	 * @return string
	 */
	private static function name(): string {
		$family     = mb_convert_encoding( 'Test Sans', 'UTF-16BE', 'UTF-8' );
		$postscript = mb_convert_encoding( 'Test Sans-Bold Italic', 'UTF-16BE', 'UTF-8' );

		return pack( 'nnn', 0, 2, 6 + 24 )
			. pack( 'nnnnnn', 3, 1, 0x409, 1, strlen( $family ), 0 )
			. pack( 'nnnnnn', 3, 1, 0x409, 6, strlen( $postscript ), strlen( $family ) )
			. $family . $postscript;
	}

	/**
	 * A cmap with a format 4 map, a format 12 map, or both.
	 *
	 * The format 4 map reaches A and B (glyphs 1 and 5, not consecutive)
	 * and Á through the glyph array — A and B with a delta of -1 added to
	 * what the array holds — and the space, the acute and Ǻ by a delta
	 * alone, one of them wrapping past 0xFFFF; then the closing segment.
	 * Unsorted, the segments and groups are written in reverse, which a
	 * font should not do and a reader should survive.
	 *
	 * @param string                              $which    '4', '12', 'both' or 'mac'.
	 * @param list<array{0: int, 1: int, 2: int}> $extra    More format 12 groups: first, last, glyph.
	 * @param bool                                $unsorted Write segments and groups in reverse.
	 *
	 * @return string
	 */
	private static function cmap( string $which, array $extra = array(), bool $unsorted = false ): string {
		// Segments: [start, end, delta, glyphs by range offset].
		$segments = array(
			array( 0x20, 0x20, 6 - 0x20, null ),
			array( 0x41, 0x42, 0xFFFF, array( 2, 6 ) ),
			array( 0xB4, 0xB4, ( 2 - 0xB4 ) & 0xFFFF, null ),
			array( 0xC1, 0xC1, 0, array( 3 ) ),
			array( 0x01FA, 0x01FA, ( 4 - 0x01FA ) & 0xFFFF, null ),
			array( 0xFFFF, 0xFFFF, 1, null ),
		);

		$segments = $unsorted ? array_reverse( $segments ) : $segments;
		$count    = count( $segments );
		$ends   = '';
		$starts = '';
		$deltas = '';
		$ranges = '';
		$array  = '';

		foreach ( $segments as $index => [ $start, $end, $delta, $glyphs ] ) {
			$ends   .= pack( 'n', $end );
			$starts .= pack( 'n', $start );
			$deltas .= pack( 'n', $delta & 0xFFFF );

			if ( null === $glyphs ) {
				$ranges .= pack( 'n', 0 );
				continue;
			}

			// Measured from this entry of idRangeOffset to the glyph array.
			$ranges .= pack( 'n', ( ( $count - $index ) * 2 ) + strlen( $array ) );
			$array  .= pack( 'n*', ...$glyphs );
		}

		$body    = pack( 'nnnn', $count * 2, 8, 2, 4 ) . $ends . pack( 'n', 0 ) . $starts . $deltas . $ranges . $array;
		$format4 = pack( 'nnn', 4, 6 + strlen( $body ), 0 ) . $body;

		// Format 12: the same characters, plus U+1F600 → glyph 5.
		$groups = array(
			array( 0x20, 0x20, 6 ),
			array( 0x41, 0x41, 1 ),
			array( 0x42, 0x42, 5 ),
			array( 0xB4, 0xB4, 2 ),
			array( 0xC1, 0xC1, 3 ),
			array( 0x01FA, 0x01FA, 4 ),
			array( 0x1F600, 0x1F600, 5 ),
			...$extra,
		);

		usort( $groups, static fn( array $a, array $b ): int => $unsorted ? $b[0] <=> $a[0] : $a[0] <=> $b[0] );

		$format12 = pack( 'nnNNN', 12, 0, 16 + ( 12 * count( $groups ) ), 0, count( $groups ) );

		foreach ( $groups as $group ) {
			$format12 .= pack( 'NNN', ...$group );
		}

		$subtables = match ( $which ) {
			'4'     => array( array( 3, 1, $format4 ) ),
			'12'    => array( array( 3, 10, $format12 ) ),
			'mac'   => array( array( 1, 0, $format4 ) ),
			default => array( array( 3, 1, $format4 ), array( 3, 10, $format12 ) ),
		};

		$header = pack( 'nn', 0, count( $subtables ) );
		$data   = '';
		$offset = 4 + ( 8 * count( $subtables ) );

		foreach ( $subtables as [ $platform, $encoding, $bytes ] ) {
			$header .= pack( 'nnN', $platform, $encoding, $offset + strlen( $data ) );
			$data   .= $bytes;
		}

		return $header . $data;
	}
}
