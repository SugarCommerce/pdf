<?php
/**
 * The faces a document draws its text in.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Models\FontFamily;
use SugarCommerce\Pdf\Fonts\{FontSubset, TrueType};

/**
 * A family set throughout, the fallback a word goes to when the base
 * fonts cannot print it, which face draws each run, and the metrics
 * layout code measures by.
 *
 * @since 1.0.0
 */
trait Fonts {

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
}
