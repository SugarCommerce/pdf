<?php
/**
 * JPEG reading, in PHP.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Jpeg
 *
 * Reads what a PDF needs to know about a JPEG — its size, its colour
 * model, which way up it goes — and hands the compressed image over
 * untouched. A PDF reader decodes JPEG itself (`/DCTDecode`), so the scan
 * goes into the file exactly as it arrived, with no loss and no work.
 *
 * Only the segments before the image data are rewritten, and only to take
 * out what a document should not carry: EXIF, which can hold where a
 * photograph was taken and on what, and the camera's thumbnail, an ICC
 * profile `/DCTDecode` never reads, comments and editor data. The JFIF
 * header and Adobe's colour-transform marker stay, because readers act on
 * them.
 *
 * @internal Read through {@see Image}.
 *
 * @since 1.4.0
 */
final class Jpeg {

	/**
	 * Frame markers a PDF reader decodes: baseline, extended and
	 * progressive Huffman. Progressive has been part of `/DCTDecode` since
	 * PDF 1.3, and every current reader draws it.
	 */
	private const FRAMES = array( 0xC0, 0xC1, 0xC2 );

	/**
	 * Frame markers that are JPEG but not what `/DCTDecode` decodes, and why.
	 */
	private const REFUSED = array(
		0xC3 => 'is a lossless JPEG',
		0xC5 => 'is a hierarchical JPEG',
		0xC6 => 'is a hierarchical JPEG',
		0xC7 => 'is a hierarchical JPEG',
		0xC9 => 'uses arithmetic coding',
		0xCA => 'uses arithmetic coding',
		0xCB => 'uses arithmetic coding',
		0xCD => 'is a hierarchical JPEG with arithmetic coding',
		0xCE => 'is a hierarchical JPEG with arithmetic coding',
		0xCF => 'is a hierarchical JPEG with arithmetic coding',
	);

	/**
	 * Read a JPEG.
	 *
	 * @since 1.4.0
	 *
	 * @param string $data       The file's bytes.
	 * @param string $label      What to call it in an error.
	 * @param int    $max_pixels The most pixels embedded.
	 *
	 * @return array{width: int, height: int, color_space: string, space: string, decode: string, parms: string, orientation: int, data: string}
	 *         Width and height as stored, before `orientation` (1–8, as EXIF numbers them) turns them.
	 *
	 * @throws \InvalidArgumentException When the file cannot be embedded, saying why.
	 */
	public static function read( string $data, string $label, int $max_pixels ): array {
		$length      = strlen( $data );
		$offset      = 2;
		$kept        = "\xFF\xD8";
		$frame       = null;
		$adobe       = null;
		$orientation = 1;

		while ( true ) {
			// Bytes between segments are not allowed, but encoders write
			// them and decoders step over them; so does this.
			$marker_at = strpos( $data, "\xFF", $offset );

			if ( false === $marker_at || $marker_at + 1 >= $length ) {
				throw new \InvalidArgumentException( $label . ' is cut short: it ends before its image data.' );
			}

			$offset = $marker_at;

			// A marker may be preceded by any number of fill bytes.
			while ( $offset + 1 < $length && 0xFF === ord( $data[ $offset + 1 ] ) ) {
				++$offset;
			}

			$marker = ord( $data[ $offset + 1 ] ?? "\x00" );

			if ( 0x00 === $marker || 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) {
				$offset += 2;
				continue;
			}

			if ( 0xD9 === $marker ) {
				throw new \InvalidArgumentException( $label . ' has no image data: it ends before it begins.' );
			}

			if ( $offset + 4 > $length ) {
				throw new \InvalidArgumentException( $label . ' is cut short: it ends inside a segment.' );
			}

			[ , $size ] = (array) unpack( 'n', $data, $offset + 2 );
			$size       = (int) $size;

			if ( $size < 2 || $offset + 2 + $size > $length ) {
				throw new \InvalidArgumentException( $label . ' is cut short: a segment runs past the end of the file.' );
			}

			$payload = substr( $data, $offset + 4, $size - 2 );

			if ( isset( self::REFUSED[ $marker ] ) ) {
				throw new \InvalidArgumentException( $label . ' ' . self::REFUSED[ $marker ] . ', which PDF readers do not decode. Save it again as an ordinary JPEG.' );
			}

			if ( 0xDA === $marker ) {
				if ( null === $frame ) {
					throw new \InvalidArgumentException( $label . ' is damaged: its image data comes before its frame header.' );
				}

				// The image runs to the last end-of-image marker. Anything
				// after it is not image, and is not carried.
				$end = strrpos( $data, "\xFF\xD9", $offset );

				if ( false === $end ) {
					throw new \InvalidArgumentException( $label . ' is cut short: its image data has no end.' );
				}

				$kept .= substr( $data, $offset, $end + 2 - $offset );
				break;
			}

			if ( in_array( $marker, self::FRAMES, true ) ) {
				$frame = self::frame( $payload, $label );
			} elseif ( 0xE1 === $marker ) {
				$orientation = self::orientation( $payload ) ?? $orientation;
			} elseif ( 0xEE === $marker && str_starts_with( $payload, 'Adobe' ) && strlen( $payload ) >= 12 ) {
				$adobe = ord( $payload[11] );
			}

			// Metadata goes; JFIF, Adobe's marker and everything the decoder
			// reads — tables, restart interval — stays.
			if ( ( $marker >= 0xE1 && $marker <= 0xED ) || 0xEF === $marker || 0xFE === $marker ) {
				$offset += 2 + $size;
				continue;
			}

			$kept   .= substr( $data, $offset, 2 + $size );
			$offset += 2 + $size;
		}

		[ $width, $height, $components, $identifiers ] = $frame;

		if ( $width * $height > $max_pixels ) {
			throw new \InvalidArgumentException( sprintf( '%s is %d × %d pixels, more than the %d this embeds. Save it smaller.', $label, $width, $height, $max_pixels ) );
		}

		// A three-component JPEG is taken to be YCbCr unless Adobe's marker
		// says otherwise — which the reader sees, as it stays — or the
		// components are named R, G and B, which a reader does not check.
		$parms = 3 === $components && null === $adobe && 'RGB' === $identifiers ? '/DecodeParms <</ColorTransform 0>>' : '';

		return array(
			'width'       => $width,
			'height'      => $height,
			'color_space' => match ( $components ) {
				1       => 'gray',
				3       => 'rgb',
				default => 'cmyk',
			},
			'space'       => match ( $components ) {
				1       => '/DeviceGray',
				3       => '/DeviceRGB',
				default => '/DeviceCMYK',
			},
			// Photoshop and everything after it write CMYK JPEGs inverted
			// and say so with the Adobe marker. Without the flip they print
			// as a negative.
			'decode'      => 4 === $components && null !== $adobe ? '/Decode [1 0 1 0 1 0 1 0]' : '',
			'parms'       => $parms,
			'orientation' => $orientation,
			'data'        => $kept,
		);
	}

	/**
	 * Read a frame header.
	 *
	 * @since 1.4.0
	 *
	 * @param string $payload The segment, without its marker and length.
	 * @param string $label   What to call the file.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: string} Width, height,
	 *         components, and the components' identifiers as characters.
	 *
	 * @throws \InvalidArgumentException When the frame is not one a PDF can show.
	 */
	private static function frame( string $payload, string $label ): array {
		if ( strlen( $payload ) < 6 ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its frame header is cut short.' );
		}

		$fields     = (array) unpack( 'Cprecision/nheight/nwidth/Ccomponents', $payload );
		$components = (int) $fields['components'];

		if ( 8 !== $fields['precision'] ) {
			throw new \InvalidArgumentException( $label . ' has ' . $fields['precision'] . ' bits a sample; PDF readers decode JPEGs of 8.' );
		}

		// A height of 0 means "given after the image", which nothing that
		// writes JPEGs today does and few readers follow.
		if ( 0 === $fields['width'] || 0 === $fields['height'] ) {
			throw new \InvalidArgumentException( $label . ' does not say how large it is.' );
		}

		if ( ! in_array( $components, array( 1, 3, 4 ), true ) ) {
			throw new \InvalidArgumentException( $label . ' has ' . $components . ' colour components. A PDF takes greyscale (1), colour (3) or CMYK (4).' );
		}

		if ( strlen( $payload ) < 6 + ( 3 * $components ) ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its frame header is cut short.' );
		}

		$identifiers = '';

		for ( $index = 0; $index < $components; $index++ ) {
			$identifiers .= $payload[ 6 + ( 3 * $index ) ];
		}

		return array( (int) $fields['width'], (int) $fields['height'], $components, $identifiers );
	}

	/**
	 * The EXIF orientation, if the segment is EXIF and has one.
	 *
	 * A camera held on its side writes the pixels as the sensor saw them
	 * and a number saying which way up they go. Every photo viewer honours
	 * it; a PDF has no idea of it, so the document turns the image itself.
	 *
	 * @since 1.4.0
	 *
	 * @param string $payload An APP1 segment, without its marker and length.
	 *
	 * @return int|null 1–8, or null when there is none or it cannot be read.
	 */
	private static function orientation( string $payload ): ?int {
		if ( ! str_starts_with( $payload, "Exif\x00\x00" ) ) {
			return null;
		}

		$tiff   = substr( $payload, 6 );
		$length = strlen( $tiff );
		$order  = substr( $tiff, 0, 2 );

		if ( $length < 8 || ( 'II' !== $order && 'MM' !== $order ) ) {
			return null;
		}

		$short = 'II' === $order ? 'v' : 'n';
		$long  = 'II' === $order ? 'V' : 'N';

		[ , $ifd ] = (array) unpack( $long, $tiff, 4 );
		$ifd       = (int) $ifd;

		if ( $ifd < 8 || $ifd + 2 > $length ) {
			return null;
		}

		[ , $count ] = (array) unpack( $short, $tiff, $ifd );

		for ( $index = 0; $index < (int) $count; $index++ ) {
			$entry = $ifd + 2 + ( 12 * $index );

			if ( $entry + 12 > $length ) {
				return null;
			}

			[ , $tag ]  = (array) unpack( $short, $tiff, $entry );
			[ , $type ] = (array) unpack( $short, $tiff, $entry + 2 );

			if ( 0x0112 !== $tag ) {
				continue;
			}

			if ( 3 !== $type ) {
				return null;
			}

			[ , $value ] = (array) unpack( $short, $tiff, $entry + 8 );

			return $value >= 1 && $value <= 8 ? (int) $value : null;
		}

		return null;
	}
}
