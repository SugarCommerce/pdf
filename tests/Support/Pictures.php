<?php
/**
 * Images made to order for the decoder tests.
 *
 * PNGs are built here, chunk by chunk, so every colour type, bit depth,
 * row filter and interlace pass can be produced on purpose — and broken
 * on purpose. Each comes with the pixels it should decode to, worked out
 * from the same values it was built from, not from the decoder under
 * test. Where GD is installed, the suite also checks libpng reads these
 * files as the same pixels, which keeps the builder honest.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

/**
 * Class Pictures
 */
final class Pictures {

	/**
	 * The seven Adam7 passes: first column, first row, column step, row step.
	 */
	private const ADAM7 = array(
		array( 0, 0, 8, 8 ),
		array( 4, 0, 8, 8 ),
		array( 0, 4, 4, 8 ),
		array( 2, 0, 4, 4 ),
		array( 0, 2, 2, 4 ),
		array( 1, 0, 2, 2 ),
		array( 0, 1, 1, 2 ),
	);

	/**
	 * Samples a pixel has, by colour type.
	 */
	public const CHANNELS = array(
		0 => 1,
		2 => 3,
		3 => 1,
		4 => 2,
		6 => 4,
	);

	/**
	 * The folder of committed images.
	 *
	 * @param string $name File name.
	 *
	 * @return string
	 */
	public static function path( string $name ): string {
		return dirname( __DIR__ ) . '/images/' . $name;
	}

	/**
	 * A sample value for every pixel and channel, varied enough that a
	 * wrong filter, a wrong pass or a swapped channel shows.
	 *
	 * @param int $width    Pixels across.
	 * @param int $height   Pixels down.
	 * @param int $channels Samples a pixel.
	 * @param int $depth    Bits a sample.
	 *
	 * @return list<list<list<int>>> Rows of pixels of samples.
	 */
	public static function pattern( int $width, int $height, int $channels, int $depth ): array {
		$max  = ( 1 << $depth ) - 1;
		$rows = array();

		for ( $y = 0; $y < $height; $y++ ) {
			$row = array();

			for ( $x = 0; $x < $width; $x++ ) {
				$pixel = array();

				for ( $c = 0; $c < $channels; $c++ ) {
					$pixel[] = ( ( $x * 37 ) + ( $y * 101 ) + ( $c * 53 ) + ( $x * $y * 7 ) ) % ( $max + 1 );
				}

				$row[] = $pixel;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Build a PNG.
	 *
	 * @param list<list<list<int>>> $pixels     Rows of pixels of samples, at the file's depth.
	 * @param int                   $type       Colour type.
	 * @param int                   $depth      Bit depth.
	 * @param array<string, mixed>  $options    `filters` (list of row filters,
	 *                                          used in turn; default all five),
	 *                                          `interlaced`, `palette` (bytes),
	 *                                          `trns` (bytes), `idat_size`
	 *                                          (split the data into chunks of
	 *                                          this size), `chunks_before_idat`
	 *                                          (raw chunk bytes).
	 *
	 * @return string
	 */
	public static function png( array $pixels, int $type, int $depth, array $options = array() ): string {
		$height     = count( $pixels );
		$width      = count( $pixels[0] );
		$filters    = $options['filters'] ?? array( 0, 1, 2, 3, 4 );
		$interlaced = (bool) ( $options['interlaced'] ?? false );
		$raw        = '';
		$turn       = 0;

		foreach ( $interlaced ? self::ADAM7 : array( array( 0, 0, 1, 1 ) ) as [ $x0, $y0, $dx, $dy ] ) {
			$pass = array();

			for ( $y = $y0; $y < $height; $y += $dy ) {
				$row = array();

				for ( $x = $x0; $x < $width; $x += $dx ) {
					$row[] = $pixels[ $y ][ $x ];
				}

				if ( array() !== $row ) {
					$pass[] = $row;
				}
			}

			$prior = null;

			foreach ( $pass as $row ) {
				$bytes  = self::pack_row( $row, $depth );
				$filter = $filters[ $turn++ % count( $filters ) ];
				$prior ??= str_repeat( "\x00", strlen( $bytes ) );
				$raw   .= chr( $filter ) . self::filter( $bytes, $prior, $filter, max( 1, intdiv( self::CHANNELS[ $type ] * $depth, 8 ) ) );
				$prior  = $bytes;
			}
		}

		$png  = "\x89PNG\r\n\x1A\n";
		$png .= self::chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, $depth, $type, 0, 0, $interlaced ? 1 : 0 ) );
		$png .= $options['chunks_before_idat'] ?? '';

		if ( isset( $options['palette'] ) ) {
			$png .= self::chunk( 'PLTE', $options['palette'] );
		}

		if ( isset( $options['trns'] ) ) {
			$png .= self::chunk( 'tRNS', $options['trns'] );
		}

		$compressed = (string) gzcompress( $raw, 9 );

		foreach ( str_split( $compressed, max( 1, (int) ( $options['idat_size'] ?? strlen( $compressed ) ) ) ) as $piece ) {
			$png .= self::chunk( 'IDAT', $piece );
		}

		return $png . self::chunk( 'IEND', '' );
	}

	/**
	 * One chunk, with its length and checksum.
	 *
	 * @param string $type Four letters.
	 * @param string $body Data.
	 *
	 * @return string
	 */
	public static function chunk( string $type, string $body ): string {
		return pack( 'N', strlen( $body ) ) . $type . $body . pack( 'N', crc32( $type . $body ) );
	}

	/**
	 * A palette of `$count` colours, each different.
	 *
	 * @param int $count Colours.
	 *
	 * @return string
	 */
	public static function palette( int $count ): string {
		$palette = '';

		for ( $index = 0; $index < $count; $index++ ) {
			$palette .= chr( ( $index * 67 ) % 256 ) . chr( ( $index * 131 + 40 ) % 256 ) . chr( ( 255 - $index * 29 ) % 256 & 0xFF );
		}

		return $palette;
	}

	/**
	 * What a reader should see: eight-bit colour samples and alpha.
	 *
	 * @param list<list<list<int>>> $pixels  As built.
	 * @param int                   $type    Colour type.
	 * @param int                   $depth   Bit depth.
	 * @param string                $palette PLTE bytes, for type 3.
	 * @param string|null           $trns    tRNS bytes.
	 *
	 * @return array{0: string, 1: string|null} Grey or RGB samples (a palette
	 *                                          looked up), and alpha or null.
	 */
	public static function expected( array $pixels, int $type, int $depth, string $palette = '', ?string $trns = null ): array {
		$colour = '';
		$alpha  = '';
		$max    = ( 1 << $depth ) - 1;
		$key    = null;

		if ( null !== $trns && ( 0 === $type || 2 === $type ) ) {
			$key = array_values( (array) unpack( 'n*', $trns ) );
		}

		foreach ( $pixels as $row ) {
			foreach ( $row as $pixel ) {
				$eight = static fn( int $value ): string => chr( 16 === $depth ? $value >> 8 : intdiv( $value * 255, $max ) );

				switch ( $type ) {
					case 0:
						$colour .= $eight( $pixel[0] );
						$alpha  .= null !== $key && $pixel === $key ? "\x00" : "\xFF";
						break;
					case 2:
						$colour .= $eight( $pixel[0] ) . $eight( $pixel[1] ) . $eight( $pixel[2] );
						$alpha  .= null !== $key && $pixel === $key ? "\x00" : "\xFF";
						break;
					case 3:
						$colour .= substr( $palette, $pixel[0] * 3, 3 );
						$alpha  .= null !== $trns && $pixel[0] < strlen( $trns ) ? $trns[ $pixel[0] ] : "\xFF";
						break;
					case 4:
						$colour .= $eight( $pixel[0] );
						$alpha  .= $eight( $pixel[1] );
						break;
					default:
						$colour .= $eight( $pixel[0] ) . $eight( $pixel[1] ) . $eight( $pixel[2] );
						$alpha  .= $eight( $pixel[3] );
				}
			}
		}

		return array( $colour, strspn( $alpha, "\xFF" ) === strlen( $alpha ) ? null : $alpha );
	}

	/**
	 * Put an APP1 EXIF segment carrying an orientation after a JPEG's SOI.
	 *
	 * @param string $jpeg        A JPEG.
	 * @param int    $orientation 1–8, or anything else to test refusal.
	 * @param bool   $motorola    Big-endian (MM) rather than little (II).
	 * @param int    $type        The TIFF type of the value: 3 is SHORT.
	 *
	 * @return string
	 */
	public static function with_orientation( string $jpeg, int $orientation, bool $motorola = false, int $type = 3 ): string {
		$short = $motorola ? 'n' : 'v';
		$long  = $motorola ? 'N' : 'V';

		// Two entries, so the orientation is not simply the first one read.
		$tiff  = ( $motorola ? 'MM' : 'II' ) . pack( $short, 42 ) . pack( $long, 8 );
		$tiff .= pack( $short, 2 );
		$tiff .= pack( $short, 0x010F ) . pack( $short, 2 ) . pack( $long, 4 ) . 'Cam' . "\x00";
		$tiff .= pack( $short, 0x0112 ) . pack( $short, $type ) . pack( $long, 1 ) . pack( $short, $orientation ) . "\x00\x00";
		$tiff .= pack( $long, 0 );

		return self::with_segment( $jpeg, 0xE1, "Exif\x00\x00" . $tiff );
	}

	/**
	 * Put a segment after a JPEG's SOI.
	 *
	 * @param string $jpeg    A JPEG.
	 * @param int    $marker  The marker's second byte.
	 * @param string $payload What follows the length.
	 *
	 * @return string
	 */
	public static function with_segment( string $jpeg, int $marker, string $payload ): string {
		return "\xFF\xD8\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload . substr( $jpeg, 2 );
	}

	/**
	 * The offset of a JPEG's first marker of a kind.
	 *
	 * @param string $jpeg   A JPEG.
	 * @param int    $marker The marker's second byte.
	 *
	 * @return int
	 */
	public static function marker( string $jpeg, int $marker ): int {
		$offset = 2;

		while ( $offset < strlen( $jpeg ) ) {
			$found = ord( $jpeg[ $offset + 1 ] );

			if ( $found === $marker ) {
				return $offset;
			}

			$offset += 2 + (int) unpack( 'n', $jpeg, $offset + 2 )[1];
		}

		return -1;
	}

	/**
	 * Pack a row of pixels at a depth, most significant bits first.
	 *
	 * @param list<list<int>> $row   Pixels of samples.
	 * @param int             $depth Bit depth.
	 *
	 * @return string
	 */
	private static function pack_row( array $row, int $depth ): string {
		$bytes = '';

		if ( $depth >= 8 ) {
			foreach ( $row as $pixel ) {
				foreach ( $pixel as $sample ) {
					$bytes .= 16 === $depth ? pack( 'n', $sample ) : chr( $sample );
				}
			}

			return $bytes;
		}

		$accumulator = 0;
		$bits        = 0;

		foreach ( $row as $pixel ) {
			foreach ( $pixel as $sample ) {
				$accumulator = ( $accumulator << $depth ) | $sample;
				$bits       += $depth;

				if ( 8 === $bits ) {
					$bytes      .= chr( $accumulator );
					$accumulator = 0;
					$bits        = 0;
				}
			}
		}

		return $bits > 0 ? $bytes . chr( $accumulator << ( 8 - $bits ) ) : $bytes;
	}

	/**
	 * Apply a PNG row filter.
	 *
	 * @param string $row    Packed row.
	 * @param string $prior  The row above, packed, or zeros.
	 * @param int    $filter 0–4.
	 * @param int    $bpp    Bytes a pixel, at least one.
	 *
	 * @return string
	 */
	private static function filter( string $row, string $prior, int $filter, int $bpp ): string {
		$out = '';

		for ( $i = 0, $n = strlen( $row ); $i < $n; $i++ ) {
			$x = ord( $row[ $i ] );
			$a = $i >= $bpp ? ord( $row[ $i - $bpp ] ) : 0;
			$b = ord( $prior[ $i ] );
			$c = $i >= $bpp ? ord( $prior[ $i - $bpp ] ) : 0;

			$predicted = match ( $filter ) {
				0       => 0,
				1       => $a,
				2       => $b,
				3       => ( $a + $b ) >> 1,
				default => self::paeth( $a, $b, $c ),
			};

			$out .= chr( ( $x - $predicted ) & 0xFF );
		}

		return $out;
	}

	/**
	 * The Paeth predictor, as the PNG specification writes it.
	 *
	 * @param int $a Left.
	 * @param int $b Above.
	 * @param int $c Above left.
	 *
	 * @return int
	 */
	private static function paeth( int $a, int $b, int $c ): int {
		$p  = $a + $b - $c;
		$pa = abs( $p - $a );
		$pb = abs( $p - $b );
		$pc = abs( $p - $c );

		if ( $pa <= $pb && $pa <= $pc ) {
			return $a;
		}

		return $pb <= $pc ? $b : $c;
	}
}
