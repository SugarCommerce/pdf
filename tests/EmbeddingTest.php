<?php
/**
 * Embedded fonts in a document: which face draws what, what is written,
 * and that a reader gets back exactly the letters that were set.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Models\FontFamily;
use SugarCommerce\Pdf\Fonts\{FontSubset, TrueType};
use SugarCommerce\Pdf\Tests\Support\{FontBuilder, Reader};

final class EmbeddingTest extends TestCase {

	private const CREATED = array( 'CreationDate' => 1795000000 );

	/* ─── The subset ────────────────────────────────────────────────── */

	public function test_codes_are_handed_out_in_the_order_glyphs_are_first_drawn(): void {
		$subset = new FontSubset( new TrueType( FontBuilder::font() ) );

		$this->assertFalse( $subset->used() );
		$this->assertSame( '000100020001', $subset->encode( 'BAB' ) );
		$this->assertSame( '00030002', $subset->encode( 'ǺA' ) );
		$this->assertSame( '0000', $subset->encode( 'C' ), 'a character the font lacks is .notdef' );
		$this->assertTrue( $subset->used() );
	}

	/**
	 * Copying text back gives the letters, beyond the BMP as a surrogate
	 * pair — and U+1F600 and B share a glyph, so the first one drawn wins.
	 */
	public function test_the_to_unicode_map_gives_each_code_its_character(): void {
		$subset = new FontSubset( new TrueType( FontBuilder::font() ) );
		$subset->encode( "\u{1F600}A" );
		$subset->encode( 'B C' );

		$cmap = $subset->to_unicode();

		$this->assertStringContainsString( "3 beginbfchar\n<0001> <D83DDE00>\n<0002> <0041>\n<0003> <0020>\nendbfchar", $cmap );
		$this->assertStringNotContainsString( '<0000>', substr( $cmap, (int) strpos( $cmap, 'beginbfchar' ) ) );
		$this->assertStringContainsString( "<0000> <FFFF>\nendcodespacerange", $cmap );
	}

	public function test_the_to_unicode_map_is_written_in_blocks_of_a_hundred(): void {
		$subset = new FontSubset( FontFamily::noto_sans()->regular );
		$subset->encode( implode( '', array_map( 'mb_chr', range( 0x0400, 0x045F ) ) ) . implode( '', array_map( 'mb_chr', range( 0x03B1, 0x03C9 ) ) ) );

		$cmap   = $subset->to_unicode();
		$blocks = array();

		preg_match_all( '/(\d+) beginbfchar\n(.*?)endbfchar/s', $cmap, $found, PREG_SET_ORDER );

		foreach ( $found as [ , $count, $entries ] ) {
			$this->assertSame( (int) $count, substr_count( $entries, "\n" ) );
			$blocks[] = (int) $count;
		}

		$this->assertSame( 100, $blocks[0] );
		$this->assertCount( 2, $blocks );
		$this->assertLessThanOrEqual( 100, $blocks[1] );
	}

	public function test_the_objects_embed_the_subset_with_its_widths(): void {
		$font   = new TrueType( FontBuilder::font() );
		$subset = new FontSubset( $font );
		$subset->encode( 'BÁ' );

		$objects = $subset->objects( 10 );

		$this->assertSame( array( 10, 11, 12, 13, 14 ), array_keys( $objects ) );
		$this->assertMatchesRegularExpression( '/^<<\/Type \/Font \/Subtype \/Type0 \/BaseFont \/[A-Z]{6}\+TestSans-BoldItalic \/Encoding \/Identity-H \/DescendantFonts \[11 0 R\] \/ToUnicode 14 0 R>>$/', $objects[10] );
		$this->assertStringContainsString( '/CIDToGIDMap /Identity', $objects[11] );
		$this->assertStringContainsString( '/W [0 [500 250 250]]', $objects[11], 'notdef, B, Á — what hmtx says, in 1/1000 em' );
		$this->assertStringContainsString( '/Flags 68 ', $objects[12], 'symbolic and italic' );
		$this->assertStringContainsString( '/ItalicAngle -12.0 /Ascent 781 /Descent -195 /CapHeight 684 ', $objects[12] );
		$this->assertStringContainsString( '/FontFile2 13 0 R', $objects[12] );

		[ $file ] = $font->subset( array( 5, 3 ) );

		$this->assertSame( 1, preg_match( '/^<<\/Length (\d+) \/Length1 (\d+) \/Filter \/FlateDecode>>\nstream\n(.*)\nendstream$/s', $objects[13], $stream ) );
		$this->assertSame( strlen( $file ), (int) $stream[2] );
		$this->assertSame( strlen( $stream[3] ), (int) $stream[1] );
		$this->assertSame( $file, gzuncompress( $stream[3] ) );
		$this->assertStringContainsString( '<0002> <00C1>', (string) gzuncompress( substr( $objects[14], (int) strpos( $objects[14], "stream\n" ) + 7, -10 ) ) );
	}

	/**
	 * The tag names the subset: the same glyphs the same tag, every time;
	 * other glyphs another.
	 */
	public function test_the_subset_tag_follows_the_glyphs(): void {
		$font = new TrueType( FontBuilder::font() );
		$tag  = static function ( string $text ) use ( $font ): string {
			$subset = new FontSubset( $font );
			$subset->encode( $text );

			return $subset->base_font();
		};

		$this->assertMatchesRegularExpression( '/^[A-Z]{6}\+TestSans-BoldItalic$/', $tag( 'AB' ) );
		$this->assertSame( $tag( 'AB' ), $tag( 'ABBA' ) );
		$this->assertNotSame( $tag( 'AB' ), $tag( 'BA' ) );
	}

	/* ─── Which face draws what ─────────────────────────────────────── */

	/**
	 * A word is never set in two faces: `Łódź` goes to the fallback whole,
	 * not as a Helvetica `ód` between two Noto letters.
	 */
	public function test_a_word_is_drawn_in_one_face(): void {
		$runs = Reader::runs( ( new Document() )->text( 'Łódź' )->text( 'Bold Ярослав', null, Font::HelveticaBold )->text( 'Plain' )->render( self::CREATED ) )[0];

		$this->assertSame( array( 'E1' ), $runs[0]['fonts'] );
		$this->assertSame( 'Łódź', $runs[0]['text'] );
		$this->assertSame( array( 'F2', 'E2' ), $runs[1]['fonts'] );
		$this->assertSame( 'Bold Ярослав', $runs[1]['text'] );
		$this->assertSame( array( 'F1' ), $runs[2]['fonts'] );
	}

	/**
	 * Windows-1252 has no `ł` or dotless `ı`; the transcoder has stand-ins
	 * for them, but a stand-in is a different letter, so those words go to
	 * the fallback like any other.
	 */
	public function test_letters_with_only_a_stand_in_are_drawn_as_written(): void {
		$pdf = ( new Document() )->text( 'Łała' )->text( 'Kırıkkale' )->text( '−5 – 2026‑11' );

		$this->assertSame( array( 'Łała', 'Kırıkkale', '-5 – 2026-11' ), $pdf->drawn_text(), 'a minus and a hyphen are punctuation: their stand-ins look the same' );
		$this->assertSame( array( 'E1' ), Reader::runs( $pdf->render( self::CREATED ) )[0][1]['fonts'] );
	}

	/**
	 * A long word in the fallback is cut by its Noto widths, but its plain
	 * ASCII head is drawn in Helvetica, which is wider for capitals like E.
	 * Each piece is checked as drawn.
	 */
	public function test_a_cut_piece_fits_in_the_face_it_is_drawn_in(): void {
		$pdf = new Document();

		foreach ( $pdf->lines( str_repeat( 'E', 60 ) . 'ż', 50, 10 ) as $line ) {
			$this->assertLessThanOrEqual( 50.0, $pdf->width( $line, 10 ), $line );
		}

		$this->assertSame( str_repeat( 'E', 60 ) . 'ż', implode( '', $pdf->lines( str_repeat( 'E', 60 ) . 'ż', 50, 10 ) ) );
	}

	/**
	 * A word the fallback cannot draw whole is split by character, each to
	 * the first face that has it, and what nothing has is a `?`.
	 */
	public function test_a_word_nothing_draws_whole_is_split_by_character(): void {
		$pdf  = ( new Document() )->text( 'aЯ東' );
		$runs = Reader::runs( $pdf->render( self::CREATED ) )[0];

		$this->assertSame( array( 'F1', 'E1', 'F1' ), $runs[0]['fonts'] );
		$this->assertSame( 'aЯ?', $runs[0]['text'] );
		$this->assertSame( array( 'aЯ東' ), $pdf->substitutions() );
	}

	public function test_a_document_in_english_embeds_nothing(): void {
		$bytes = ( new Document() )->text( 'Invoice — €5.00, £3, Café, naïve “quoted”' )->render( self::CREATED );

		$this->assertSame( array(), Reader::embedded_fonts( $bytes ) );
		$this->assertLessThan( 2000, strlen( $bytes ) );
	}

	public function test_with_no_fallback_nothing_is_embedded(): void {
		$pdf   = ( new Document() )->fallback( null )->text( 'Ярослав' );
		$bytes = $pdf->render( self::CREATED );

		$this->assertSame( array(), Reader::embedded_fonts( $bytes ) );
		$this->assertSame( array( '???????' ), $pdf->drawn_text() );
	}

	public function test_a_fallback_of_your_own_draws_what_it_has(): void {
		$own  = new FontFamily( new TrueType( FontBuilder::font() ) );
		$pdf  = ( new Document() )->fallback( $own )->text( "\u{1F600}" )->text( 'Ярослав' );
		$runs = Reader::runs( $pdf->render( self::CREATED ) )[0];

		$this->assertSame( "\u{1F600}", $runs[0]['text'] );
		$this->assertSame( array( 'E1' ), $runs[0]['fonts'] );
		$this->assertSame( '???????', $runs[1]['text'] );
	}

	/**
	 * Set in a family, every role is drawn in its face; a role the family
	 * has no face for takes the nearest, and monospaced text stays Courier
	 * when it has no monospaced face.
	 */
	public function test_a_family_draws_every_role(): void {
		$regular = FontFamily::noto_sans()->regular;
		$bold    = FontFamily::noto_sans()->bold;
		$family  = new FontFamily( $regular, $bold );
		$pdf     = ( new Document() )->family( $family );

		$pdf->text( 'Regular' )->text( 'Bold', null, Font::HelveticaBold )->text( 'Italic', null, Font::HelveticaOblique )->text( 'Code', null, Font::Courier );

		$bytes = $pdf->render( self::CREATED );
		$runs  = Reader::runs( $bytes )[0];

		$this->assertSame( array( 'E1' ), $runs[0]['fonts'] );
		$this->assertSame( array( 'E2' ), $runs[1]['fonts'] );
		$this->assertSame( array( 'E1' ), $runs[2]['fonts'], 'no italic face: the regular' );
		$this->assertSame( array( 'F3' ), $runs[3]['fonts'], 'no monospaced face: Courier' );
		$this->assertSame( $family, $pdf->font_family() );
		$this->assertSame( array( 'NotoSans-Regular', 'NotoSans-Bold' ), array_map( static fn( string $name ): string => substr( $name, 7 ), array_keys( Reader::embedded_fonts( $bytes ) ) ) );

		$italic = new TrueType( FontBuilder::font() );

		$this->assertSame( $italic, ( new FontFamily( $regular, italic: $italic ) )->face( Font::HelveticaOblique ) );
		$this->assertSame( $regular, ( new FontFamily( $regular ) )->face( Font::HelveticaBold ) );
		$this->assertSame( $italic, ( new FontFamily( $regular, mono: $italic ) )->face( Font::Courier ) );
	}

	/**
	 * A character the family lacks goes to the base font when Windows-1252
	 * has it, and to the fallback when it does not.
	 */
	public function test_what_a_family_lacks_falls_back(): void {
		$family = new FontFamily( new TrueType( FontBuilder::font() ) );
		$runs   = Reader::runs( ( new Document() )->family( $family )->text( 'AB ABé Я' )->render( self::CREATED ) )[0];

		$this->assertSame( 'AB ABé Я', $runs[0]['text'] );
		$this->assertSame( array( 'E1', 'F1', 'E1', 'E2' ), $runs[0]['fonts'] );
	}

	/**
	 * Right-to-left and shaped scripts are refused even by a font that has
	 * the glyphs: drawn left to right, unshaped, they would be wrong.
	 */
	public function test_scripts_that_need_shaping_are_refused_and_reported(): void {
		$font = new TrueType(
			FontBuilder::font(
				array(
					'groups' => array( array( 0x05D0, 0x05D0, 1 ), array( 0x0627, 0x0627, 1 ), array( 0x0915, 0x0915, 1 ), array( 0x0E01, 0x0E01, 1 ) ),
				)
			)
		);

		$this->assertTrue( $font->covers( 'אاक' ) );

		$pdf = ( new Document() )->family( new FontFamily( $font ) )->fallback( new FontFamily( $font ) );

		foreach ( array( 'א', 'ا', 'क' ) as $text ) {
			$pdf->text( $text );
		}

		$pdf->text( 'ก' );

		$this->assertSame( array( '?', '?', '?', 'ก' ), $pdf->drawn_text() );
		$this->assertSame( array( 'א', 'ا', 'क' ), $pdf->substitutions() );
	}

	/**
	 * Decomposed input — `e` and a combining acute — is composed first,
	 * so it prints as the one letter both fonts have.
	 */
	public function test_decomposed_letters_are_composed(): void {
		$pdf = ( new Document() )->fallback( null )->text( "Cafe\u{0301}" )->text( "Zoe\u{0308}" );

		$this->assertSame( array( 'Café', 'Zoë' ), $pdf->drawn_text() );
		$this->assertSame( array(), $pdf->substitutions() );
	}

	public function test_control_characters_never_reach_an_embedded_font(): void {
		$runs = Reader::runs( ( new Document() )->text( "Яро\x00сл\x07ав\r" )->render( self::CREATED ) )[0];

		$this->assertSame( 'Ярослав', $runs[0]['text'] );
	}

	/* ─── Measuring as drawn ────────────────────────────────────────── */

	/**
	 * What the document measures is what the reader advances: the width
	 * from the PDF's own `/W` and `/Widths` is the width `width()` gave,
	 * so right-aligned text ends on the edge.
	 */
	public function test_right_aligned_text_ends_on_the_edge_by_the_files_own_widths(): void {
		foreach ( array( null, FontFamily::noto_sans() ) as $family ) {
			$pdf   = ( new Document() )->family( $family );
			$texts = array( '€1,234.56', 'Ярослав Łódź', 'Ελένη — £3', 'Plain ASCII', 'Mixed Ковальчук and 東京' );

			foreach ( $texts as $index => $text ) {
				$pdf->text_at( $text, 100, 700 - ( $index * 20 ), 11, Font::HelveticaBold, null, Align::Right, 300 );
			}

			foreach ( Reader::runs( $pdf->render( self::CREATED ) )[0] as $index => $run ) {
				$this->assertEqualsWithDelta( 400.0, $run['x'] + $run['width'], 0.011, $texts[ $index ] );
				$this->assertEqualsWithDelta( $pdf->width( $texts[ $index ], 11, Font::HelveticaBold ), $run['width'], 0.0001, $texts[ $index ] );
			}
		}
	}

	/**
	 * Wrapping adds up words and spaces; with each word in one face and
	 * every space its own, a wrapped line is exactly that sum and never
	 * passes the column — whatever the scripts.
	 */
	public function test_wrapped_mixed_scripts_stay_inside_the_column(): void {
		$words = array( 'Łódź', 'invoice', 'Ярослав', 'Ελένη', '東京', '€12.00', 'naïve', 'Θεσσαλονίκη', 'a', 'Київ—Львів', 'https://example.com/' . str_repeat( 'ż', 30 ) );

		mt_srand( 7 );

		foreach ( array( null, FontFamily::noto_sans() ) as $family ) {
			for ( $round = 0; $round < 12; $round++ ) {
				$text = '';

				for ( $count = 0; $count < 40; $count++ ) {
					$text .= $words[ mt_rand( 0, count( $words ) - 1 ) ] . ' ';
				}

				$width = (float) mt_rand( 60, 220 );
				$pdf   = ( new Document() )->family( $family );

				$pdf->block( $text, 50, $width, 9.5 );

				foreach ( Reader::runs( $pdf->render( self::CREATED ) ) as $runs ) {
					foreach ( $runs as $run ) {
						$this->assertLessThanOrEqual( 50 + $width + 0.01, $run['x'] + $run['width'], $run['text'] );
					}
				}
			}
		}
	}

	public function test_fit_shortens_mixed_text_to_what_fits(): void {
		$pdf = new Document();

		foreach ( array( 'Ярослав Ковальчук-Шевченко', 'Łódź Łódź Łódź Łódź', 'Ελληνικά δείγματα' ) as $text ) {
			$fitted = $pdf->fit( $text, 60, 10 );

			$this->assertStringEndsWith( '...', $fitted );
			$this->assertLessThanOrEqual( 60.0, $pdf->width( $fitted, 10 ), $text );
		}
	}

	/**
	 * In a family, a line box is the family's cap height plus the headroom
	 * Helvetica has above its capitals, so a layout keeps its rhythm.
	 */
	public function test_line_metrics_come_from_the_face_that_draws(): void {
		$plain  = new Document();
		$family = ( new Document() )->family( FontFamily::noto_sans() );

		$this->assertSame( Font::Helvetica->ascender( 10 ), $plain->ascender( 10 ) );
		$this->assertSame( Font::HelveticaBold->cap_height( 8 ), $plain->cap_height( 8, Font::HelveticaBold ) );
		$this->assertSame( Font::Helvetica->descender( 10 ), $plain->descender( 10 ) );
		$this->assertEqualsWithDelta( 10 * ( 714 + 63 ) / 1000, $family->ascender( 10 ), 0.0001 );
		$this->assertEqualsWithDelta( 8 * 0.714, $family->cap_height( 8 ), 0.0001 );
		$this->assertEqualsWithDelta( 10 * 0.293, $family->descender( 10 ), 0.0001 );
		$this->assertEqualsWithDelta( ( 20 + ( 9.5 * 0.714 ) ) / 2, $family->baseline_in_band( 20, 9.5 ), 0.0001 );
		$this->assertSame( $plain->ascender(), $plain->ascender( $plain->font_size ) );
	}

	/* ─── The file ──────────────────────────────────────────────────── */

	/**
	 * Asking what was drawn draws the footer to read it, and must not
	 * leave its glyphs or fonts behind in the file.
	 */
	public function test_asking_for_the_drawn_text_does_not_change_the_file(): void {
		$make = static function (): Document {
			$pdf = ( new Document() )->text( 'Body' );
			$pdf->on_each_page( static fn( Document $doc, int $page, int $total ) => $doc->text_at( "Сторінка {$page} з {$total}", 0, 20, 8, Font::HelveticaBold ) );

			return $pdf;
		};

		$asked = $make();

		$this->assertContains( 'Сторінка 1 з 1', $asked->drawn_text() );
		$this->assertSame( $make()->render( self::CREATED ), $asked->render( self::CREATED ) );
		$this->assertSame( $asked->render( self::CREATED ), $asked->render( self::CREATED ) );
	}

	public function test_qpdf_and_pdffonts_accept_a_document_set_in_the_family(): void {
		$bytes = ( new Document() )->family( FontFamily::noto_sans() )
			->text( 'Ярослав Łódź Ελένη €' )
			->text( 'Heading', 14, Font::HelveticaBold )
			->text( 'Code 123', null, Font::Courier )
			->render( self::CREATED );

		[ $code, $output ] = Reader::run( Reader::tool( 'qpdf' ), array( '--check' ), $bytes );

		$this->assertSame( 0, $code, $output );

		[ , $fonts ] = Reader::run( Reader::tool( 'pdffonts' ), array(), $bytes );

		$this->assertMatchesRegularExpression( '/[A-Z]{6}\+NotoSans-Regular\s+CID TrueType\s+Identity-H\s+yes yes yes/', $fonts );
		$this->assertMatchesRegularExpression( '/[A-Z]{6}\+NotoSans-Bold\s+CID TrueType\s+Identity-H\s+yes yes yes/', $fonts );
		$this->assertMatchesRegularExpression( '/Courier\s+Type 1\s+WinAnsi\s+no\s+no\s+no/', $fonts );

		[ , $text ] = Reader::run( Reader::tool( 'pdftotext' ), array( '-enc', 'UTF-8' ), $bytes, array( '-' ) );

		$this->assertStringContainsString( 'Ярослав Łódź Ελένη €', $text );
	}

	/**
	 * Each embedded subset holds the glyphs drawn and what they are built
	 * from, and nothing else: a page of Cyrillic is a few kilobytes, not
	 * the 290 KB face.
	 */
	public function test_the_subset_is_only_what_was_drawn(): void {
		$bytes = ( new Document() )->text( 'Ярослав' )->render( self::CREATED );
		$fonts = Reader::embedded_fonts( $bytes );

		$this->assertCount( 1, $fonts );

		$file   = (string) reset( $fonts );
		$tables = FontBuilder::tables( $file );

		// .notdef and the six distinct letters: Я р о с л а в.
		$this->assertSame( 8, unpack( 'n', $tables['maxp']['bytes'], 4 )[1] );
		$this->assertSame( 0xB1B0AFBA, FontBuilder::checksum( $file ) );
		$this->assertLessThan( 5000, strlen( $bytes ) );
	}

	/**
	 * Hex text has nothing to escape and nothing to break out of; the
	 * literal pieces either side are escaped as ever.
	 */
	public function test_embedded_text_cannot_escape_its_string(): void {
		$stream = Reader::streams( ( new Document() )->text( 'Ярослав) Tj (x> Tj <' )->render( self::CREATED ) )[0];

		$this->assertSame( 1, preg_match_all( '/<([0-9A-F]+)> Tj/', $stream ) );
		$this->assertStringContainsString( '( Tj \\(x> Tj <) Tj', $stream );
	}

	public function test_a_family_of_files_that_cannot_be_embedded_says_which(): void {
		$path = dirname( __DIR__ ) . '/tests/.tmp/variable.ttf';

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, FontBuilder::font( array( 'extra' => array( 'fvar' => str_repeat( "\0", 16 ) ) ) ) );

		try {
			FontFamily::files( dirname( __DIR__ ) . '/fonts/NotoSans-Regular.ttf', $path );
			$this->fail( 'A variable font was accepted.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertStringContainsString( 'variable.ttf is a variable font', $error->getMessage() );
		} finally {
			unlink( $path );
		}
	}
}
