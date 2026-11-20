<?php
/**
 * The PNG decoder: every colour type, depth, row filter and interlace
 * pass, against the pixels each file was built from — and every way a
 * file can be broken, refused with the reason.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\{Document, Fit, Image};
use SugarCommerce\Pdf\Images\Png;
use SugarCommerce\Pdf\Tests\Support\{Pictures, Reader};

final class PngTest extends TestCase {

	/**
	 * The eight bytes a PNG starts with, for building broken ones.
	 */
	private const SIGNATURE = Png::SIGNATURE;

	/**
	 * The joined IDAT data of a file.
	 *
	 * @param string $png A PNG.
	 *
	 * @return string
	 */
	private static function idat( string $png ): string {
		$data   = '';
		$offset = 8;

		while ( $offset < strlen( $png ) ) {
			$length = (int) unpack( 'N', $png, $offset )[1];

			if ( 'IDAT' === substr( $png, $offset + 4, 4 ) ) {
				$data .= substr( $png, $offset + 8, $length );
			}

			$offset += 12 + $length;
		}

		return $data;
	}

	/**
	 * Every colour type at every depth it allows, plain and interlaced,
	 * at a size that leaves partial bytes at the end of a row and empty
	 * columns in the first interlace passes.
	 *
	 * @return array<string, array{0: int, 1: int, 2: bool}>
	 */
	public static function kinds(): array {
		$kinds = array();
		$depths = array(
			0 => array( 1, 2, 4, 8, 16 ),
			2 => array( 8, 16 ),
			3 => array( 1, 2, 4, 8 ),
			4 => array( 8, 16 ),
			6 => array( 8, 16 ),
		);

		foreach ( $depths as $type => $allowed ) {
			foreach ( $allowed as $depth ) {
				foreach ( array( false, true ) as $interlaced ) {
					$kinds[ sprintf( 'type %d, %d-bit%s', $type, $depth, $interlaced ? ', interlaced' : '' ) ] = array( $type, $depth, $interlaced );
				}
			}
		}

		return $kinds;
	}

	/**
	 * Build a file of a kind, with what it should decode to.
	 *
	 * @param int  $type       Colour type.
	 * @param int  $depth      Bit depth.
	 * @param bool $interlaced Adam7 or not.
	 * @param int  $width      Pixels across.
	 * @param int  $height     Pixels down.
	 *
	 * @return array{png: string, pixels: list<list<list<int>>>, palette: string}
	 */
	private static function build( int $type, int $depth, bool $interlaced, int $width = 13, int $height = 11 ): array {
		$pixels  = Pictures::pattern( $width, $height, Pictures::CHANNELS[ $type ], $depth );
		$palette = 3 === $type ? Pictures::palette( 1 << $depth ) : '';
		$options = array( 'interlaced' => $interlaced );

		if ( '' !== $palette ) {
			$options['palette'] = $palette;
		}

		return array(
			'png'     => Pictures::png( $pixels, $type, $depth, $options ),
			'pixels'  => $pixels,
			'palette' => $palette,
		);
	}

	/**
	 * An image's stream and mask, decoded, and its dictionary.
	 *
	 * @param Image $image The image.
	 *
	 * @return array{dict: string, data: string, mask: string|null}
	 */
	private static function embedded( Image $image ): array {
		$objects = $image->objects( 10 );
		$split   = static function ( string $object ): array {
			$at = strpos( $object, ">>\nstream\n" );

			return array( substr( $object, 0, $at + 2 ), substr( $object, $at + 10, -10 ) );
		};

		[ $dict, $data ] = $split( $objects[10] );
		$mask            = isset( $objects[11] ) ? (string) gzuncompress( $split( $objects[11] )[1] ) : null;

		return array(
			'dict' => $dict,
			'data' => str_contains( $dict, '/DecodeParms' ) ? $data : (string) gzuncompress( $data ),
			'mask' => $mask,
		);
	}

	/**
	 * Decoded in PHP where it has to be, passed through where a PDF can
	 * read it as it is — and either way the samples are the ones built.
	 */
	#[DataProvider( 'kinds' )]
	public function test_each_kind_reads_as_the_pixels_it_was_built_from( int $type, int $depth, bool $interlaced ): void {
		$built    = self::build( $type, $depth, $interlaced );
		$image    = new Image( $built['png'] );
		$embedded = self::embedded( $image );

		$this->assertSame( 13, $image->width );
		$this->assertSame( 11, $image->height );
		$this->assertSame( 'png', $image->format );
		$this->assertSame( match ( $type ) {
			0, 4    => 'gray',
			2, 6    => 'rgb',
			default => 'indexed',
		}, $image->color_space );

		$passes = ! $interlaced && $depth <= 8 && $type < 4;

		if ( $passes ) {
			// The file's own rows, read by the reader's PNG predictor.
			$this->assertStringContainsString( sprintf( '/DecodeParms <</Predictor 15 /Colors %d /BitsPerComponent %d /Columns 13>>', Pictures::CHANNELS[ $type ], $depth ), $embedded['dict'] );
			$this->assertStringContainsString( '/BitsPerComponent ' . $depth . ' ', $embedded['dict'] );
			$this->assertSame( self::idat( $built['png'] ), $embedded['data'] );
			$this->assertNull( $embedded['mask'] );

			return;
		}

		$this->assertStringNotContainsString( '/DecodeParms', $embedded['dict'] );
		$this->assertStringContainsString( '/BitsPerComponent 8 ', $embedded['dict'] );

		[ $colour, $alpha ] = Pictures::expected( $built['pixels'], $type, $depth, $built['palette'] );

		if ( 3 === $type ) {
			// A palette stays a palette: the indexes, one a byte.
			$indexes = '';

			foreach ( $built['pixels'] as $row ) {
				foreach ( $row as $pixel ) {
					$indexes .= chr( $pixel[0] );
				}
			}

			$colour = $indexes;
		}

		$this->assertSame( bin2hex( $colour ), bin2hex( $embedded['data'] ) );
		$this->assertSame( null === $alpha ? null : bin2hex( $alpha ), null === $embedded['mask'] ? null : bin2hex( $embedded['mask'] ) );
		$this->assertSame( null !== $alpha, $image->has_alpha );
	}

	/**
	 * The independent check: poppler decodes what was embedded — predictor
	 * rows, a palette, a soft mask — into exactly the pixels built.
	 */
	#[DataProvider( 'kinds' )]
	public function test_poppler_decodes_each_kind_to_the_pixels_built( int $type, int $depth, bool $interlaced ): void {
		$built = self::build( $type, $depth, $interlaced );
		$pdf   = new Document();

		$this->assertTrue( $pdf->image( new Image( $built['png'] ), 100, 100, 13, 11, Fit::Fill ) );

		[ $colour, $alpha ] = Pictures::expected( $built['pixels'], $type, $depth, $built['palette'] );

		// pdfimages writes any one-bit image as a bitmap, palette or not;
		// what the palette makes of it shows on the rendered page instead.
		if ( 3 === $type && 1 === $depth && ! $interlaced ) {
			$this->assertSame( bin2hex( $colour ), bin2hex( self::drawn( $built['png'], 13, 11 ) ) );

			return;
		}

		$images = Reader::extracted_images( $pdf->render() );

		$this->assertCount( null === $alpha ? 1 : 2, $images );
		$this->assertSame( array( 13, 11 ), array( $images[0]['width'], $images[0]['height'] ) );
		$this->assertSame( bin2hex( $colour ), bin2hex( self::grey_or_colour( $images[0], strlen( $colour ) ) ) );

		if ( null !== $alpha ) {
			$this->assertSame( bin2hex( $alpha ), bin2hex( self::grey_or_colour( $images[1], strlen( $alpha ) ) ) );
		}
	}

	/**
	 * An image drawn four points a pixel, rendered at 72 dots an inch and
	 * read back from the middle of each pixel's square, as RGB — clear of
	 * the blending at the squares' edges.
	 *
	 * @param string $png    The file.
	 * @param int    $width  Pixels across.
	 * @param int    $height Pixels down.
	 *
	 * @return string
	 */
	private static function drawn( string $png, int $width, int $height ): string {
		$pdf = new Document();

		$pdf->image( new Image( $png ), 100, $pdf->height() - 100 - ( $height * 4 ), $width * 4, $height * 4, Fit::Fill );

		$page   = Reader::rendered( $pdf->render() );
		$pixels = '';

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$pixels .= pack( 'C*', ...Reader::pixel( $page, 100 + ( $x * 4 ) + 2, 100 + ( $y * 4 ) + 2 ) );
			}
		}

		return $pixels;
	}

	/**
	 * An extracted image's samples, as grey when grey was expected.
	 *
	 * Poppler writes greyscale as a PGM or as a PPM with the three channels
	 * equal, depending on the release; either is the same pixels.
	 *
	 * @param array{width: int, height: int, channels: int, samples: string} $image  Extracted.
	 * @param int                                                            $length Bytes expected.
	 *
	 * @return string
	 */
	private static function grey_or_colour( array $image, int $length ): string {
		if ( 3 !== $image['channels'] || strlen( $image['samples'] ) === $length ) {
			return $image['samples'];
		}

		$grey = (string) preg_replace( '/(.)\\1\\1/s', '$1', $image['samples'] );

		return strlen( $grey ) === $length ? $grey : $image['samples'];
	}

	/**
	 * The files the builder makes are what libpng reads, too; without
	 * this the two tests above would only prove the decoder agrees with
	 * the builder.
	 */
	#[DataProvider( 'kinds' )]
	public function test_libpng_reads_the_built_files_as_the_same_pixels( int $type, int $depth, bool $interlaced ): void {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			$this->markTestSkipped( 'GD is not installed.' );
		}

		// GD reads interlaced files only with a warning from libpng; the
		// interlaced builder is checked by the libpng-made pair instead.
		if ( $interlaced ) {
			$this->markTestSkipped( 'GD does not turn on libpng\'s interlace handling.' );
		}

		$built              = self::build( $type, $depth, $interlaced );
		[ $colour, $alpha ] = Pictures::expected( $built['pixels'], $type, $depth, $built['palette'] );
		$gd                 = imagecreatefromstring( $built['png'] );
		$channels           = in_array( $type, array( 0, 4 ), true ) ? 1 : 3;

		$this->assertNotFalse( $gd );

		imagepalettetotruecolor( $gd );

		for ( $y = 0; $y < 11; $y++ ) {
			for ( $x = 0; $x < 13; $x++ ) {
				$value  = imagecolorat( $gd, $x, $y );
				$offset = ( $y * 13 ) + $x;
				$want   = array_values( (array) unpack( 'C*', substr( $colour, $offset * $channels, $channels ) ) );
				$got    = 1 === $channels ? array( ( $value >> 16 ) & 0xFF ) : array( ( $value >> 16 ) & 0xFF, ( $value >> 8 ) & 0xFF, $value & 0xFF );

				// GD keeps seven bits of alpha, inverted.
				$opacity = null === $alpha ? 255 : ord( $alpha[ $offset ] );

				$this->assertSame( 127 - ( $opacity >> 1 ), ( $value >> 24 ) & 0x7F, "alpha at $x,$y" );

				if ( $opacity > 0 ) {
					$this->assertSame( $want, $got, "colour at $x,$y" );
				}
			}
		}
	}

	public function test_a_file_made_by_libpng_decodes_the_same_plain_and_interlaced(): void {
		$plain      = Image::file( Pictures::path( 'logo.png' ) );
		$interlaced = Image::file( Pictures::path( 'interlaced.png' ) );

		$this->assertTrue( $plain->has_alpha );
		$this->assertSame( $plain->digest, $interlaced->digest );
	}

	public function test_a_file_made_by_libpng_matches_libpngs_own_pixels(): void {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			$this->markTestSkipped( 'GD is not installed.' );
		}

		$embedded = self::embedded( Image::file( Pictures::path( 'logo.png' ) ) );
		$gd       = imagecreatefrompng( Pictures::path( 'logo.png' ) );
		$checked  = 0;

		$this->assertNotFalse( $gd );

		for ( $y = 0; $y < 60; $y++ ) {
			for ( $x = 0; $x < 180; $x++ ) {
				$value   = imagecolorat( $gd, $x, $y );
				$offset  = ( $y * 180 ) + $x;
				$opacity = ord( (string) $embedded['mask'][ $offset ] );

				$this->assertSame( 127 - ( $opacity >> 1 ), ( $value >> 24 ) & 0x7F );

				if ( $opacity > 0 ) {
					$this->assertSame( array( ( $value >> 16 ) & 0xFF, ( $value >> 8 ) & 0xFF, $value & 0xFF ), array_values( (array) unpack( 'C3', $embedded['data'], $offset * 3 ) ) );
					++$checked;
				}
			}
		}

		$this->assertGreaterThan( 2000, $checked );
	}

	public function test_a_palette_with_one_transparent_colour_gets_a_mask_and_keeps_its_indexes(): void {
		$image    = Image::file( Pictures::path( 'palette.png' ) );
		$embedded = self::embedded( $image );

		$this->assertSame( 'indexed', $image->color_space );
		$this->assertTrue( $image->has_alpha );
		$this->assertMatchesRegularExpression( '/\/ColorSpace \[\/Indexed \/DeviceRGB \d+ <[0-9A-F]+>\]/', $embedded['dict'] );
		$this->assertStringContainsString( "\x00", (string) $embedded['mask'] );
		$this->assertStringContainsString( "\xFF", (string) $embedded['mask'] );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: list<int>}>
	 */
	public static function colour_keys(): array {
		return array(
			'grey, 2-bit'  => array( 0, 2, array( 3 ) ),
			'grey, 8-bit'  => array( 0, 8, array( 37 ) ),
			'grey, 16-bit' => array( 0, 16, array( 202 ) ),
			'RGB, 8-bit'   => array( 2, 8, array( 37, 90, 143 ) ),
			'RGB, 16-bit'  => array( 2, 16, array( 37, 90, 143 ) ),
		);
	}

	/**
	 * One colour named transparent: the pixels of exactly that colour, at
	 * the file's full depth, and no others.
	 *
	 * @param int       $type  Colour type.
	 * @param int       $depth Bit depth.
	 * @param list<int> $key   The transparent colour.
	 */
	#[DataProvider( 'colour_keys' )]
	public function test_a_transparent_colour_becomes_a_mask( int $type, int $depth, array $key ): void {
		$pixels       = Pictures::pattern( 9, 7, Pictures::CHANNELS[ $type ], $depth );
		$pixels[2][3] = $key;
		$pixels[5][8] = $key;

		// A sixteen-bit neighbour equal in its high byte only must stay.
		if ( 16 === $depth ) {
			$pixels[0][0] = array_map( static fn( int $value ): int => $value + 1, $key );
		}

		$trns               = pack( 'n*', ...$key );
		$image              = new Image( Pictures::png( $pixels, $type, $depth, array( 'trns' => $trns ) ) );
		$embedded           = self::embedded( $image );
		[ $colour, $alpha ] = Pictures::expected( $pixels, $type, $depth, '', $trns );

		$this->assertTrue( $image->has_alpha );
		$this->assertSame( bin2hex( (string) $alpha ), bin2hex( (string) $embedded['mask'] ) );
		$this->assertSame( "\x00", $embedded['mask'][ ( 2 * 9 ) + 3 ] ?? '' );
		$this->assertSame( "\x00", $embedded['mask'][ ( 5 * 9 ) + 8 ] ?? '' );

		if ( 16 === $depth ) {
			$this->assertSame( "\xFF", $embedded['mask'][0] ?? '' );
		}
		$this->assertSame( bin2hex( $colour ), bin2hex( $embedded['data'] ) );
	}

	public function test_palette_transparency_covers_only_the_entries_it_lists(): void {
		$pixels  = Pictures::pattern( 8, 8, 1, 4 );
		$palette = Pictures::palette( 16 );
		$trns    = "\x00\x80\xFF";
		$image   = new Image( Pictures::png( $pixels, 3, 4, array( 'palette' => $palette, 'trns' => $trns ) ) );

		[ , $alpha ] = Pictures::expected( $pixels, 3, 4, $palette, $trns );

		$this->assertSame( bin2hex( (string) $alpha ), bin2hex( (string) self::embedded( $image )['mask'] ) );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function ignored_transparency(): array {
		return array(
			'all opaque palette entries' => array( 3, "\xFF\xFF\xFF" ),
			'more entries than colours'  => array( 3, str_repeat( "\x00", 17 ) ),
			'grey of the wrong length'   => array( 0, "\x00\x01\x02" ),
			'colour of the wrong length' => array( 2, "\x00\x01" ),
		);
	}

	/**
	 * A tRNS chunk that makes nothing transparent, or is malformed, is
	 * ignored as libpng ignores it, and the image still passes through.
	 *
	 * @param int    $type Colour type.
	 * @param string $trns The chunk.
	 */
	#[DataProvider( 'ignored_transparency' )]
	public function test_transparency_that_says_nothing_is_ignored( int $type, string $trns ): void {
		$options = array( 'trns' => $trns );

		if ( 3 === $type ) {
			$options['palette'] = Pictures::palette( 16 );
		}

		$image = new Image( Pictures::png( Pictures::pattern( 8, 8, Pictures::CHANNELS[ $type ], 3 === $type ? 4 : 8 ), $type, 3 === $type ? 4 : 8, $options ) );

		$this->assertFalse( $image->has_alpha );
		$this->assertStringContainsString( '/DecodeParms', self::embedded( $image )['dict'] );
	}

	public function test_alpha_that_is_opaque_everywhere_is_no_mask(): void {
		$pixels = Pictures::pattern( 6, 6, 4, 8 );

		foreach ( $pixels as $y => $row ) {
			foreach ( $row as $x => $pixel ) {
				$pixels[ $y ][ $x ][3] = 255;
			}
		}

		$image = new Image( Pictures::png( $pixels, 6, 8 ) );

		$this->assertFalse( $image->has_alpha );
		$this->assertCount( 1, $image->objects( 1 ) );
	}

	public function test_image_data_split_across_many_chunks_reads_the_same(): void {
		$pixels = Pictures::pattern( 13, 11, 4, 8 );
		$whole  = new Image( Pictures::png( $pixels, 6, 8 ) );
		$split  = new Image( Pictures::png( $pixels, 6, 8, array( 'idat_size' => 7 ) ) );

		$this->assertSame( $whole->digest, $split->digest );
	}

	public function test_each_row_filter_on_its_own_reads_the_same(): void {
		$pixels  = Pictures::pattern( 17, 9, 4, 16 );
		$digests = array();

		foreach ( range( 0, 4 ) as $filter ) {
			$digests[] = ( new Image( Pictures::png( $pixels, 6, 16, array( 'filters' => array( $filter ) ) ) ) )->digest;
		}

		$this->assertCount( 1, array_unique( $digests ) );
	}

	/**
	 * Paeth's ties decide between neighbours of different values: with
	 * left 3, above 0 and above-left 2, "above" and "above-left" are
	 * equally near and the specification takes "above". Small values in
	 * every arrangement make every tie happen.
	 */
	public function test_paeth_breaks_ties_as_the_specification_does(): void {
		$pixels = array();

		for ( $y = 0; $y < 16; $y++ ) {
			for ( $x = 0; $x < 16; $x++ ) {
				$pixels[ $y ][ $x ] = array( ( ( $x * 7 ) + ( $y * 3 ) + ( $x * $y ) ) % 4, 255 );
			}
		}

		$pixels[0][0] = array( 2, 255 );
		$pixels[0][1] = array( 0, 255 );
		$pixels[1][0] = array( 3, 255 );
		$pixels[1][1] = array( 1, 128 );

		$image        = new Image( Pictures::png( $pixels, 4, 8, array( 'filters' => array( 4 ) ) ) );
		[ $colour ]   = Pictures::expected( $pixels, 4, 8 );

		$this->assertSame( bin2hex( $colour ), bin2hex( self::embedded( $image )['data'] ) );
	}

	public function test_ancillary_chunks_are_skipped_even_when_damaged(): void {
		$pixels  = Pictures::pattern( 5, 5, 3, 8 );
		$damaged = substr( Pictures::chunk( 'tEXt', "Comment\x00hello" ), 0, -1 ) . 'X';
		$image   = new Image( Pictures::png( $pixels, 2, 8, array( 'chunks_before_idat' => Pictures::chunk( 'gAMA', pack( 'N', 45455 ) ) . $damaged . Pictures::chunk( 'zzZz', 'private' ) ) ) );

		$this->assertSame( 5, $image->width );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function broken(): array {
		$pixels = Pictures::pattern( 8, 8, 3, 8 );
		$good   = Pictures::png( $pixels, 2, 8 );
		$header = static fn( int $width, int $height, int $depth, int $type, int $compression = 0, int $filter = 0, int $interlace = 0 ): string => Pictures::chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, $depth, $type, $compression, $filter, $interlace ) );
		$idat   = static fn( string $raw ): string => Pictures::chunk( 'IDAT', (string) gzcompress( $raw ) );
		$end    = Pictures::chunk( 'IEND', '' );
		$rows   = static fn( int $filter, int $rows, int $length ): string => str_repeat( chr( $filter ) . str_repeat( "\x10", $length ), $rows );

		// IDAT whose rows pass for a valid image, then keep going.
		$bomb = self::SIGNATURE . $header( 1, 1, 8, 2 ) . $idat( str_repeat( "\x00", 8 * 1024 * 1024 ) ) . $end;

		return array(
			'only the signature'           => array( self::SIGNATURE, 'cut short' ),
			'cut in the middle'            => array( substr( $good, 0, (int) ( strlen( $good ) * 0.6 ) ), 'cut short' ),
			'no end chunk'                 => array( substr( $good, 0, -12 ), 'cut short' ),
			'damaged image data checksum'  => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . substr( $idat( $rows( 0, 8, 24 ) ), 0, -1 ) . 'X' . $end, 'fails its checksum' ),
			'header not first'             => array( self::SIGNATURE . Pictures::chunk( 'gAMA', pack( 'N', 1 ) ) . $header( 8, 8, 8, 2 ) . $idat( $rows( 0, 8, 24 ) ) . $end, 'does not start with its header' ),
			'header the wrong length'      => array( self::SIGNATURE . Pictures::chunk( 'IHDR', str_repeat( "\x01", 12 ) ) . $end, 'header is the wrong length' ),
			'no width'                     => array( self::SIGNATURE . $header( 0, 8, 8, 2 ) . $end, 'declares a size' ),
			'a depth its type lacks'       => array( self::SIGNATURE . $header( 8, 8, 4, 2 ) . $end, 'is not a kind of PNG' ),
			'a colour type PNG lacks'      => array( self::SIGNATURE . $header( 8, 8, 8, 5 ) . $end, 'is not a kind of PNG' ),
			'an unknown compression'       => array( self::SIGNATURE . $header( 8, 8, 8, 2, 1 ) . $end, 'does not define' ),
			'an unknown interlace'         => array( self::SIGNATURE . $header( 8, 8, 8, 2, 0, 0, 2 ) . $end, 'does not define' ),
			'a palette image with none'    => array( self::SIGNATURE . $header( 8, 8, 8, 3 ) . $idat( $rows( 0, 8, 8 ) ) . $end, 'no palette' ),
			'a palette of broken colours'  => array( self::SIGNATURE . $header( 8, 8, 8, 3 ) . Pictures::chunk( 'PLTE', "\x01\x02\x03\x04" ) . $idat( $rows( 0, 8, 8 ) ) . $end, 'whole number of colours' ),
			'no image data'                => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . $end, 'no image data' ),
			'data that is not zlib'        => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . Pictures::chunk( 'IDAT', 'not deflated at all' ) . $end, 'does not decompress' ),
			'too few rows'                 => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . $idat( $rows( 0, 7, 24 ) ) . $end, 'cut short' ),
			'too few rows, decoded'        => array( self::SIGNATURE . $header( 8, 8, 8, 6 ) . $idat( $rows( 0, 7, 32 ) ) . $end, 'cut short' ),
			'too many rows'                => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . $idat( $rows( 0, 9, 24 ) ) . $end, 'more than its size allows' ),
			'an unknown row filter'        => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . $idat( $rows( 0, 7, 24 ) . $rows( 5, 1, 24 ) ) . $end, 'filter PNG does not define' ),
			'an unknown row filter, decoded' => array( self::SIGNATURE . $header( 8, 8, 8, 6 ) . $idat( $rows( 0, 7, 32 ) . $rows( 5, 1, 32 ) ) . $end, 'filter PNG does not define' ),
			'a deflate bomb'               => array( $bomb, 'more than its size allows' ),
			'a critical chunk unknown'     => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . Pictures::chunk( 'ZZZZ', '' ) . $idat( $rows( 0, 8, 24 ) ) . $end, 'ZZZZ' ),
			'a chunk with no name'         => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . Pictures::chunk( "\x00\x01\x02\x03", '' ) . $end, 'no name' ),
			'a chunk longer than the file' => array( self::SIGNATURE . $header( 8, 8, 8, 2 ) . pack( 'N', 0x7FFFFFF0 ) . 'IDAT' . 'abcd', 'runs past the end' ),
		);
	}

	/**
	 * @param string $png     The file.
	 * @param string $message What the refusal says.
	 */
	#[DataProvider( 'broken' )]
	public function test_a_broken_file_is_refused_with_the_reason( string $png, string $message ): void {
		try {
			new Image( $png, 'logo.png' );
			$this->fail( 'Expected a refusal.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringStartsWith( 'logo.png ', $error->getMessage() );
			$this->assertStringContainsString( $message, $error->getMessage() );
		}
	}

	public function test_a_deflate_bomb_is_stopped_without_inflating_it(): void {
		$header = Pictures::chunk( 'IHDR', pack( 'NNCCCCC', 4, 4, 8, 6, 0, 0, 0 ) );
		$zeros  = str_repeat( "\x00", 1024 * 1024 );
		$stream = deflate_init( ZLIB_ENCODING_DEFLATE, array( 'level' => 9 ) );
		$packed = '';

		// 256 MB of zeros, deflated a megabyte at a time.
		for ( $megabyte = 0; $megabyte < 256; $megabyte++ ) {
			$packed .= (string) deflate_add( $stream, $zeros, ZLIB_NO_FLUSH );
		}

		$packed .= (string) deflate_add( $stream, '', ZLIB_FINISH );
		$bomb    = self::SIGNATURE . $header . Pictures::chunk( 'IDAT', $packed ) . Pictures::chunk( 'IEND', '' );
		$before  = memory_get_peak_usage();
		$started = microtime( true );

		try {
			new Image( $bomb );
			$this->fail( 'Expected a refusal.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'more than its size allows', $error->getMessage() );
		}

		$this->assertLessThan( 16 * 1024 * 1024, memory_get_peak_usage() - $before );
		$this->assertLessThan( 1.0, microtime( true ) - $started );
	}

	public function test_a_size_beyond_the_limit_is_refused_before_anything_is_inflated(): void {
		$header = Pictures::chunk( 'IHDR', pack( 'NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0 ) );
		$png    = self::SIGNATURE . $header . Pictures::chunk( 'IDAT', (string) gzcompress( 'x' ) ) . Pictures::chunk( 'IEND', '' );

		$this->expectExceptionMessage( '100000 × 100000 pixels, more than the 50000000 this embeds' );

		new Image( $png, 'huge.png' );
	}

	/**
	 * Only images that have to be decoded count against the decoding
	 * limit; an opaque one of the same size goes through.
	 */
	public function test_the_decoding_limit_applies_only_to_images_that_need_decoding(): void {
		$side = (int) sqrt( Image::MAX_DECODED_PIXELS ) + 1;
		$rows = str_repeat( "\x00" . str_repeat( "\x00", $side * 3 ), $side );

		$opaque = self::SIGNATURE . Pictures::chunk( 'IHDR', pack( 'NNCCCCC', $side, $side, 8, 2, 0, 0, 0 ) ) . Pictures::chunk( 'IDAT', (string) gzcompress( $rows ) ) . Pictures::chunk( 'IEND', '' );

		$this->assertSame( $side, ( new Image( $opaque ) )->width );

		$transparent = self::SIGNATURE . Pictures::chunk( 'IHDR', pack( 'NNCCCCC', $side, $side, 8, 6, 0, 0, 0 ) ) . Pictures::chunk( 'IDAT', (string) gzcompress( 'x' ) ) . Pictures::chunk( 'IEND', '' );

		$this->expectExceptionMessage( 'has transparency, so it has to be decoded' );

		new Image( $transparent, 'big.png' );
	}
}

