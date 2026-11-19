<?php
/**
 * Golden files: every fixture's bytes, its text as poppler reads it, and
 * the verdicts of qpdf and pdffonts.
 *
 * The PDFs are compared byte for byte, so any change to what is written —
 * a moved rule, a reworded note — is a diff somebody reviews. The text
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

	#[DataProvider( 'fixtures' )]
	public function test_the_pdf_is_byte_for_byte_its_golden_file( string $name ): void {
		$bytes = Fixtures::bytes( Fixtures::all()[ $name ] );

		$this->assertSame( $this->golden( $name . '.pdf', $bytes ), $bytes, 'Run UPDATE_GOLDEN=1 composer test after a deliberate change.' );
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
	 * Base-14 only, for now: nothing embedded, nothing a reader has to
	 * substitute. Embedded subsets arrive with TrueType support, and this
	 * is the test that will say so.
	 */
	#[DataProvider( 'fixtures' )]
	public function test_pdffonts_lists_only_the_base_fonts( string $name ): void {
		[ $code, $output ] = Reader::run( Reader::tool( 'pdffonts' ), array(), Fixtures::bytes( Fixtures::all()[ $name ] ) );

		$this->assertSame( 0, $code, $output );

		$rows = array_slice( explode( "\n", trim( $output ) ), 2 );

		$this->assertNotEmpty( $rows );

		foreach ( $rows as $row ) {
			$this->assertMatchesRegularExpression( '/^(Helvetica|Helvetica-Bold|Courier)\s+Type 1\s+WinAnsi\s+no\s+no\s+no\s/', $row );
		}
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
