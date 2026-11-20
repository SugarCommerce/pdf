<?php
/**
 * PDF document builder.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

use SugarCommerce\Pdf\Fonts\{FontSubset, TrueType};
use SugarCommerce\Pdf\Images\Svg;
use SugarCommerce\Pdf\Output\Writer;
use SugarCommerce\Qr\QR;

/**
 * Class Document
 *
 * Builds invoices, statements and reports — text, tables, rules, boxes,
 * vector logos and pictures ({@see Image}) — with no dependencies.
 *
 * Text is set in the base-14 fonts every reader is required to have, so
 * a document in a Western European language is a few kilobytes. Real
 * font metrics are carried (see {@see Font}), which is what makes
 * right-aligned money columns and measured table layout possible at all —
 * padding with spaces only lines up in a monospace font.
 *
 * Those fonts know Windows-1252 and nothing else. A word they cannot
 * print as written — `Łódź`, `Ярослав`, `Ελένη` — is drawn in an embedded
 * {@see FontFamily} instead, the bundled Noto Sans unless another is set
 * with {@see fallback()}, and only the glyphs drawn are embedded. A
 * document can also be set in a family throughout ({@see family()}),
 * which then draws everything it can and leaves the base fonts as the
 * fallback.
 *
 * **What it cannot do.** Right-to-left and shaped scripts — Arabic,
 * Hebrew, the Indic scripts — need a shaping engine to reorder letters
 * and join them, which this is not. They are printed as `?` and reported
 * by {@see substitutions()} rather than drawn in the wrong order. So is
 * anything no font in reach has a glyph for.
 *
 * @since 1.0.0
 */
final class Document {

	/**
	 * Completed page content streams.
	 *
	 * @var list<string>
	 */
	private array $pages = array();

	/**
	 * The stream currently being written.
	 */
	private string $current = '';

	/**
	 * Vertical position, in points from the page bottom.
	 */
	private float $y;

	/**
	 * Page width in points, after orientation is applied.
	 */
	private readonly float $page_width;

	/**
	 * Page height in points, after orientation is applied.
	 */
	private readonly float $page_height;

	/**
	 * Callback drawing a footer on every page.
	 *
	 * @var (callable(Document, int, int): mixed)|null
	 */
	private $footer = null;

	/**
	 * Every run of text drawn, as a reader will extract it, in the order
	 * it was drawn.
	 *
	 * Kept so a template's own checks can ask what actually reached the
	 * page rather than what was in the data. On a document whose validity
	 * depends on certain words appearing, only the first question is
	 * worth answering.
	 *
	 * @var list<string>
	 */
	private array $drawn = array();

	/**
	 * Characters already transcoded, for text measured and drawn again.
	 *
	 * @var array<string, string>
	 */
	private static array $transcoded = array();

	/**
	 * Height kept clear above the bottom margin, for a footer.
	 */
	private float $clear = 0.0;

	/**
	 * The document's language, as a BCP 47 tag, or empty.
	 */
	private string $language = '';

	/**
	 * The family the document is set in, or null for the base fonts.
	 */
	private ?FontFamily $family = null;

	/**
	 * The family for words the base fonts cannot print; null for none.
	 */
	private ?FontFamily $fallback = null;

	/**
	 * Whether {@see fallback()} has been called; until it is, the bundled
	 * family is the fallback, read the first time a word needs it.
	 */
	private bool $fallback_chosen = false;

	/**
	 * What each embedded font has drawn, by the font's object ID.
	 *
	 * @var array<int, FontSubset>
	 */
	private array $subsets = array();

	/**
	 * Font resources in the order first used: a base font, or an embedded
	 * font by its object ID.
	 *
	 * @var array<string, Font|int>
	 */
	private array $resources = array();

	/**
	 * Text that did not reach the page as written.
	 *
	 * @var list<string>
	 */
	private array $substituted = array();

	/**
	 * Which font draws each word, by role, for words seen before.
	 *
	 * @var array<string, list<array{0: TrueType|null, 1: string, 2: bool}>>
	 */
	private array $resolved = array();

	/**
	 * Images drawn, by resource name, in the order first drawn.
	 *
	 * @var array<string, Image>
	 */
	private array $images = array();

	/**
	 * Images that were not drawn, or not drawn whole, one sentence each.
	 *
	 * @var list<string>
	 */
	private array $image_warnings = array();

	/**
	 * Whether the file is written as PDF/A-3b.
	 */
	private bool $archival = false;

	/**
	 * The family a PDF/A document is set in when none was chosen.
	 */
	private ?FontFamily $archival_family = null;

	/**
	 * Files carried inside the document, by name.
	 *
	 * @var array<string, Attachment>
	 */
	private array $attachments = array();

	/**
	 * Extra `rdf:Description` elements for the XMP packet.
	 *
	 * @var list<string>
	 */
	private array $xmp = array();

	/**
	 * Start an empty document with the cursor at the top margin.
	 *
	 * @since 1.0.0
	 *
	 * @param PageSize    $size        Paper size. A4 by default, since
	 *                                 everywhere outside North America
	 *                                 uses it and a Letter layout prints
	 *                                 clipped on A4.
	 * @param Orientation $orientation Portrait or landscape.
	 * @param float       $margin      Margin on all sides, in points.
	 * @param float       $font_size   Default body size.
	 * @param float       $leading     Line height.
	 */
	public function __construct(
		public readonly PageSize $size = PageSize::A4,
		public readonly Orientation $orientation = Orientation::Portrait,
		public readonly float $margin = 56.0,
		public readonly float $font_size = 10.0,
		public readonly float $leading = 14.0,
	) {
		[ $width, $height ] = $size->dimensions();

		$this->page_width  = Orientation::Landscape === $orientation ? $height : $width;
		$this->page_height = Orientation::Landscape === $orientation ? $width : $height;
		$this->y           = $this->page_height - $margin;
	}

	/* ─── Geometry ──────────────────────────────────────────────────── */

	/**
	 * Width of the printable column.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function content_width(): float {
		return $this->page_width - ( $this->margin * 2 );
	}

	/**
	 * Left edge of the printable column.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function left(): float {
		return $this->margin;
	}

	/**
	 * Right edge of the printable column.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function right(): float {
		return $this->page_width - $this->margin;
	}

	/**
	 * Page height in points.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function height(): float {
		return $this->page_height;
	}

	/**
	 * Current vertical position.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function cursor(): float {
		return $this->y;
	}

	/**
	 * Space left before the bottom margin.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function remaining(): float {
		return $this->y - $this->margin - $this->clear;
	}

	/**
	 * The height a fresh page gives the flow.
	 *
	 * From the top margin down to the bottom margin, less the band kept
	 * clear for a footer: the most any one block can have.
	 *
	 * @since 1.2.0
	 *
	 * @return float
	 */
	public function page_room(): float {
		return $this->page_height - ( $this->margin * 2 ) - $this->clear;
	}

	/**
	 * Keep a band above the bottom margin clear of the flow.
	 *
	 * For a footer drawn in it: a footnote's rule sits above the margin,
	 * and text flowed down to the margin runs through it. Everything that
	 * breaks a page — {@see break_if_needed()}, {@see text()},
	 * {@see block()}, a {@see Table} — breaks that much earlier.
	 *
	 * @since 1.2.0
	 *
	 * @param float $points Height of the band. Negative is taken as none.
	 *
	 * @return self
	 */
	public function keep_clear( float $points ): self {
		$this->clear = max( 0.0, self::number( $points ) );

		return $this;
	}

	/**
	 * Say what language the document is in.
	 *
	 * Written to the catalogue as `/Lang`, which is what a screen reader
	 * uses to pick a voice, and what PDF/A and PDF/UA validators look for.
	 * Anything that is not a well-formed language tag is ignored rather
	 * than written: the value goes into the file as a string.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tag BCP 47 tag, e.g. `en`, `de-AT`. `de_AT` is read
	 *                    as `de-AT`.
	 *
	 * @return self
	 */
	public function language( string $tag ): self {
		$tag = str_replace( '_', '-', trim( $tag ) );

		$this->language = 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $tag ) ? $tag : '';

		return $this;
	}

	/**
	 * The document's language tag, or empty when none was set.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function language_tag(): string {
		return $this->language;
	}

	/* ─── Type ──────────────────────────────────────────────────────── */

	/**
	 * Set the document in a family of embedded fonts.
	 *
	 * Every role is drawn in the family's face for it — Helvetica as the
	 * regular, Helvetica-Bold as the bold — so a layout written for the
	 * base fonts is set in the family unchanged. A word the family has no
	 * glyphs for falls back to the base fonts, then to {@see fallback()}.
	 *
	 * Set it before drawing: text already on the page stays as it was
	 * drawn, and measuring done before it was set was done in Helvetica.
	 *
	 * @since 1.3.0
	 *
	 * @param FontFamily|null $family The family, or null for the base fonts.
	 *
	 * @return self
	 */
	public function family( ?FontFamily $family ): self {
		$this->family   = $family;
		$this->resolved = array();

		return $this;
	}

	/**
	 * The family the document is set in, or null.
	 *
	 * @since 1.3.0
	 *
	 * @return FontFamily|null
	 */
	public function font_family(): ?FontFamily {
		return $this->family;
	}

	/* ─── Archiving ─────────────────────────────────────────────────── */

	/**
	 * Write the file as PDF/A-3b: archivable, and allowed to carry files.
	 *
	 * The level Factur-X and ZUGFeRD e-invoices are built on. What it
	 * changes:
	 *
	 * - **Every font is embedded.** The base-14 fonts belong to the reader,
	 *   so they cannot be used at all: text is set in the document's
	 *   {@see family()}, or the bundled Noto Sans when it has none, and
	 *   monospaced text in that family's regular face when it has no
	 *   monospaced one. A character no font in reach has prints as `?` in
	 *   the family and is listed by {@see substitutions()}, as before.
	 * - **Colour is sRGB.** The file carries an sRGB output intent (a
	 *   456-byte CC0 profile, `icc/`), and a CMYK JPEG cannot be drawn
	 *   under it: {@see image()} refuses one and says so in
	 *   {@see image_warnings()}. Convert it to RGB.
	 * - **The file says what it is.** An XMP metadata packet that agrees
	 *   with the information dictionary and declares PDF/A-3 part B, a
	 *   file identifier made from the content, and a `%PDF-1.7` header.
	 *   Every file {@see attach()}ed is listed as an associated file with
	 *   its relationship.
	 *
	 * Set it before drawing anything: what is drawn is set in the fonts
	 * the mode allows.
	 *
	 * @since 1.5.0
	 *
	 * @return self
	 *
	 * @throws \LogicException When something has already been drawn.
	 */
	public function archival(): self {
		if ( array() !== $this->pages || '' !== $this->current || array() !== $this->resources || array() !== $this->images ) {
			throw new \LogicException( 'Make a document archival before drawing on it: what is already drawn may be set in fonts a PDF/A file cannot carry.' );
		}

		$this->archival = true;
		$this->resolved = array();

		return $this;
	}

	/**
	 * Whether the file is written as PDF/A-3b.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_archival(): bool {
		return $this->archival;
	}

	/**
	 * Carry a file inside the document.
	 *
	 * A reader lists it and offers to save it out. In a PDF/A-3 it is also
	 * an associated file of the document, with its relationship — which
	 * is how a Factur-X reader finds the invoice XML. A second file under
	 * the same name replaces the first.
	 *
	 * @since 1.5.0
	 *
	 * @param Attachment $file The file.
	 *
	 * @return self
	 */
	public function attach( Attachment $file ): self {
		$this->attachments[ $file->name ] = $file;

		return $this;
	}

	/**
	 * The files the document carries, in the order attached.
	 *
	 * @since 1.5.0
	 *
	 * @return list<Attachment>
	 */
	public function attachments(): array {
		return array_values( $this->attachments );
	}

	/**
	 * Add an `rdf:Description` to the XMP packet of a PDF/A file.
	 *
	 * PDF/A allows only the XMP properties a file declares a schema for,
	 * so a description using a schema of its own must come with its
	 * extension schema — Factur-X's `fx:` properties are the case in mind.
	 * Written verbatim: it must be well-formed RDF/XML.
	 *
	 * @since 1.5.0
	 *
	 * @param string $description One or more `rdf:Description` elements.
	 *
	 * @return self
	 */
	public function xmp( string $description ): self {
		$this->xmp[] = $description;

		return $this;
	}

	/**
	 * Choose the family for words the base fonts cannot print.
	 *
	 * The bundled Noto Sans until this is called, which covers Latin,
	 * Greek and Cyrillic. Pass another to cover more — a Noto CJK face
	 * for Japanese, say — or null to embed nothing and print what
	 * Windows-1252 lacks as its nearest letter or `?`, as before 1.3.0.
	 *
	 * @since 1.3.0
	 *
	 * @param FontFamily|null $family The fallback, or null for none.
	 *
	 * @return self
	 */
	public function fallback( ?FontFamily $family ): self {
		$this->fallback        = $family;
		$this->fallback_chosen = true;
		$this->resolved        = array();

		return $this;
	}

	/**
	 * Every run of text that did not reach the page as written.
	 *
	 * A character no font in reach has, or one in a script this cannot
	 * lay out (Arabic, Hebrew, the Indic scripts), is printed as `?` or
	 * its nearest letter. That looks like a typo rather than a fault on
	 * the page, so it is listed here for a template to warn about.
	 *
	 * @since 1.3.0
	 *
	 * @return list<string>
	 */
	public function substitutions(): array {
		return $this->substituted;
	}

	/**
	 * Distance from the top of a line box down to the baseline.
	 *
	 * The base font's, or the family's face's for the role. A face's own
	 * ascender is not used: fonts reserve room above the capitals for
	 * accents and stacked marks — Noto Sans a full 1.07 em — and a layout
	 * built on Helvetica's line boxes would move every line down a third
	 * of an em. Instead the face gets the headroom Helvetica has above
	 * its capitals, so text sits in a box the same way whichever face
	 * draws it.
	 *
	 * @since 1.3.0
	 *
	 * @param float|null $size Font size, or null for the document default.
	 * @param Font       $font Role.
	 *
	 * @return float
	 */
	public function ascender( ?float $size = null, Font $font = Font::Helvetica ): float {
		$size ??= $this->font_size;
		$face   = $this->primary( $font );

		return null === $face ? $font->ascender( $size ) : $size * ( $face->cap_height + 63 ) / 1000;
	}

	/**
	 * The height of a capital letter, in the face that draws the role.
	 *
	 * @since 1.3.0
	 *
	 * @param float|null $size Font size, or null for the document default.
	 * @param Font       $font Role.
	 *
	 * @return float
	 */
	public function cap_height( ?float $size = null, Font $font = Font::Helvetica ): float {
		$size ??= $this->font_size;
		$face   = $this->primary( $font );

		return null === $face ? $font->cap_height( $size ) : $size * $face->cap_height / 1000;
	}

	/**
	 * How far the descender falls below the baseline, in the face that
	 * draws the role.
	 *
	 * @since 1.3.0
	 *
	 * @param float|null $size Font size, or null for the document default.
	 * @param Font       $font Role.
	 *
	 * @return float
	 */
	public function descender( ?float $size = null, Font $font = Font::Helvetica ): float {
		$size ??= $this->font_size;
		$face   = $this->primary( $font );

		return null === $face ? $font->descender( $size ) : $size * abs( $face->descender ) / 1000;
	}

	/**
	 * Baseline offset for text vertically centred in a band: equal space
	 * above the capitals and below the baseline.
	 *
	 * @since 1.3.0
	 *
	 * @param float      $band_height Height of the band.
	 * @param float|null $size        Font size, or null for the document default.
	 * @param Font       $font        Role.
	 *
	 * @return float Distance from the band top down to the baseline.
	 */
	public function baseline_in_band( float $band_height, ?float $size = null, Font $font = Font::Helvetica ): float {
		return ( $band_height + $this->cap_height( $size, $font ) ) / 2;
	}

	/**
	 * Move the cursor down.
	 *
	 * @since 1.0.0
	 *
	 * @param float $points Distance.
	 *
	 * @return self
	 */
	public function gap( float $points ): self {
		$this->y -= $points;

		return $this;
	}

	/**
	 * Move the cursor to an absolute position.
	 *
	 * @since 1.0.0
	 *
	 * @param float $y Points from the page bottom.
	 *
	 * @return self
	 */
	public function move_to( float $y ): self {
		$this->y = $y;

		return $this;
	}

	/* ─── Pages ─────────────────────────────────────────────────────── */

	/**
	 * Start a new page.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public function page_break(): self {
		$this->pages[] = $this->current;
		$this->current = '';
		$this->y       = $this->page_height - $this->margin;

		return $this;
	}

	/**
	 * Break only if the next block would not fit.
	 *
	 * @since 1.0.0
	 *
	 * @param float $needed Height required.
	 *
	 * @return bool Whether a break happened.
	 */
	public function break_if_needed( float $needed ): bool {
		if ( $this->remaining() >= $needed ) {
			return false;
		}

		$this->page_break();

		return true;
	}

	/**
	 * Register a footer, drawn on every page at render time.
	 *
	 * Deferred rather than drawn per page because "Page 2 of 7" is not
	 * knowable until the seventh page exists.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(Document, int, int): mixed $callback Receives
	 *                                                     ( Document, int $page, int $total ).
	 *
	 * @return self
	 */
	public function on_each_page( callable $callback ): self {
		$this->footer = $callback;

		return $this;
	}

	/**
	 * Pages created so far.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function page_count(): int {
		return count( $this->pages ) + ( '' !== $this->current ? 1 : 0 );
	}

	/* ─── Text ──────────────────────────────────────────────────────── */

	/**
	 * Write a line and advance the cursor.
	 *
	 * Wraps to the content width and paginates.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text  Text.
	 * @param float|null $size  Font size, or null for the document default.
	 * @param Font       $font  Typeface.
	 * @param Color|null $color Colour.
	 * @param Align      $align Alignment within the content column.
	 *
	 * @return self
	 */
	public function text(
		string $text,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		foreach ( $this->wrap( $text, $font, $size, $this->content_width() ) as $line ) {
			$this->break_if_needed( $this->leading );

			// The cursor marks the TOP of the line box; PDF positions
			// text by its baseline. Drawing at the cursor puts ascenders
			// above the margin and through anything drawn above.
			$this->text_at(
				$line,
				$this->margin,
				$this->y - $this->ascender( $size, $font ),
				$size,
				$font,
				$color,
				$align,
				$this->content_width()
			);

			$this->y -= $this->leading;
		}

		return $this;
	}

	/**
	 * Write a heading.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text  Text.
	 * @param float|null $size  Font size.
	 * @param Color|null $color Colour.
	 *
	 * @return self
	 */
	public function heading( string $text, ?float $size = null, ?Color $color = null ): self {
		return $this->text( $text, $size ?? $this->font_size + 2, Font::HelveticaBold, $color );
	}

	/**
	 * Write text at a horizontal offset on the current line, without
	 * advancing the cursor.
	 *
	 * Baseline handling is done for you — pass the column position and
	 * the width to align within.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text      Text.
	 * @param float      $x         Left edge of the box.
	 * @param float      $box_width Width to align within.
	 * @param float|null $size      Font size.
	 * @param Font       $font      Typeface.
	 * @param Color|null $color     Colour.
	 * @param Align      $align     Alignment.
	 *
	 * @return self
	 */
	public function cell(
		string $text,
		float $x,
		float $box_width,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		return $this->text_at( $text, $x, $this->y - $this->ascender( $size, $font ), $size, $font, $color, $align, $box_width );
	}

	/**
	 * Write text vertically centred in a band starting at the cursor.
	 *
	 * The band runs from the cursor down by `$band_height`. Use it for
	 * anything drawn on a filled rectangle — totals boxes, table rows,
	 * header strips — so the text sits optically centred rather than
	 * pinned to the top.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text        Text.
	 * @param float      $x           Left edge of the box.
	 * @param float      $box_width   Width to align within.
	 * @param float      $band_height Height of the band.
	 * @param float|null $size        Font size.
	 * @param Font       $font        Typeface.
	 * @param Color|null $color       Colour.
	 * @param Align      $align       Alignment.
	 *
	 * @return self
	 */
	public function banded(
		string $text,
		float $x,
		float $box_width,
		float $band_height,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left
	): self {
		$size ??= $this->font_size;

		return $this->text_at(
			$text,
			$x,
			$this->y - $this->baseline_in_band( $band_height, $size, $font ),
			$size,
			$font,
			$color,
			$align,
			$box_width
		);
	}

	/**
	 * Write text at an absolute position, leaving the cursor alone.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text      Text.
	 * @param float      $x         Left edge of the box.
	 * @param float      $y         Baseline.
	 * @param float      $size      Font size.
	 * @param Font       $font      Typeface.
	 * @param Color|null $color     Colour.
	 * @param Align      $align     Alignment within `$box_width`.
	 * @param float      $box_width Width to align within.
	 *
	 * @return self
	 */
	public function text_at(
		string $text,
		float $x,
		float $y,
		float $size,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left,
		float $box_width = 0.0
	): self {
		if ( '' === $text ) {
			return $this;
		}

		$size    = self::number( $size );
		$pieces  = $this->pieces( $text, $font );
		$drawn   = '';
		$measure = 0.0;
		$show    = '';
		$current = '';
		$lossy   = false;

		foreach ( $pieces as [ $face, $piece, $replaced ] ) {
			$lossy = $lossy || $replaced;

			if ( null === $face ) {
				$bytes = self::winansi( $piece );

				if ( '' === $bytes ) {
					continue;
				}

				$resource  = $this->resource( $font );
				$operand   = '(' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $bytes ) . ')';
				$measure  += $font->width_of( $bytes, $size );
				$drawn    .= (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
			} else {
				$resource  = $this->resource( $face );
				$operand   = '<' . $this->subsets[ spl_object_id( $face ) ]->encode( $piece ) . '>';
				$measure  += $face->width_of( $piece, $size );
				$drawn    .= $piece;
			}

			// One text object for the whole run, switching font only where
			// the face changes: each show advances from where the last
			// ended, so the pieces sit together as one line.
			if ( $resource !== $current ) {
				$show   .= sprintf( "/%s %.2F Tf\n", $resource, $size );
				$current = $resource;
			}

			$show .= $operand . " Tj\n";
		}

		if ( '' === $show ) {
			return $this;
		}

		// What a reader will extract, not what was asked for: a name the
		// fonts could not hold has not reached the page as written.
		$this->drawn[] = $drawn;

		if ( $lossy && ! in_array( $text, $this->substituted, true ) ) {
			$this->substituted[] = $text;
		}

		// Alignment is computed from the measured width — the reason the
		// font metrics exist at all. The escaping backslashes are never
		// drawn, so they are not measured.
		if ( Align::Left !== $align && $box_width > 0 ) {
			$x += Align::Right === $align ? $box_width - $measure : ( $box_width - $measure ) / 2;
		}

		$color ??= Color::black();

		$this->current .= "q\n";

		if ( ! $color->is_black() ) {
			$this->current .= $color->operands() . " rg\n";
		}

		// The position goes after the first font is chosen, as it always
		// has: one Tf, one Td, one Tj for a run in a single font.
		$position       = sprintf( "%.2F %.2F Td\n", self::number( $x ), self::number( $y ) );
		$this->current .= "BT\n" . self::positioned( $show, $position ) . "ET\nQ\n";

		return $this;
	}

	/**
	 * Font selections and shows with the text position put in after the
	 * first selection.
	 *
	 * @since 1.3.0
	 *
	 * @param string $show     Font selections and shows.
	 * @param string $position The `Td` line.
	 *
	 * @return string
	 */
	private static function positioned( string $show, string $position ): string {
		$at = (int) strpos( $show, "Tf\n" ) + 3;

		return substr( $show, 0, $at ) . $position . substr( $show, $at );
	}

	/**
	 * Write a row of columns across the content width.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $cells  Cell text.
	 * @param array<int, float>  $widths Column widths. Empty splits evenly.
	 * @param array<int, Align>  $aligns Per-column alignment.
	 * @param Font               $font   Typeface.
	 * @param float|null         $size   Font size.
	 * @param Color|null         $color  Colour.
	 *
	 * @return self
	 */
	public function columns(
		array $cells,
		array $widths = array(),
		array $aligns = array(),
		Font $font = Font::Helvetica,
		?float $size = null,
		?Color $color = null
	): self {
		$size ??= $this->font_size;
		$cells  = array_values( $cells );
		$count  = count( $cells );

		if ( 0 === $count ) {
			return $this;
		}

		if ( array() === $widths ) {
			$widths = array_fill( 0, $count, $this->content_width() / $count );
		}

		$this->break_if_needed( $this->leading );

		$x = $this->margin;

		foreach ( $cells as $index => $cell ) {
			$column = $widths[ $index ] ?? ( $this->content_width() / $count );

			$this->text_at(
				$this->fit( (string) $cell, $column - 6, $size, $font ),
				$x,
				$this->y - $this->ascender( $size, $font ),
				$size,
				$font,
				$color,
				$aligns[ $index ] ?? Align::Left,
				$column - 6
			);

			$x += $column;
		}

		$this->y -= $this->leading;

		return $this;
	}

	/**
	 * Draw wrapped text in a column, advancing the cursor.
	 *
	 * Where {@see cell()} draws one line and anything past the box edge is
	 * simply not there — right for a table cell cut short on purpose,
	 * wrong for a note somebody wrote — this breaks the text into lines
	 * that fit, keeps the newlines it was given, and starts a new page
	 * when the next line would go below the bottom margin.
	 *
	 * The cursor marks the top of the first line and ends one line's
	 * leading below the last, as {@see text()} does.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text    Text; `\n` starts a new line.
	 * @param float      $x       Left edge of the column.
	 * @param float      $width   Column width.
	 * @param float|null $size    Font size, or null for the document default.
	 * @param Font       $font    Typeface.
	 * @param Color|null $color   Colour.
	 * @param Align      $align   Alignment of each line within the column.
	 * @param float|null $leading Distance between baselines; 1.35 times the
	 *                            size when null.
	 *
	 * @return self
	 */
	public function block(
		string $text,
		float $x,
		float $width,
		?float $size = null,
		Font $font = Font::Helvetica,
		?Color $color = null,
		Align $align = Align::Left,
		?float $leading = null
	): self {
		$size  ??= $this->font_size;
		$step    = $leading ?? $size * 1.35;

		foreach ( $this->wrap( $text, $font, $size, $width ) as $line ) {
			$this->break_if_needed( $step );

			if ( '' !== $line ) {
				$this->text_at( $line, $x, $this->y - $this->ascender( $size, $font ), $size, $font, $color, $align, $width );
			}

			$this->y -= $step;
		}

		return $this;
	}

	/**
	 * The height {@see block()} would take, without drawing anything.
	 *
	 * For sizing a panel, or keeping a block on one page, before its
	 * contents go in.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text    Text.
	 * @param float      $width   Column width.
	 * @param float|null $size    Font size.
	 * @param Font       $font    Typeface.
	 * @param float|null $leading Distance between baselines.
	 *
	 * @return float
	 */
	public function block_height( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica, ?float $leading = null ): float {
		$size ??= $this->font_size;

		return count( $this->wrap( $text, $font, $size, $width ) ) * ( $leading ?? $size * 1.35 );
	}

	/**
	 * Text broken into the lines that fit a width.
	 *
	 * The same breaks {@see block()} makes: at spaces, at the newlines
	 * given, and through a word wider than the column.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text  Text.
	 * @param float      $width Column width.
	 * @param float|null $size  Font size.
	 * @param Font       $font  Typeface.
	 *
	 * @return list<string>
	 */
	public function lines( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica ): array {
		return $this->wrap( $text, $font, $size ?? $this->font_size, $width );
	}

	/**
	 * The width of text as this document will draw it.
	 *
	 * In the face each word will be drawn in — the family's, a base font,
	 * or the fallback — and after transcoding, so `€` in Helvetica counts
	 * as the one character it prints as rather than the three bytes it is
	 * in UTF-8.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text Text.
	 * @param float|null $size Font size.
	 * @param Font       $font Typeface.
	 *
	 * @return float
	 */
	public function width( string $text, ?float $size = null, Font $font = Font::Helvetica ): float {
		$size ??= $this->font_size;
		$total  = 0.0;

		foreach ( $this->pieces( $text, $font ) as [ $face, $piece ] ) {
			$total += null === $face ? $font->width_of( self::winansi( $piece ), $size ) : $face->width_of( $piece, $size );
		}

		return $total;
	}

	/**
	 * Text shortened with an ellipsis to fit a width, measured as drawn.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text  Text.
	 * @param float      $width Available width.
	 * @param float|null $size  Font size.
	 * @param Font       $font  Typeface.
	 *
	 * @return string The text unchanged when it fits.
	 */
	public function fit( string $text, float $width, ?float $size = null, Font $font = Font::Helvetica ): string {
		$size ??= $this->font_size;

		if ( $this->width( $text, $size, $font ) <= $width ) {
			return $text;
		}

		$budget = $width - $this->width( '...', $size, $font );
		$kept   = '';
		$used   = 0.0;

		foreach ( $this->advances( $text, $font, $size ) as [ $character, $advance ] ) {
			$used += $advance;

			if ( $used > $budget ) {
				break;
			}

			$kept .= $character;
		}

		// Checked as drawn, as a cut piece is: the shortened last word may
		// resolve to another face than the whole word was measured in.
		while ( '' !== $kept && $this->width( rtrim( $kept ) . '...', $size, $font ) > $width ) {
			$kept = mb_substr( $kept, 0, -1, 'UTF-8' );
		}

		return rtrim( $kept ) . '...';
	}

	/**
	 * Every run of text drawn so far, as a reader will extract it.
	 *
	 * The characters as they reached the page: a word drawn in an embedded
	 * font is recorded as written, one the base fonts printed is recorded
	 * as they printed it — `?` for a character nothing could draw. It
	 * includes what the footer draws on each page.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string>
	 */
	public function drawn_text(): array {
		return $this->with_footers()[0];
	}

	/**
	 * What the footers would add, asked without drawing them for good.
	 *
	 * @since 1.4.0
	 *
	 * @return array{0: list<string>, 1: list<string>} The drawn text and the
	 *                                                 image warnings, footers
	 *                                                 included.
	 */
	private function with_footers(): array {
		if ( null === $this->footer ) {
			return array( $this->drawn, $this->image_warnings );
		}

		// The footers are drawn to be read, not kept: the glyphs, fonts and
		// images they use are put back too, or asking would change the file.
		$saved          = $this->drawn;
		$resources      = $this->resources;
		$substituted    = $this->substituted;
		$subsets        = array_map( static fn( FontSubset $subset ): FontSubset => clone $subset, $this->subsets );
		$images         = $this->images;
		$image_warnings = $this->image_warnings;
		$pages          = $this->pages;
		$pages[]        = $this->current;

		$this->apply_footers( $pages );

		$result = array( array_merge( $saved, array_slice( $this->drawn, count( $saved ) ) ), $this->image_warnings );

		$this->drawn          = $saved;
		$this->resources      = $resources;
		$this->substituted    = $substituted;
		$this->subsets        = $subsets;
		$this->images         = $images;
		$this->image_warnings = $image_warnings;

		return $result;
	}

	/* ─── Vector ────────────────────────────────────────────────────── */

	/**
	 * Draw a horizontal rule at the cursor.
	 *
	 * @since 1.0.0
	 *
	 * @param Color|null $color     Colour.
	 * @param float      $thickness Line width.
	 * @param float      $spacing   Space above and below.
	 *
	 * @return self
	 */
	public function rule( ?Color $color = null, float $thickness = 0.5, float $spacing = 6.0 ): self {
		$this->y -= $spacing;
		$this->line( $this->margin, $this->y, $this->right(), $this->y, $color, $thickness );
		$this->y -= $spacing;

		return $this;
	}

	/**
	 * Draw a straight line.
	 *
	 * @since 1.0.0
	 *
	 * @param float      $x1        Start x.
	 * @param float      $y1        Start y.
	 * @param float      $x2        End x.
	 * @param float      $y2        End y.
	 * @param Color|null $color     Colour.
	 * @param float      $thickness Line width.
	 *
	 * @return self
	 */
	public function line( float $x1, float $y1, float $x2, float $y2, ?Color $color = null, float $thickness = 0.5 ): self {
		$color ??= Color::grey( 190 );

		$this->current .= sprintf(
			"q\n%s RG\n%.2F w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
			$color->operands(),
			self::number( $thickness ),
			self::number( $x1 ),
			self::number( $y1 ),
			self::number( $x2 ),
			self::number( $y2 )
		);

		return $this;
	}

	/**
	 * Draw a filled rectangle.
	 *
	 * @since 1.0.0
	 *
	 * @param float      $x      Left.
	 * @param float      $y      Bottom.
	 * @param float      $width  Width.
	 * @param float      $height Height.
	 * @param Color|null $color  Fill colour.
	 *
	 * @return self
	 */
	public function rect( float $x, float $y, float $width, float $height, ?Color $color = null ): self {
		$color ??= Color::grey( 242 );

		$this->current .= sprintf(
			"q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n",
			$color->operands(),
			self::number( $x ),
			self::number( $y ),
			self::number( $width ),
			self::number( $height )
		);

		return $this;
	}

	/**
	 * Draw an SVG path as vector artwork — a logo, typically.
	 *
	 * Stays vector, so it is sharp at any zoom and adds a few hundred
	 * bytes rather than an embedded bitmap. See {@see Svg} for what
	 * converts and what does not.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $path_data  SVG `d` attribute.
	 * @param float      $x          Left position.
	 * @param float      $y          Bottom position.
	 * @param float      $scale      Scale applied to path units.
	 * @param Color|null $color      Fill colour.
	 * @param float      $svg_height viewBox height, for the Y flip.
	 *
	 * @return self
	 */
	public function svg_path(
		string $path_data,
		float $x,
		float $y,
		float $scale = 1.0,
		?Color $color = null,
		float $svg_height = 100.0
	): self {
		$operators = Svg::to_pdf_path( $path_data, $scale, $svg_height );

		if ( '' === $operators ) {
			return $this;
		}

		$color ??= Color::black();

		$this->current .= sprintf(
			"q\n%s rg\n1 0 0 1 %.2F %.2F cm\n%sf\nQ\n",
			$color->operands(),
			self::number( $x ),
			self::number( $y ),
			$operators
		);

		return $this;
	}

	/**
	 * Draw a QR code with its bottom-left corner at a point.
	 *
	 * Drawn as vector squares — each run of dark modules in a row is one
	 * rectangle — so it is sharp at any zoom and on any printer, where a
	 * bitmap at the wrong scale arrives soft-edged. The modules come from
	 * sugarcommerce/qr.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $data  What the code says.
	 * @param float       $x     Left edge.
	 * @param float       $y     Bottom edge.
	 * @param float       $size  Width and height of the square, quiet zone
	 *                           included. Below about 70 points a printed
	 *                           code stops scanning reliably.
	 * @param Color|null  $color Colour of the dark modules.
	 * @param string|null $level L, M, Q or H; null for M, falling back to L
	 *                           for text too long for M.
	 * @param int         $quiet Clear margin in modules. The standard asks
	 *                           for four, and scanners do fail without it.
	 *
	 * @return bool Whether a code was drawn: false for empty text, text too
	 *              long for any code, or a level that is not one of the four.
	 */
	public function qr( string $data, float $x, float $y, float $size, ?Color $color = null, ?string $level = null, int $quiet = 4 ): bool {
		if ( '' === trim( $data ) || $size <= 0 ) {
			return false;
		}

		try {
			$matrix = QR::matrix( $data, $level );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		$count  = count( $matrix );
		$margin = max( 0, $quiet );
		$module = $size / ( $count + ( $margin * 2 ) );
		$path   = '';

		foreach ( $matrix as $row => $modules ) {
			$modules[] = false;
			$start     = null;

			foreach ( $modules as $column => $dark ) {
				if ( $dark && null === $start ) {
					$start = $column;
				} elseif ( ! $dark && null !== $start ) {
					// The matrix reads top down and the page counts up from
					// the bottom; written straight out, the code is mirrored.
					$path .= sprintf(
						"%.3F %.3F %.3F %.3F re\n",
						self::number( $x + ( ( $start + $margin ) * $module ) ),
						self::number( $y + $size - ( ( $row + $margin + 1 ) * $module ) ),
						self::number( ( $column - $start ) * $module ),
						self::number( $module )
					);
					$start = null;
				}
			}
		}

		if ( '' === $path ) {
			return false;
		}

		$this->current .= "q\n" . ( $color ?? Color::black() )->operands() . " rg\n" . $path . "f\nQ\n";

		return true;
	}

	/* ─── Images ────────────────────────────────────────────────────── */

	/**
	 * Read an image for drawing, or record why it cannot be.
	 *
	 * For a template that needs the image's size before it draws: a path
	 * that does not give an image returns null and is listed by
	 * {@see image_warnings()}, so nothing goes missing unsaid. So does a
	 * CMYK JPEG in an {@see archival()} document (since 1.5.0).
	 *
	 * @since 1.4.0
	 *
	 * @param Image|string $image An image, or the path to a JPEG or PNG.
	 *
	 * @return Image|null
	 */
	public function load_image( Image|string $image ): ?Image {
		if ( ! $image instanceof Image ) {
			try {
				$image = Image::file( $image );
			} catch ( \InvalidArgumentException $error ) {
				$this->warn( $error->getMessage() );

				return null;
			}
		}

		// A PDF/A file's colour is sRGB, from its output intent, and a CMYK
		// picture has no meaning under it. Refused rather than converted:
		// turning ink into light needs the press's profile, which a writer
		// does not have, and a guess shifts a brand colour.
		if ( $this->archival && 'cmyk' === $image->color_space ) {
			$this->warn( $image->name . ' is a CMYK JPEG, which a PDF/A file in sRGB cannot carry, and was not drawn. Save it as RGB.' );

			return null;
		}

		return $image;
	}

	/**
	 * Draw an image into a box.
	 *
	 * The box is given by its bottom-left corner, as everything on a PDF
	 * page is. How the picture fills it is `$fit`: whole and in proportion
	 * (the default), covering it and cut off at its edges, or stretched to
	 * it. Where the picture and the box differ in shape, `$align` places it
	 * across the box; it is centred top to bottom.
	 *
	 * Drawing the same image again — on every page, say — embeds it once.
	 *
	 * @since 1.4.0
	 *
	 * @param Image|string $image  An image, or the path to a JPEG or PNG.
	 * @param float        $x      Left of the box.
	 * @param float        $y      Bottom of the box.
	 * @param float        $width  Width of the box.
	 * @param float        $height Height of the box.
	 * @param Fit          $fit    How the image fills it.
	 * @param Align        $align  Where across the box the image sits.
	 *
	 * @return bool Whether it was drawn. When it was not — a file that is
	 *              not an image, a damaged or oversized one, an empty box —
	 *              {@see image_warnings()} says why.
	 */
	public function image( Image|string $image, float $x, float $y, float $width, float $height, Fit $fit = Fit::Contain, Align $align = Align::Center ): bool {
		$image = $this->load_image( $image );

		if ( null === $image ) {
			return false;
		}

		$x      = self::number( $x );
		$y      = self::number( $y );
		$width  = self::number( $width );
		$height = self::number( $height );

		if ( $width <= 0 || $height <= 0 ) {
			$this->warn( sprintf( '%s was given a box of %s × %s points and was not drawn.', $image->name, self::points( $width ), self::points( $height ) ) );

			return false;
		}

		[ $drawn_width, $drawn_height ] = match ( $fit ) {
			Fit::Fill    => array( $width, $height ),
			Fit::Contain => $image->fitted( $width, $height ),
			Fit::Cover   => $width / $height > $image->aspect_ratio()
				? array( $width, $width / $image->aspect_ratio() )
				: array( $height * $image->aspect_ratio(), $height ),
		};

		$left = match ( $align ) {
			Align::Left   => $x,
			Align::Right  => $x + $width - $drawn_width,
			Align::Center => $x + ( ( $width - $drawn_width ) / 2 ),
		};

		$bottom = $y + ( ( $height - $drawn_height ) / 2 );

		// Covering overhangs the box; the clip is what keeps the overhang
		// off the page.
		$clip = Fit::Cover === $fit ? sprintf( "%.3F %.3F %.3F %.3F re\nW n\n", $x, $y, $width, $height ) : '';

		$this->current .= "q\n" . $clip . $image->placement( $left, $bottom, $drawn_width, $drawn_height ) . '/' . $this->image_resource( $image ) . " Do\nQ\n";

		// A reader cuts the page off at its edge, and nothing about what is
		// left shows that anything was.
		$tolerance = 0.01;

		if ( $x < -$tolerance || $y < -$tolerance || $x + $width > $this->page_width + $tolerance || $y + $height > $this->page_height + $tolerance ) {
			$this->warn( $image->name . ' runs off the edge of the page, where a reader cuts it off.' );
		}

		return true;
	}

	/**
	 * Every image that was not drawn, or not drawn whole: one sentence each.
	 *
	 * A file that is not an image, a damaged or cut-short one, one too
	 * large to embed, a box with no size, a picture placed partly off the
	 * page. A missing logo looks like a design choice rather than a fault,
	 * so this is the one place that says so. It includes what the footer
	 * draws on each page.
	 *
	 * @since 1.4.0
	 *
	 * @return list<string> Empty when every image arrived whole.
	 */
	public function image_warnings(): array {
		return $this->with_footers()[1];
	}

	/**
	 * The resource name an image is drawn under, registered on first use.
	 *
	 * Keyed by what is embedded rather than by the object, so the same
	 * file read twice is still one image in the file.
	 *
	 * @since 1.4.0
	 *
	 * @param Image $image The image.
	 *
	 * @return string
	 */
	private function image_resource( Image $image ): string {
		foreach ( $this->images as $name => $known ) {
			if ( $known->digest === $image->digest ) {
				return $name;
			}
		}

		$name                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $name ] = $image;

		return $name;
	}

	/**
	 * Record an image warning, once however often it recurs.
	 *
	 * @since 1.4.0
	 *
	 * @param string $warning One sentence.
	 *
	 * @return void
	 */
	private function warn( string $warning ): void {
		if ( ! in_array( $warning, $this->image_warnings, true ) ) {
			$this->image_warnings[] = $warning;
		}
	}

	/**
	 * A measurement for a message: whole points without a fraction.
	 *
	 * @since 1.4.0
	 *
	 * @param float $points Points.
	 *
	 * @return string
	 */
	private static function points( float $points ): string {
		return rtrim( rtrim( sprintf( '%.2F', $points ), '0' ), '.' );
	}

	/* ─── Output ────────────────────────────────────────────────────── */

	/**
	 * Serialise to PDF bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string|int> $metadata Title, Author, Subject,
	 *                                            Keywords, Creator, and
	 *                                            CreationDate (a Unix
	 *                                            timestamp, since 1.1.0)
	 *                                            for the same bytes on
	 *                                            every render.
	 *
	 * @return string
	 */
	public function render( array $metadata = array() ): string {
		$pages = $this->pages;

		if ( '' !== $this->current || array() === $pages ) {
			$pages[] = $this->current;
		}

		$saved   = $this->drawn;
		$stamped = $this->apply_footers( $pages );

		// The footers' text belongs to this render, not to the document:
		// rendering twice must not record the page numbers twice.
		$this->drawn = $saved;

		$fonts = array();

		foreach ( $this->resources as $resource => $font ) {
			$fonts[ $resource ] = $font instanceof Font ? $font : $this->subsets[ $font ];
		}

		return Writer::write(
			$stamped,
			$this->page_width,
			$this->page_height,
			$metadata,
			$this->language,
			$fonts,
			$this->images,
			array_values( $this->attachments ),
			$this->archival,
			$this->xmp
		);
	}

	/**
	 * Write the document to a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $path     Destination.
	 * @param array<string, string|int> $metadata Document metadata, as
	 *                                            for {@see render()}.
	 *
	 * @return int Bytes written.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function save( string $path, array $metadata = array() ): int {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed write throws below, with the path; the warning would only repeat it.
		$written = @file_put_contents( $path, $this->render( $metadata ) );

		if ( false === $written ) {
			throw new \RuntimeException( 'Could not write the PDF to: ' . $path );
		}

		return $written;
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * Draw the registered footer onto every page.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $pages Page streams.
	 *
	 * @return list<string>
	 */
	private function apply_footers( array $pages ): array {
		if ( null === $this->footer ) {
			return $pages;
		}

		$total    = count( $pages );
		$callback = $this->footer;
		$saved    = $this->current;
		$saved_y  = $this->y;

		foreach ( $pages as $index => $stream ) {
			$this->current = '';
			$callback( $this, $index + 1, $total );
			$pages[ $index ] = $stream . $this->current;
		}

		$this->current = $saved;
		$this->y       = $saved_y;

		return $pages;
	}

	/**
	 * Break text to fit a width, honouring embedded newlines.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text  Text.
	 * @param Font   $font  Typeface.
	 * @param float  $size  Font size.
	 * @param float  $width Available width.
	 *
	 * @return list<string>
	 */
	private function wrap( string $text, Font $font, float $size, float $width ): array {
		$lines = array();
		$space = $this->width( ' ', $size, $font );

		foreach ( explode( "\n", $text ) as $paragraph ) {
			$paragraph = rtrim( $paragraph );

			if ( '' === $paragraph ) {
				$lines[] = '';
				continue;
			}

			$line = '';
			$used = 0.0;

			// Each word is measured once and the line's width kept as a
			// sum: there is no kerning in the base fonts, so a line is
			// exactly its words and the spaces between them. Re-measuring
			// the whole line for every word is quadratic in its length.
			foreach ( explode( ' ', $paragraph ) as $word ) {
				$advance = $this->width( $word, $size, $font );

				if ( '' === $line && $advance <= $width ) {
					$line = $word;
					$used = $advance;
					continue;
				}

				if ( '' !== $line && $used + $space + $advance <= $width ) {
					$line .= ' ' . $word;
					$used += $space + $advance;
					continue;
				}

				if ( '' !== $line ) {
					$lines[] = $line;
				}

				// A single word wider than the column — a long URL, say —
				// must be cut or it runs off the page.
				$line = $advance > $width ? $this->cut( $word, $font, $size, $width, $lines ) : $word;
				$used = $this->width( $line, $size, $font );
			}

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Cut a word wider than the column into column-wide pieces.
	 *
	 * Walks the word once, measuring a character at a time and cutting on
	 * character boundaries. Re-measuring the remainder after every cut —
	 * the obvious loop — is quadratic, and a few hundred kilobytes of
	 * unbroken text in a customer-supplied field then holds a worker for
	 * minutes. Cutting mid-character would also break the UTF-8, and one
	 * broken sequence turns every accent on the line into `?`.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $word  The word, wider than `$width`.
	 * @param Font         $font  Typeface.
	 * @param float        $size  Font size.
	 * @param float        $width Available width.
	 * @param list<string> $lines Completed lines; every full piece is
	 *                            appended here.
	 *
	 * @return string The last, partial piece, which later words may join.
	 */
	private function cut( string $word, Font $font, float $size, float $width, array &$lines ): string {
		$piece = '';
		$used  = 0.0;

		foreach ( $this->advances( $word, $font, $size ) as [ $character, $advance ] ) {
			// Never emit an empty piece: a single character wider than the
			// column stands on a line of its own, which also guarantees
			// the walk makes progress.
			if ( '' !== $piece && $used + $advance > $width ) {
				[ $lines[], $piece ] = $this->settle( $piece, $font, $size, $width );

				$used = $this->width( $piece, $size, $font );
			}

			$piece .= $character;
			$used  += $advance;
		}

		return $piece;
	}

	/**
	 * A cut piece, checked as it will be drawn.
	 *
	 * The characters were measured in the face their whole word resolves
	 * to, and a piece is resolved again on its own: the head of a long
	 * Polish URL is plain ASCII and is drawn in Helvetica, not the Noto
	 * it was measured in. Where that makes it wider, characters move on
	 * to the next piece until it fits. Usually there is nothing to move.
	 *
	 * @since 1.3.0
	 *
	 * @param string $piece The piece.
	 * @param Font   $font  Role.
	 * @param float  $size  Font size.
	 * @param float  $width Column width.
	 *
	 * @return array{0: string, 1: string} The piece that fits, and what moves on.
	 */
	private function settle( string $piece, Font $font, float $size, float $width ): array {
		$carry = '';

		while ( mb_strlen( $piece, 'UTF-8' ) > 1 && $this->width( $piece, $size, $font ) > $width ) {
			$carry = mb_substr( $piece, -1, null, 'UTF-8' ) . $carry;
			$piece = mb_substr( $piece, 0, -1, 'UTF-8' );
		}

		return array( $piece, $carry );
	}

	/**
	 * Text split into the pieces each font draws.
	 *
	 * Decided a word at a time, so a word is never set in two faces: in
	 * the family when it has every glyph; otherwise in the base font when
	 * Windows-1252 holds it exactly; otherwise in the fallback when that
	 * has every glyph. Only a word none of them can draw whole is split,
	 * character by character, down the same order — and a character
	 * nothing has goes to the base font, to print as its nearest letter
	 * or `?`, flagged so it is reported.
	 *
	 * Spaces are words of their own. That keeps a line's width exactly its
	 * words' widths plus its spaces', which is what wrapping adds up.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text Text.
	 * @param Font   $font Role.
	 *
	 * @return list<array{0: TrueType|null, 1: string, 2: bool}> Face (null
	 *         for the base font), the piece, and whether any of it will
	 *         not print as written.
	 */
	private function pieces( string $text, Font $font ): array {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			if ( ! $this->archival ) {
				return array( array( null, $text, 1 === preg_match( '/[\x80-\xFF]/', $text ) ) );
			}

			// No base font to print the bytes as Windows-1252: what is not
			// UTF-8 becomes `?`, which the family has.
			$text = mb_scrub( $text, 'UTF-8' );
		}

		$primary = $this->primary( $font );

		// Plain ASCII in the base fonts, which is most of every invoice.
		if ( null === $primary && 1 !== preg_match( '/[\x80-\xFF]/', $text ) ) {
			return array( array( null, $text, false ) );
		}

		$text   = self::composed( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $text ) );
		$pieces = array();

		foreach ( preg_split( '/( )/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array() as $word ) {
			$key = $font->value . "\0" . $word;

			// A bound on the cache: a long document of distinct words would
			// otherwise keep every one.
			if ( count( $this->resolved ) > 4096 ) {
				$this->resolved = array();
			}

			foreach ( $this->resolved[ $key ] ??= $this->resolve( $word, $font, $primary ) as $piece ) {
				$last = count( $pieces ) - 1;

				if ( $last >= 0 && $pieces[ $last ][0] === $piece[0] ) {
					$pieces[ $last ][1] .= $piece[1];
					$pieces[ $last ][2]  = $pieces[ $last ][2] || $piece[2];
				} else {
					$pieces[] = $piece;
				}
			}
		}

		return $pieces;
	}

	/**
	 * Which fonts draw one word.
	 *
	 * @since 1.3.0
	 *
	 * @param string        $word    One word, or one space.
	 * @param Font          $font    Role.
	 * @param TrueType|null $primary The family's face for the role.
	 *
	 * @return list<array{0: TrueType|null, 1: string, 2: bool}>
	 */
	private function resolve( string $word, Font $font, ?TrueType $primary ): array {
		$characters = mb_str_split( $word, 1, 'UTF-8' );
		$codes      = array_map( static fn( string $character ): int => mb_ord( $character, 'UTF-8' ), $characters );
		$refused    = array_filter( $codes, self::refused( ... ) );

		if ( null !== $primary && array() === $refused && $primary->covers( $word ) ) {
			return array( array( $primary, $word, false ) );
		}

		// A PDF/A file has no base fonts to fall back on.
		$exact = $this->archival ? array() : array_filter( $codes, self::lossless( ... ) );

		if ( ! $this->archival && count( $exact ) === count( $codes ) ) {
			return array( array( null, $word, false ) );
		}

		$fallback = $this->fallback_face( $font );

		if ( null !== $fallback && array() === $refused && $fallback->covers( $word ) ) {
			return array( array( $fallback, $word, false ) );
		}

		$pieces = array();

		foreach ( $characters as $index => $character ) {
			$code = $codes[ $index ];

			$piece = match ( true ) {
				isset( $refused[ $index ] )                               => array( null, $character, true ),
				null !== $primary && 0 !== $primary->glyph( $code )       => array( $primary, $character, false ),
				isset( $exact[ $index ] )                                 => array( null, $character, false ),
				null !== $fallback && 0 !== $fallback->glyph( $code )     => array( $fallback, $character, false ),
				default                                                   => array( null, $character, true ),
			};

			$last = count( $pieces ) - 1;

			if ( $last >= 0 && $pieces[ $last ][0] === $piece[0] ) {
				$pieces[ $last ][1] .= $character;
				$pieces[ $last ][2]  = $pieces[ $last ][2] || $piece[2];
			} else {
				$pieces[] = $piece;
			}
		}

		return null !== $primary && $this->archival ? self::stood_in( $pieces, $primary ) : $pieces;
	}

	/**
	 * Pieces left to the base font, set in an embedded face instead.
	 *
	 * In a PDF/A file. Each is printed as the base font would have printed
	 * it — a letter without its accent, a `?` — so a document reads the
	 * same in either mode; whatever the face still lacks is a `?` too.
	 * Never its `.notdef` glyph, which PDF/A forbids showing.
	 *
	 * @since 1.5.0
	 *
	 * @param list<array{0: TrueType|null, 1: string, 2: bool}> $pieces From resolve().
	 * @param TrueType                                          $face   The family's face for the role.
	 *
	 * @return list<array{0: TrueType|null, 1: string, 2: bool}> Every piece in an embedded face.
	 */
	private static function stood_in( array $pieces, TrueType $face ): array {
		$result = array();

		foreach ( $pieces as [ $owner, $piece, $replaced ] ) {
			if ( null === $owner ) {
				$printed = '';

				foreach ( mb_str_split( (string) mb_convert_encoding( self::winansi( $piece ), 'UTF-8', 'Windows-1252' ), 1, 'UTF-8' ) as $character ) {
					$printed .= 0 !== $face->glyph( mb_ord( $character, 'UTF-8' ) ) ? $character : ( 0 !== $face->glyph( 0x3F ) ? '?' : '' );
				}

				[ $owner, $piece, $replaced ] = array( $face, $printed, $replaced || $printed !== $piece );
			}

			$last = count( $result ) - 1;

			if ( $last >= 0 && $result[ $last ][0] === $owner ) {
				$result[ $last ][1] .= $piece;
				$result[ $last ][2]  = $result[ $last ][2] || $replaced;
			} else {
				$result[] = array( $owner, $piece, $replaced );
			}
		}

		return $result;
	}

	/**
	 * Each character of a text with the width it will be drawn at.
	 *
	 * Measured in the face its word resolves to, so cutting a word or
	 * shortening a value to fit agrees with how the line is drawn.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text Text.
	 * @param Font   $font Role.
	 * @param float  $size Font size.
	 *
	 * @return \Generator<int, array{0: string, 1: float}> One at a time: a
	 *         value of half a megabyte is half a million of them.
	 */
	private function advances( string $text, Font $font, float $size ): \Generator {
		$known = array();

		foreach ( $this->pieces( $text, $font ) as [ $face, $piece ] ) {
			$id = null === $face ? 0 : spl_object_id( $face );

			foreach ( self::characters( $piece ) as $character ) {
				yield array(
					$character,
					$known[ $id ][ $character ] ??= null === $face ? $font->width_of( self::winansi( $character ), $size ) : $face->width_of( $character, $size ),
				);
			}
		}
	}

	/**
	 * The face text in a role is set in first: the family's, or null for
	 * the base font.
	 *
	 * In a PDF/A file there is always one — the family's face for the
	 * role, its regular when it has none (monospaced text in a family
	 * without a monospaced face), and the bundled Noto Sans when no family
	 * was chosen — because the base fonts cannot be embedded.
	 *
	 * @since 1.5.0
	 *
	 * @param Font $font Role.
	 *
	 * @return TrueType|null
	 */
	private function primary( Font $font ): ?TrueType {
		if ( ! $this->archival ) {
			return $this->family?->face( $font );
		}

		$family = $this->family ?? ( $this->archival_family ??= FontFamily::noto_sans() );

		return $family->face( $font ) ?? $family->regular;
	}

	/**
	 * The fallback family's face for a role, its regular when it has none.
	 *
	 * @since 1.3.0
	 *
	 * @param Font $font Role.
	 *
	 * @return TrueType|null
	 */
	private function fallback_face( Font $font ): ?TrueType {
		if ( ! $this->fallback_chosen ) {
			$this->fallback_chosen = true;

			// The bundled family is read the first time a word needs it, so
			// a document in English never opens the font files.
			try {
				$this->fallback = FontFamily::noto_sans();
			} catch ( \InvalidArgumentException ) {
				$this->fallback = null;
			}
		}

		return null === $this->fallback ? null : $this->fallback->face( $font ) ?? $this->fallback->regular;
	}

	/**
	 * The resource name a font is shown under, registered on first use.
	 *
	 * @since 1.3.0
	 *
	 * @param Font|TrueType $font A base font, or an embedded face.
	 *
	 * @return string
	 */
	private function resource( Font|TrueType $font ): string {
		if ( $font instanceof Font ) {
			$this->resources[ $font->resource() ] ??= $font;

			return $font->resource();
		}

		$id = spl_object_id( $font );

		if ( ! isset( $this->subsets[ $id ] ) ) {
			$this->subsets[ $id ]                                     = new FontSubset( $font );
			$this->resources[ 'E' . count( $this->subsets ) ] = $id;
		}

		return (string) array_search( $id, $this->resources, true );
	}

	/**
	 * Whether Windows-1252 holds a character as itself.
	 *
	 * ASCII, Latin-1, the euro and its other additions — and the spaces,
	 * dashes and primes with a stand-in that looks the same. A letter that
	 * would lose its accent or turn into a different letter does not count.
	 *
	 * @since 1.3.0
	 *
	 * @param int $code Code point.
	 *
	 * @return bool
	 */
	private static function lossless( int $code ): bool {
		return $code < 0x80
			|| ( $code >= 0xA0 && $code <= 0xFF )
			|| isset( self::WINDOWS_1252[ $code ] )
			|| ( $code >= 0x2000 && isset( self::STAND_INS[ $code ] ) );
	}

	/**
	 * Whether a character belongs to a script this cannot lay out.
	 *
	 * Right-to-left scripts need reordering and Arabic letters change form
	 * by position; the Indic, Khmer and Myanmar scripts reorder and stack
	 * their vowels. Drawn a code point at a time, left to right, any of
	 * them comes out wrong in a way a reader who does not know the script
	 * could not tell — so they are refused, printed as `?` and reported.
	 *
	 * @since 1.3.0
	 *
	 * @param int $code Code point.
	 *
	 * @return bool
	 */
	private static function refused( int $code ): bool {
		return ( $code >= 0x0590 && $code <= 0x08FF )    // Hebrew, Arabic, Syriac, Thaana, N'Ko and the rest.
			|| ( $code >= 0x0900 && $code <= 0x0DFF )     // Devanagari to Sinhala.
			|| ( $code >= 0x1000 && $code <= 0x109F )     // Myanmar.
			|| ( $code >= 0x1780 && $code <= 0x17FF )     // Khmer.
			|| ( $code >= 0xFB1D && $code <= 0xFDFF )     // Hebrew and Arabic presentation forms.
			|| ( $code >= 0xFE70 && $code <= 0xFEFE );    // Arabic presentation forms B.
	}

	/**
	 * Text in composed form: `e` and a combining acute as one `é`.
	 *
	 * A font draws the composed letter with the accent where its designer
	 * put it; the two separate glyphs only line up with the positioning
	 * tables this does not read, and Windows-1252 has no combining accent
	 * at all.
	 *
	 * @since 1.3.0
	 *
	 * @param string $text Valid UTF-8.
	 *
	 * @return string
	 */
	private static function composed( string $text ): string {
		if ( ! class_exists( \Normalizer::class ) || 1 !== preg_match( '/[\x80-\xFF]/', $text ) ) {
			return $text;
		}

		$composed = \Normalizer::normalize( $text, \Normalizer::FORM_C );

		return is_string( $composed ) ? $composed : $text;
	}

	/**
	 * Force a value into a number the format can express.
	 *
	 * `sprintf( '%.2F', INF )` writes the literal `INF` into the content
	 * stream, and `NAN` writes `NAN` — neither is a PDF number, and a
	 * reader that meets one drops the page or refuses the file. A
	 * caller-supplied dimension that overflowed (a width computed from a
	 * division by zero, a coordinate scaled by a huge factor) is exactly
	 * how one gets there.
	 *
	 * The bound is the specification's implementation limit for a real
	 * number, ±32767 — far beyond any page.
	 *
	 * @since 1.0.0
	 *
	 * @param float $value Raw value.
	 *
	 * @return float
	 */
	public static function number( float $value ): float {
		if ( is_nan( $value ) ) {
			return 0.0;
		}

		return max( -32767.0, min( 32767.0, $value ) );
	}

	/**
	 * Prepare a string for a PDF text operator.
	 *
	 * Transcodes UTF-8 to Windows-1252 so £, é and — survive, removes
	 * every control character, and escapes the three bytes that would
	 * otherwise terminate the string early. An unescaped `)` produces a
	 * file no reader will open.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Raw text.
	 *
	 * @return string
	 */
	public static function escape( string $text ): string {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), self::winansi( $text ) );
	}

	/**
	 * Windows-1252's 0x80–0x9F: the characters it has that Latin-1 does not.
	 *
	 * Every other byte from 0xA0 up is the Unicode code point itself.
	 *
	 * @since 1.0.0
	 */
	private const WINDOWS_1252 = array(
		0x20AC => "\x80", // €
		0x201A => "\x82", // ‚
		0x0192 => "\x83", // ƒ
		0x201E => "\x84", // „
		0x2026 => "\x85", // …
		0x2020 => "\x86", // †
		0x2021 => "\x87", // ‡
		0x02C6 => "\x88", // ˆ
		0x2030 => "\x89", // ‰
		0x0160 => "\x8A", // Š
		0x2039 => "\x8B", // ‹
		0x0152 => "\x8C", // Œ
		0x017D => "\x8E", // Ž
		0x2018 => "\x91", // ‘
		0x2019 => "\x92", // ’
		0x201C => "\x93", // “
		0x201D => "\x94", // ”
		0x2022 => "\x95", // •
		0x2013 => "\x96", // –
		0x2014 => "\x97", // —
		0x02DC => "\x98", // ˜
		0x2122 => "\x99", // ™
		0x0161 => "\x9A", // š
		0x203A => "\x9B", // ›
		0x0153 => "\x9C", // œ
		0x017E => "\x9E", // ž
		0x0178 => "\x9F", // Ÿ
	);

	/**
	 * Stand-ins for characters with no accent to strip and no 1252 byte.
	 *
	 * @since 1.0.0
	 */
	private const STAND_INS = array(
		0x0110 => 'D',   // Đ
		0x0111 => 'd',   // đ
		0x0126 => 'H',   // Ħ
		0x0127 => 'h',   // ħ
		0x0131 => 'i',   // ı
		0x0141 => 'L',   // Ł
		0x0142 => 'l',   // ł
		0x0166 => 'T',   // Ŧ
		0x0167 => 't',   // ŧ
		0x2010 => '-',   // hyphen
		0x2011 => '-',   // non-breaking hyphen
		0x2012 => "\x96", // figure dash
		0x2015 => "\x97", // horizontal bar
		0x2032 => "'",   // prime
		0x2033 => '"',   // double prime
		0x2212 => '-',   // minus sign
		0x202F => "\xA0", // narrow no-break space, in French amounts
		0x2009 => ' ',   // thin space
		0x200B => '',    // zero-width space
		0xFEFF => '',    // byte order mark
	);

	/**
	 * Transcode text to the bytes the page will actually show.
	 *
	 * UTF-8 to Windows-1252, with every C0 control and DEL removed but
	 * the delimiters not yet escaped. This is what gets measured: a
	 * backslash added by escaping is never drawn, so counting it pushes
	 * a right-aligned `(£5.00)` a backslash-width out of line.
	 *
	 * Done here, a character at a time, rather than by iconv: iconv's
	 * `//TRANSLIT` is missing on some builds (musl, Alpine) and on others
	 * gives up on the whole string at the first character it cannot
	 * place, and either way every €, £ and é came out as `?`. A character
	 * Windows-1252 lacks loses its accent where it has one (ą → a, ř → r),
	 * takes a stand-in where there is one (Ł → L), and is `?` otherwise —
	 * Greek, Cyrillic and CJK need an embedded font.
	 *
	 * Invalid UTF-8 cannot be transcoded at all, so it degrades to
	 * printable ASCII with `?` for everything else rather than failing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Raw text.
	 *
	 * @return string Windows-1252 bytes, no control characters.
	 */
	private static function winansi( string $text ): string {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$converted = (string) preg_replace( '/[^\x20-\x7E]/', '?', $text );
		} elseif ( ! preg_match( '/[\x80-\xFF]/', $text ) ) {
			$converted = $text;
		} else {
			$converted = '';

			// A bound on what the cache holds: a hostile string of every
			// code point would otherwise grow it without limit.
			if ( count( self::$transcoded ) > 4096 ) {
				self::$transcoded = array();
			}

			foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $character ) {
				$converted .= self::$transcoded[ $character ] ??= self::winansi_character( $character );
			}
		}

		// Every C0 control and DEL, including CR and LF — a stray CR in a
		// customer's name would otherwise displace the rest of the line.
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $converted );
	}

	/**
	 * One UTF-8 character as Windows-1252 bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $character One valid UTF-8 character.
	 *
	 * @return string
	 */
	private static function winansi_character( string $character ): string {
		$code = mb_ord( $character, 'UTF-8' );

		if ( $code < 0x80 ) {
			return $character;
		}

		if ( $code >= 0xA0 && $code <= 0xFF ) {
			return chr( $code );
		}

		if ( isset( self::WINDOWS_1252[ $code ] ) ) {
			return self::WINDOWS_1252[ $code ];
		}

		if ( isset( self::STAND_INS[ $code ] ) ) {
			return self::STAND_INS[ $code ];
		}

		// The letter under the accent: ą is a and an ogonek.
		if ( class_exists( \Normalizer::class ) ) {
			$base = preg_replace( '/\p{Mn}+/u', '', (string) \Normalizer::normalize( $character, \Normalizer::FORM_D ) );

			if ( is_string( $base ) && '' !== $base && $base !== $character && mb_strlen( $base, 'UTF-8' ) === 1 ) {
				$mapped = self::winansi_character( $base );

				if ( '?' !== $mapped ) {
					return $mapped;
				}
			}
		}

		// iconv only for a letter or digit (a ligature: ﬁ → fi). For the
		// rest, what comes back depends on the server's iconv: glibc turns
		// 😀 into `:-D`, macOS's into nothing at all. The symbols worth a
		// stand-in are in STAND_INS.
		if ( ! preg_match( '/^[\p{L}\p{N}]$/u', $character ) ) {
			return '?';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A build without //TRANSLIT returns false, handled below.
		$transliterated = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $character );

		if ( is_string( $transliterated ) && '' !== $transliterated && ! str_contains( $transliterated, '?' ) ) {
			return $transliterated;
		}

		return '?';
	}

	/**
	 * Split text into characters, or into bytes when it is not UTF-8.
	 *
	 * Cutting on a byte boundary can split a multibyte character, and one
	 * broken sequence makes the whole string invalid UTF-8 — at which
	 * point every accent in it degrades to `?`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Text.
	 *
	 * @return list<string>
	 */
	private static function characters( string $text ): array {
		return mb_check_encoding( $text, 'UTF-8' ) ? mb_str_split( $text, 1, 'UTF-8' ) : str_split( $text );
	}
}
