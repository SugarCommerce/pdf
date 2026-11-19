<?php
/**
 * The JPEG reader: sizes, colour models, orientation, what is carried
 * and what is left out — and every way a file can be broken.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Image;
use SugarCommerce\Pdf\Tests\Support\Pictures;

final class JpegTest extends TestCase {

	/**
	 * The image stream and its dictionary.
	 *
	 * @param Image $image The image.
	 *
	 * @return array{dict: string, data: string}
	 */
	private static function embedded( Image $image ): array {
		$object = $image->objects( 1 )[1];
		$at     = (int) strpos( $object, ">>\nstream\n" );

		return array(
			'dict' => substr( $object, 0, $at + 2 ),
			'data' => substr( $object, $at + 10, -10 ),
		);
	}

	/**
	 * A committed file's bytes.
	 *
	 * @param string $name File name.
	 *
	 * @return string
	 */
	private static function file( string $name ): string {
		return (string) file_get_contents( Pictures::path( $name ) );
	}

	public function test_a_baseline_jpeg_is_passed_through_from_its_frame_to_its_end(): void {
		$source   = self::file( 'photo.jpg' );
		$image    = new Image( $source );
		$embedded = self::embedded( $image );

		$this->assertSame( array( 120, 80, 'jpeg', 'rgb', false, 1 ), array( $image->width, $image->height, $image->format, $image->color_space, $image->has_alpha, $image->orientation ) );
		$this->assertSame( '<</Type /XObject /Subtype /Image /Width 120 /Height 80 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $embedded['data'] ) . '>>', $embedded['dict'] );
		$this->assertStringStartsWith( "\xFF\xD8\xFF\xE0", $embedded['data'] );

		// Everything from the quantisation tables on, byte for byte.
		$tables = Pictures::marker( $source, 0xDB );

		$this->assertGreaterThan( 0, $tables );
		$this->assertStringEndsWith( substr( $source, $tables ), $embedded['data'] );
	}

	public function test_a_progressive_jpeg_is_passed_through(): void {
		$image = new Image( self::file( 'progressive.jpg' ) );

		$this->assertSame( array( 120, 80, 'rgb' ), array( $image->width, $image->height, $image->color_space ) );
		$this->assertStringContainsString( "\xFF\xC2", self::embedded( $image )['data'] );
	}

	public function test_a_greyscale_jpeg_is_drawn_in_grey(): void {
		$image = new Image( self::file( 'gray.jpg' ) );

		$this->assertSame( 'gray', $image->color_space );
		$this->assertStringContainsString( '/ColorSpace /DeviceGray', self::embedded( $image )['dict'] );
	}

	/**
	 * Photoshop's CMYK is stored inverted and flagged with Adobe's marker;
	 * the Decode array is what turns it back.
	 */
	public function test_an_adobe_cmyk_jpeg_is_inverted_back(): void {
		$image = new Image( self::file( 'cmyk.jpg' ) );
		$dict  = self::embedded( $image )['dict'];

		$this->assertSame( 'cmyk', $image->color_space );
		$this->assertStringContainsString( '/ColorSpace /DeviceCMYK /BitsPerComponent 8 /Decode [1 0 1 0 1 0 1 0] /Filter /DCTDecode', $dict );
	}

	public function test_a_cmyk_jpeg_without_adobes_marker_is_not_inverted(): void {
		$source = self::file( 'cmyk.jpg' );
		$adobe  = Pictures::marker( $source, 0xEE );
		$length = (int) unpack( 'n', $source, $adobe + 2 )[1];
		$plain  = substr( $source, 0, $adobe ) . substr( $source, $adobe + 2 + $length );

		$this->assertStringNotContainsString( '/Decode', self::embedded( new Image( $plain ) )['dict'] );
	}

	/**
	 * A JPEG whose components are named R, G and B holds RGB, not YCbCr;
	 * a PDF reader assumes YCbCr unless told.
	 */
	public function test_components_named_rgb_are_not_colour_transformed(): void {
		$source = self::file( 'photo.jpg' );
		$frame  = Pictures::marker( $source, 0xC0 );
		$named  = $source;

		foreach ( array( 'R', 'G', 'B' ) as $index => $letter ) {
			$named[ $frame + 10 + ( 3 * $index ) ] = $letter;
		}

		$this->assertStringContainsString( '/Filter /DCTDecode /DecodeParms <</ColorTransform 0>>', self::embedded( new Image( $named ) )['dict'] );
		$this->assertStringNotContainsString( '/DecodeParms', self::embedded( new Image( $source ) )['dict'] );
	}

	/**
	 * EXIF, the ICC profile and comments are left behind: where a photo was
	 * taken has no business on an invoice, and the reader uses none of it.
	 * The JFIF header and Adobe's marker stay.
	 */
	public function test_metadata_is_left_out_and_what_readers_use_stays(): void {
		$source = Pictures::with_orientation( self::file( 'cmyk.jpg' ), 1 );
		$source = Pictures::with_segment( $source, 0xFE, 'GPS 53.3498 N, 6.2603 W' );
		$source = Pictures::with_segment( $source, 0xE2, "ICC_PROFILE\x00\x01\x01" . str_repeat( "\x2A", 4000 ) );

		$data = self::embedded( new Image( $source ) )['data'];

		foreach ( array( 'Exif', 'ICC_PROFILE', 'GPS 53', 'Cam' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $data );
		}

		$this->assertStringContainsString( 'JFIF', $data );
		$this->assertStringContainsString( 'Adobe', $data );
		$this->assertStringNotContainsString( "\x2A\x2A\x2A\x2A", $data );
		$this->assertStringNotContainsString( 'Photoshop', $data );
		$this->assertLessThan( strlen( self::file( 'cmyk.jpg' ) ), strlen( $data ) );
	}

	public function test_bytes_after_the_end_of_the_image_are_not_carried(): void {
		$source = self::file( 'photo.jpg' );
		$data   = self::embedded( new Image( $source . 'appended payload' ) )['data'];

		$this->assertStringEndsWith( "\xFF\xD9", $data );
		$this->assertStringNotContainsString( 'appended', $data );
	}

	/**
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function orientations(): array {
		$cases = array();

		foreach ( range( 1, 8 ) as $orientation ) {
			$cases[ $orientation . ', Intel' ]    = array( $orientation, false );
			$cases[ $orientation . ', Motorola' ] = array( $orientation, true );
		}

		return $cases;
	}

	/**
	 * Read in either byte order; turned a quarter, the sides swap.
	 *
	 * @param int  $orientation 1–8.
	 * @param bool $motorola    Big-endian.
	 */
	#[DataProvider( 'orientations' )]
	public function test_the_exif_orientation_is_read_and_turns_the_size( int $orientation, bool $motorola ): void {
		$image = new Image( Pictures::with_orientation( self::file( 'photo.jpg' ), $orientation, $motorola ) );

		$this->assertSame( $orientation, $image->orientation );
		$this->assertSame( $orientation >= 5 ? array( 80, 120 ) : array( 120, 80 ), array( $image->width, $image->height ) );
		$this->assertEqualsWithDelta( $orientation >= 5 ? 80 / 120 : 120 / 80, $image->aspect_ratio(), 0.0001 );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unreadable_orientations(): array {
		$photo = (string) file_get_contents( Pictures::path( 'photo.jpg' ) );

		return array(
			'out of range'          => array( Pictures::with_orientation( $photo, 9 ) ),
			'zero'                  => array( Pictures::with_orientation( $photo, 0 ) ),
			'not a SHORT'           => array( Pictures::with_orientation( $photo, 6, false, 4 ) ),
			'no byte order'         => array( Pictures::with_segment( $photo, 0xE1, "Exif\x00\x00XX*\x00\x08\x00\x00\x00" ) ),
			'directory off the end' => array( Pictures::with_segment( $photo, 0xE1, "Exif\x00\x00II*\x00\xFF\xFF\x00\x00" ) ),
			'entries off the end'   => array( Pictures::with_segment( $photo, 0xE1, "Exif\x00\x00II*\x00\x08\x00\x00\x00\xFF\x00" ) ),
			'too short'             => array( Pictures::with_segment( $photo, 0xE1, "Exif\x00\x00II" ) ),
			'not EXIF at all'       => array( Pictures::with_segment( $photo, 0xE1, 'http://ns.adobe.com/xap/1.0/' ) ),
		);
	}

	/**
	 * An orientation that cannot be read leaves the image upright, as a
	 * photo viewer would.
	 *
	 * @param string $jpeg The file.
	 */
	#[DataProvider( 'unreadable_orientations' )]
	public function test_an_orientation_that_cannot_be_read_leaves_the_image_upright( string $jpeg ): void {
		$image = new Image( $jpeg );

		$this->assertSame( 1, $image->orientation );
		$this->assertSame( array( 120, 80 ), array( $image->width, $image->height ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function broken(): array {
		$photo = (string) file_get_contents( Pictures::path( 'photo.jpg' ) );
		$frame = Pictures::marker( $photo, 0xC0 );
		$scan  = Pictures::marker( $photo, 0xDA );
		$with  = static function ( int $offset, string $bytes ) use ( $photo ): string {
			return substr_replace( $photo, $bytes, $offset, strlen( $bytes ) );
		};

		return array(
			'the first three bytes'       => array( "\xFF\xD8\xFF", 'cut short' ),
			'a start and nothing'         => array( "\xFF\xD8\xFF\xD9", 'ends before it begins' ),
			'cut before the image data'   => array( substr( $photo, 0, $scan ), 'cut short' ),
			'cut inside a segment'        => array( substr( $photo, 0, $frame + 3 ), 'cut short' ),
			'cut inside the image data'   => array( substr( $photo, 0, -200 ), 'image data has no end' ),
			'no frame header'             => array( substr( $photo, 0, $frame ) . substr( $photo, $frame + 2 + (int) unpack( 'n', $photo, $frame + 2 )[1] ), 'before its frame header' ),
			'twelve bits a sample'        => array( $with( $frame + 4, "\x0C" ), '12 bits a sample' ),
			'no height'                   => array( $with( $frame + 5, "\x00\x00" ), 'does not say how large' ),
			'two components'              => array( $with( $frame + 9, "\x02" ), '2 colour components' ),
			'a frame header cut short'    => array( $with( $frame + 2, "\x00\x07" ), 'cut short' ),
			'arithmetic coding'           => array( $with( $frame + 1, "\xC9" ), 'arithmetic coding' ),
			'lossless'                    => array( $with( $frame + 1, "\xC3" ), 'lossless' ),
			'hierarchical'                => array( $with( $frame + 1, "\xC5" ), 'hierarchical' ),
			'a segment shorter than two'  => array( $with( $frame + 2, "\x00\x01" ), 'cut short' ),
			'a segment past the end'      => array( "\xFF\xD8\xFF\xE0\xFF\xFF" . 'JFIF', 'cut short' ),
			'too large to embed'          => array( $with( $frame + 5, "\xFF\xFF\xFF\xFF" ), '65535 × 65535 pixels, more than the 50000000' ),
		);
	}

	/**
	 * @param string $jpeg    The file.
	 * @param string $message What the refusal says.
	 */
	#[DataProvider( 'broken' )]
	public function test_a_broken_file_is_refused_with_the_reason( string $jpeg, string $message ): void {
		try {
			new Image( $jpeg, 'photo.jpg' );
			$this->fail( 'Expected a refusal.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringStartsWith( 'photo.jpg ', $error->getMessage() );
			$this->assertStringContainsString( $message, $error->getMessage() );
		}
	}

	/**
	 * Garbage between segments is stepped over, as decoders step over it;
	 * so are fill bytes before a marker.
	 */
	public function test_stray_bytes_between_segments_are_stepped_over(): void {
		$photo = self::file( 'photo.jpg' );
		$frame = Pictures::marker( $photo, 0xC0 );
		$messy = substr( $photo, 0, $frame ) . "junk\xFF\xFF\xFF" . substr( $photo, $frame );

		$this->assertSame( 120, ( new Image( $messy ) )->width );
	}
}
