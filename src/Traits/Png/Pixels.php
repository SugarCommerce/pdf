<?php
/**
 * A PNG's rows, decoded.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Png;

/**
 * The rows unfiltered, the samples unpacked to eight bits, the passes
 * of an interlaced image put back together, and alpha split into a
 * mask of its own.
 *
 * @since 1.4.0
 */
trait Pixels {

	/**
	 * Every pixel's samples, top row first, one byte a sample — two at
	 * sixteen bits — with interlacing undone.
	 *
	 * @since 1.4.0
	 *
	 * @param string $idat       The joined IDAT data.
	 * @param int    $width      Pixels across.
	 * @param int    $height     Pixels down.
	 * @param int    $channels   Samples a pixel.
	 * @param int    $depth      Bits a sample.
	 * @param bool   $interlaced Adam7 or not.
	 * @param string $label      What to call the file.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the data is damaged.
	 */
	private static function samples( string $idat, int $width, int $height, int $channels, int $depth, bool $interlaced, string $label ): string {
		$passes = $interlaced ? self::ADAM7 : array( array( 0, 0, 1, 1 ) );
		$sizes  = array();

		foreach ( $passes as $index => [ $x0, $y0, $dx, $dy ] ) {
			$sizes[ $index ] = array(
				$width > $x0 ? intdiv( $width - $x0 + $dx - 1, $dx ) : 0,
				$height > $y0 ? intdiv( $height - $y0 + $dy - 1, $dy ) : 0,
			);
		}

		// An empty pass has no rows and no filter bytes.
		$expected = 0;

		foreach ( $sizes as [ $columns, $rows ] ) {
			$expected += $columns > 0 ? $rows * ( 1 + self::row_bytes( $columns, $channels, $depth ) ) : 0;
		}

		$raw    = self::inflate( $idat, $expected, $label );
		$offset = 0;
		$pixel  = $channels * ( 16 === $depth ? 2 : 1 );
		$lines  = array();

		foreach ( $sizes as $index => [ $columns, $rows ] ) {
			if ( 0 === $columns || 0 === $rows ) {
				$lines[ $index ] = array();
				continue;
			}

			$lines[ $index ] = self::unfilter( $raw, $offset, $rows, self::row_bytes( $columns, $channels, $depth ), max( 1, intdiv( $channels * $depth, 8 ) ), $label );
			$offset         += $rows * ( 1 + self::row_bytes( $columns, $channels, $depth ) );

			if ( $depth < 8 ) {
				$lines[ $index ] = self::unpack_bits( $lines[ $index ], $depth, $columns );
			}
		}

		if ( ! $interlaced ) {
			return implode( '', $lines[0] );
		}

		$image = '';

		for ( $y = 0; $y < $height; $y++ ) {
			$row = array_fill( 0, $width, '' );

			foreach ( $passes as $index => [ $x0, $y0, $dx, $dy ] ) {
				if ( $y < $y0 || 0 !== ( $y - $y0 ) % $dy || array() === $lines[ $index ] ) {
					continue;
				}

				$line = $lines[ $index ][ intdiv( $y - $y0, $dy ) ];

				for ( $x = $x0, $k = 0; $x < $width; $x += $dx, $k++ ) {
					$row[ $x ] = substr( $line, $k * $pixel, $pixel );
				}
			}

			$image .= implode( '', $row );
		}

		return $image;
	}

	/**
	 * Undo the per-row filters.
	 *
	 * @since 1.4.0
	 *
	 * @param string $raw    Inflated data.
	 * @param int    $offset Where the first row's filter byte is.
	 * @param int    $rows   Rows to read.
	 * @param int    $length Bytes a row, without its filter byte.
	 * @param int    $bpp    Bytes a pixel, at least one: how far back "left" is.
	 * @param string $label  What to call the file.
	 *
	 * @return list<string> The rows, unfiltered.
	 *
	 * @throws \InvalidArgumentException When a row names a filter PNG does not have.
	 */
	private static function unfilter( string $raw, int $offset, int $rows, int $length, int $bpp, string $label ): array {
		$out   = array();
		$prior = array_fill( 1, $length, 0 );

		for ( $row = 0; $row < $rows; $row++ ) {
			$filter  = ord( $raw[ $offset ] );
			$current = (array) unpack( 'C*', substr( $raw, $offset + 1, $length ) );
			$offset += $length + 1;

			switch ( $filter ) {
				case 0:
					break;
				case 1:
					for ( $i = $bpp + 1; $i <= $length; $i++ ) {
						$current[ $i ] = ( $current[ $i ] + $current[ $i - $bpp ] ) & 0xFF;
					}
					break;
				case 2:
					for ( $i = 1; $i <= $length; $i++ ) {
						$current[ $i ] = ( $current[ $i ] + $prior[ $i ] ) & 0xFF;
					}
					break;
				case 3:
					for ( $i = 1; $i <= $length; $i++ ) {
						$left          = $i > $bpp ? $current[ $i - $bpp ] : 0;
						$current[ $i ] = ( $current[ $i ] + ( ( $left + $prior[ $i ] ) >> 1 ) ) & 0xFF;
					}
					break;
				case 4:
					for ( $i = 1; $i <= $length; $i++ ) {
						$a = $i > $bpp ? $current[ $i - $bpp ] : 0;
						$b = $prior[ $i ];
						$c = $i > $bpp ? $prior[ $i - $bpp ] : 0;

						$estimate = $a + $b - $c;
						$pa       = abs( $estimate - $a );
						$pb       = abs( $estimate - $b );
						$pc       = abs( $estimate - $c );

						$current[ $i ] = ( $current[ $i ] + ( $pa <= $pb && $pa <= $pc ? $a : ( $pb <= $pc ? $b : $c ) ) ) & 0xFF;
					}
					break;
				default:
					throw new \InvalidArgumentException( $label . ' is damaged: a row uses a filter PNG does not define.' );
			}

			$out[] = pack( 'C*', ...$current );
			$prior = $current;
		}

		return $out;
	}

	/**
	 * Spread samples of one, two or four bits out to a byte each, keeping
	 * their values, and drop the padding at the end of each row.
	 *
	 * @since 1.4.0
	 *
	 * @param list<string> $rows    Packed rows.
	 * @param int          $depth   1, 2 or 4.
	 * @param int          $columns Samples a row.
	 *
	 * @return list<string>
	 */
	private static function unpack_bits( array $rows, int $depth, int $columns ): array {
		static $tables = array();

		if ( ! isset( $tables[ $depth ] ) ) {
			$per_byte = intdiv( 8, $depth );
			$mask     = ( 1 << $depth ) - 1;

			for ( $byte = 0; $byte < 256; $byte++ ) {
				$spread = '';

				for ( $index = $per_byte - 1; $index >= 0; $index-- ) {
					$spread .= chr( ( $byte >> ( $index * $depth ) ) & $mask );
				}

				$tables[ $depth ][ chr( $byte ) ] = $spread;
			}
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ] = substr( strtr( $row, $tables[ $depth ] ), 0, $columns );
		}

		return $rows;
	}

	/**
	 * Separate colour from alpha, and bring every sample to eight bits.
	 *
	 * @since 1.4.0
	 *
	 * @param string      $samples      From {@see samples()}.
	 * @param int         $type         Colour type.
	 * @param int         $depth        Bit depth.
	 * @param string|null $transparency From {@see transparency()}.
	 *
	 * @return array{0: string, 1: string|null} Colour samples (palette indexes
	 *                                          for type 3), and alpha.
	 */
	private static function split( string $samples, int $type, int $depth, ?string $transparency ): array {
		$wide = 16 === $depth;

		// Sixteen-bit samples keep their high byte, which is what rounding
		// them to eight bits comes to.
		if ( 4 === $type ) {
			return $wide
				? array( (string) preg_replace( '/(.).../s', '$1', $samples ), (string) preg_replace( '/..(.)./s', '$1', $samples ) )
				: array( (string) preg_replace( '/(.)./s', '$1', $samples ), (string) preg_replace( '/.(.)/s', '$1', $samples ) );
		}

		if ( 6 === $type ) {
			return $wide
				? array( (string) preg_replace( '/(.).(.).(.).../s', '$1$2$3', $samples ), (string) preg_replace( '/......(.)./s', '$1', $samples ) )
				: array( (string) preg_replace( '/(...)./s', '$1', $samples ), (string) preg_replace( '/...(.)/s', '$1', $samples ) );
		}

		$alpha = null;

		if ( 3 === $type && null !== $transparency ) {
			$alpha = strtr( $samples, self::bytes(), $transparency );
		} elseif ( null !== $transparency ) {
			// One colour is transparent: a pixel is, or is not, exactly it.
			$alpha = '';

			foreach ( str_split( $samples, strlen( $transparency ) ) as $value ) {
				$alpha .= $value === $transparency ? "\x00" : "\xFF";
			}
		}

		$colour = $wide ? (string) preg_replace( '/(.)./s', '$1', $samples ) : $samples;

		// Greyscale below eight bits is stretched to the full range: a one-
		// bit white is 1, which on an eight-bit scale is near black.
		if ( 0 === $type && $depth < 8 ) {
			$levels = 1 << $depth;
			$scale  = intdiv( 255, $levels - 1 );
			$to     = '';

			for ( $value = 0; $value < $levels; $value++ ) {
				$to .= chr( $value * $scale );
			}

			$colour = strtr( $colour, substr( self::bytes(), 0, $levels ), $to );
		}

		return array( $colour, $alpha );
	}

	/**
	 * Bytes in a row of packed samples.
	 *
	 * @since 1.4.0
	 *
	 * @param int $columns  Pixels.
	 * @param int $channels Samples a pixel.
	 * @param int $depth    Bits a sample.
	 *
	 * @return int
	 */
	private static function row_bytes( int $columns, int $channels, int $depth ): int {
		return intdiv( ( $columns * $channels * $depth ) + 7, 8 );
	}
}
