<?php
/**
 * A TrueType font, read and subset in PHP.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Fonts;

use SugarCommerce\Pdf\Traits\TrueType\{Bytes, CharacterMap, Reading, Subsetting};

/**
 * Class TrueType
 *
 * Enough of a `.ttf` to draw with it and embed only what was drawn: the
 * character map, the advance widths, the outlines and the few numbers a
 * layout positions baselines from.
 *
 * Embedding a whole font is not an option. Noto Sans is 430 KB against an
 * invoice that is otherwise 6 KB, so the glyphs actually used are copied
 * into a new, much smaller font (see {@see subset()}).
 *
 * Nothing here needs an extension: the tables are big-endian integers at
 * known offsets, and `unpack()` reads them. Every offset is checked before
 * it is read — a font is a file somebody handed you, and a damaged or
 * hostile one has to fail with a reason, not a warning and garbage.
 *
 * Refused, each with a message saying what to use instead: PostScript
 * outlines (`.otf` that starts `OTTO`), collections (`.ttc`), WOFF, variable
 * fonts, and fonts whose licence bits forbid embedding or subsetting.
 *
 * What a font answers is here; reading its tables, its character map, the
 * subset and the bytes are traits in Traits/TrueType.
 *
 * @since 1.3.0
 */
final class TrueType {

	use Reading;
	use CharacterMap;
	use Subsetting;
	use Bytes;

	/**
	 * The PostScript name, cleaned to what a PDF name may hold.
	 */
	public readonly string $name;

	/**
	 * The family name, e.g. `Noto Sans`.
	 */
	public readonly string $family;

	/**
	 * Design units per em.
	 */
	public readonly int $units_per_em;

	/**
	 * Number of glyphs in the font.
	 */
	public readonly int $glyph_count;

	/**
	 * Ascender in 1/1000 em, preferring OS/2's typographic value.
	 */
	public readonly float $ascender;

	/**
	 * Descender in 1/1000 em — negative, as fonts declare it.
	 */
	public readonly float $descender;

	/**
	 * Height of a capital letter in 1/1000 em.
	 */
	public readonly float $cap_height;

	/**
	 * Height of a lowercase `x` in 1/1000 em.
	 */
	public readonly float $x_height;

	/**
	 * Italic angle in degrees, counter-clockwise from vertical.
	 */
	public readonly float $italic_angle;

	/**
	 * Bounding box of every glyph, in 1/1000 em: x min, y min, x max, y max.
	 *
	 * @var array{0: float, 1: float, 2: float, 3: float}
	 */
	public readonly array $bbox;

	/**
	 * OS/2 weight class: 400 regular, 700 bold.
	 */
	public readonly int $weight;

	/**
	 * Whether the face says it is bold.
	 */
	public readonly bool $bold;

	/**
	 * Whether the face says it is italic or oblique.
	 */
	public readonly bool $italic;

	/**
	 * Whether every glyph has the same advance.
	 */
	public readonly bool $fixed_pitch;

	/**
	 * The file.
	 */
	private readonly string $data;

	/**
	 * Table tag => [ offset, length ].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private readonly array $tables;

	/**
	 * Offsets into `glyf`, glyph count + 1 entries.
	 *
	 * @var list<int>
	 */
	private readonly array $loca;

	/**
	 * Advance widths in font units, one per glyph.
	 *
	 * @var list<int>
	 */
	private readonly array $advances;

	/**
	 * Left side bearings in font units, one per glyph.
	 *
	 * @var list<int>
	 */
	private readonly array $bearings;

	/**
	 * The chosen character map: format (4 or 12) and its subtable offset.
	 *
	 * @var array{0: int, 1: int}
	 */
	private readonly array $cmap;

	/**
	 * Format 4 segments (end, start, delta, range offset, range position)
	 * or format 12 groups (start, end, first glyph), sorted by end.
	 *
	 * @var list<array{0: int, 1: int, 2: int, 3?: int, 4?: int}>
	 */
	private readonly array $segments;

	/**
	 * Code point => glyph, filled as characters are looked up.
	 *
	 * @var array<int, int>
	 */
	private array $glyphs = array();

	/**
	 * Fonts already read from disk, by path.
	 *
	 * @var array<string, self>
	 */
	private static array $loaded = array();

	/**
	 * Read a font from bytes.
	 *
	 * @since 1.3.0
	 *
	 * @param string $data  The `.ttf` file's contents.
	 * @param string $label What to call the font in an error.
	 *
	 * @throws \InvalidArgumentException When the font cannot be embedded,
	 *                                   saying why.
	 */
	public function __construct( string $data, string $label = 'The font' ) {
		$this->data = $data;

		if ( strlen( $data ) < 12 ) {
			throw new \InvalidArgumentException( $label . ' is not a font file.' );
		}

		$tag = substr( $data, 0, 4 );

		match ( true ) {
			'OTTO' === $tag                   => throw new \InvalidArgumentException( $label . ' has PostScript (CFF) outlines. Only TrueType outlines can be embedded: use the .ttf build of the font.' ),
			'ttcf' === $tag                   => throw new \InvalidArgumentException( $label . ' is a font collection (.ttc). Use the single .ttf for the face you want.' ),
			'wOFF' === $tag || 'wOF2' === $tag => throw new \InvalidArgumentException( $label . ' is a web font (WOFF). Use the .ttf it was made from.' ),
			"\x00\x01\x00\x00" !== $tag && 'true' !== $tag => throw new \InvalidArgumentException( $label . ' is not a TrueType font.' ),
			default                           => null,
		};

		$this->tables = $this->directory( $label );

		foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap' ) as $required ) {
			if ( ! isset( $this->tables[ $required ] ) ) {
				throw new \InvalidArgumentException( $label . ' has no ' . $required . ' table, which every TrueType font needs.' );
			}
		}

		// A variable font is one set of outlines plus the deltas that move
		// them between weights. A subset keeps the outlines and loses the
		// deltas, so every weight would quietly print as the default one.
		if ( isset( $this->tables['fvar'] ) ) {
			throw new \InvalidArgumentException( $label . ' is a variable font. Subsetting keeps its outlines and drops what makes a weight, so every weight would print as the default. Use a static instance (Regular, Bold) — most projects ship them alongside.' );
		}

		$this->check_licence( $label );

		[ $head ]           = $this->tables['head'];
		$this->units_per_em = $this->u16( $head + 18 );

		if ( $this->units_per_em < 16 || $this->units_per_em > 16384 ) {
			throw new \InvalidArgumentException( $label . ' declares ' . $this->units_per_em . ' units per em, outside the 16–16384 a font may use.' );
		}

		$this->glyph_count = $this->u16( $this->tables['maxp'][0] + 4 );

		if ( 0 === $this->glyph_count ) {
			throw new \InvalidArgumentException( $label . ' has no glyphs.' );
		}

		$this->loca                          = $this->read_loca( $label );
		[ $this->advances, $this->bearings ] = $this->read_metrics();
		[ $this->cmap, $this->segments ]     = $this->read_cmap( $label );

		$this->read_vertical_metrics();
		$this->name   = $this->postscript_name();
		$this->family = $this->name_record( 1 ) ?? $this->name;
	}

	/**
	 * Read a font file, once per request.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path Path to a `.ttf`.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the file is missing or cannot be embedded.
	 */
	public static function file( string $path ): self {
		if ( isset( self::$loaded[ $path ] ) ) {
			return self::$loaded[ $path ];
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file throws below, with the path.
		$data = is_file( $path ) ? @file_get_contents( $path ) : false;

		if ( false === $data ) {
			throw new \InvalidArgumentException( 'Could not read the font file ' . $path . '.' );
		}

		self::$loaded[ $path ] = new self( $data, basename( $path ) );

		return self::$loaded[ $path ];
	}

	/* ─── Characters ────────────────────────────────────────────────── */

	/**
	 * The glyph for a code point, or 0 when the font has none.
	 *
	 * @since 1.3.0
	 *
	 * @param int $code Unicode code point.
	 *
	 * @return int Glyph ID; 0 is `.notdef`, the empty box.
	 */
	public function glyph( int $code ): int {
		if ( isset( $this->glyphs[ $code ] ) ) {
			return $this->glyphs[ $code ];
		}

		// A bound on the cache, as for the transcoder's: a hostile string
		// of every code point would otherwise grow it without limit.
		if ( count( $this->glyphs ) > 8192 ) {
			$this->glyphs = array();
		}

		$glyph = $code >= 0 && $code <= 0x10FFFF ? $this->lookup( $code ) : 0;

		$this->glyphs[ $code ] = $glyph < $this->glyph_count ? $glyph : 0;

		return $this->glyphs[ $code ];
	}

	/**
	 * Whether the font has a glyph for every character of a string.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text UTF-8 text.
	 *
	 * @return bool
	 */
	public function covers( string $text ): bool {
		foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $character ) {
			if ( 0 === $this->glyph( mb_ord( $character, 'UTF-8' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A glyph's advance width in 1/1000 em, as a PDF publishes it.
	 *
	 * Rounded to a whole unit here, once, so what a layout measures and
	 * what the `/W` array tells the reader are the same number.
	 *
	 * @since 1.3.0
	 *
	 * @param int $glyph Glyph ID.
	 *
	 * @return int
	 */
	public function advance( int $glyph ): int {
		return (int) round( ( $this->advances[ $glyph ] ?? 0 ) * 1000 / $this->units_per_em );
	}

	/**
	 * The width of text in points, every character through its glyph.
	 *
	 * A character the font lacks counts as `.notdef`, the box it would
	 * print as; a document only hands this text the font covers.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text UTF-8 text.
	 * @param float  $size Font size in points.
	 *
	 * @return float
	 */
	public function width_of( string $text, float $size ): float {
		$units = 0;

		foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $character ) {
			$units += $this->advance( $this->glyph( mb_ord( $character, 'UTF-8' ) ) );
		}

		return ( $units / 1000 ) * $size;
	}

	/* ─── Glyphs ────────────────────────────────────────────────────── */

	/**
	 * Whether a glyph is built from other glyphs — `é` from `e` and an acute.
	 *
	 * @since 1.3.0
	 *
	 * @param int $glyph Glyph ID.
	 *
	 * @return bool
	 */
	public function is_composite( int $glyph ): bool {
		$outline = $this->outline( $glyph );

		return strlen( $outline ) >= 10 && self::signed( self::read16( $outline, 0 ) ) < 0;
	}

	/**
	 * The glyphs a composite glyph is assembled from, in order.
	 *
	 * Leaving these out of a subset prints `é` as a bare accent: the kind
	 * of fault that only ever shows on somebody's name.
	 *
	 * @since 1.3.0
	 *
	 * @param int $glyph Glyph ID.
	 *
	 * @return list<int> Empty for a simple glyph.
	 */
	public function components( int $glyph ): array {
		$outline = $this->outline( $glyph );
		$found   = array();

		foreach ( self::component_offsets( $outline ) as $offset ) {
			$found[] = self::read16( $outline, $offset );
		}

		return $found;
	}
}
