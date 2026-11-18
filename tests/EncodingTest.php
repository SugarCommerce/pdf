<?php
/**
 * Text reaching the page in the bytes its fonts know.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;

/**
 * The page fonts use WinAnsi (Windows-1252). Every character it has must
 * come out as its byte, whatever iconv the server has, and a character it
 * lacks must never take the rest of the string down with it.
 */
final class EncodingTest extends TestCase {

	/** "Café £5 €5 Zoë" in Windows-1252. */
	private const CAFE = "Caf\xE9 \xA35 \x805 Zo\xEB";

	public function test_currency_signs_and_accents_are_their_winansi_bytes(): void {
		$this->assertSame( bin2hex( self::CAFE ), bin2hex( Document::escape( 'Café £5 €5 Zoë' ) ) );
	}

	/**
	 * A customer whose name is partly in another script: the Latin part
	 * still prints. iconv gave up on the whole string here, so the £, € and
	 * accents all became `?`.
	 */
	public function test_one_foreign_character_does_not_spoil_the_rest(): void {
		$this->assertSame( bin2hex( self::CAFE . ' ?????' ), bin2hex( Document::escape( 'Café £5 €5 Zoë Ελένη' ) ) );
		$this->assertSame( bin2hex( self::CAFE . ' ?' ), bin2hex( Document::escape( 'Café £5 €5 Zoë 😀' ) ) );
	}

	/**
	 * Every character Windows-1252 adds to Latin-1 is its own byte.
	 */
	public function test_the_whole_windows_1252_range_maps(): void {
		$this->assert_whole_range();
	}

	/**
	 * Each byte from 0x80 up, from its UTF-8 character.
	 */
	private function assert_whole_range(): void {
		for ( $byte = 0x80; $byte <= 0xFF; $byte++ ) {
			$utf8 = mb_convert_encoding( chr( $byte ), 'UTF-8', 'Windows-1252' );

			// The five bytes 1252 leaves undefined.
			if ( in_array( $byte, array( 0x81, 0x8D, 0x8F, 0x90, 0x9D ), true ) ) {
				continue;
			}

			$this->assertSame( bin2hex( chr( $byte ) ), bin2hex( Document::escape( $utf8 ) ), 'U+' . dechex( mb_ord( $utf8, 'UTF-8' ) ) );
		}
	}

	/**
	 * Letters Windows-1252 lacks lose their accent rather than become `?`.
	 *
	 * @param string $text     As typed.
	 * @param string $expected In Windows-1252.
	 */
	#[DataProvider( 'centralEuropeanProvider' )]
	public function test_central_european_names_keep_their_letters( string $text, string $expected ): void {
		$this->assertSame( bin2hex( $expected ), bin2hex( Document::escape( $text ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function centralEuropeanProvider(): array {
		return array(
			'Polish' => array( 'Łódź Wąchock', "L\xF3dz Wachock" ),
			'Czech'  => array( 'Dvořák Šťastný', "Dvor\xE1k \x8Atastn\xFD" ),
			'Turkish' => array( 'Kırıkkale', 'Kirikkale' ),
		);
	}

	/**
	 * A server whose iconv has no `//TRANSLIT` (musl, Alpine) answers false
	 * for any non-ASCII text, which turned every €, £ and é into `?`.
	 */
	#[RunInSeparateProcess]
	public function test_an_iconv_without_transliteration_changes_nothing(): void {
		require_once __DIR__ . '/Support/IconvWithoutTranslit.php';

		$this->assertFalse( \SugarCommerce\Pdf\iconv( 'UTF-8', 'Windows-1252//TRANSLIT', 'é' ) );
		$this->assertSame( bin2hex( self::CAFE ), bin2hex( Document::escape( 'Café £5 €5 Zoë' ) ) );
		$this->assert_whole_range();

		foreach ( self::centralEuropeanProvider() as list( $text, $expected ) ) {
			$this->assertSame( bin2hex( $expected ), bin2hex( Document::escape( $text ) ), $text );
		}
	}

	/**
	 * Bytes that are not UTF-8 cannot be read as characters, so they
	 * degrade to `?` rather than becoming whatever they happen to be in
	 * Windows-1252.
	 */
	public function test_text_that_is_not_utf8_degrades_to_ascii(): void {
		$this->assertSame( 'Caf? ?5', Document::escape( "Caf\xE9 \xA35" ) );
	}

	public function test_a_rendered_page_carries_the_bytes(): void {
		$rendered = ( new Document() )->text( 'Café £5 €5 Zoë' )->render();

		$this->assertStringContainsString( '(' . self::CAFE . ') Tj', $rendered );
	}
}
