<?php
/**
 * The TrueType reader and subsetter, against fonts made to order and the
 * bundled Noto Sans.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\FontFamily;
use SugarCommerce\Pdf\Fonts\TrueType;
use SugarCommerce\Pdf\Tests\Support\FontBuilder;

final class TrueTypeTest extends TestCase {

	/* ─── Reading ───────────────────────────────────────────────────── */

	public function test_the_numbers_a_layout_needs_are_read_and_scaled_to_the_em(): void {
		$font = new TrueType( FontBuilder::font() );

		$this->assertSame( 'TestSans-BoldItalic', $font->name, 'the PostScript name, cleaned for a PDF name' );
		$this->assertSame( 'Test Sans', $font->family );
		$this->assertSame( 2048, $font->units_per_em );
		$this->assertSame( 7, $font->glyph_count );

		// OS/2's typographic values win over hhea's 1900 / -500.
		$this->assertSame( 1600 * 1000 / 2048, $font->ascender );
		$this->assertSame( -400 * 1000 / 2048, $font->descender );
		$this->assertSame( 1400 * 1000 / 2048, $font->cap_height );
		$this->assertSame( 1000 * 1000 / 2048, $font->x_height );
		$this->assertSame( array( -100 * 1000 / 2048, -400 * 1000 / 2048, 2000 * 1000 / 2048, 2100 * 1000 / 2048 ), $font->bbox );
		$this->assertSame( -12.0, $font->italic_angle );
		$this->assertSame( 700, $font->weight );
		$this->assertTrue( $font->bold );
		$this->assertTrue( $font->italic );
		$this->assertFalse( $font->fixed_pitch );
	}

	/**
	 * Without OS/2, hhea's values; and estimates for what only OS/2 has.
	 */
	public function test_a_font_without_os2_falls_back_to_hhea(): void {
		$font = new TrueType( FontBuilder::font( array( 'drop' => array( 'OS/2' ) ) ) );

		$this->assertSame( 1900 * 1000 / 2048, $font->ascender );
		$this->assertSame( -500 * 1000 / 2048, $font->descender );
		$this->assertEqualsWithDelta( $font->ascender * 0.92, $font->cap_height, 0.0001 );
		$this->assertSame( 400, $font->weight );
		$this->assertTrue( $font->bold, 'from macStyle' );
		$this->assertFalse( $font->italic );
	}

	public function test_advances_follow_hmtx_and_share_the_last_after_the_full_entries(): void {
		$font = new TrueType( FontBuilder::font() );

		// 1024, 1366, 512 at 2048 units: 500, 666.99 → 667, 250.
		$this->assertSame( array( 500, 667, 250, 250, 250, 250, 250 ), array_map( $font->advance( ... ), range( 0, 6 ) ) );
		$this->assertSame( 0, $font->advance( 99 ), 'no such glyph' );
		$this->assertEqualsWithDelta( ( 667 + 250 ) / 1000 * 12, $font->width_of( 'AB', 12 ), 0.0001 );
	}

	/* ─── Character maps ────────────────────────────────────────────── */

	/**
	 * Every way format 4 reaches a glyph: a delta, a delta that wraps past
	 * 0xFFFF, and an offset into the glyph array.
	 *
	 * @param string $map Which subtables the font has.
	 */
	#[DataProvider( 'maps' )]
	public function test_characters_reach_their_glyphs( string $map ): void {
		$font = new TrueType( FontBuilder::font( array( 'cmap' => $map ) ) );

		$this->assertSame(
			array( 0x20 => 6, 0x41 => 1, 0x42 => 5, 0xB4 => 2, 0xC1 => 3, 0x01FA => 4 ),
			array_combine( array( 0x20, 0x41, 0x42, 0xB4, 0xC1, 0x01FA ), array_map( $font->glyph( ... ), array( 0x20, 0x41, 0x42, 0xB4, 0xC1, 0x01FA ) ) )
		);

		foreach ( array( 0x1F, 0x40, 0x43, 0xB3, 0xB5, 0xC0, 0xC2, 0x01F9, 0x01FB, 0xFFFF, 0x110000, -1 ) as $missing ) {
			$this->assertSame( 0, $font->glyph( $missing ), sprintf( 'U+%04X', $missing ) );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function maps(): array {
		return array(
			'format 4'  => array( '4' ),
			'format 12' => array( '12' ),
			'both'      => array( 'both' ),
		);
	}

	/**
	 * Segments out of order are a fault in the font, not a reason to find
	 * the wrong glyph.
	 *
	 * @param string $map Which subtables the font has.
	 */
	#[DataProvider( 'maps' )]
	public function test_unsorted_segments_still_reach_their_glyphs( string $map ): void {
		$font = new TrueType( FontBuilder::font( array( 'cmap' => $map, 'unsorted' => true ) ) );

		foreach ( array( 0x20 => 6, 0x41 => 1, 0x42 => 5, 0xB4 => 2, 0xC1 => 3, 0x01FA => 4, 0x43 => 0 ) as $code => $glyph ) {
			$this->assertSame( $glyph, $font->glyph( $code ), sprintf( 'U+%04X', $code ) );
		}
	}

	/**
	 * Beyond the Basic Multilingual Plane only format 12 reaches, so a font
	 * with both must be read through it.
	 */
	public function test_format_12_is_preferred_and_reaches_beyond_the_bmp(): void {
		$this->assertSame( 5, ( new TrueType( FontBuilder::font( array( 'cmap' => 'both' ) ) ) )->glyph( 0x1F600 ) );
		$this->assertSame( 5, ( new TrueType( FontBuilder::font( array( 'cmap' => '12' ) ) ) )->glyph( 0x1F600 ) );
		$this->assertSame( 0, ( new TrueType( FontBuilder::font( array( 'cmap' => '4' ) ) ) )->glyph( 0x1F600 ) );
	}

	public function test_covers_means_every_character(): void {
		$font = new TrueType( FontBuilder::font() );

		$this->assertTrue( $font->covers( 'AB ÁǺ' ) );
		$this->assertFalse( $font->covers( 'ABC' ) );
		$this->assertTrue( $font->covers( '' ) );
	}

	/* ─── Composite glyphs ──────────────────────────────────────────── */

	public function test_composite_glyphs_name_their_components_whatever_their_flags(): void {
		$font = new TrueType( FontBuilder::font() );

		$this->assertFalse( $font->is_composite( 1 ) );
		$this->assertTrue( $font->is_composite( 3 ) );
		$this->assertSame( array( 1, 2 ), $font->components( 3 ), 'word arguments, a scale' );
		$this->assertSame( array( 3, 2 ), $font->components( 4 ), 'byte arguments, an x/y scale, instructions' );
		$this->assertSame( array(), $font->components( 1 ) );
		$this->assertSame( array(), $font->components( 6 ), 'no outline at all' );
		$this->assertSame( array(), $font->components( 99 ) );
	}

	/**
	 * A transform is two, four or eight bytes; skipping the wrong number
	 * reads the next component's glyph from the middle of this one's.
	 *
	 * @param int    $flag      The transform flag.
	 * @param string $transform Its bytes.
	 */
	#[DataProvider( 'transforms' )]
	public function test_a_transform_is_skipped_whole_before_the_next_component( int $flag, string $transform ): void {
		$glyphs    = FontBuilder::glyphs();
		$glyphs[4] = pack( 'nnnnn', 0xFFFF, 0, 0, 10, 10 )
			. pack( 'nn', 0x0002 | $flag | 0x0020, 1 ) . pack( 'cc', 0, 0 ) . $transform
			. pack( 'nn', 0x0002, 5 ) . pack( 'cc', 0, 0 );

		$font = new TrueType( self::with_glyphs( $glyphs ) );

		$this->assertSame( array( 1, 5 ), $font->components( 4 ) );
		$this->assertSame( array( 0 => 0, 4 => 1, 1 => 2, 5 => 3 ), $font->subset( array( 4 ) )[1] );
	}

	/** @return array<string, array{0: int, 1: string}> */
	public static function transforms(): array {
		return array(
			'scale'      => array( 0x0008, pack( 'n', 0x4000 ) ),
			'x/y scale'  => array( 0x0040, pack( 'nn', 0x0003, 0x4000 ) ),
			'two by two' => array( 0x0080, pack( 'nnnn', 0x4000, 0x0001, 0x0003, 0x4000 ) ),
		);
	}

	/* ─── Subsetting ────────────────────────────────────────────────── */

	/**
	 * `.notdef` first, then what was asked for in the order asked, then
	 * every component, breadth first — the components of components too.
	 */
	public function test_a_subset_keeps_notdef_the_order_and_every_component(): void {
		$font = new TrueType( FontBuilder::font() );

		[ , $mapping ] = $font->subset( array( 5, 4, 5, 99, -1 ) );

		$this->assertSame( array( 0 => 0, 5 => 1, 4 => 2, 3 => 3, 2 => 4, 1 => 5 ), $mapping );
	}

	public function test_a_subset_is_a_well_formed_font_of_only_those_glyphs(): void {
		$font  = new TrueType( FontBuilder::font() );
		$bytes = $font->subset( array( 4 ) )[0];

		$tables = FontBuilder::tables( $bytes );

		$this->assertSame( array( 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp' ), array_keys( $tables ), 'the tables a reader draws from, sorted' );
		$this->assertSame( "\x00\x01\x00\x00", substr( $bytes, 0, 4 ) );
		$this->assertSame( array( 6, 64, 2, 32 ), array_values( unpack( 'nnum/nrange/nselector/nshift', $bytes, 4 ) ) );

		foreach ( $tables as $tag => $table ) {
			$this->assertSame( 0, $table['offset'] % 4, $tag . ' is 4-byte aligned' );
			$this->assertSame( FontBuilder::checksum( 'head' === $tag ? substr_replace( $table['bytes'], "\0\0\0\0", 8, 4 ) : $table['bytes'] ), $table['checksum'], $tag . ' checksum' );
		}

		// The whole file sums to the magic number once the adjustment is in.
		$this->assertSame( 0xB1B0AFBA, FontBuilder::checksum( $bytes ) );

		// Five glyphs: .notdef, Ǻ, Á, acute, A.
		$this->assertSame( 5, unpack( 'n', $tables['maxp']['bytes'], 4 )[1] );
		$this->assertSame( 5, unpack( 'n', $tables['hhea']['bytes'], 34 )[1] );
		$this->assertSame( 1, unpack( 'n', $tables['head']['bytes'], 50 )[1], 'long loca' );

		$loca     = array_values( unpack( 'N6', $tables['loca']['bytes'] ) );
		$original = FontBuilder::glyphs();

		$this->assertSame( 0, $loca[0] );
		$this->assertSame( strlen( $tables['glyf']['bytes'] ), $loca[5] );

		foreach ( array( 0 => 0, 1 => 4, 2 => 3, 3 => 2, 4 => 1 ) as $new => $old ) {
			$outline = substr( $tables['glyf']['bytes'], $loca[ $new ], $loca[ $new + 1 ] - $loca[ $new ] );

			$this->assertSame( 0, $loca[ $new ] % 4, 'glyph ' . $new . ' aligned' );
			$this->assertSame( rtrim( self::renumbered( $original[ $old ] ), "\0" ), rtrim( $outline, "\0" ), 'glyph ' . $new );
		}

		// Advances and bearings travel with their glyphs, negative bearing included.
		$this->assertSame(
			array( 1024, 50, 512, 20, 512, 20, 512, -30 & 0xFFFF, 1366, 20 ),
			array_values( unpack( 'n10', $tables['hmtx']['bytes'] ) )
		);
	}

	/**
	 * Components point at the new numbers, or the accent lands on the
	 * wrong letter.
	 */
	public function test_components_are_renumbered_into_the_subset(): void {
		$font  = new TrueType( FontBuilder::font() );
		$glyf  = FontBuilder::tables( $font->subset( array( 4 ) )[0] );
		$loca  = array_values( unpack( 'N6', $glyf['loca']['bytes'] ) );
		$bytes = $glyf['glyf']['bytes'];

		// Ǻ (now 1) is Á (now 2) and the acute (now 3); Á is A (now 4) and the acute.
		$this->assertSame( 2, unpack( 'n', $bytes, $loca[1] + 12 )[1] );
		$this->assertSame( 3, unpack( 'n', $bytes, $loca[1] + 18 )[1] );
		$this->assertSame( 4, unpack( 'n', $bytes, $loca[2] + 12 )[1] );
		$this->assertSame( 3, unpack( 'n', $bytes, $loca[2] + 20 )[1] );
	}

	public function test_a_short_loca_font_subsets_the_same(): void {
		$short = new TrueType( FontBuilder::font() );
		$long  = new TrueType( FontBuilder::font( array( 'long_loca' => true ) ) );

		$this->assertSame( $long->components( 4 ), $short->components( 4 ) );
		$this->assertSame( $long->subset( array( 4, 5 ) ), $short->subset( array( 4, 5 ) ) );
	}

	public function test_hinting_programs_are_kept(): void {
		$font   = new TrueType( FontBuilder::font( array( 'extra' => array( 'fpgm' => "\xB0\x00", 'prep' => "\xB0\x01", 'cvt ' => "\x00\x10" ) ) ) );
		$tables = FontBuilder::tables( $font->subset( array( 1 ) )[0] );

		$this->assertSame( array( 'cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep' ), array_keys( $tables ), 'sorted by tag' );
		$this->assertSame( "\xB0\x00", $tables['fpgm']['bytes'] );
		$this->assertSame( "\xB0\x01", $tables['prep']['bytes'] );
		$this->assertSame( "\x00\x10", $tables['cvt ']['bytes'] );
	}

	public function test_trimming_tables_leaves_a_font_that_reads_the_same(): void {
		$font    = new TrueType( FontBuilder::font( array( 'extra' => array( 'GPOS' => str_repeat( "\x01", 99 ) ) ) ) );
		$trimmed = new TrueType( $font->without_tables( array( 'OS/2', 'cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'name', 'post' ) ) );

		$this->assertNotContains( 'GPOS', $trimmed->tags() );
		$this->assertSame( $font->subset( array( 4 ) ), $trimmed->subset( array( 4 ) ) );
		$this->assertSame( 0xB1B0AFBA, FontBuilder::checksum( $font->without_tables( array( 'cmap', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp' ) ) ) );
	}

	/* ─── The bundled family ────────────────────────────────────────── */

	public function test_noto_sans_covers_latin_greek_and_cyrillic(): void {
		$family = FontFamily::noto_sans();

		$this->assertSame( 'NotoSans-Regular', $family->regular->name );
		$this->assertSame( 'NotoSans-Bold', $family->bold?->name );
		$this->assertSame( 714.0, $family->regular->cap_height );

		foreach ( array( 'Łódź Wąchock', 'Dvořák Šťastný', 'Kırıkkale', 'Ελένη Θεσσαλονίκη', 'Ярослав Київ', '€£¥₴₽', '«»„“—–' ) as $text ) {
			$this->assertTrue( $family->regular->covers( $text ), $text );
			$this->assertTrue( $family->bold?->covers( $text ), $text );
		}

		$this->assertFalse( $family->regular->covers( '東京' ) );
	}

	/**
	 * In Noto Sans `é` is built from `e` and an acute: subsetting `é` alone
	 * must bring both.
	 */
	public function test_a_real_accented_letter_brings_its_parts(): void {
		$font  = FontFamily::noto_sans()->regular;
		$glyph = $font->glyph( 0xE9 );
		$parts = $font->components( $glyph );

		$this->assertTrue( $font->is_composite( $glyph ) );
		$this->assertContains( $font->glyph( 0x65 ), $parts );

		[ $bytes, $mapping ] = $font->subset( array( $glyph ) );

		$this->assertSame( array_merge( array( 0, $glyph ), $parts ), array_keys( $mapping ) );
		$this->assertSame( 0xB1B0AFBA, FontBuilder::checksum( $bytes ) );
		$this->assertLessThan( 2000, strlen( $bytes ) );
	}

	/* ─── Refusals ──────────────────────────────────────────────────── */

	/**
	 * Each with a reason somebody can act on.
	 *
	 * @param string $bytes  The file.
	 * @param string $reason Part of the message.
	 */
	#[DataProvider( 'refusals' )]
	public function test_fonts_that_cannot_be_embedded_are_refused_with_a_reason( string $bytes, string $reason ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $reason );

		new TrueType( $bytes, 'Broken.ttf' );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function refusals(): array {
		$font = FontBuilder::font();

		return array(
			'empty'               => array( '', 'not a font file' ),
			'not a font'          => array( str_repeat( 'x', 64 ), 'not a TrueType font' ),
			'CFF outlines'        => array( FontBuilder::font( array( 'tag' => 'OTTO' ) ), 'PostScript (CFF) outlines' ),
			'collection'          => array( 'ttcf' . substr( $font, 4 ), 'font collection (.ttc)' ),
			'WOFF'                => array( 'wOFF' . substr( $font, 4 ), 'web font (WOFF)' ),
			'WOFF2'               => array( 'wOF2' . substr( $font, 4 ), 'web font (WOFF)' ),
			'variable'            => array( FontBuilder::font( array( 'extra' => array( 'fvar' => str_repeat( "\0", 16 ) ) ) ), 'is a variable font' ),
			'restricted licence'  => array( FontBuilder::font( array( 'fs_type' => 0x0002 ) ), 'does not allow it to be embedded' ),
			'bitmap only'         => array( FontBuilder::font( array( 'fs_type' => 0x0200 ) ), 'does not allow it to be embedded' ),
			'no subsetting'       => array( FontBuilder::font( array( 'fs_type' => 0x0100 ) ), 'does not allow it to be subset' ),
			'no glyf'             => array( FontBuilder::font( array( 'drop' => array( 'glyf' ) ) ), 'has no glyf table' ),
			'no cmap'             => array( FontBuilder::font( array( 'drop' => array( 'cmap' ) ) ), 'has no cmap table' ),
			'not a Unicode cmap'  => array( FontBuilder::font( array( 'cmap' => 'mac' ) ), 'no Unicode character map' ),
			'units per em'        => array( FontBuilder::font( array( 'upem' => 0 ) ), 'units per em' ),
			'directory past end'  => array( substr( $font, 0, 40 ), 'table directory runs past the end' ),
			'table past end'      => array( substr( $font, 0, strlen( $font ) - 40 ), 'runs past the end of the file' ),
			'loca short'          => array( FontBuilder::font( array( 'extra' => array( 'loca' => "\0\0" ) ) ), 'loca table has fewer entries' ),
		);
	}

	/**
	 * Editable and preview-and-print embedding are permissions to embed.
	 */
	public function test_permissive_licence_bits_are_accepted(): void {
		foreach ( array( 0x0000, 0x0004, 0x0008 ) as $bits ) {
			$this->assertSame( 7, ( new TrueType( FontBuilder::font( array( 'fs_type' => $bits ) ) ) )->glyph_count );
		}
	}

	public function test_a_missing_file_is_refused_with_its_path(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '/no/such/font.ttf' );

		TrueType::file( '/no/such/font.ttf' );
	}

	/**
	 * A damaged font fails with a reason or reads as a font; it never
	 * warns, reads past its end, or loops. Every truncation and a sweep of
	 * corrupted bytes through the tables.
	 */
	public function test_a_damaged_font_never_does_worse_than_refuse(): void {
		$font = FontBuilder::font();
		$seen = 0;

		$damaged = array();

		for ( $length = 0; $length < strlen( $font ); $length += 7 ) {
			$damaged[] = substr( $font, 0, $length );
		}

		for ( $offset = 12; $offset < strlen( $font ); $offset += 3 ) {
			foreach ( array( "\xFF", "\x00", "\x80" ) as $byte ) {
				$damaged[] = substr_replace( $font, $byte, $offset, 1 );
			}
		}

		foreach ( $damaged as $bytes ) {
			try {
				$parsed = new TrueType( $bytes );
			} catch ( \InvalidArgumentException ) {
				continue;
			}

			++$seen;

			foreach ( array( 0x20, 0x41, 0xC1, 0x01FA, 0x1F600 ) as $code ) {
				$this->assertLessThan( $parsed->glyph_count, $parsed->glyph( $code ) );
			}

			$this->assertIsString( $parsed->subset( range( 0, $parsed->glyph_count ) )[0] );
		}

		$this->assertGreaterThan( 50, $seen, 'most single-byte damage still reads' );
	}

	/**
	 * A composite that names itself — a loop in a hostile font — is
	 * subset once, not forever.
	 */
	public function test_a_composite_that_contains_itself_terminates(): void {
		$glyphs    = FontBuilder::glyphs();
		$glyphs[3] = pack( 'nnnnn', 0xFFFF, 0, 0, 1, 1 ) . pack( 'nn', 0x0002 | 0x0020, 4 ) . pack( 'cc', 0, 0 ) . pack( 'nn', 0x0002, 3 ) . pack( 'cc', 0, 0 );
		$glyphs[4] = pack( 'nnnnn', 0xFFFF, 0, 0, 1, 1 ) . pack( 'nn', 0x0002, 3 ) . pack( 'cc', 0, 0 );

		[ , $mapping ] = ( new TrueType( self::with_glyphs( $glyphs ) ) )->subset( array( 3 ) );

		$this->assertSame( array( 0 => 0, 3 => 1, 4 => 2 ), $mapping );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * The default font with its outlines replaced.
	 *
	 * @param list<string> $glyphs Outlines, even lengths.
	 *
	 * @return string
	 */
	private static function with_glyphs( array $glyphs ): string {
		$tables = array();

		foreach ( FontBuilder::tables( FontBuilder::font( array( 'long_loca' => true ) ) ) as $tag => $table ) {
			$tables[ $tag ] = $table['bytes'];
		}

		$tables['glyf'] = '';
		$loca           = array( 0 );

		foreach ( $glyphs as $glyph ) {
			$tables['glyf'] .= $glyph . ( strlen( $glyph ) % 2 ? "\0" : '' );
			$loca[]          = strlen( $tables['glyf'] );
		}

		$tables['loca'] = pack( 'N*', ...$loca );

		return FontBuilder::assemble( $tables );
	}

	/**
	 * An original outline with its component IDs as the subset of glyph 4
	 * numbers them.
	 *
	 * @param string $outline Original bytes.
	 *
	 * @return string
	 */
	private static function renumbered( string $outline ): string {
		$map = array( 0 => 0, 4 => 1, 3 => 2, 2 => 3, 1 => 4 );

		if ( $outline === FontBuilder::glyphs()[3] ) {
			return substr_replace( substr_replace( $outline, pack( 'n', $map[1] ), 12, 2 ), pack( 'n', $map[2] ), 20, 2 );
		}

		if ( $outline === FontBuilder::glyphs()[4] ) {
			return substr_replace( substr_replace( $outline, pack( 'n', $map[3] ), 12, 2 ), pack( 'n', $map[2] ), 18, 2 );
		}

		return $outline;
	}
}
