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

namespace SugarCommerce\Pdf\Images;

use SugarCommerce\Pdf\Image;
use SugarCommerce\Pdf\Traits\Png\{Chunks, Pixels};

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
 * The file and its stream are read here; its chunks and its pixels are
 * traits in Traits/Png.
 *
 * @internal Read through {@see Image}.
 *
 * @since 1.4.0
 */
final class Png {

	use Chunks;
	use Pixels;

	/**
	 * The eight bytes every PNG starts with.
	 */
	public const string SIGNATURE = "\x89PNG\r\n\x1A\n";

	/**
	 * The seven passes of Adam7: first column, first row, column step, row step.
	 */
	private const array ADAM7 = array(
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
	private const array CHANNELS = array(
		0 => 1,
		2 => 3,
		3 => 1,
		4 => 2,
		6 => 4,
	);

	/**
	 * Bit depths each colour type allows.
	 */
	private const array DEPTHS = array(
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
	private const int PIECE = 4096;

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
}
