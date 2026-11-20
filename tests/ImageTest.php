<?php
/**
 * Images on the page: reading a file, placing it in a box, turning it
 * upright, embedding it once, and saying so when one does not arrive.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Image;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Fit;
use SugarCommerce\Pdf\Models\Templates\Branding;
use SugarCommerce\Pdf\Models\Templates\LineItem;
use SugarCommerce\Pdf\Models\Templates\Party;
use SugarCommerce\Pdf\Templates\Invoice;
use SugarCommerce\Pdf\Templates\Receipt;
use SugarCommerce\Pdf\Templates\Statement;
use SugarCommerce\Pdf\Tests\Support\{Pictures, ProbeWrapper, Reader};

final class ImageTest extends TestCase {

	/**
	 * The drawing operators a document wrote for its images, on page one.
	 *
	 * @param Document $pdf The document.
	 *
	 * @return list<string> One `q … Do Q` group each.
	 */
	private static function placements( Document $pdf ): array {
		preg_match_all( '/q\n(?:[^\n]* re\nW n\n)?[^\n]* cm\n\/Im\d+ Do\nQ\n/', Reader::streams( $pdf->render() )[0], $groups );

		return $groups[0];
	}

	/* ─── Reading ───────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function other_formats(): array {
		return array(
			'empty'        => array( '', 'is empty.' ),
			'GIF'          => array( "GIF89a\x01\x00\x01\x00", 'is a GIF. Save it as a PNG.' ),
			'WebP'         => array( "RIFF\x00\x00\x00\x00WEBPVP8 ", 'is a WebP image.' ),
			'HEIC'         => array( "\x00\x00\x00\x18ftypheic", 'is a HEIC or AVIF image.' ),
			'TIFF'         => array( "II*\x00\x08\x00\x00\x00", 'is a TIFF.' ),
			'BMP'          => array( "BM\x36\x00\x00\x00", 'is a BMP.' ),
			'PDF'          => array( "%PDF-1.4\n", 'is a PDF, not an image.' ),
			'SVG'          => array( "<?xml version=\"1.0\"?>\n<!-- logo -->\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\">", 'is an SVG. Draw its path with svg_path()' ),
			'bare SVG'     => array( '<svg viewBox="0 0 10 10"><path d="M0 0"/></svg>', 'is an SVG.' ),
			'a JPEG start' => array( "\xFF\xD8\x00\x00", 'is damaged: it starts as a JPEG and goes no further.' ),
			'text'         => array( 'hello', 'is not a JPEG or PNG.' ),
		);
	}

	/**
	 * Refused by what it is, so the message says what to do.
	 *
	 * @param string $data    File contents.
	 * @param string $message The refusal.
	 */
	#[DataProvider( 'other_formats' )]
	public function test_other_formats_are_refused_by_name( string $data, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'logo ' . $message );

		new Image( $data, 'logo' );
	}

	public function test_a_file_too_large_is_refused_before_it_is_read(): void {
		$path = dirname( __DIR__ ) . '/tests/.tmp/huge.png';

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		$handle = fopen( $path, 'w' );
		ftruncate( $handle, Image::MAX_BYTES + 1 );
		fclose( $handle );

		try {
			Image::file( $path );
			$this->fail( 'Expected a refusal.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertSame( 'huge.png is 33554433 bytes, more than the 33554432 this reads. Save it smaller.', $error->getMessage() );
		} finally {
			unlink( $path );
		}

		$this->expectExceptionMessage( 'bytes, more than the' );

		new Image( str_repeat( "\x00", Image::MAX_BYTES + 1 ) );
	}

	public function test_a_missing_file_says_where_it_looked(): void {
		$this->expectExceptionMessage( 'Could not read the image file /nowhere/logo.png.' );

		Image::file( '/nowhere/logo.png' );
	}

	public function test_a_folder_is_not_an_image_file(): void {
		$this->expectExceptionMessage( 'Could not read the image file' );

		Image::file( __DIR__ );
	}

	/**
	 * Only a file on this server is read. A path is handed to PHP's file
	 * functions, which follow any stream wrapper it names: ftp:// reaches
	 * out to another host, phar:// opens an archive. Neither is touched.
	 */
	public function test_a_stream_wrapper_is_never_followed(): void {
		ProbeWrapper::register( (string) file_get_contents( Pictures::path( 'logo.png' ) ) );

		try {
			foreach ( array( 'probe://example.com/logo.png', 'PROBE://example.com/logo.png', 'ftp://127.0.0.1:1/logo.png', 'phar://logo.phar/logo.png', "logo.png\0.php" ) as $path ) {
				try {
					Image::file( $path );
					$this->fail( $path . ' was read.' );
				} catch ( \InvalidArgumentException $error ) {
					$this->assertStringStartsWith( 'Could not read the image file', $error->getMessage(), $path );
				}
			}

			$this->assertSame( array(), ProbeWrapper::$touched );
		} finally {
			ProbeWrapper::unregister();
		}
	}

	public function test_a_file_is_read_once_and_named_by_its_file_name(): void {
		$image = Image::file( Pictures::path( 'photo.jpg' ) );

		$this->assertSame( $image, Image::file( Pictures::path( 'photo.jpg' ) ) );
		$this->assertSame( 'photo.jpg', $image->name );
	}

	/**
	 * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
	 */
	public static function boxes(): array {
		return array(
			'wider box'   => array( 300, 100, 150, 100 ),
			'taller box'  => array( 90, 300, 90, 60 ),
			'same shape'  => array( 60, 40, 60, 40 ),
			'empty box'   => array( 0, 40, 0, 0 ),
			'negative'    => array( -10, 40, 0, 0 ),
		);
	}

	/**
	 * The largest in-proportion size inside a box, for a 3:2 picture.
	 *
	 * @param float $width   Box width.
	 * @param float $height  Box height.
	 * @param float $fitted_width  Expected width.
	 * @param float $fitted_height Expected height.
	 */
	#[DataProvider( 'boxes' )]
	public function test_fitted_keeps_the_proportions( float $width, float $height, float $fitted_width, float $fitted_height ): void {
		[ $got_width, $got_height ] = Image::file( Pictures::path( 'photo.jpg' ) )->fitted( $width, $height );

		$this->assertEqualsWithDelta( $fitted_width, $got_width, 0.0001 );
		$this->assertEqualsWithDelta( $fitted_height, $got_height, 0.0001 );
	}

	/* ─── Placing ───────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{0: Fit, 1: Align, 2: string}>
	 */
	public static function fits(): array {
		// A 120 × 80 picture in a 300 × 100 box at (50, 400).
		return array(
			'contain, centred' => array( Fit::Contain, Align::Center, "q\n150.000 0.000 0.000 100.000 125.000 400.000 cm\n/Im1 Do\nQ\n" ),
			'contain, left'    => array( Fit::Contain, Align::Left, "q\n150.000 0.000 0.000 100.000 50.000 400.000 cm\n/Im1 Do\nQ\n" ),
			'contain, right'   => array( Fit::Contain, Align::Right, "q\n150.000 0.000 0.000 100.000 200.000 400.000 cm\n/Im1 Do\nQ\n" ),
			'cover, centred'   => array( Fit::Cover, Align::Center, "q\n50.000 400.000 300.000 100.000 re\nW n\n300.000 0.000 0.000 200.000 50.000 350.000 cm\n/Im1 Do\nQ\n" ),
			'cover, right'     => array( Fit::Cover, Align::Right, "q\n50.000 400.000 300.000 100.000 re\nW n\n300.000 0.000 0.000 200.000 50.000 350.000 cm\n/Im1 Do\nQ\n" ),
			'fill'             => array( Fit::Fill, Align::Center, "q\n300.000 0.000 0.000 100.000 50.000 400.000 cm\n/Im1 Do\nQ\n" ),
		);
	}

	/**
	 * @param Fit    $fit      How it fills the box.
	 * @param Align  $align    Where across it.
	 * @param string $expected The operators.
	 */
	#[DataProvider( 'fits' )]
	public function test_the_box_is_filled_as_asked( Fit $fit, Align $align, string $expected ): void {
		$pdf = new Document();

		$this->assertTrue( $pdf->image( Pictures::path( 'photo.jpg' ), 50, 400, 300, 100, $fit, $align ) );
		$this->assertSame( array( $expected ), self::placements( $pdf ) );
	}

	public function test_a_taller_box_centres_the_picture_top_to_bottom(): void {
		$pdf = new Document();

		$pdf->image( Pictures::path( 'photo.jpg' ), 50, 400, 60, 100, Fit::Contain, Align::Left );

		$this->assertSame( array( "q\n60.000 0.000 0.000 40.000 50.000 430.000 cm\n/Im1 Do\nQ\n" ), self::placements( $pdf ) );
	}

	public function test_a_covering_picture_overhangs_on_its_long_side_and_is_aligned_on_it(): void {
		$pdf = new Document();

		// 3:2 into a square: as tall as the box, wider, cut off at the sides.
		$pdf->image( Pictures::path( 'photo.jpg' ), 100, 100, 80, 80, Fit::Cover, Align::Left );
		$pdf->image( Pictures::path( 'photo.jpg' ), 100, 100, 80, 80, Fit::Cover, Align::Right );

		$this->assertSame(
			array(
				"q\n100.000 100.000 80.000 80.000 re\nW n\n120.000 0.000 0.000 80.000 100.000 100.000 cm\n/Im1 Do\nQ\n",
				"q\n100.000 100.000 80.000 80.000 re\nW n\n120.000 0.000 0.000 80.000 60.000 100.000 cm\n/Im1 Do\nQ\n",
			),
			self::placements( $pdf )
		);
	}

	/**
	 * @return array<int, array{0: int, 1: string}>
	 */
	public static function matrices(): array {
		// Drawn into a 100 × 50 box at (10, 20).
		return array(
			1 => array( 1, '100.000 0.000 0.000 50.000 10.000 20.000 cm' ),
			2 => array( 2, '-100.000 0.000 0.000 50.000 110.000 20.000 cm' ),
			3 => array( 3, '-100.000 0.000 0.000 -50.000 110.000 70.000 cm' ),
			4 => array( 4, '100.000 0.000 0.000 -50.000 10.000 70.000 cm' ),
			5 => array( 5, '0.000 -50.000 -100.000 0.000 110.000 70.000 cm' ),
			6 => array( 6, '0.000 -50.000 100.000 0.000 10.000 70.000 cm' ),
			7 => array( 7, '0.000 50.000 100.000 0.000 10.000 20.000 cm' ),
			8 => array( 8, '0.000 50.000 -100.000 0.000 110.000 20.000 cm' ),
		);
	}

	/**
	 * @param int    $orientation EXIF orientation.
	 * @param string $expected    The `cm` operator.
	 */
	#[DataProvider( 'matrices' )]
	public function test_each_orientation_has_its_matrix( int $orientation, string $expected ): void {
		$image = new Image( Pictures::with_orientation( (string) file_get_contents( Pictures::path( 'photo.jpg' ) ), $orientation ) );

		$this->assertSame( $expected . "\n", $image->placement( 10, 20, 100, 50 ) );
	}

	/**
	 * @return array<int, array{0: int}>
	 */
	public static function orientations(): array {
		return array_map( static fn( array $case ): array => array( $case[0] ), self::matrices() );
	}

	/**
	 * The independent check: poppler draws each orientation the way the
	 * EXIF specification says a viewer shows it.
	 *
	 * The stored picture has four coloured quarters. For every quarter of
	 * the page's picture, the specification's own mapping finds the stored
	 * pixel that should be there, and so the colour.
	 *
	 * @param int $orientation EXIF orientation.
	 */
	#[DataProvider( 'orientations' )]
	public function test_poppler_shows_each_orientation_as_a_photo_viewer_would( int $orientation ): void {
		$image = new Image( Pictures::with_orientation( (string) file_get_contents( Pictures::path( 'photo.jpg' ) ), $orientation ) );
		$pdf   = new Document();
		$top   = 100;

		// Drawn a point a pixel, as the image is seen.
		$pdf->image( $image, 100, $pdf->height() - $top - $image->height, $image->width, $image->height, Fit::Fill );

		$page = Reader::rendered( $pdf->render() );

		// Stored: 120 × 80; top-left red, top-right green, bottom-left blue,
		// bottom-right yellow.
		$colours = array( array( 220, 30, 30 ), array( 30, 180, 40 ), array( 30, 60, 220 ), array( 240, 210, 20 ) );
		$stored  = static fn( int $c, int $r ): array => $colours[ ( $r >= 40 ? 2 : 0 ) + ( $c >= 60 ? 1 : 0 ) ];

		// Where a displayed pixel comes from, per the EXIF specification's
		// description of each value (W = 120, H = 80 as stored).
		$source = match ( $orientation ) {
			1 => static fn( int $x, int $y ): array => array( $x, $y ),
			2 => static fn( int $x, int $y ): array => array( 119 - $x, $y ),
			3 => static fn( int $x, int $y ): array => array( 119 - $x, 79 - $y ),
			4 => static fn( int $x, int $y ): array => array( $x, 79 - $y ),
			5 => static fn( int $x, int $y ): array => array( $y, $x ),
			6 => static fn( int $x, int $y ): array => array( $y, 79 - $x ),
			7 => static fn( int $x, int $y ): array => array( 119 - $y, 79 - $x ),
			8 => static fn( int $x, int $y ): array => array( 119 - $y, $x ),
		};

		foreach ( array( 0.25, 0.75 ) as $across ) {
			foreach ( array( 0.25, 0.75 ) as $down ) {
				$x = (int) ( $image->width * $across );
				$y = (int) ( $image->height * $down );

				[ $c, $r ] = $source( $x, $y );
				$want      = $stored( $c, $r );
				$got       = Reader::pixel( $page, 100 + $x, $top + $y );

				foreach ( array( 0, 1, 2 ) as $channel ) {
					$this->assertEqualsWithDelta( $want[ $channel ], $got[ $channel ], 12, "orientation $orientation at $x,$y" );
				}
			}
		}
	}

	/**
	 * CMYK is checked on the page too: without the Decode array a
	 * Photoshop CMYK picture prints as its own negative.
	 */
	public function test_poppler_shows_a_cmyk_jpeg_in_its_colours(): void {
		$pdf = new Document();
		$top = 100;

		$pdf->image( Pictures::path( 'cmyk.jpg' ), 100, $pdf->height() - $top - 80, 120, 80, Fit::Fill );

		$page = Reader::rendered( $pdf->render() );
		$red  = Reader::pixel( $page, 130, $top + 20 );

		$this->assertGreaterThan( 180, $red[0] );
		$this->assertLessThan( 90, $red[1] );
		$this->assertLessThan( 90, $red[2] );
	}

	/* ─── Embedding ─────────────────────────────────────────────────── */

	public function test_the_same_picture_on_every_page_is_embedded_once(): void {
		$pdf   = new Document();
		$bytes = (string) file_get_contents( Pictures::path( 'logo.png' ) );

		foreach ( range( 1, 3 ) as $page ) {
			// Read afresh each time: the same bytes are the same image.
			$pdf->image( new Image( $bytes ), 50, 700, 90, 30 );
			$pdf->image( Pictures::path( 'logo.png' ), 50, 600, 90, 30 );
			$pdf->page_break();
		}

		$pdf->image( Pictures::path( 'photo.jpg' ), 50, 500, 120, 80 );

		$rendered = $pdf->render();
		$plain    = Reader::plain( $rendered );

		$this->assertSame( 2, substr_count( $plain, '/Subtype /Image /Width 180' ) );
		$this->assertSame( 1, substr_count( $plain, '/Filter /DCTDecode' ) );
		$this->assertSame( 4, substr_count( $plain, '/XObject <</Im1 ' ) );
		$this->assertStringContainsString( '/Im2 ', $plain );

		foreach ( Reader::streams( $rendered ) as $index => $stream ) {
			$this->assertSame( $index < 3 ? 2 : 1, substr_count( $stream, ' Do' ), 'page ' . ( $index + 1 ) );
		}
	}

	public function test_a_picture_in_the_footer_is_one_image_and_asking_changes_nothing(): void {
		$pdf = new Document();

		$pdf->on_each_page( static fn( Document $doc ) => $doc->image( Pictures::path( 'palette.png' ), 50, 20, 32, 16 ) );
		$pdf->text( 'One' )->page_break()->text( 'Two' );

		$before = $pdf->render( array( 'CreationDate' => 1 ) );

		$pdf->drawn_text();
		$pdf->image_warnings();

		$this->assertSame( $before, $pdf->render( array( 'CreationDate' => 1 ) ) );

		$listed = Reader::image_list( $before );

		$this->assertSame( array( 1, 1, 2, 2 ), array_column( $listed, 'page' ) );
		$this->assertSame( array( 'image', 'smask', 'image', 'smask' ), array_column( $listed, 'type' ) );
		$this->assertCount( 1, array_unique( array_column( $listed, 'object' ) ) );
	}

	/**
	 * Same colours, different shape or transparency: different pictures,
	 * however alike their samples are.
	 */
	public function test_pictures_alike_in_their_samples_are_still_two(): void {
		$solid  = static fn( int $width, int $height, int $alpha ): string => Pictures::png( array_fill( 0, $height, array_fill( 0, $width, array( 200, 40, 40, $alpha ) ) ), 6, 8 );
		$images = array( new Image( $solid( 4, 2, 128 ) ), new Image( $solid( 4, 2, 64 ) ), new Image( $solid( 2, 4, 128 ) ) );
		$pdf    = new Document();

		foreach ( $images as $index => $image ) {
			$pdf->image( $image, 50 + ( $index * 60 ), 500, 40, 40 );
		}

		$this->assertCount( 3, array_unique( array_map( static fn( Image $image ): string => $image->digest, $images ) ) );
		$this->assertSame( 3, substr_count( Reader::plain( $pdf->render() ), '/SMask ' ) );
	}

	/**
	 * Asking for the drawn text runs the footers and puts back what they
	 * registered; a picture the footer draws keeps the name it would have
	 * had, after the ones the page drew first.
	 */
	public function test_asking_before_drawing_more_does_not_reorder_the_pictures(): void {
		$make = static function ( bool $ask ): string {
			$pdf = new Document();

			$pdf->on_each_page( static fn( Document $doc ) => $doc->image( Pictures::path( 'palette.png' ), 50, 20, 32, 16 ) );

			if ( $ask ) {
				$pdf->drawn_text();
			}

			$pdf->image( Pictures::path( 'photo.jpg' ), 50, 500, 120, 80 );

			return $pdf->render( array( 'CreationDate' => 1 ) );
		};

		$this->assertSame( $make( false ), $make( true ) );
		$this->assertStringContainsString( '/XObject <</Im1 ', $make( true ) );
		$this->assertStringContainsString( 'DCTDecode', Reader::objects( $make( true ) )[4]['dict'] );
	}

	/**
	 * Fonts first, then images — the picture and its mask in turn — then
	 * the pages; the mask the picture names is the one written after it.
	 */
	public function test_images_are_numbered_after_the_fonts_with_their_masks(): void {
		$pdf = new Document();

		$pdf->text( 'Logo' );
		$pdf->image( Pictures::path( 'logo.png' ), 50, 600, 90, 30 );
		$pdf->image( Pictures::path( 'photo.jpg' ), 50, 500, 120, 80 );

		$objects = Reader::objects( $pdf->render() );

		$this->assertStringContainsString( '/BaseFont /Helvetica', $objects[4]['dict'] );
		$this->assertStringContainsString( '/Width 180 /Height 60 /ColorSpace /DeviceRGB', $objects[5]['dict'] );
		$this->assertStringContainsString( '/SMask 6 0 R', $objects[5]['dict'] );
		$this->assertStringContainsString( '/Width 180 /Height 60 /ColorSpace /DeviceGray', $objects[6]['dict'] );
		$this->assertStringNotContainsString( '/SMask', $objects[6]['dict'] );
		$this->assertStringContainsString( '/DCTDecode', $objects[7]['dict'] );
		$this->assertStringContainsString( '/Type /Page ', $objects[8]['dict'] );
		$this->assertStringContainsString( '/XObject <</Im1 5 0 R /Im2 7 0 R>>', $objects[8]['dict'] );
	}

	public function test_a_document_without_images_has_no_xobjects(): void {
		$this->assertStringNotContainsString( '/XObject', ( new Document() )->text( 'Plain' )->render() );
	}

	public function test_every_kind_of_image_together_passes_qpdf_and_lists_in_pdfimages(): void {
		$pdf   = new Document();
		$files = array( 'photo.jpg', 'progressive.jpg', 'gray.jpg', 'cmyk.jpg', 'logo.png', 'palette.png', 'opaque.png' );

		foreach ( $files as $index => $file ) {
			$this->assertTrue( $pdf->image( Pictures::path( $file ), 50 + ( ( $index % 3 ) * 160 ), 650 - ( intdiv( $index, 3 ) * 120 ), 150, 100 ) );
		}

		$bytes = $pdf->render();

		[ $code, $output ] = Reader::run( Reader::tool( 'qpdf' ), array( '--check' ), $bytes );

		$this->assertSame( 0, $code, $output );

		$rows = array_map(
			static fn( array $row ): string => implode( ' ', array( $row['type'], $row['width'], $row['height'], $row['color'], $row['comp'], $row['bpc'], $row['enc'] ) ),
			Reader::image_list( $bytes )
		);

		$this->assertSame(
			array(
				'image 120 80 rgb 3 8 jpeg',
				'image 120 80 rgb 3 8 jpeg',
				'image 120 80 gray 1 8 jpeg',
				'image 120 80 cmyk 4 8 jpeg',
				'image 180 60 rgb 3 8 image',
				'smask 180 60 gray 1 8 image',
				'image 64 32 index 1 8 image',
				'smask 64 32 gray 1 8 image',
				'image 120 80 rgb 3 8 image',
			),
			$rows
		);
	}

	/* ─── Warnings ──────────────────────────────────────────────────── */

	public function test_an_image_that_cannot_be_read_is_not_drawn_and_says_why(): void {
		$pdf = new Document();

		$this->assertFalse( $pdf->image( '/nowhere/logo.png', 50, 50, 100, 100 ) );
		$this->assertNull( $pdf->load_image( __FILE__ ) );
		$this->assertSame(
			array(
				'Could not read the image file /nowhere/logo.png.',
				'ImageTest.php is not a JPEG or PNG.',
			),
			$pdf->image_warnings()
		);
		$this->assertStringNotContainsString( ' Do', Reader::streams( $pdf->render() )[0] );
	}

	/**
	 * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: string|null}>
	 */
	public static function placings(): array {
		return array(
			'inside'              => array( 50, 50, 100, 100, null ),
			'touching the edges'  => array( 0, 0, 595.28, 841.89, null ),
			'off the left'        => array( -5, 50, 100, 100, 'photo.jpg runs off the edge of the page, where a reader cuts it off.' ),
			'off the bottom'      => array( 50, -5, 100, 100, 'photo.jpg runs off the edge of the page, where a reader cuts it off.' ),
			'off the right'       => array( 500, 50, 100, 100, 'photo.jpg runs off the edge of the page, where a reader cuts it off.' ),
			'off the top'         => array( 50, 800, 100, 100, 'photo.jpg runs off the edge of the page, where a reader cuts it off.' ),
			'no width'            => array( 50, 50, 0, 100, 'photo.jpg was given a box of 0 × 100 points and was not drawn.' ),
			'a negative height'   => array( 50, 50, 100, -2.5, 'photo.jpg was given a box of 100 × -2.5 points and was not drawn.' ),
			'not a number'        => array( 50, 50, NAN, 100, 'photo.jpg was given a box of 0 × 100 points and was not drawn.' ),
		);
	}

	/**
	 * @param float       $x       Left.
	 * @param float       $y       Bottom.
	 * @param float       $width   Width.
	 * @param float       $height  Height.
	 * @param string|null $warning What is said, if anything.
	 */
	#[DataProvider( 'placings' )]
	public function test_a_picture_off_the_page_or_in_no_box_is_reported( float $x, float $y, float $width, float $height, ?string $warning ): void {
		$pdf = new Document();

		$drawn = $pdf->image( Pictures::path( 'photo.jpg' ), $x, $y, $width, $height, Fit::Fill );

		$this->assertSame( null === $warning ? array() : array( $warning ), $pdf->image_warnings() );
		$this->assertSame( null === $warning || ! str_contains( $warning, 'not drawn' ), $drawn );
	}

	public function test_a_footer_warning_is_listed_once_before_and_after_rendering(): void {
		$pdf = new Document();

		$pdf->on_each_page( static fn( Document $doc ) => $doc->image( '/nowhere/seal.png', 50, 20, 32, 16 ) );
		$pdf->text( 'One' )->page_break()->text( 'Two' );

		$this->assertSame( array( 'Could not read the image file /nowhere/seal.png.' ), $pdf->image_warnings() );

		$pdf->render();

		$this->assertSame( array( 'Could not read the image file /nowhere/seal.png.' ), $pdf->image_warnings() );
	}

	/* ─── Logos on the templates ────────────────────────────────────── */

	/**
	 * An invoice with a branding.
	 *
	 * @param Branding $brand The branding.
	 *
	 * @return Invoice
	 */
	private static function invoice( Branding $brand ): Invoice {
		return new Invoice(
			$brand,
			'INV-1',
			new Party( 'Seller Ltd', array( '1 Road' ), tax_id: 'IE1' ),
			new Party( 'Buyer', array( '2 Street' ) ),
			array( new LineItem( 'Thing', '€10.00' ) ),
			total: array( 'Total' => '€10.00' ),
			supply_date: '1 November 2026',
		);
	}

	/**
	 * Where the wordmark starts, from the first text run on page one.
	 *
	 * @param Document $pdf Rendered template.
	 *
	 * @return float
	 */
	private static function wordmark_x( Document $pdf ): float {
		return Reader::runs( $pdf->render() )[0][0]['x'];
	}

	public function test_an_invoice_logo_is_as_tall_as_the_band_and_the_wordmark_follows_it(): void {
		$pdf = self::invoice( new Branding( 'Northwind', logo_image: Pictures::path( 'logo.png' ) ) )->render();

		// 180 × 60 is 3:1: 102 × 34 at the left margin, the band's top 48
		// below the page's.
		$this->assertSame( array( sprintf( "q\n102.000 0.000 0.000 34.000 48.000 %.3F cm\n/Im1 Do\nQ\n", $pdf->height() - 48 - 34 ) ), self::placements( $pdf ) );
		$this->assertEqualsWithDelta( 48 + 102 + 12, self::wordmark_x( $pdf ), 0.01 );
		$this->assertSame( array(), $pdf->image_warnings() );
	}

	public function test_a_very_wide_logo_stops_at_four_times_the_band_and_is_centred_in_it(): void {
		$wide = new Image( Pictures::png( Pictures::pattern( 80, 10, 3, 8 ), 2, 8 ) );
		$pdf  = self::invoice( new Branding( 'Northwind', logo_image: $wide ) )->render();

		// 8:1 into 136 × 34: 136 × 17, 8.5 down from the band's top.
		$this->assertSame( array( sprintf( "q\n136.000 0.000 0.000 17.000 48.000 %.3F cm\n/Im1 Do\nQ\n", $pdf->height() - 48 - 34 + 8.5 ) ), self::placements( $pdf ) );
		$this->assertEqualsWithDelta( 48 + 136 + 12, self::wordmark_x( $pdf ), 0.01 );
	}

	public function test_a_logo_that_cannot_be_read_falls_back_to_the_vector_mark_and_says_so(): void {
		$brand = new Branding( 'Northwind', logo_path: 'M0,0 L10,0 L10,10 Z', logo_box: 10, logo_image: '/nowhere/logo.png' );
		$pdf   = self::invoice( $brand )->render();

		$this->assertSame( array(), self::placements( $pdf ) );
		$this->assertStringContainsString( "re\n", Reader::streams( $pdf->render() )[0] . "re\n" );
		$this->assertStringContainsString( ' l', Reader::streams( $pdf->render() )[0] );
		$this->assertEqualsWithDelta( 48 + 34 + 12, self::wordmark_x( $pdf ), 0.01 );
		$this->assertSame( array( 'Could not read the image file /nowhere/logo.png.' ), $pdf->image_warnings() );
	}

	public function test_a_logo_that_cannot_be_read_and_no_vector_mark_leaves_the_wordmark_at_the_margin(): void {
		$pdf = self::invoice( new Branding( 'Northwind', logo_image: __FILE__ ) )->render();

		$this->assertEqualsWithDelta( 48, self::wordmark_x( $pdf ), 0.01 );
		$this->assertSame( array( 'ImageTest.php is not a JPEG or PNG.' ), $pdf->image_warnings() );
	}

	public function test_a_receipt_and_a_statement_draw_the_logo_in_their_own_bands(): void {
		$brand     = new Branding( 'Northwind', logo_image: Pictures::path( 'photo.jpg' ) );
		$receipt   = ( new Receipt( $brand, 'R-1', new Party( 'Buyer' ), array( new LineItem( 'Thing', '€5.00' ) ), '€5.00' ) )->render();
		$statement = ( new Statement( $brand, new Party( 'Buyer' ), 'November 2026', array( array( '1 Nov', 'Order', '€5.00' ) ), array( 'Closing balance' => '€5.00' ) ) )->render();

		// 3:2 in a 26-point band, at the receipt's 40-point margin; 30 on
		// the statement, at 48.
		$this->assertSame( array( sprintf( "q\n39.000 0.000 0.000 26.000 40.000 %.3F cm\n/Im1 Do\nQ\n", $receipt->height() - 40 - 26 ) ), self::placements( $receipt ) );
		$this->assertEqualsWithDelta( 40 + 39 + 10, self::wordmark_x( $receipt ), 0.01 );
		$this->assertSame( array( sprintf( "q\n45.000 0.000 0.000 30.000 48.000 %.3F cm\n/Im1 Do\nQ\n", $statement->height() - 48 - 30 ) ), self::placements( $statement ) );
		$this->assertEqualsWithDelta( 48 + 45 + 12, self::wordmark_x( $statement ), 0.01 );
	}
}
