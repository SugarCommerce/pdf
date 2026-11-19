<?php
/**
 * The part of an embedded font one document uses.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class FontSubset
 *
 * Records which glyphs of a {@see TrueType} a document draws, writes text
 * as those glyphs, and turns them into the PDF objects that embed them.
 *
 * Text is written as two-byte codes under Identity-H, where each code is a
 * glyph's position in the subset. The subset does not exist until the
 * document is rendered, so positions are handed out as glyphs are first
 * drawn and the subset is built to match them — which also means the same
 * document always produces the same subset, byte for byte.
 *
 * One per font per document: the font is shared and read-only, and what a
 * document drew belongs to that document alone. Two documents sharing the
 * usage would each embed the other's glyphs.
 *
 * @since 1.3.0
 */
final class FontSubset {

	/**
	 * Glyph IDs in first-use order; the index is the code written.
	 *
	 * @var list<int>
	 */
	private array $order = array( 0 );

	/**
	 * Glyph ID => code.
	 *
	 * @var array<int, int>
	 */
	private array $codes = array( 0 => 0 );

	/**
	 * Code => the character it was drawn for, for the ToUnicode map.
	 *
	 * @var array<int, string>
	 */
	private array $characters = array();

	/**
	 * Start recording a document's use of a font.
	 *
	 * @since 1.3.0
	 *
	 * @param TrueType $font The font.
	 */
	public function __construct( public readonly TrueType $font ) {}

	/**
	 * Text as the hex string a text operator shows, its glyphs recorded.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text UTF-8 text, every character one the font has.
	 *
	 * @return string Upper-case hex, four digits per character.
	 */
	public function encode( string $text ): string {
		$hex = '';

		foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $character ) {
			$glyph = $this->font->glyph( mb_ord( $character, 'UTF-8' ) );

			if ( ! isset( $this->codes[ $glyph ] ) ) {
				$this->codes[ $glyph ] = count( $this->order );
				$this->order[]         = $glyph;
			}

			$code = $this->codes[ $glyph ];

			// The first character a glyph was drawn for is the one copying
			// it gives back.
			if ( 0 !== $code ) {
				$this->characters[ $code ] ??= $character;
			}

			$hex .= sprintf( '%04X', $code );
		}

		return $hex;
	}

	/**
	 * Whether anything has been drawn.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	public function used(): bool {
		return count( $this->order ) > 1;
	}

	/**
	 * The name the font is embedded under: a tag, a plus, its own name.
	 *
	 * The six-letter tag marks a subset, which PDF/A validators look for,
	 * and differs between subsets of one font, so a reader never takes two
	 * of them for the same thing. Made from the glyphs, not at random: the
	 * same document must render to the same bytes.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function base_font(): string {
		$hash = md5( $this->font->name . ':' . implode( ',', $this->order ) );
		$tag  = '';

		for ( $index = 0; $index < 6; $index++ ) {
			$tag .= chr( 65 + ( hexdec( substr( $hash, $index * 2, 2 ) ) % 26 ) );
		}

		return $tag . '+' . $this->font->name;
	}

	/**
	 * The five objects that embed the subset, numbered from `$first`.
	 *
	 * A Type0 font points at a CIDFontType2 descendant, which points at a
	 * descriptor, which points at the font file. The ToUnicode map beside
	 * them is what makes the text copy, search and read aloud as the
	 * letters it is rather than as glyph numbers.
	 *
	 * @since 1.3.0
	 *
	 * @param int $first The object number of the Type0 font.
	 *
	 * @return array<int, string> Object number => body.
	 */
	public function objects( int $first ): array {
		[ $file ] = $this->font->subset( $this->order );

		$font       = $this->font;
		$name       = $this->base_font();
		$descendant = $first + 1;
		$descriptor = $first + 2;
		$program    = $first + 3;
		$unicode    = $first + 4;

		// Every code's width, in one run from 0. A width left out falls
		// back to /DW, a whole em of space where a narrow letter was meant.
		$widths = array();

		foreach ( $this->order as $glyph ) {
			$widths[] = $font->advance( $glyph );
		}

		// Bit 3 symbolic, the honest description of a font with no standard
		// character set; bit 7 italic, bit 1 fixed pitch, which a reader
		// uses if it ever has to stand another font in.
		$flags = 4 | ( $font->italic ? 64 : 0 ) | ( $font->fixed_pitch ? 1 : 0 );

		// TrueType has no stem width; readers use it to pick a substitute,
		// so it is estimated from the weight rather than one value for all.
		$stem = max( 50, ( (int) round( $font->weight / 65 ) * 10 ) - 10 );

		[ $min_x, $min_y, $max_x, $max_y ] = $font->bbox;

		$compressed = (string) gzcompress( $file, 9 );
		$cmap       = $this->to_unicode();
		$cmap_bytes = (string) gzcompress( $cmap, 9 );

		return array(
			$first      => '<</Type /Font /Subtype /Type0 /BaseFont /' . $name . ' /Encoding /Identity-H'
				. ' /DescendantFonts [' . $descendant . ' 0 R] /ToUnicode ' . $unicode . ' 0 R>>',
			$descendant => '<</Type /Font /Subtype /CIDFontType2 /BaseFont /' . $name
				. ' /CIDSystemInfo <</Registry (Adobe) /Ordering (Identity) /Supplement 0>>'
				. ' /FontDescriptor ' . $descriptor . ' 0 R /DW 1000 /W [0 [' . implode( ' ', $widths ) . ']] /CIDToGIDMap /Identity>>',
			$descriptor => '<</Type /FontDescriptor /FontName /' . $name . ' /Flags ' . $flags
				. sprintf( ' /FontBBox [%d %d %d %d]', round( $min_x ), round( $min_y ), round( $max_x ), round( $max_y ) )
				. sprintf( ' /ItalicAngle %.1F /Ascent %d /Descent %d /CapHeight %d', $font->italic_angle, round( $font->ascender ), round( $font->descender ), round( $font->cap_height ) )
				. ' /StemV ' . $stem . ' /FontFile2 ' . $program . ' 0 R>>',
			$program    => '<</Length ' . strlen( $compressed ) . ' /Length1 ' . strlen( $file ) . " /Filter /FlateDecode>>\nstream\n" . $compressed . "\nendstream",
			$unicode    => '<</Length ' . strlen( $cmap_bytes ) . " /Filter /FlateDecode>>\nstream\n" . $cmap_bytes . "\nendstream",
		);
	}

	/**
	 * The CMap from each code back to the character it was drawn for.
	 *
	 * A character beyond the Basic Multilingual Plane is written as its
	 * UTF-16 surrogate pair, as the format asks.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function to_unicode(): string {
		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo <</Registry (Adobe) /Ordering (UCS) /Supplement 0>> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
			. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

		ksort( $this->characters );

		// At most 100 entries to a block, as the format allows.
		foreach ( array_chunk( $this->characters, 100, true ) as $chunk ) {
			$cmap .= count( $chunk ) . " beginbfchar\n";

			foreach ( $chunk as $code => $character ) {
				$cmap .= sprintf( "<%04X> <%s>\n", $code, strtoupper( bin2hex( (string) mb_convert_encoding( $character, 'UTF-16BE', 'UTF-8' ) ) ) );
			}

			$cmap .= "endbfchar\n";
		}

		return $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
	}
}
