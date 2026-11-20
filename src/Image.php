<?php
/**
 * A picture carried inside the PDF.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

use SugarCommerce\Pdf\Images\{Jpeg, Png};

/**
 * Class Image
 *
 * A JPEG or PNG, read and checked once, ready to draw on any number of
 * pages of any number of documents. A document embeds each image once
 * however many times it is drawn — a logo on every page is one copy.
 *
 * A JPEG goes in as it arrived, because a PDF reader decodes JPEG itself;
 * greyscale, colour and CMYK all work, and an EXIF orientation turns it
 * the way a photo viewer would. A PNG goes in as its own compressed rows
 * where a PDF can read them as they are, and is decoded otherwise — for
 * transparency, which becomes a soft mask, for sixteen-bit samples and
 * for interlacing (see {@see Png}).
 *
 * Anything else is refused with the reason, never embedded to show as
 * nothing: a damaged or cut-short file, another format, a JPEG kind no
 * reader decodes, and anything larger than the limits below.
 *
 * @since 1.4.0
 */
final class Image {

	/**
	 * The largest file read, in bytes: 32 MB.
	 */
	public const int MAX_BYTES = 33554432;

	/**
	 * The most pixels embedded: 50 megapixels, a large camera's photograph.
	 * More gains nothing on paper and makes every reader slow to open it.
	 */
	public const int MAX_PIXELS = 50000000;

	/**
	 * The most pixels decoded in PHP: four megapixels, 2048 × 2048.
	 *
	 * Only a PNG that has to be decoded — one with transparency, sixteen
	 * bits a sample or interlacing — counts against it; it bounds the
	 * memory and time decoding takes on a modest host. A logo or signature
	 * is far smaller.
	 */
	public const int MAX_DECODED_PIXELS = 4194304;

	/**
	 * How each EXIF orientation maps the image's unit square onto the
	 * unit square it is drawn in, as the six numbers of a PDF matrix.
	 */
	private const array ORIENTATIONS = array(
		1 => array( 1, 0, 0, 1, 0, 0 ),
		2 => array( -1, 0, 0, 1, 1, 0 ),
		3 => array( -1, 0, 0, -1, 1, 1 ),
		4 => array( 1, 0, 0, -1, 0, 1 ),
		5 => array( 0, -1, -1, 0, 1, 1 ),
		6 => array( 0, -1, 1, 0, 0, 1 ),
		7 => array( 0, 1, 1, 0, 0, 0 ),
		8 => array( 0, 1, -1, 0, 1, 0 ),
	);

	/**
	 * Images already read from files, by path.
	 *
	 * @var array<string, self>
	 */
	private static array $loaded = array();

	/**
	 * Pixels across, as the image is seen — after its orientation.
	 */
	public readonly int $width;

	/**
	 * Pixels down, as the image is seen.
	 */
	public readonly int $height;

	/**
	 * `jpeg` or `png`.
	 */
	public readonly string $format;

	/**
	 * `gray`, `rgb`, `cmyk` or `indexed` (a PNG palette).
	 */
	public readonly string $color_space;

	/**
	 * Whether it carries transparency, drawn through a soft mask.
	 */
	public readonly bool $has_alpha;

	/**
	 * The EXIF orientation, 1–8; 1, upright, for anything without one.
	 */
	public readonly int $orientation;

	/**
	 * What the image is called in a warning: its file name, or the label given.
	 */
	public readonly string $name;

	/**
	 * A fingerprint of what is embedded, so the same picture read twice is
	 * still embedded once.
	 */
	public readonly string $digest;

	/**
	 * The image dictionary's entries after `/Subtype /Image`, without the length.
	 */
	private readonly string $dictionary;

	/**
	 * The image stream's bytes.
	 */
	private readonly string $data;

	/**
	 * The deflated soft mask, if any.
	 */
	private readonly ?string $alpha;

	/**
	 * Read an image from its bytes.
	 *
	 * @since 1.4.0
	 *
	 * @param string $data  The file's contents.
	 * @param string $label What to call it in an error and a warning.
	 *
	 * @throws \InvalidArgumentException When it cannot be embedded, saying why.
	 */
	public function __construct( string $data, string $label = 'The image' ) {
		if ( strlen( $data ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( sprintf( '%s is %d bytes, more than the %d this reads. Save it smaller.', $label, strlen( $data ), self::MAX_BYTES ) );
		}

		if ( str_starts_with( $data, Png::SIGNATURE ) ) {
			$png = Png::read( $data, $label, self::MAX_PIXELS, self::MAX_DECODED_PIXELS );

			$this->format      = 'png';
			$this->orientation = 1;
			$this->alpha       = $png['alpha'];
			$this->data        = $png['data'];
			$this->color_space = $png['color_space'];
			$this->dictionary  = sprintf( '/Width %d /Height %d /ColorSpace %s /BitsPerComponent %d /Filter /FlateDecode', $png['width'], $png['height'], $png['space'], $png['bits'] )
				. ( '' !== $png['parms'] ? ' ' . $png['parms'] : '' );

			[ $stored_width, $stored_height ] = array( $png['width'], $png['height'] );
		} elseif ( str_starts_with( $data, "\xFF\xD8\xFF" ) ) {
			$jpeg = Jpeg::read( $data, $label, self::MAX_PIXELS );

			$this->format      = 'jpeg';
			$this->orientation = $jpeg['orientation'];
			$this->alpha       = null;
			$this->data        = $jpeg['data'];
			$this->color_space = $jpeg['color_space'];
			$this->dictionary  = sprintf( '/Width %d /Height %d /ColorSpace %s /BitsPerComponent 8', $jpeg['width'], $jpeg['height'], $jpeg['space'] )
				. ( '' !== $jpeg['decode'] ? ' ' . $jpeg['decode'] : '' )
				. ' /Filter /DCTDecode'
				. ( '' !== $jpeg['parms'] ? ' ' . $jpeg['parms'] : '' );

			[ $stored_width, $stored_height ] = array( $jpeg['width'], $jpeg['height'] );
		} else {
			throw new \InvalidArgumentException( $label . ' ' . self::not_supported( $data ) );
		}

		// Turned a quarter, the image is seen with its sides swapped.
		$turned = $this->orientation >= 5;

		$this->width     = $turned ? $stored_height : $stored_width;
		$this->height    = $turned ? $stored_width : $stored_height;
		$this->has_alpha = null !== $this->alpha;
		$this->name      = $label;
		$this->digest    = md5( $this->dictionary . "\x00" . $this->data . "\x00" . $this->alpha );
	}

	/**
	 * Read an image file, once per request.
	 *
	 * @since 1.4.0
	 *
	 * @param string $path Path to a `.jpg`, `.jpeg` or `.png`.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the file is missing, unreadable
	 *                                   or cannot be embedded.
	 */
	public static function file( string $path ): self {
		if ( isset( self::$loaded[ $path ] ) ) {
			return self::$loaded[ $path ];
		}

		$size = is_file( $path ) ? filesize( $path ) : false;

		if ( false === $size ) {
			throw new \InvalidArgumentException( 'Could not read the image file ' . $path . '.' );
		}

		// Measured before it is read: a file too large to embed should not
		// be loaded into memory to find that out.
		if ( $size > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( sprintf( '%s is %d bytes, more than the %d this reads. Save it smaller.', basename( $path ), $size, self::MAX_BYTES ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file; a failed read throws below, with the path.
		$data = @file_get_contents( $path );

		if ( false === $data ) {
			throw new \InvalidArgumentException( 'Could not read the image file ' . $path . '.' );
		}

		self::$loaded[ $path ] = new self( $data, basename( $path ) );

		return self::$loaded[ $path ];
	}

	/**
	 * Width over height, as the image is seen.
	 *
	 * @since 1.4.0
	 *
	 * @return float
	 */
	public function aspect_ratio(): float {
		return $this->width / $this->height;
	}

	/**
	 * The largest size the image can be drawn at inside a box, keeping
	 * its proportions.
	 *
	 * @since 1.4.0
	 *
	 * @param float $width  Box width.
	 * @param float $height Box height.
	 *
	 * @return array{0: float, 1: float} Width and height.
	 */
	public function fitted( float $width, float $height ): array {
		if ( ! ( $width > 0 ) || ! ( $height > 0 ) ) {
			return array( 0.0, 0.0 );
		}

		if ( $width / $height > $this->aspect_ratio() ) {
			return array( $height * $this->aspect_ratio(), $height );
		}

		return array( $width, $width / $this->aspect_ratio() );
	}

	/**
	 * The matrix that draws the image, upright, into a box.
	 *
	 * An image is drawn into a unit square that the matrix scales and
	 * moves; an EXIF orientation is a turn or a flip of that square first.
	 *
	 * @since 1.4.0
	 *
	 * @param float $x      Left.
	 * @param float $y      Bottom.
	 * @param float $width  Width.
	 * @param float $height Height.
	 *
	 * @return string A `cm` operator, with its line break.
	 */
	public function placement( float $x, float $y, float $width, float $height ): string {
		[ $a, $b, $c, $d, $e, $f ] = self::ORIENTATIONS[ $this->orientation ];

		$numbers = array( $width * $a, $height * $b, $width * $c, $height * $d, $x + ( $width * $e ), $y + ( $height * $f ) );

		// A flip writes a zero as -0, which a reader accepts and nobody
		// wants to read; -0.0 === 0.0, so it comes out as 0.
		$numbers = array_map(
			static function ( float $value ): string {
				$value = Document::number( $value );

				return sprintf( '%.3F', 0.0 === $value ? 0.0 : $value );
			},
			$numbers
		);

		return implode( ' ', $numbers ) . " cm\n";
	}

	/**
	 * The objects that embed the image, numbered from `$first`: the image,
	 * and its soft mask when it has transparency.
	 *
	 * @since 1.4.0
	 *
	 * @param int $first The image's object number.
	 *
	 * @return array<int, string> Object number => body.
	 */
	public function objects( int $first ): array {
		$mask    = null !== $this->alpha ? ' /SMask ' . ( $first + 1 ) . ' 0 R' : '';
		$objects = array(
			$first => '<</Type /XObject /Subtype /Image ' . $this->dictionary . $mask . ' /Length ' . strlen( $this->data ) . ">>\nstream\n" . $this->data . "\nendstream",
		);

		if ( null !== $this->alpha ) {
			preg_match( '/\/Width (\d+) \/Height (\d+)/', $this->dictionary, $size );

			$objects[ $first + 1 ] = '<</Type /XObject /Subtype /Image /Width ' . $size[1] . ' /Height ' . $size[2]
				. ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen( $this->alpha ) . ">>\nstream\n" . $this->alpha . "\nendstream";
		}

		return $objects;
	}

	/**
	 * Why a file that is neither JPEG nor PNG is refused, by what it is.
	 *
	 * @since 1.4.0
	 *
	 * @param string $data The file's bytes.
	 *
	 * @return string The rest of the sentence after the image's name.
	 */
	private static function not_supported( string $data ): string {
		$start = ltrim( substr( $data, 0, 512 ) );

		return match ( true ) {
			'' === $data                                                         => 'is empty.',
			str_starts_with( $data, 'GIF8' )                                     => 'is a GIF. Save it as a PNG.',
			str_starts_with( $data, 'RIFF' ) && 'WEBP' === substr( $data, 8, 4 ) => 'is a WebP image. Save it as a PNG or JPEG.',
			'ftyp' === substr( $data, 4, 4 )                                     => 'is a HEIC or AVIF image. Save it as a JPEG.',
			str_starts_with( $data, "II*\x00" ) || str_starts_with( $data, "MM\x00*" ) => 'is a TIFF. Save it as a PNG or JPEG.',
			str_starts_with( $data, 'BM' )                                       => 'is a BMP. Save it as a PNG.',
			str_starts_with( $data, '%PDF' )                                     => 'is a PDF, not an image.',
			1 === preg_match( '/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg[\s>]/is', $start ) => 'is an SVG. Draw its path with svg_path(), or give it to Branding as logo_path.',
			str_starts_with( $data, "\xFF\xD8" )                                 => 'is damaged: it starts as a JPEG and goes no further.',
			default                                                              => 'is not a JPEG or PNG.',
		};
	}
}
