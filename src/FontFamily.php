<?php
/**
 * A typeface in regular, bold and italic, embedded in the document.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class FontFamily
 *
 * The faces a document is set in when Helvetica is not enough — because a
 * name is in Greek or Cyrillic, or because the brand has a typeface.
 *
 * Layout code never names a face. It asks for a role — {@see Font}'s
 * regular, bold, italic or monospaced — and the document answers with
 * the family's face for that role, or the base-14 font when there is no
 * family. A template written for Helvetica is set in the family without
 * a line of it changing.
 *
 * A role the family has no face for takes the nearest one it has: bold
 * without a bold face is drawn in the regular, italic in the upright.
 * The alternative is drawing nothing, and a missing line is worse than an
 * unslanted one.
 *
 * Each face is read once per request, however many documents use it, and
 * only the glyphs a document draws are embedded in it.
 *
 * @since 1.3.0
 */
final readonly class FontFamily {

	/**
	 * Define a family from faces already read.
	 *
	 * @since 1.3.0
	 *
	 * @param TrueType      $regular The body face.
	 * @param TrueType|null $bold    Headings, labels, totals. The regular when null.
	 * @param TrueType|null $italic  Emphasis. The regular when null.
	 * @param TrueType|null $mono    Anything set in code. Courier when null:
	 *                               the regular face is not monospaced, and a
	 *                               column of figures set in it would not line up.
	 */
	public function __construct(
		public TrueType $regular,
		public ?TrueType $bold = null,
		public ?TrueType $italic = null,
		public ?TrueType $mono = null,
	) {}

	/**
	 * A family from `.ttf` files.
	 *
	 * ```php
	 * $family = FontFamily::files( __DIR__ . '/fonts/Inter-Regular.ttf', __DIR__ . '/fonts/Inter-Bold.ttf' );
	 * ```
	 *
	 * @since 1.3.0
	 *
	 * @param string      $regular Path to the regular face.
	 * @param string|null $bold    Path to the bold face.
	 * @param string|null $italic  Path to the italic face.
	 * @param string|null $mono    Path to a monospaced face.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When a file is missing or cannot be
	 *                                   embedded — a variable font, a CFF
	 *                                   `.otf`, a collection — saying which.
	 */
	public static function files( string $regular, ?string $bold = null, ?string $italic = null, ?string $mono = null ): self {
		return new self(
			TrueType::file( $regular ),
			null !== $bold ? TrueType::file( $bold ) : null,
			null !== $italic ? TrueType::file( $italic ) : null,
			null !== $mono ? TrueType::file( $mono ) : null
		);
	}

	/**
	 * Noto Sans, the family that comes with this library.
	 *
	 * Latin with every accent European languages use, Greek and Cyrillic,
	 * in regular and bold — the two weights the templates set. It is the
	 * fallback a document reaches for when a name has characters Helvetica
	 * cannot print, and a family to set a whole document in.
	 *
	 * The files are Noto Sans 2.015 with the shaping and kerning tables
	 * and the glyph names taken out, which this writer never reads; the
	 * outlines are untouched. SIL Open Font License 1.1 (`fonts/OFL.txt`).
	 *
	 * @since 1.3.0
	 *
	 * @return self
	 */
	public static function noto_sans(): self {
		$folder = dirname( __DIR__ ) . '/fonts/';

		return self::files( $folder . 'NotoSans-Regular.ttf', $folder . 'NotoSans-Bold.ttf' );
	}

	/**
	 * The face for a role.
	 *
	 * @since 1.3.0
	 *
	 * @param Font $role The role a layout asked for.
	 *
	 * @return TrueType|null Null for monospaced text when the family has no
	 *                       monospaced face: Courier draws it.
	 */
	public function face( Font $role ): ?TrueType {
		return match ( $role ) {
			Font::Helvetica        => $this->regular,
			Font::HelveticaBold    => $this->bold ?? $this->regular,
			Font::HelveticaOblique => $this->italic ?? $this->regular,
			Font::Courier          => $this->mono,
		};
	}
}
