<?php
/**
 * The chunks of a PNG file.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Png;

/**
 * Walking the chunks with their CRCs, and reading the header and the
 * transparency chunk, each checked before it is trusted.
 *
 * @since 1.4.0
 */
trait Chunks {

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
}
