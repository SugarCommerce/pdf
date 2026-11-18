<?php
/**
 * The WordPress hookups: the site's language, __(), and sending a download.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Download;
use SugarCommerce\Pdf\Templates\Language;
use SugarCommerce\Pdf\Templates\Translations;

final class WordPressTest extends TestCase {

	protected function setUp(): void {
		pdf_reset_wp();
	}

	protected function tearDown(): void {
		pdf_reset_wp();
	}

	/* ─── The site's language ───────────────────────────────────────── */

	public function test_site_language_follows_the_locale(): void {
		$GLOBALS['pdf_wp']['locale'] = 'de_DE';
		$this->assertSame( Language::German, Language::site() );

		$GLOBALS['pdf_wp']['locale'] = 'nn_NO';
		$this->assertSame( Language::Norwegian, Language::site() );
	}

	public function test_site_language_is_english_for_a_locale_with_no_case(): void {
		$GLOBALS['pdf_wp']['locale'] = 'pl_PL';
		$this->assertSame( Language::English, Language::site() );

		$GLOBALS['pdf_wp']['locale'] = '';
		$this->assertSame( Language::English, Language::site() );
	}

	/* ─── __() ──────────────────────────────────────────────────────── */

	public function test_wordpress_translations_use_the_plugins_own_wording(): void {
		$GLOBALS['pdf_wp']['translations']['my-plugin'] = array( 'Invoice' => 'Bill' );

		$translator = Translations::wordpress( 'my-plugin', Language::German );

		$this->assertSame( 'Bill', $translator->translate( 'invoice', 'Invoice' ) );
	}

	public function test_an_untranslated_phrase_falls_through_to_the_built_in_language(): void {
		$translator = Translations::wordpress( 'my-plugin', Language::German );

		$this->assertSame( Language::German->get( 'invoice' ), $translator->translate( 'invoice', 'Invoice' ) );
		$this->assertNotSame( 'Invoice', $translator->translate( 'invoice', 'Invoice' ) );
	}

	public function test_another_domains_translations_are_not_used(): void {
		$GLOBALS['pdf_wp']['translations']['someone-else'] = array( 'Invoice' => 'Bill' );

		$this->assertSame( Language::English->get( 'invoice' ), Translations::wordpress( 'my-plugin', Language::English )->translate( 'invoice', 'Invoice' ) );
	}

	public function test_the_fallback_defaults_to_the_sites_language(): void {
		$GLOBALS['pdf_wp']['locale'] = 'fr_FR';

		$this->assertSame( Language::French->get( 'invoice' ), Translations::wordpress( 'my-plugin' )->translate( 'invoice', 'Invoice' ) );
	}

	/* ─── Download headers ──────────────────────────────────────────── */

	public function test_headers_describe_an_uncached_pdf_attachment(): void {
		$headers = Download::headers( 'INV-0042.pdf', 1234 );

		$this->assertSame( 'application/pdf', $headers['Content-Type'] );
		$this->assertSame( '1234', $headers['Content-Length'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertSame( 'attachment; filename="INV-0042.pdf"; filename*=UTF-8\'\'INV-0042.pdf', $headers['Content-Disposition'] );
	}

	public function test_inline_opens_in_the_viewer(): void {
		$this->assertStringStartsWith( 'inline;', Download::headers( 'a', 1, true )['Content-Disposition'] );
	}

	public function test_a_negative_length_is_never_sent(): void {
		$this->assertSame( '0', Download::headers( 'a', -5 )['Content-Length'] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function names(): array {
		return array(
			'plain'               => array( 'invoice', 'invoice.pdf', 'invoice.pdf' ),
			'extension replaced'  => array( 'receipt.PDF', 'receipt.pdf', 'receipt.pdf' ),
			'accents'             => array( 'Rechnung Müller', 'Rechnung-Muller.pdf', 'Rechnung Müller.pdf' ),
			'line break'          => array( "a\r\nSet-Cookie: x=1", 'aSet-Cookie-x-1.pdf', 'aSet-Cookie: x=1.pdf' ),
			'quote'               => array( 'a"; filename="evil.exe', 'a-filename-evil.exe.pdf', 'a; filename=evil.exe.pdf' ),
			'path'                => array( '../../etc/passwd', 'etcpasswd.pdf', 'etcpasswd.pdf' ),
			'backslash'           => array( '..\\..\\boot.ini', 'boot.ini.pdf', 'boot.ini.pdf' ),
			'empty'               => array( '', 'document.pdf', 'document.pdf' ),
			'only punctuation'    => array( '"""', 'document.pdf', 'document.pdf' ),
			'not ascii at all'    => array( '請求書', 'document.pdf', '請求書.pdf' ),
			'invalid utf-8'       => array( "caf\xE9", 'cafe.pdf', 'café.pdf' ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_file_names_are_made_safe( string $asked, string $ascii, string $name ): void {
		$this->assertSame( $ascii, Download::ascii_name( $asked ) );
		$this->assertSame( $name, Download::name( $asked ) );
	}

	public function test_the_disposition_carries_no_control_characters_or_stray_quotes(): void {
		$disposition = Download::headers( "x\"\r\n\0y", 1 )['Content-Disposition'];

		$this->assertDoesNotMatchRegularExpression( '/[\x00-\x1F\x7F]/', $disposition );
		$this->assertSame( 2, substr_count( $disposition, '"' ) );
	}

	public function test_the_utf8_name_is_percent_encoded(): void {
		$this->assertStringEndsWith( "filename*=UTF-8''Rechnung%20M%C3%BCller.pdf", Download::headers( 'Rechnung Müller', 1 )['Content-Disposition'] );
	}

	public function test_long_names_are_capped(): void {
		$name = Download::name( str_repeat( 'a', 500 ) );

		$this->assertSame( Download::MAX_NAME + 4, mb_strlen( $name ) );
		$this->assertStringEndsWith( '.pdf', $name );
	}

	/* ─── Sending ───────────────────────────────────────────────────── */

	public function test_send_writes_the_headers_and_the_bytes(): void {
		$bytes = ( new Document() )->text( 'Hello' )->render( array( 'CreationDate' => 1_700_000_000 ) );

		$this->expectOutputString( $bytes );
		$this->assertTrue( Download::send( $bytes, 'hello', true ) );

		$this->assertSame( 1, $GLOBALS['pdf_wp']['nocache'] );
		$this->assertContains( 'Content-Type: application/pdf', $GLOBALS['pdf_wp']['headers'] );
		$this->assertContains( 'Content-Length: ' . strlen( $bytes ), $GLOBALS['pdf_wp']['headers'] );
		$this->assertContains( 'Content-Disposition: inline; filename="hello.pdf"; filename*=UTF-8\'\'hello.pdf', $GLOBALS['pdf_wp']['headers'] );
	}

	public function test_send_does_nothing_once_output_has_started(): void {
		$GLOBALS['pdf_wp']['sent'] = true;

		$this->expectOutputString( '' );
		$this->assertFalse( Download::send( '%PDF-1.4', 'x' ) );
		$this->assertSame( array(), $GLOBALS['pdf_wp']['headers'] );
		$this->assertSame( 0, $GLOBALS['pdf_wp']['nocache'] );
	}
}
