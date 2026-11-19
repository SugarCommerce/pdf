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
	 * Every object in a PDF this library wrote, found through its xref
	 * table, with each stream decoded.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return array<int, array{dict: string, stream: string|null}>
	 */
	public static function objects( string $bytes ): array {
		Assert::assertSame( 1, preg_match( '/startxref\n(\d+)\n%%EOF$/', $bytes, $start ), 'no startxref' );
		Assert::assertSame( 1, preg_match( '/^xref\n0 (\d+)\n/', substr( $bytes, (int) $start[1] ), $header ), 'no xref table' );

		$table   = substr( $bytes, (int) $start[1] + strlen( $header[0] ) );
		$objects = array();

		for ( $number = 1; $number < (int) $header[1]; $number++ ) {
			$offset = (int) substr( $table, $number * 20, 10 );
			$prefix = $number . " 0 obj\n";

			Assert::assertSame( $prefix, substr( $bytes, $offset, strlen( $prefix ) ), 'object ' . $number );

			$body   = $offset + strlen( $prefix );
			$end    = (int) strpos( $bytes, "\nendobj", $body );
			$marker = strpos( $bytes, ">>\nstream\n", $body );
			$stream = null;

			if ( false !== $marker && $marker < $end ) {
				$dict = substr( $bytes, $body, $marker + 2 - $body );

				Assert::assertSame( 1, preg_match( '/\/Length (\d+)/', $dict, $length ), 'object ' . $number . ' has no /Length' );

				$stream = substr( $bytes, $marker + 10, (int) $length[1] );

				Assert::assertSame( "\nendstream", substr( $bytes, $marker + 10 + (int) $length[1], 10 ), 'object ' . $number . ': /Length is wrong' );

				if ( str_contains( $dict, '/FlateDecode' ) ) {
					$decoded = gzuncompress( $stream );
					Assert::assertIsString( $decoded, 'object ' . $number . ' does not inflate' );
					$stream = $decoded;
				}
			} else {
				$dict = substr( $bytes, $body, $end - $body );
			}

			$objects[ $number ] = array(
				'dict'   => $dict,
				'stream' => $stream,
			);
		}

		return $objects;
	}

	/**
	 * The PDF with every stream inflated, for asserting on its operators.
	 *
	 * Not a PDF any more — the offsets no longer match — but every object
	 * and every content stream in a form a string search can read.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return string
	 */
	public static function plain( string $bytes ): string {
		$plain = '';

		foreach ( self::objects( $bytes ) as $number => $object ) {
			$plain .= $number . " 0 obj\n" . $object['dict'];

			if ( null !== $object['stream'] ) {
				$plain .= "\nstream\n" . $object['stream'] . "\nendstream";
			}

			$plain .= "\nendobj\n";
		}

		return $plain;
	}

	/**
	 * The PDF as compared with a golden file: streams inflated, and the
	 * lengths that depend on how they were deflated taken out.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return string
	 */
	public static function canonical( string $bytes ): string {
		return (string) preg_replace( '/\/Length \d+/', '/Length *', self::plain( $bytes ) );
	}

	/**
	 * The decoded content stream of each page, in page order.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return list<string>
	 */
	public static function streams( string $bytes ): array {
		$objects = self::objects( $bytes );
		$streams = array();

		foreach ( self::page_numbers( $objects ) as $page ) {
			preg_match( '/\/Contents (\d+) 0 R/', $objects[ $page ]['dict'], $contents );
			$streams[] = (string) $objects[ (int) $contents[1] ]['stream'];
		}

		return $streams;
	}

	/**
	 * Text runs as the content streams place them, page by page: one per
	 * text object, with its first font, size and position, the text as
	 * the PDF's own fonts decode it, and its width by the PDF's own widths
	 * — `/Widths` for a base font, `/W` for an embedded one.
	 *
	 * Both are what a reader uses, so a test of where text ends checks the
	 * file and not this library's opinion of it.
	 *
	 * @param string $bytes A PDF written by this library.
	 *
	 * @return list<list<array{font: string, fonts: list<string>, size: float, x: float, y: float, text: string, width: float}>>
	 */
	public static function runs( string $bytes ): array {
		$objects = self::objects( $bytes );
		$pages   = array();

		foreach ( self::page_numbers( $objects ) as $page ) {
			preg_match( '/\/Font <<(.*?)>>/', $objects[ $page ]['dict'], $resources );
			preg_match_all( '/\/(\w+) (\d+) 0 R/', $resources[1] ?? '', $entries, PREG_SET_ORDER );

			$fonts = array();

			foreach ( $entries as $entry ) {
				$fonts[ $entry[1] ] = self::font_metrics( $objects, (int) $entry[2] );
			}

			preg_match( '/\/Contents (\d+) 0 R/', $objects[ $page ]['dict'], $contents );
			preg_match_all( '/BT\n(.*?)ET\n/s', (string) $objects[ (int) $contents[1] ]['stream'], $blocks );

			$runs = array();

			foreach ( $blocks[1] as $block ) {
				preg_match_all( '/\/(\w+) ([\d.]+) Tf\n|(-?[\d.]+) (-?[\d.]+) Td\n|\(((?:\\\\.|[^\\\\)])*)\) Tj\n|<([0-9A-F]*)> Tj\n/s', $block, $operators, PREG_SET_ORDER );

				$run     = array(
					'font'  => '',
					'fonts' => array(),
					'size'  => 0.0,
					'x'     => 0.0,
					'y'     => 0.0,
					'text'  => '',
					'width' => 0.0,
				);
				$current = '';

				foreach ( $operators as $operator ) {
					if ( '' !== ( $operator[1] ?? '' ) ) {
						$current        = $operator[1];
						$run['font']    = '' === $run['font'] ? $current : $run['font'];
						$run['fonts'][] = $current;
						$run['size']    = (float) $operator[2];
					} elseif ( '' !== ( $operator[3] ?? '' ) ) {
						$run['x'] = (float) $operator[3];
						$run['y'] = (float) $operator[4];
					} elseif ( isset( $operator[6] ) ) {
						[ $text, $units ] = self::decode_hex( $fonts[ $current ], $operator[6] );
						$run['text']     .= $text;
						$run['width']    += $units * $run['size'] / 1000;
					} else {
						$raw              = (string) preg_replace( '/\\\\(.)/s', '$1', $operator[5] );
						$run['text']     .= (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
						$run['width']    += self::literal_units( $fonts[ $current ], $raw ) * $run['size'] / 1000;
					}
				}

				$runs[] = $run;
			}

			$pages[] = $runs;
		}

		return $pages;
	}

	/**
	 * Every embedded font's file, by the name it is embedded under.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return array<string, string> BaseFont => the subset `.ttf`.
	 */
	public static function embedded_fonts( string $bytes ): array {
		$objects = self::objects( $bytes );
		$found   = array();

		foreach ( $objects as $object ) {
			if ( 1 === preg_match( '/\/Type \/FontDescriptor \/FontName \/(\S+) .*\/FontFile2 (\d+) 0 R/', $object['dict'], $match ) ) {
				$found[ $match[1] ] = (string) $objects[ (int) $match[2] ]['stream'];
			}
		}

		return $found;
	}

	/**
	 * Object numbers of the pages, in order.
	 *
	 * @param array<int, array{dict: string, stream: string|null}> $objects From objects().
	 *
	 * @return list<int>
	 */
	private static function page_numbers( array $objects ): array {
		foreach ( $objects as $object ) {
			if ( 1 === preg_match( '/\/Type \/Pages \/Kids \[([^\]]*)\]/', $object['dict'], $kids ) ) {
				preg_match_all( '/(\d+) 0 R/', $kids[1], $numbers );

				return array_map( 'intval', $numbers[1] );
			}
		}

		return array();
	}

	/**
	 * How a font's codes decode and how wide they are.
	 *
	 * @param array<int, array{dict: string, stream: string|null}> $objects From objects().
	 * @param int                                                  $number  The font's object.
	 *
	 * @return array{widths: array<int, int>, unicode: array<int, string>|null}
	 */
	private static function font_metrics( array $objects, int $number ): array {
		$dict = $objects[ $number ]['dict'];

		if ( ! str_contains( $dict, '/Type0' ) ) {
			preg_match( '/\/FirstChar (\d+)/', $dict, $first );
			preg_match( '/\/Widths \[([^\]]*)\]/', $dict, $list );

			$widths = array();

			foreach ( preg_split( '/\s+/', trim( $list[1] ?? '' ) ) ?: array() as $index => $width ) {
				$widths[ (int) ( $first[1] ?? 0 ) + $index ] = (int) $width;
			}

			return array(
				'widths'  => $widths,
				'unicode' => null,
			);
		}

		preg_match( '/\/DescendantFonts \[(\d+) 0 R\]/', $dict, $descendant );
		preg_match( '/\/ToUnicode (\d+) 0 R/', $dict, $map );
		preg_match( '/\/W \[(.*)\] \/CIDToGIDMap/', $objects[ (int) $descendant[1] ]['dict'], $list );

		// "c [w1 w2 ...]" runs and "c1 c2 w" ranges.
		$widths = array();
		preg_match_all( '/(\d+) \[([^\]]*)\]|(\d+) (\d+) (\d+)/', $list[1] ?? '', $groups, PREG_SET_ORDER );

		foreach ( $groups as $group ) {
			if ( '' !== $group[1] ) {
				foreach ( preg_split( '/\s+/', trim( $group[2] ) ) ?: array() as $offset => $width ) {
					$widths[ (int) $group[1] + $offset ] = (int) $width;
				}
			} else {
				for ( $code = (int) $group[3]; $code <= (int) $group[4]; $code++ ) {
					$widths[ $code ] = (int) $group[5];
				}
			}
		}

		$unicode = array();
		preg_match_all( '/<([0-9A-F]{4})> <([0-9A-F]+)>/', (string) $objects[ (int) $map[1] ]['stream'], $pairs, PREG_SET_ORDER );

		foreach ( $pairs as $pair ) {
			$unicode[ (int) hexdec( $pair[1] ) ] = (string) mb_convert_encoding( (string) hex2bin( $pair[2] ), 'UTF-8', 'UTF-16BE' );
		}

		return array(
			'widths'  => $widths,
			'unicode' => $unicode,
		);
	}

	/**
	 * A hex string's text and width in 1/1000 em.
	 *
	 * @param array{widths: array<int, int>, unicode: array<int, string>|null} $font Metrics.
	 * @param string                                                           $hex  Hex digits.
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function decode_hex( array $font, string $hex ): array {
		$text  = '';
		$units = 0;

		foreach ( str_split( $hex, 4 ) as $code ) {
			$code   = (int) hexdec( $code );
			$text  .= $font['unicode'][ $code ] ?? "\u{FFFD}";
			$units += $font['widths'][ $code ] ?? 1000;
		}

		return array( $text, $units );
	}

	/**
	 * A literal string's width in 1/1000 em.
	 *
	 * @param array{widths: array<int, int>, unicode: array<int, string>|null} $font  Metrics.
	 * @param string                                                           $bytes WinAnsi bytes.
	 *
	 * @return int
	 */
	private static function literal_units( array $font, string $bytes ): int {
		$units = 0;

		for ( $index = 0, $length = strlen( $bytes ); $index < $length; $index++ ) {
			$units += $font['widths'][ ord( $bytes[ $index ] ) ] ?? 0;
		}

		return $units;
	}
}
