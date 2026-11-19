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

namespace SugarCommerce\Pdf;

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
 * @since 1.3.0
 */
final class TrueType {

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

	/* ─── Subsetting ────────────────────────────────────────────────── */

	/**
	 * A new font holding only some glyphs, numbered in the order given.
	 *
	 * The order is the caller's: text is written to the page before the
	 * subset exists, as glyph positions in it, so the font is built to fit
	 * those positions. `.notdef` stays at 0, as every font must have it;
	 * the glyphs asked for follow in order; then every glyph a composite
	 * among them is built from, and theirs in turn, where they cannot
	 * disturb the positions already written.
	 *
	 * Only the tables a PDF reader draws from are kept — head, hhea, maxp,
	 * hmtx, loca, glyf, and the hinting programs when there are any. The
	 * character map is left out: under Identity-H the PDF itself says
	 * which glyph each code is.
	 *
	 * @since 1.3.0
	 *
	 * @param list<int> $requested Glyph IDs, in the order they were first used.
	 *
	 * @return array{0: string, 1: array<int, int>} The font, and old glyph ID => new.
	 */
	public function subset( array $requested ): array {
		$ordered = array( 0 );
		$mapping = array( 0 => 0 );

		foreach ( $requested as $glyph ) {
			if ( $glyph >= 0 && $glyph < $this->glyph_count && ! isset( $mapping[ $glyph ] ) ) {
				$mapping[ $glyph ] = count( $ordered );
				$ordered[]         = $glyph;
			}
		}

		// Breadth first over a list that grows as it is walked, so a
		// component that is itself a composite brings its own along.
		for ( $index = 0; $index < count( $ordered ); $index++ ) {
			foreach ( $this->components( $ordered[ $index ] ) as $component ) {
				if ( $component < $this->glyph_count && ! isset( $mapping[ $component ] ) ) {
					$mapping[ $component ] = count( $ordered );
					$ordered[]             = $component;
				}
			}
		}

		$glyf = '';
		$loca = pack( 'N', 0 );
		$hmtx = '';

		foreach ( $ordered as $old ) {
			$outline = $this->outline( $old );
			$offsets = self::component_offsets( $outline );

			// The components still name glyphs by their old IDs; point them
			// at the new ones, or the accent lands on the wrong letter.
			foreach ( $offsets as $offset ) {
				$outline = substr_replace( $outline, pack( 'n', $mapping[ self::read16( $outline, $offset ) ] ?? 0 ), $offset, 2 );
			}

			// Four-byte aligned, as the loca offsets assume.
			$glyf .= $outline . str_repeat( "\0", ( 4 - ( strlen( $outline ) % 4 ) ) % 4 );
			$loca .= pack( 'N', strlen( $glyf ) );
			$hmtx .= pack( 'nn', $this->advances[ $old ] ?? 0, ( $this->bearings[ $old ] ?? 0 ) & 0xFFFF );
		}

		$count = count( $ordered );

		// Long offsets, and the checksum adjustment cleared until the whole
		// file exists to be summed.
		$head = substr_replace( $this->table( 'head' ), pack( 'n', 1 ), 50, 2 );
		$head = substr_replace( $head, "\0\0\0\0", 8, 4 );
		$hhea = substr_replace( $this->table( 'hhea' ), pack( 'n', $count ), 34, 2 );
		$maxp = substr_replace( $this->table( 'maxp' ), pack( 'n', $count ), 4, 2 );

		$tables = array(
			'glyf' => $glyf,
			'head' => $head,
			'hhea' => $hhea,
			'hmtx' => $hmtx,
			'loca' => $loca,
			'maxp' => $maxp,
		);

		// A hinted glyph's instructions call functions in fpgm and read
		// values from cvt; dropping those makes a rasteriser that runs the
		// hints fail on every glyph. Small, so kept when present.
		foreach ( array( 'cvt ', 'fpgm', 'prep' ) as $hinting ) {
			if ( isset( $this->tables[ $hinting ] ) ) {
				$tables[ $hinting ] = $this->table( $hinting );
			}
		}

		$font = self::assemble( $tables );

		// The whole file, including this field, must sum to 0xB1B0AFBA.
		$adjustment = ( 0xB1B0AFBA - self::checksum( $font ) ) & 0xFFFFFFFF;
		$position   = strpos( $font, 'head' );
		$at         = self::read32( $font, (int) $position + 8 ) + 8;

		return array( substr_replace( $font, pack( 'N', $adjustment ), $at, 4 ), $mapping );
	}

	/**
	 * Table bytes as the file has them, or empty when it has none.
	 *
	 * @since 1.3.0
	 *
	 * @param string $tag Four-character table tag.
	 *
	 * @return string
	 */
	public function table( string $tag ): string {
		if ( ! isset( $this->tables[ $tag ] ) ) {
			return '';
		}

		return substr( $this->data, $this->tables[ $tag ][0], $this->tables[ $tag ][1] );
	}

	/**
	 * The tags of every table the file has.
	 *
	 * @since 1.3.0
	 *
	 * @return list<string>
	 */
	public function tags(): array {
		return array_keys( $this->tables );
	}

	/**
	 * A font file with only the tables given, the rest dropped.
	 *
	 * What the bundled family was made with: the shaping and kerning
	 * tables (GSUB, GPOS, GDEF) are for engines this writer is not, and
	 * glyph names in `post` are for nobody — together a third of the file.
	 *
	 * @since 1.3.0
	 *
	 * @param list<string>          $keep    Tags to keep.
	 * @param array<string, string> $replace Tag => replacement bytes.
	 *
	 * @return string
	 */
	public function without_tables( array $keep, array $replace = array() ): string {
		$tables = array();

		foreach ( $keep as $tag ) {
			if ( isset( $replace[ $tag ] ) ) {
				$tables[ $tag ] = $replace[ $tag ];
			} elseif ( isset( $this->tables[ $tag ] ) ) {
				$tables[ $tag ] = $this->table( $tag );
			}
		}

		$tables['head'] = substr_replace( $tables['head'] ?? $this->table( 'head' ), "\0\0\0\0", 8, 4 );
		$font           = self::assemble( $tables );
		$at             = self::read32( $font, (int) strpos( $font, 'head' ) + 8 ) + 8;

		return substr_replace( $font, pack( 'N', ( 0xB1B0AFBA - self::checksum( $font ) ) & 0xFFFFFFFF ), $at, 4 );
	}

	/* ─── Reading ───────────────────────────────────────────────────── */

	/**
	 * The table directory.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 *
	 * @throws \InvalidArgumentException When a table lies outside the file.
	 */
	private function directory( string $label ): array {
		$count  = $this->u16( 4 );
		$size   = strlen( $this->data );
		$tables = array();

		if ( 12 + ( $count * 16 ) > $size ) {
			throw new \InvalidArgumentException( $label . ' is cut short: its table directory runs past the end of the file.' );
		}

		for ( $index = 0; $index < $count; $index++ ) {
			$record = 12 + ( $index * 16 );
			$tag    = substr( $this->data, $record, 4 );
			$offset = $this->u32( $record + 8 );
			$length = $this->u32( $record + 12 );

			if ( $offset + $length > $size ) {
				throw new \InvalidArgumentException( $label . ' is damaged: its ' . trim( $tag ) . ' table runs past the end of the file.' );
			}

			$tables[ $tag ] = array( $offset, $length );
		}

		return $tables;
	}

	/**
	 * Refuse a font whose licence bits say it may not be embedded.
	 *
	 * OS/2 `fsType`: bit 1 is "restricted licence embedding", bit 9
	 * "bitmap embedding only", bit 8 "no subsetting" — and a subset is the
	 * only way this embeds anything.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When embedding is not allowed.
	 */
	private function check_licence( string $label ): void {
		if ( ! isset( $this->tables['OS/2'] ) || $this->tables['OS/2'][1] < 10 ) {
			return;
		}

		$type = $this->u16( $this->tables['OS/2'][0] + 8 );

		if ( 0 !== ( $type & 0x0002 ) || 0 !== ( $type & 0x0200 ) ) {
			throw new \InvalidArgumentException( $label . '\'s licence does not allow it to be embedded in a document (OS/2 fsType ' . $type . ').' );
		}

		if ( 0 !== ( $type & 0x0100 ) ) {
			throw new \InvalidArgumentException( $label . '\'s licence does not allow it to be subset, and embedding the whole font in every document is not something this does (OS/2 fsType ' . $type . ').' );
		}
	}

	/**
	 * Glyph offsets into `glyf`, short or long as `head` says.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return list<int>
	 *
	 * @throws \InvalidArgumentException When loca is too short or points outside glyf.
	 */
	private function read_loca( string $label ): array {
		[ $offset, $length ] = $this->tables['loca'];

		$long    = 1 === $this->u16( $this->tables['head'][0] + 50 );
		$entries = $this->glyph_count + 1;

		if ( $length < $entries * ( $long ? 4 : 2 ) ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its loca table has fewer entries than it has glyphs.' );
		}

		$values = array_values( (array) unpack( ( $long ? 'N' : 'n' ) . $entries, $this->data, $offset ) );
		$loca   = array();
		$limit  = $this->tables['glyf'][1];

		foreach ( $values as $value ) {
			$value  = $long ? (int) $value : (int) $value * 2;
			$loca[] = min( $value, $limit );
		}

		return $loca;
	}

	/**
	 * Advance widths and left side bearings for every glyph.
	 *
	 * `hmtx` holds `numberOfHMetrics` full pairs, then a bearing alone for
	 * each glyph after them, which shares the last advance. The bearing is
	 * kept: a rasteriser positions an outline by it, and an italic `f`
	 * whose ink starts left of its origin says so there.
	 *
	 * @since 1.3.0
	 *
	 * @return array{0: list<int>, 1: list<int>}
	 */
	private function read_metrics(): array {
		[ $offset, $length ] = $this->tables['hmtx'];

		$metrics  = max( 1, min( $this->u16( $this->tables['hhea'][0] + 34 ), $this->glyph_count ) );
		$pairs    = min( $metrics, intdiv( $length, 4 ) );
		$advances = array();
		$bearings = array();
		$last     = 0;

		if ( $pairs > 0 ) {
			$values = array_values( (array) unpack( 'n' . ( $pairs * 2 ), $this->data, $offset ) );

			for ( $index = 0; $index < $pairs; $index++ ) {
				$last       = (int) $values[ $index * 2 ];
				$advances[] = $last;
				$bearings[] = self::signed( (int) $values[ ( $index * 2 ) + 1 ] );
			}
		}

		$rest = min( $this->glyph_count - $pairs, intdiv( max( 0, $length - ( $pairs * 4 ) ), 2 ) );

		if ( $rest > 0 ) {
			foreach ( (array) unpack( 'n' . $rest, $this->data, $offset + ( $pairs * 4 ) ) as $bearing ) {
				$advances[] = $last;
				$bearings[] = self::signed( (int) $bearing );
			}
		}

		// A table shorter than it claims: the missing glyphs take the last
		// advance and no bearing, rather than reading past the table.
		while ( count( $advances ) < $this->glyph_count ) {
			$advances[] = $last;
			$bearings[] = 0;
		}

		return array( $advances, $bearings );
	}

	/**
	 * Choose the Unicode character map and read its segments.
	 *
	 * Format 12 covers every plane and is preferred where the font has it;
	 * format 4 covers the Basic Multilingual Plane, which is every
	 * alphabet an invoice is likely to need. Windows and Unicode platform
	 * maps both qualify; the old Macintosh one is not Unicode.
	 *
	 * @since 1.3.0
	 *
	 * @param string $label Font name for errors.
	 *
	 * @return array{0: array{0: int, 1: int}, 1: list<array{0: int, 1: int, 2: int, 3?: int, 4?: int}>}
	 *
	 * @throws \InvalidArgumentException When there is no Unicode map in a supported format.
	 */
	private function read_cmap( string $label ): array {
		[ $offset, $length ] = $this->tables['cmap'];

		$count = $this->u16( $offset + 2 );
		$best  = null;
		$rank  = 0;

		for ( $index = 0; $index < $count && 4 + ( ( $index + 1 ) * 8 ) <= $length; $index++ ) {
			$record   = $offset + 4 + ( $index * 8 );
			$platform = $this->u16( $record );
			$encoding = $this->u16( $record + 2 );
			$at       = $offset + $this->u32( $record + 4 );

			if ( $at + 4 > $offset + $length ) {
				continue;
			}

			$format  = $this->u16( $at );
			$unicode = 0 === $platform || ( 3 === $platform && ( 1 === $encoding || 10 === $encoding ) );

			if ( ! $unicode || ( 4 !== $format && 12 !== $format ) ) {
				continue;
			}

			$score = 12 === $format ? 2 : 1;

			if ( $score > $rank ) {
				$best = array( $format, $at );
				$rank = $score;
			}
		}

		if ( null === $best ) {
			throw new \InvalidArgumentException( $label . ' has no Unicode character map in format 4 or 12, so its characters cannot be found.' );
		}

		$end = $offset + $length;

		return array( $best, 4 === $best[0] ? $this->format_4( $best[1], $end, $label ) : $this->format_12( $best[1], $end, $label ) );
	}

	/**
	 * Segments of a format 4 map.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $at    Subtable offset.
	 * @param int    $end   End of the cmap table.
	 * @param string $label Font name for errors.
	 *
	 * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> End, start, delta, range offset, its position.
	 *
	 * @throws \InvalidArgumentException When the segments run past the table.
	 */
	private function format_4( int $at, int $end, string $label ): array {
		$segments = intdiv( $this->u16( $at + 6 ), 2 );
		$ends     = $at + 14;
		$starts   = $ends + ( $segments * 2 ) + 2;
		$deltas   = $starts + ( $segments * 2 );
		$ranges   = $deltas + ( $segments * 2 );

		if ( $ranges + ( $segments * 2 ) > $end ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its character map runs past its table.' );
		}

		$found = array();

		for ( $index = 0; $index < $segments; $index++ ) {
			$found[] = array(
				$this->u16( $ends + ( $index * 2 ) ),
				$this->u16( $starts + ( $index * 2 ) ),
				$this->u16( $deltas + ( $index * 2 ) ),
				$this->u16( $ranges + ( $index * 2 ) ),
				$ranges + ( $index * 2 ),
			);
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $found;
	}

	/**
	 * Groups of a format 12 map.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $at    Subtable offset.
	 * @param int    $end   End of the cmap table.
	 * @param string $label Font name for errors.
	 *
	 * @return list<array{0: int, 1: int, 2: int}> End, start, first glyph.
	 *
	 * @throws \InvalidArgumentException When the groups run past the table.
	 */
	private function format_12( int $at, int $end, string $label ): array {
		$groups = $this->u32( $at + 12 );

		if ( $at + 16 + ( $groups * 12 ) > $end ) {
			throw new \InvalidArgumentException( $label . ' is damaged: its character map runs past its table.' );
		}

		$found = array();

		for ( $index = 0; $index < $groups; $index++ ) {
			$record  = $at + 16 + ( $index * 12 );
			$found[] = array( $this->u32( $record + 4 ), $this->u32( $record ), $this->u32( $record + 8 ) );
		}

		usort( $found, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $found;
	}

	/**
	 * Look a code point up in the chosen map.
	 *
	 * A binary search for the first segment ending at or after the code,
	 * then the format's own arithmetic. Format 4's is the odd one: a
	 * segment either adds a delta to the code, or points into an array of
	 * glyph IDs by an offset measured from where the offset itself sits.
	 *
	 * @since 1.3.0
	 *
	 * @param int $code Code point.
	 *
	 * @return int Glyph ID, 0 for none.
	 */
	private function lookup( int $code ): int {
		$low  = 0;
		$high = count( $this->segments ) - 1;

		while ( $low < $high ) {
			$middle = intdiv( $low + $high, 2 );

			if ( $this->segments[ $middle ][0] < $code ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		$segment = $this->segments[ $low ] ?? null;

		if ( null === $segment || $segment[0] < $code || $segment[1] > $code ) {
			return 0;
		}

		if ( 12 === $this->cmap[0] ) {
			return $segment[2] + ( $code - $segment[1] );
		}

		[ , $start, $delta, $range, $position ] = $segment + array(
			3 => 0,
			4 => 0,
		);

		if ( 0 === $range ) {
			return ( $code + $delta ) & 0xFFFF;
		}

		$at = $position + $range + ( ( $code - $start ) * 2 );

		if ( $at + 2 > $this->tables['cmap'][0] + $this->tables['cmap'][1] ) {
			return 0;
		}

		$glyph = $this->u16( $at );

		return 0 === $glyph ? 0 : ( $glyph + $delta ) & 0xFFFF;
	}

	/**
	 * Vertical metrics, scaled to 1/1000 em.
	 *
	 * Three tables disagree about an ascender, and each is right about
	 * something: `hhea` has the designer's line spacing, OS/2's `sTypo*`
	 * what a modern layout engine is told to use, `usWin*` the clipping
	 * bounds Windows enforces. OS/2's typographic values win where they
	 * are set, `hhea` otherwise. Cap height and x-height exist from OS/2
	 * version 2; below that they are estimated from the ascender.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function read_vertical_metrics(): void {
		$scale = 1000 / $this->units_per_em;
		$head  = $this->tables['head'][0];
		$hhea  = $this->tables['hhea'][0];

		$ascender   = self::signed( $this->u16( $hhea + 4 ) ) * $scale;
		$descender  = self::signed( $this->u16( $hhea + 6 ) ) * $scale;
		$cap_height = 0.0;
		$x_height   = 0.0;
		$weight     = 400;
		$italic     = false;

		if ( isset( $this->tables['OS/2'] ) && $this->tables['OS/2'][1] >= 78 ) {
			[ $os2, $length ] = $this->tables['OS/2'];

			$weight = $this->u16( $os2 + 4 );
			$italic = 0 !== ( $this->u16( $os2 + 62 ) & 0x0001 );
			$typo_a = self::signed( $this->u16( $os2 + 68 ) ) * $scale;
			$typo_d = self::signed( $this->u16( $os2 + 70 ) ) * $scale;

			$ascender  = 0.0 !== $typo_a ? $typo_a : $ascender;
			$descender = 0.0 !== $typo_d ? $typo_d : $descender;

			if ( $this->u16( $os2 ) >= 2 && $length >= 90 ) {
				$x_height   = self::signed( $this->u16( $os2 + 86 ) ) * $scale;
				$cap_height = self::signed( $this->u16( $os2 + 88 ) ) * $scale;
			}
		}

		// macStyle, for a font whose OS/2 table is missing or wrong.
		$style = $this->u16( $head + 44 );

		$this->ascender     = $ascender > 0 ? $ascender : 800.0;
		$this->descender    = $descender < 0 ? $descender : -200.0;
		$this->cap_height   = $cap_height > 0 ? $cap_height : $this->ascender * 0.92;
		$this->x_height     = $x_height > 0 ? $x_height : $this->cap_height * 0.73;
		$this->weight       = max( 1, min( 1000, $weight ) );
		$this->bold         = 0 !== ( $style & 0x0001 ) || $this->weight >= 600;
		$this->italic       = 0 !== ( $style & 0x0002 ) || $italic;
		$this->bbox         = array(
			self::signed( $this->u16( $head + 36 ) ) * $scale,
			self::signed( $this->u16( $head + 38 ) ) * $scale,
			self::signed( $this->u16( $head + 40 ) ) * $scale,
			self::signed( $this->u16( $head + 42 ) ) * $scale,
		);
		$this->italic_angle = isset( $this->tables['post'] ) && $this->tables['post'][1] >= 16
			? self::signed32( $this->u32( $this->tables['post'][0] + 4 ) ) / 65536
			: 0.0;
		$this->fixed_pitch  = isset( $this->tables['post'] ) && $this->tables['post'][1] >= 16 && 0 !== $this->u32( $this->tables['post'][0] + 12 );
	}

	/**
	 * The PostScript name, cleaned for a PDF name object.
	 *
	 * A PDF name cannot hold spaces or delimiters, and an empty one is no
	 * name at all — so a font with no usable record is called by its
	 * family, then `Embedded`.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function postscript_name(): string {
		foreach ( array( 6, 4, 1 ) as $id ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) $this->name_record( $id ) );

			if ( '' !== $name ) {
				return substr( $name, 0, 63 );
			}
		}

		return 'Embedded';
	}

	/**
	 * A string from the `name` table, preferring the Windows Unicode record.
	 *
	 * @since 1.3.0
	 *
	 * @param int $id Name ID: 1 family, 4 full name, 6 PostScript name.
	 *
	 * @return string|null
	 */
	private function name_record( int $id ): ?string {
		if ( ! isset( $this->tables['name'] ) || $this->tables['name'][1] < 6 ) {
			return null;
		}

		[ $offset, $length ] = $this->tables['name'];

		$count   = $this->u16( $offset + 2 );
		$storage = $offset + $this->u16( $offset + 4 );
		$found   = null;

		for ( $index = 0; $index < $count && 6 + ( ( $index + 1 ) * 12 ) <= $length; $index++ ) {
			$record = $offset + 6 + ( $index * 12 );

			if ( $this->u16( $record + 6 ) !== $id ) {
				continue;
			}

			$platform = $this->u16( $record );
			$size     = $this->u16( $record + 8 );
			$at       = $storage + $this->u16( $record + 10 );

			if ( $at + $size > $offset + $length ) {
				continue;
			}

			$bytes = substr( $this->data, $at, $size );

			if ( 3 === $platform || 0 === $platform ) {
				return trim( (string) mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16BE' ) );
			}

			if ( 1 === $platform ) {
				$found ??= trim( (string) preg_replace( '/[^\x20-\x7E]/', '', $bytes ) );
			}
		}

		return $found;
	}

	/**
	 * A glyph's outline bytes, empty for a glyph with none (a space).
	 *
	 * @since 1.3.0
	 *
	 * @param int $glyph Glyph ID.
	 *
	 * @return string
	 */
	private function outline( int $glyph ): string {
		if ( $glyph < 0 || $glyph >= $this->glyph_count ) {
			return '';
		}

		$start = $this->loca[ $glyph ];
		$end   = $this->loca[ $glyph + 1 ];

		return $end > $start ? substr( $this->data, $this->tables['glyf'][0] + $start, $end - $start ) : '';
	}

	/**
	 * Where each component's glyph ID sits in a composite outline.
	 *
	 * After the 10-byte header, each component is flags, a glyph ID, two
	 * arguments (bytes or words, as flag 0x0001 says), then a scale of
	 * none, one, two or four values (0x0008, 0x0040, 0x0080). Flag 0x0020
	 * says another component follows.
	 *
	 * @since 1.3.0
	 *
	 * @param string $outline Glyph bytes.
	 *
	 * @return list<int> Byte offsets of the glyph IDs; empty for a simple glyph.
	 */
	private static function component_offsets( string $outline ): array {
		$length = strlen( $outline );

		if ( $length < 10 || self::signed( self::read16( $outline, 0 ) ) >= 0 ) {
			return array();
		}

		$found  = array();
		$cursor = 10;

		while ( $cursor + 4 <= $length ) {
			$flags   = self::read16( $outline, $cursor );
			$found[] = $cursor + 2;
			$cursor += 4 + ( 0 !== ( $flags & 0x0001 ) ? 4 : 2 );

			if ( 0 !== ( $flags & 0x0008 ) ) {
				$cursor += 2;
			} elseif ( 0 !== ( $flags & 0x0040 ) ) {
				$cursor += 4;
			} elseif ( 0 !== ( $flags & 0x0080 ) ) {
				$cursor += 8;
			}

			if ( 0 === ( $flags & 0x0020 ) ) {
				break;
			}
		}

		return $found;
	}

	/* ─── Writing ───────────────────────────────────────────────────── */

	/**
	 * An sfnt file from its tables, sorted by tag as readers expect.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, string> $tables Tag => bytes.
	 *
	 * @return string
	 */
	private static function assemble( array $tables ): string {
		ksort( $tables, SORT_STRING );

		$count    = count( $tables );
		$power    = 1;
		$selector = 0;

		while ( $power * 2 <= $count ) {
			$power *= 2;
			++$selector;
		}

		$range     = $power * 16;
		$header    = pack( 'Nnnnn', 0x00010000, $count, $range, $selector, ( $count * 16 ) - $range );
		$directory = '';
		$body      = '';
		$offset    = 12 + ( $count * 16 );

		foreach ( $tables as $tag => $bytes ) {
			$padded     = $bytes . str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
			$directory .= str_pad( $tag, 4 ) . pack( 'NNN', self::checksum( $padded ), $offset, strlen( $bytes ) );
			$body      .= $padded;
			$offset    += strlen( $padded );
		}

		return $header . $directory . $body;
	}

	/**
	 * A table checksum: the sum of its big-endian 32-bit words.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes Bytes, padded with zeros to a multiple of four.
	 *
	 * @return int
	 */
	private static function checksum( string $bytes ): int {
		$bytes .= str_repeat( "\0", ( 4 - ( strlen( $bytes ) % 4 ) ) % 4 );
		$sum    = 0;

		foreach ( str_split( $bytes, 32768 ) as $chunk ) {
			foreach ( (array) unpack( 'N*', $chunk ) as $word ) {
				$sum = ( $sum + (int) $word ) & 0xFFFFFFFF;
			}
		}

		return $sum;
	}

	/* ─── Bytes ─────────────────────────────────────────────────────── */

	/**
	 * An unsigned 16-bit value from the file, 0 past its end.
	 *
	 * @since 1.3.0
	 *
	 * @param int $offset Byte offset.
	 *
	 * @return int
	 */
	private function u16( int $offset ): int {
		return self::read16( $this->data, $offset );
	}

	/**
	 * An unsigned 32-bit value from the file, 0 past its end.
	 *
	 * @since 1.3.0
	 *
	 * @param int $offset Byte offset.
	 *
	 * @return int
	 */
	private function u32( int $offset ): int {
		return self::read32( $this->data, $offset );
	}

	/**
	 * An unsigned 16-bit big-endian value, 0 past the end.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes  Bytes.
	 * @param int    $offset Byte offset.
	 *
	 * @return int
	 */
	private static function read16( string $bytes, int $offset ): int {
		if ( $offset < 0 || $offset + 2 > strlen( $bytes ) ) {
			return 0;
		}

		return ( ord( $bytes[ $offset ] ) << 8 ) | ord( $bytes[ $offset + 1 ] );
	}

	/**
	 * An unsigned 32-bit big-endian value, 0 past the end.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes  Bytes.
	 * @param int    $offset Byte offset.
	 *
	 * @return int
	 */
	private static function read32( string $bytes, int $offset ): int {
		if ( $offset < 0 || $offset + 4 > strlen( $bytes ) ) {
			return 0;
		}

		return (int) unpack( 'N', $bytes, $offset )[1];
	}

	/**
	 * A 16-bit value read as signed — an FWord.
	 *
	 * Descenders are negative: read unsigned, -220 becomes 65316 and every
	 * line acquires a 65-em gap.
	 *
	 * @since 1.3.0
	 *
	 * @param int $value 0-65535.
	 *
	 * @return int
	 */
	private static function signed( int $value ): int {
		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	/**
	 * A 32-bit value read as signed.
	 *
	 * @since 1.3.0
	 *
	 * @param int $value 0-4294967295.
	 *
	 * @return int
	 */
	private static function signed32( int $value ): int {
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}
}
