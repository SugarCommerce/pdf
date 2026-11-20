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
use SugarCommerce\Pdf\Models\FontFamily;
use SugarCommerce\Pdf\Models\Orientation;
use SugarCommerce\Pdf\Models\PageSize;
use SugarCommerce\Pdf\Traits\Document\{Archiving, Encoding, Fonts, Images, Measuring, Output, Text, Vector};

/**
 * Class Document
 *
 * Builds invoices, statements and reports — text, tables, rules, boxes,
 * vector logos and pictures ({@see Image}) — with no dependencies.
 *
 * Text is set in the base-14 fonts every reader is required to have, so
 * a document in a Western European language is a few kilobytes. Real
 * font metrics are carried (see {@see \SugarCommerce\Pdf\Models\Font}), which is what makes
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
 * The page geometry and the flow are here; each other job is a trait in
 * Traits/Document: text, measuring, fonts, vector drawing, images,
 * archiving, encoding and the finished file.
 *
 * @since 1.0.0
 */
final class Document {

	use Archiving;
	use Fonts;
	use Text;
	use Measuring;
	use Vector;
	use Images;
	use Output;
	use Encoding;

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

	/* ─── Pages ─────────────────────────────────────────────────────── */

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
}
