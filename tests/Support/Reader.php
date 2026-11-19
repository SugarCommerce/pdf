<?php
/**
 * Reading a rendered PDF back, the way somebody else's software will.
 *
 * Two readers: smalot/pdfparser, a dev-only PHP parser that every run has,
 * and poppler's and qpdf's command-line tools, which CI installs. The
 * tools are stricter; the parser is always there.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

use PHPUnit\Framework\Assert;
use Smalot\PdfParser\Parser;
use SugarCommerce\Pdf\Font;

/**
 * Class Reader
 */
final class Reader {

	/**
	 * The text of each page, as smalot/pdfparser extracts it.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return list<string>
	 */
	public static function pages( string $bytes ): array {
		$pages = array();

		foreach ( ( new Parser() )->parseContent( $bytes )->getPages() as $page ) {
			$pages[] = $page->getText();
		}

		return $pages;
	}

	/**
	 * The text of the whole document.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return string
	 */
	public static function text( string $bytes ): string {
		return implode( "\n", self::pages( $bytes ) );
	}

	/**
	 * Text with its spacing taken out, so a line break or a tab between
	 * two words does not stop a match.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function squeezed( string $text ): string {
		return (string) preg_replace( '/\s+/u', '', $text );
	}

	/**
	 * A command-line tool, or a skipped test when it is not installed.
	 *
	 * In CI a missing tool is a failure: the workflow installs them, and a
	 * suite that quietly skips its strictest checks is not passing.
	 *
	 * @param string $name e.g. `pdftotext`.
	 *
	 * @return string The command.
	 */
	public static function tool( string $name ): string {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}

		if ( false !== getenv( 'CI' ) && '' !== getenv( 'CI' ) ) {
			Assert::fail( $name . ' is not installed; CI installs poppler-utils and qpdf for these checks.' );
		}

		Assert::markTestSkipped( $name . ' is not installed (brew install poppler qpdf).' );
	}

	/**
	 * Run a tool on a PDF.
	 *
	 * @param string       $tool  From tool().
	 * @param list<string> $args  Arguments before the file.
	 * @param string       $bytes The PDF.
	 * @param list<string> $after Arguments after the file.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	public static function run( string $tool, array $args, string $bytes, array $after = array() ): array {
		// Named .pdf, and inside the package: Ubuntu's qpdf runs under an
		// AppArmor profile that refuses to open anything else.
		$folder = dirname( __DIR__ ) . '/.tmp';

		if ( ! is_dir( $folder ) ) {
			mkdir( $folder, 0777, true );
		}

		$file = $folder . '/' . bin2hex( random_bytes( 8 ) ) . '.pdf';
		file_put_contents( $file, $bytes );

		$command = escapeshellarg( $tool );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$command .= ' ' . escapeshellarg( $file );

		foreach ( $after as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$output = array();
		$code   = 0;

		exec( $command . ' 2>&1', $output, $code );
		unlink( $file );

		return array( $code, implode( "\n", $output ) );
	}

	/**
	 * The face behind a font resource name.
	 *
	 * @param string $resource `F1`, `F2` or `F3`.
	 *
	 * @return Font
	 */
	public static function font( string $resource ): Font {
		return match ( $resource ) {
			'F2'    => Font::HelveticaBold,
			'F3'    => Font::Courier,
			default => Font::Helvetica,
		};
	}

	/**
	 * Text runs as the content streams place them: font, size, position
	 * and the WinAnsi bytes shown, page by page.
	 *
	 * @param string $bytes A PDF written by this library (streams are not
	 *                      compressed).
	 *
	 * @return list<list<array{font: string, size: float, x: float, y: float, text: string}>>
	 */
	public static function runs( string $bytes ): array {
		preg_match_all( '/stream\n(.*?)\nendstream/s', $bytes, $streams );

		$pages = array();

		foreach ( $streams[1] as $stream ) {
			preg_match_all( '/\/(F\d) ([\d.]+) Tf\n(-?[\d.]+) (-?[\d.]+) Td\n\(((?:\\\\.|[^\\\\)])*)\) Tj/s', $stream, $matches, PREG_SET_ORDER );

			$runs = array();

			foreach ( $matches as $match ) {
				$runs[] = array(
					'font' => $match[1],
					'size' => (float) $match[2],
					'x'    => (float) $match[3],
					'y'    => (float) $match[4],
					'text' => (string) preg_replace( '/\\\\(.)/s', '$1', $match[5] ),
				);
			}

			$pages[] = $runs;
		}

		return $pages;
	}
}
