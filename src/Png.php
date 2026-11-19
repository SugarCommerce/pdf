<?php
/**
 * PNG reading, in PHP.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Png
 *
 * Turns a PNG file into what a PDF image needs: samples, a colour space
 * and, where the file has transparency, a soft mask.
 *
 * Most PNGs are never decoded. A PDF's `/FlateDecode` with the PNG
 * predictor reads exactly the filtered, deflated rows a non-interlaced
 * PNG stores, so an opaque greyscale, colour or palette image of up to
 * eight bits goes in as its own `IDAT` bytes — only inflated once, in
 * pieces, to prove it is whole. The rest are decoded here: alpha has to
 * come out into a mask of its own, sixteen-bit samples come down to
 * eight (PDF 1.4 has nothing wider), and an interlaced image's seven
 * passes have to be put back together.
 *
 * Written in PHP rather than handed to GD or Imagick. Neither is on every
 * host, so a pure-PHP path would be needed anyway — and with two paths
 * the one the tests prove is not the one a given server runs. GD also
 * reduces a palette to true colour and rounds alpha to seven bits, and
 * the bytes it produces depend on the libpng and zlib it was built with,
 * which would make the same document render differently from one server
 * to the next.
 *
 * @internal Read through {@see Image}.
 *
 * @since 1.4.0
 */
final class Png {

	/**
	 * The eight bytes every PNG starts with.
	 */
	public const SIGNATURE = "\x89PNG\r\n\x1A\n";

	/**
	 * The seven passes of Adam7: first column, first row, column step, row step.
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
	private const CHANNELS = array(
		0 => 1,
		2 => 3,
		3 => 1,
		4 => 2,
		6 => 4,
	);

	/**
	 * Bit depths each colour type allows.
	 */
	private const DEPTHS = array(
		0 => array( 1, 2, 4, 8, 16 ),
		2 => array( 8, 16 ),
		3 => array( 1, 2, 4, 8 ),
		4 => array( 8, 16 ),
		6 => array( 8, 16 ),
	);

	/**
	 * Compressed bytes inflated at a time. Deflate expands at most about
	 * a thousand to one, so a piece never inflates past a few megabytes,
	 * however the stream was made.
	 */
	private const PIECE = 4096;

	/**
	 * Read a PNG.
	 *
	 * @since 1.4.0
	 *
	 * @param string $data        The file's bytes.
	 * @param string $label       What to call it in an error.
	 * @param int    $max_pixels  The most pixels passed through undecoded.
	 * @param int    $max_decoded The most pixels decoded in PHP.
	 *
	 * @return array{width: int, height: int, color_space: string, space: string, bits: int, parms: string, data: string, alpha: string|null}
	 *         `data` and `alpha` deflated; `parms` a `/DecodeParms` entry or empty.
	 *
	 * @throws \InvalidArgumentException When the file cannot be embedded, saying why.
	 */
	public static function read( string $data, string $label, int $max_pixels, int $max_decoded ): array {
		[ $header, $palette, $transparency, $idat ] = self::chunks( $data, $label );

		[ 'width' => $width, 'height' => $height, 'type' => $type, 'depth' => $depth, 'interlaced' => $interlaced ] = $header;

		if ( 3 === $type && '' === $palette ) {
			throw new \InvalidArgumentException( $label . ' is damaged: it is a palette image with no palette.' );
		}

		if ( $width * $height > $max_pixels ) {
			throw new \InvalidArgumentException( sprintf( '%s is %d × %d pixels, more than the %d this embeds. Save it smaller.', $label, $width, $height, $max_pixels ) );
		}

		// Transparency PDF cannot take as it stands: only a soft mask
		// expresses it, and a soft mask needs the pixels. A tRNS chunk on a
		// greyscale-with-alpha or colour-with-alpha image is not allowed,
		// and is ignored as libpng ignores it.
		$transparency = in_array( $type, array( 0, 2, 3 ), true ) ? self::transparency( $type, $depth, $transparency, $palette ) : null;
		$channels     = self::CHANNELS[ $type ];
		$space        = self::space( $type, $palette );

		if ( ! $interlaced && $depth <= 8 && $type < 4 && null === $transparency ) {
			$row = self::row_bytes( $width, $channels, $depth );

			self::inflate( $idat, $height * ( $row + 1 ), $label, $row + 1 );

			return array(
				'width'       => $width,
				'height'      => $height,
				'color_space' => self::color_space( $type ),
				'space'       => $space,
				'bits'        => $depth,
				'parms'       => sprintf( '/DecodeParms <</Predictor 15 /Colors %d /BitsPerComponent %d /Columns %d>>', $channels, $depth, $width ),
				'data'        => $idat,
				'alpha'       => null,
			);
		}

		if ( $width * $height > $max_decoded ) {
			throw new \InvalidArgumentException(
				sprintf(
					'%s is %d × %d pixels and %s, so it has to be decoded, which this does for images of up to %d pixels. Save it smaller, or as a JPEG if it needs no transparency.',
					$label,
					$width,
					$height,
					self::why_decoded( $type, $depth, $interlaced, null !== $transparency ),
					$max_decoded
				)
			);
		}

		$samples            = self::samples( $idat, $width, $height, $channels, $depth, $interlaced, $label );
		[ $colour, $alpha ] = self::split( $samples, $type, $depth, $transparency );

		// Alpha that is opaque everywhere is no mask at all.
		$alpha = null !== $alpha && strspn( $alpha, "\xFF" ) === strlen( $alpha ) ? null : $alpha;

		return array(
			'width'       => $width,
			'height'      => $height,
			'color_space' => self::color_space( $type ),
			'space'       => $space,
			'bits'        => 8,
			'parms'       => '',
			'data'        => (string) gzcompress( $colour, 6 ),
			'alpha'       => null === $alpha ? null : (string) gzcompress( $alpha, 6 ),
		);
	}

	/**
	 * Walk the chunks, checking each, and keep the four that matter.
	 *
	 * @since 1.4.0
	 *
	 * @param string $data  The file.
	 * @param string $label What to call it.
	 *
	 * @return array{0: array{width: int, height: int, type: int, depth: int, interlaced: bool}, 1: string, 2: string|null, 3: string}
	 *         The header, the palette, the tRNS chunk, the joined IDAT data.
	 *
	 * @throws \InvalidArgumentException When a chunk is damaged or missing.
	 */
	private static function chunks( string $data, string $label ): array {
		$length       = strlen( $data );
		$offset       = 8;
		$header       = null;
		$palette      = '';
		$transparency = null;
		$idat         = '';
		$ended        = false;

		while ( $offset + 12 <= $length ) {
			[ , $size ] = (array) unpack( 'N', $data, $offset );
			$size       = (int) $size;
			$type       = substr( $data, $offset + 4, 4 );

			if ( 1 !== preg_match( '/^[A-Za-z]{4}$/', $type ) ) {
				throw new \InvalidArgumentException( $label . ' is damaged: a chunk has no name where one should be.' );
			}

			if ( $size > 0x7FFFFFFF || $offset + 12 + $size > $length ) {
				throw new \InvalidArgumentException( $label . ' is cut short: its ' . $type . ' chunk runs past the end of the file.' );
			}

			$body       = substr( $data, $offset + 8, $size );
			[ , $crc ]  = (array) unpack( 'N', $data, $offset + 8 + $size );
			$critical   = ctype_upper( $type[0] );
			$offset    += 12 + $size;

			// A damaged ancillary chunk is skipped, as libpng skips it; a
			// damaged critical one means the image itself is wrong.
			if ( crc32( $type . $body ) !== (int) $crc ) {
				if ( $critical ) {
					throw new \InvalidArgumentException( $label . ' is damaged: its ' . $type . ' chunk fails its checksum.' );
				}

				continue;
			}

			if ( null === $header && 'IHDR' !== $type ) {
				throw new \InvalidArgumentException( $label . ' is damaged: it does not start with its header.' );
			}

			switch ( $type ) {
				case 'IHDR':
					$header = self::header( $body, $label );
					break;
				case 'PLTE':
					if ( 0 === $size || 0 !== $size % 3 || $size > 768 ) {
						throw new \InvalidArgumentException( $label . ' is damaged: its palette is not a whole number of colours.' );
					}
					$palette = $body;
					break;
				case 'tRNS':
					$transparency = $body;
					break;
				case 'IDAT':
					$idat .= $body;
					break;
				case 'IEND':
					$ended = true;
					break 2;
				default:
					if ( $critical ) {
						throw new \InvalidArgumentException( $label . ' needs its ' . $type . ' chunk understood, and this reads only standard PNG.' );
					}
			}
		}

		if ( null === $header || ! $ended ) {
			throw new \InvalidArgumentException( $label . ' is cut short: it ends before its last chunk.' );
		}

		if ( '' === $idat ) {
			throw new \InvalidArgumentException( $label . ' has no image data.' );
		}

		return array( $header, $palette, $transparency, $idat );
	}

	/**
	 * Read and check the IHDR chunk.
	 *
	 * @since 1.4.0
	 *
	 * @param string $body  The chunk's data.
	 * @param string $label What to call the file.
	 *
	 * @return array{width: int, height: int, type: int, depth: int, interlaced: bool}
	 *
	 * @throws \InvalidArgumentException When a value is not one PNG allows.
	 */
	private static function header( string $body, string $label ): array {
		if ( 13 !== strlen( $body ) ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its header is the wrong length.' );
		}

		$fields = (array) unpack( 'Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $body );
		$width  = (int) $fields['width'];
		$height = (int) $fields['height'];
		$depth  = (int) $fields['depth'];
		$type   = (int) $fields['type'];

		if ( $width < 1 || $height < 1 || $width > 0x7FFFFFFF || $height > 0x7FFFFFFF ) {
			throw new \InvalidArgumentException( $label . ' is damaged: it declares a size of ' . $width . ' × ' . $height . ' pixels.' );
		}

		if ( ! isset( self::DEPTHS[ $type ] ) || ! in_array( $depth, self::DEPTHS[ $type ], true ) ) {
			throw new \InvalidArgumentException( $label . ' is damaged: colour type ' . $type . ' at ' . $depth . ' bits is not a kind of PNG.' );
		}

		if ( 0 !== $fields['compression'] || 0 !== $fields['filter'] || $fields['interlace'] > 1 ) {
			throw new \InvalidArgumentException( $label . ' uses a compression, filter or interlace method PNG does not define.' );
		}

		return array(
			'width'      => $width,
			'height'     => $height,
			'type'       => $type,
			'depth'      => $depth,
			'interlaced' => 1 === $fields['interlace'],
		);
	}

	/**
	 * What the tRNS chunk makes transparent.
	 *
	 * @since 1.4.0
	 *
	 * @param int         $type    Colour type: 0, 2 or 3.
	 * @param int         $depth   Bit depth.
	 * @param string|null $chunk   The tRNS data, if any.
	 * @param string      $palette The palette, for type 3.
	 *
	 * @return string|null For a palette, 256 alpha bytes by index; for
	 *                     greyscale or colour, the one sample value, at the
	 *                     file's own width, that is transparent. Null for
	 *                     none — an absent chunk, or one of the wrong length,
	 *                     which libpng also ignores.
	 */
	private static function transparency( int $type, int $depth, ?string $chunk, string $palette ): ?string {
		if ( null === $chunk ) {
			return null;
		}

		if ( 3 === $type ) {
			$colours = intdiv( strlen( $palette ), 3 );

			if ( '' === $chunk || strlen( $chunk ) > $colours || strspn( $chunk, "\xFF" ) === strlen( $chunk ) ) {
				return null;
			}

			return str_pad( $chunk, 256, "\xFF" );
		}

		if ( strlen( $chunk ) !== ( 0 === $type ? 2 : 6 ) ) {
			return null;
		}

		// Each value is two bytes whatever the depth: at sixteen bits it is
		// compared as it is, below that as one byte.
		if ( 16 === $depth ) {
			return $chunk;
		}

		$key = '';

		foreach ( str_split( $chunk, 2 ) as $value ) {
			[ , $number ] = (array) unpack( 'n', $value );
			$key         .= chr( (int) $number & 0xFF );
		}

		return $key;
	}

	/**
	 * Inflate the image data, refusing anything but exactly what the
	 * header promises.
	 *
	 * Done a piece at a time, so a stream built to inflate to gigabytes is
	 * stopped as soon as it passes the size the image can be — and, when
	 * the rows are not wanted, without ever holding them all.
	 *
	 * @since 1.4.0
	 *
	 * @param string $idat     The joined IDAT data.
	 * @param int    $expected Bytes the rows add up to, filter bytes included.
	 * @param string $label    What to call the file.
	 * @param int    $stride   When set, the length of a row: each row's filter
	 *                         byte is checked as it goes by, and nothing is
	 *                         kept. Zero keeps everything.
	 *
	 * @return string The rows, or '' when only checked.
	 *
	 * @throws \InvalidArgumentException When the data is damaged, short or long.
	 */
	private static function inflate( string $idat, int $expected, string $label, int $stride = 0 ): string {
		$context = inflate_init( ZLIB_ENCODING_DEFLATE );
		$kept    = '';
		$total   = 0;
		$pieces  = str_split( $idat, self::PIECE );

		foreach ( $pieces as $piece ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Damaged data throws below, naming the file.
			$out = false === $context ? false : @inflate_add( $context, $piece, ZLIB_SYNC_FLUSH );

			if ( false === $out ) {
				throw new \InvalidArgumentException( $label . ' is damaged: its image data does not decompress.' );
			}

			if ( $total + strlen( $out ) > $expected ) {
				throw new \InvalidArgumentException( $label . ' is damaged: its image data decompresses to more than its size allows.' );
			}

			if ( $stride > 0 ) {
				// The first row starting in this piece.
				for ( $start = (int) ( ceil( $total / $stride ) * $stride ); $start < $total + strlen( $out ); $start += $stride ) {
					if ( ord( $out[ $start - $total ] ) > 4 ) {
						throw new \InvalidArgumentException( $label . ' is damaged: a row uses a filter PNG does not define.' );
					}
				}
			} else {
				$kept .= $out;
			}

			$total += strlen( $out );

			if ( false !== $context && ZLIB_STREAM_END === inflate_get_status( $context ) ) {
				break;
			}
		}

		if ( $total < $expected || false === $context || ZLIB_STREAM_END !== inflate_get_status( $context ) ) {
			throw new \InvalidArgumentException( $label . ' is cut short: its image data ends before its last row.' );
		}

		return $kept;
	}

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
	 * Every byte value in order: the "from" side of a byte-for-byte strtr().
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	private static function bytes(): string {
		static $bytes = null;

		return $bytes ??= implode( '', array_map( 'chr', range( 0, 255 ) ) );
	}

	/**
	 * The colour space a PDF draws the samples in.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $type    Colour type.
	 * @param string $palette The palette, for type 3.
	 *
	 * @return string
	 */
	private static function space( int $type, string $palette ): string {
		return match ( $type ) {
			0, 4    => '/DeviceGray',
			2, 6    => '/DeviceRGB',
			default => '[/Indexed /DeviceRGB ' . ( intdiv( strlen( $palette ), 3 ) - 1 ) . ' <' . strtoupper( bin2hex( $palette ) ) . '>]',
		};
	}

	/**
	 * The colour model, as {@see Image::$color_space} names it.
	 *
	 * @since 1.4.0
	 *
	 * @param int $type Colour type.
	 *
	 * @return string
	 */
	private static function color_space( int $type ): string {
		return match ( $type ) {
			0, 4    => 'gray',
			2, 6    => 'rgb',
			default => 'indexed',
		};
	}

	/**
	 * Why an image has to be decoded rather than passed through, for the
	 * message that refuses one too large to decode.
	 *
	 * @since 1.4.0
	 *
	 * @param int  $type        Colour type.
	 * @param int  $depth       Bit depth.
	 * @param bool $interlaced  Adam7 or not.
	 * @param bool $transparent Whether a tRNS chunk applies.
	 *
	 * @return string
	 */
	private static function why_decoded( int $type, int $depth, bool $interlaced, bool $transparent ): string {
		return match ( true ) {
			$type >= 4 || $transparent => 'has transparency',
			16 === $depth              => 'has sixteen bits a sample',
			$interlaced                => 'is interlaced',
			default                    => 'is not one a PDF reads as it stands',
		};
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
