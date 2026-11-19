<?php
/**
 * Golden files: every fixture's bytes, its text as poppler reads it, and
 * the verdicts of qpdf and pdffonts.
 *
 * The PDFs are compared object by object with their streams inflated —
 * every operator and every byte of every embedded font — so any change to
 * what is written, a moved rule, a reworded note, is a diff somebody
 * reviews; only how zlib happened to deflate it is left out. The text
 * files survive refactoring that moves bytes but not words, and catch
 * what the bytes cannot say plainly: a seller block that vanished, a note
 * that wrapped differently, a line that went missing.
 *
 * Regenerate after a deliberate change, and read the diff:
 *
 *   UPDATE_GOLDEN=1 composer test
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Tests\Support\{Fixtures, Reader};

final class GoldenTest extends TestCase {

	/** @return array<string, array{0: string}> */
	public static function fixtures(): array {
		$names = array();

		foreach ( array_keys( Fixtures::all() ) as $name ) {
			$names[ $name ] = array( $name );
		}

		return $names;
	}

	/**
	 * Compared with every stream inflated and the lengths and offsets that
	 * follow from compression set aside: two zlib builds may deflate the
	 * same operators to different bytes, and neither is wrong. What is
	 * compared is every object, every operator and every byte of every
	 * embedded font.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_the_pdf_is_its_golden_file( string $name ): void {
		$bytes = Fixtures::bytes( Fixtures::all()[ $name ] );

		$this->assertSame( Reader::canonical( $this->golden( $name . '.pdf', $bytes ) ), Reader::canonical( $bytes ), 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
	}

	#[DataProvider( 'fixtures' )]
	public function test_the_same_document_renders_to_the_same_bytes( string $name ): void {
		$this->assertSame( Fixtures::bytes( Fixtures::all()[ $name ] ), Fixtures::bytes( Fixtures::all()[ $name ] ) );
	}

	#[DataProvider( 'fixtures' )]
	public function test_poppler_reads_the_golden_text( string $name ): void {
		$tool = Reader::tool( 'pdftotext' );

		[ $code, $text ] = Reader::run( $tool, array( '-layout', '-enc', 'UTF-8' ), Fixtures::bytes( Fixtures::all()[ $name ] ), array( '-' ) );

		$this->assertSame( 0, $code, $text );

		$text = self::normalised( $text );

		$this->assertSame( $this->golden( $name . '.txt', $text ), $text, 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
	}

	#[DataProvider( 'fixtures' )]
	public function test_qpdf_finds_nothing_wrong( string $name ): void {
		[ $code, $output ] = Reader::run( Reader::tool( 'qpdf' ), array( '--check' ), Fixtures::bytes( Fixtures::all()[ $name ] ) );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'No syntax or stream encoding errors found', $output );
	}

	/**
	 * Every font either one of the base fonts, which a reader has, or an
	 * embedded subset — `emb yes`, `sub yes`, `uni yes` and a `ABCDEF+`
	 * tag — so nothing is left for a reader to substitute.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_pdffonts_lists_base_fonts_and_embedded_subsets( string $name ): void {
		[ $code, $output ] = Reader::run( Reader::tool( 'pdffonts' ), array(), Fixtures::bytes( Fixtures::all()[ $name ] ) );

		$this->assertSame( 0, $code, $output );

		$rows = array_slice( explode( "\n", trim( $output ) ), 2 );

		$this->assertNotEmpty( $rows );

		foreach ( $rows as $row ) {
			$this->assertMatchesRegularExpression( '/^((Helvetica|Helvetica-Bold|Helvetica-Oblique|Courier)\s+Type 1\s+WinAnsi\s+no\s+no\s+no|[A-Z]{6}\+NotoSans-(Regular|Bold)\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes)\s/', $row );
		}
	}

	/**
	 * Every picture as poppler lists it: the logo where there is one, its
	 * mask with it, and nothing on the documents drawn only in vectors.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_pdfimages_lists_each_picture_once( string $name ): void {
		$rows = array_map(
			static fn( array $row ): string => implode( ' ', array( $row['page'], $row['type'], $row['width'], $row['height'], $row['color'], $row['comp'], $row['bpc'], $row['enc'] ) ),
			Reader::image_list( Fixtures::bytes( Fixtures::all()[ $name ] ) )
		);

		$expected = match ( $name ) {
			'logo'         => array( '1 image 180 60 rgb 3 8 image', '1 smask 180 60 gray 1 8 image' ),
			'receipt-logo' => array( '1 image 120 80 rgb 3 8 jpeg' ),
			default        => array(),
		};

		$this->assertSame( $expected, $rows );
	}

	public function test_a_fixture_logo_reaches_the_page_with_nothing_to_report(): void {
		foreach ( array( 'logo', 'receipt-logo' ) as $name ) {
			$this->assertSame( array(), Fixtures::document( Fixtures::all()[ $name ] )->image_warnings(), $name );
		}
	}

	/**
	 * Which fixtures embed: the ones whose words Helvetica cannot print,
	 * and the one set in the family. The rest stay a few kilobytes.
	 */
	public function test_only_the_documents_that_need_a_font_embed_one(): void {
		foreach ( Fixtures::all() as $name => $template ) {
			$embedded = array_keys( Reader::embedded_fonts( Fixtures::bytes( $template ) ) );
			$expected = match ( $name ) {
				'international' => array( 'NotoSans-Bold', 'NotoSans-Regular' ),
				'typeface'      => array( 'NotoSans-Bold', 'NotoSans-Regular' ),
				default         => array(),
			};

			$this->assertSame( $expected, array_map( static fn( string $font ): string => substr( $font, 7 ), $embedded ), $name );
		}
	}

	/**
	 * The names round-trip: poppler and smalot both read back, through the
	 * ToUnicode maps, exactly the letters that were set.
	 */
	public function test_greek_cyrillic_polish_and_currency_signs_extract_as_written(): void {
		$bytes = Fixtures::bytes( Fixtures::international() );
		$names = array( 'Ярослав Ковальчук', 'Київ 01001', 'Łódź field recordings', 'Wąchock', 'Ελληνικά δείγματα', 'Θεσσαλονίκη', '«Карпати»', 'Dziękujemy!', '£', '€' );

		[ , $poppler ] = Reader::run( Reader::tool( 'pdftotext' ), array( '-enc', 'UTF-8' ), $bytes, array( '-' ) );

		foreach ( array( 'poppler' => $poppler, 'smalot' => Reader::text( $bytes ) ) as $reader => $text ) {
			foreach ( $names as $name ) {
				$this->assertStringContainsString( $name, $text, $reader );
			}
		}

		$typeface = Fixtures::bytes( Fixtures::typeface() );

		$this->assertStringContainsString( 'Zoë Dvořáková', Reader::text( $typeface ) );
		$this->assertStringContainsString( 'Šťastný nový rok', Reader::text( $typeface ) );
	}

	/* ─── Archival and e-invoices ───────────────────────────────────── */

	/** @return array<string, array{0: string}> */
	public static function einvoices(): array {
		$names = array();

		foreach ( array_keys( Fixtures::einvoices() ) as $name ) {
			$names[ $name ] = array( $name );
		}

		return $names;
	}

	#[DataProvider( 'einvoices' )]
	public function test_the_einvoice_is_its_golden_file( string $name ): void {
		$bytes = Fixtures::einvoice_bytes( Fixtures::einvoices()[ $name ] );

		$this->assertSame( Reader::canonical( $this->golden( $name . '.pdf', $bytes ) ), Reader::canonical( $bytes ), 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
		$this->assertSame( $bytes, Fixtures::einvoice_bytes( Fixtures::einvoices()[ $name ] ) );
	}

	#[DataProvider( 'einvoices' )]
	public function test_the_einvoice_xml_is_its_golden_file( string $name ): void {
		[ $invoice, $details ] = Fixtures::einvoices()[ $name ];

		$xml = $invoice->factur_x_xml( $details );

		$this->assertSame( $this->golden( $name . '.xml', $xml ), $xml, 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
	}

	#[DataProvider( 'einvoices' )]
	public function test_poppler_reads_the_einvoice_golden_text( string $name ): void {
		[ $code, $text ] = Reader::run( Reader::tool( 'pdftotext' ), array( '-layout', '-enc', 'UTF-8' ), Fixtures::einvoice_bytes( Fixtures::einvoices()[ $name ] ), array( '-' ) );

		$this->assertSame( 0, $code, $text );

		$text = self::normalised( $text );

		$this->assertSame( $this->golden( $name . '.txt', $text ), $text, 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
	}

	/**
	 * Every fixture as PDF/A: qpdf finds nothing wrong, every font is an
	 * embedded subset — no base font left for a reader to supply — and a
	 * reader extracts the same text in the same order as from the ordinary
	 * file. The family is wider than Helvetica, so lines may break in other
	 * places; the words do not change.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_the_archival_file_has_only_embedded_fonts_and_the_same_text( string $name ): void {
		$template = Fixtures::all()[ $name ];
		$archival = Fixtures::bytes( $template, true );

		[ $code, $output ] = Reader::run( Reader::tool( 'qpdf' ), array( '--check' ), $archival );

		$this->assertSame( 0, $code, $output );

		[ $code, $fonts ] = Reader::run( Reader::tool( 'pdffonts' ), array(), $archival );

		$this->assertSame( 0, $code, $fonts );

		foreach ( array_slice( explode( "\n", trim( $fonts ) ), 2 ) as $row ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{6}\+NotoSans-(Regular|Bold)\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes\s/', $row );
		}

		$read = static fn( string $bytes ): string => Reader::squeezed( Reader::run( Reader::tool( 'pdftotext' ), array( '-raw', '-enc', 'UTF-8' ), $bytes, array( '-' ) )[1] );

		$this->assertSame( $read( Fixtures::bytes( $template ) ), $read( $archival ) );
		$this->assertSame( Fixtures::document( $template )->substitutions(), $template->render( true )->substitutions() );
	}

	/**
	 * pdftotext's layout as compared: spacing collapsed, blank lines
	 * dropped. Two poppler releases disagree about how many spaces a
	 * column gap is; neither disagrees about which words are on a line.
	 *
	 * @param string $text Raw output.
	 *
	 * @return string
	 */
	private static function normalised( string $text ): string {
		$lines = array();

		foreach ( preg_split( '/\R/u', str_replace( "\f", "\n--- page ---\n", $text ) ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/[ \t]+/u', ' ', $line ) );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		// The last page's form feed marks the end, not another page.
		while ( array() !== $lines && '--- page ---' === end( $lines ) ) {
			array_pop( $lines );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * A golden file's contents, written first when UPDATE_GOLDEN is set.
	 *
	 * @param string $file   Name under tests/golden.
	 * @param string $actual What was produced.
	 *
	 * @return string
	 */
	private function golden( string $file, string $actual ): string {
		$path = __DIR__ . '/golden/' . $file;

		if ( '' !== (string) getenv( 'UPDATE_GOLDEN' ) ) {
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}

			file_put_contents( $path, $actual );
		}

		if ( ! is_file( $path ) ) {
			$this->fail( 'No golden file ' . $file . ' yet: run UPDATE_GOLDEN=1 composer test.' );
		}

		return (string) file_get_contents( $path );
	}
}
