<?php
/**
 * Template wording, translated.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Enum Language
 *
 * The fixed wording in the built-in templates — headings, column labels,
 * status lines — in the languages a European seller most often needs.
 *
 * **Only Latin-1 languages are included, and that is a hard limit rather
 * than a shortlist.** The base-14 PDF fonts use WinAnsi encoding, which
 * covers Western and Northern Europe: German umlauts, French accents,
 * Spanish tildes, the Nordic å/ä/ö/æ/ø all render. Polish, Czech,
 * Hungarian, Turkish, Greek and Cyrillic do not — `ł`, `ę`, `č`, `ő`,
 * `ğ` and anything Cyrillic are absent from the encoding, so they would
 * print as substitutes or vanish. Adding them means embedding a font,
 * which is a different library.
 *
 * Your own content — customer names, item descriptions — is subject to
 * exactly the same limit, whichever language is selected here.
 *
 * @since 1.0.0
 */
enum Language: string implements Translator {

	/**
	 * English, and the fallback for every missing phrase.
	 */
	case English = 'en';

	/**
	 * German. Also switches on the German VAT wording.
	 */
	case German = 'de';

	/**
	 * French.
	 */
	case French = 'fr';

	/**
	 * Spanish.
	 */
	case Spanish = 'es';

	/**
	 * Italian.
	 */
	case Italian = 'it';

	/**
	 * Dutch.
	 */
	case Dutch = 'nl';

	/**
	 * Portuguese.
	 */
	case Portuguese = 'pt';

	/**
	 * Swedish.
	 */
	case Swedish = 'sv';

	/**
	 * Danish.
	 */
	case Danish = 'da';

	/**
	 * Norwegian Bokmål; Nynorsk and `no` resolve here too.
	 */
	case Norwegian = 'nb';

	/**
	 * Finnish.
	 */
	case Finnish = 'fi';

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function translate( string $key, string $default ): string {
		return self::strings()[ $this->value ][ $key ] ?? $default;
	}

	/**
	 * Look up a phrase.
	 *
	 * Falls back to English for anything untranslated, so a missing entry
	 * degrades to a comprehensible document rather than a blank label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Phrase key.
	 *
	 * @return string
	 */
	public function get( string $key ): string {
		return self::strings()[ $this->value ][ $key ]
			?? self::strings()['en'][ $key ]
			?? $key;
	}

	/**
	 * Every phrase for this language, English-backfilled.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return array_merge( self::strings()['en'], self::strings()[ $this->value ] ?? array() );
	}

	/**
	 * Resolve a locale or language tag.
	 *
	 * Accepts `de`, `de_DE`, `de-AT` and similar, so a stored locale can
	 * be passed straight through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $locale Language tag.
	 *
	 * @return self English when unrecognised.
	 */
	public static function from_locale( string $locale ): self {
		$code = strtolower( substr( trim( str_replace( '-', '_', $locale ) ), 0, 2 ) );

		// Norwegian has two written forms and several tags; both map here.
		if ( in_array( $code, array( 'nb', 'nn', 'no' ), true ) ) {
			return self::Norwegian;
		}

		return self::tryFrom( $code ) ?? self::English;
	}

	/**
	 * The site's language, when this is running inside WordPress.
	 *
	 * Reads determine_locale(), which answers for the current request — a
	 * user's own admin language included — and falls back to get_locale().
	 * Outside WordPress, or for a locale none of the cases cover, English.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function site(): self {
		$locale = match ( true ) {
			function_exists( 'determine_locale' ) => (string) determine_locale(),
			function_exists( 'get_locale' )       => (string) get_locale(),
			default                               => '',
		};

		return '' === $locale ? self::English : self::from_locale( $locale );
	}

	/**
	 * The phrase table, read from data/phrases.php the first time it is
	 * needed and kept for the rest of the request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function strings(): array {
		static $strings = null;

		return $strings ??= require dirname( __DIR__, 2 ) . '/data/phrases.php';
	}
}
