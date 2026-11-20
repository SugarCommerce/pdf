<?php
/**
 * Text and numbers as a PDF content stream writes them.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

/**
 * Numbers in the fewest digits, and text as Windows-1252 bytes in an
 * escaped PDF string, for the base fonts.
 *
 * @since 1.0.0
 */
trait Encoding {

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
