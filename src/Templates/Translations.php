<?php
/**
 * Adapter from any translation system to the template contract.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

/**
 * Class Translations
 *
 * Wraps a callable, so an existing translation system becomes a
 * {@see Translator} in one line and the strings in your PDFs go through
 * the same workflow as everything else you translate.
 *
 *   // WordPress — the plugin's own translations, then the built-in
 *   // phrases in the site's language.
 *   $translator = Translations::WordPress( 'my-plugin' );
 *
 *   // Laravel — looks up a stable key, falling back to the source.
 *   $translator = new Translations(
 *       fn( string $key, string $default ): string => trans( "pdf.$key" ) ?: $default
 *   );
 *
 *   ( new Invoice( …, translator: $translator ) )->save( 'invoice.pdf' );
 *
 * Whatever the callable returns is still subject to WinAnsi encoding —
 * see {@see Language} — so a translation into Polish or Greek will not
 * render whatever system supplied it.
 *
 * @since 1.0.0
 */
final class Translations implements Translator {

	/**
	 * The wrapped translation function.
	 *
	 * Not a promoted readonly property: PHP requires those to be typed,
	 * and there is no type for "callable" that also accepts a plain
	 * function name or an object with __invoke.
	 *
	 * Typed to return `mixed` because real translation functions do
	 * return null or false for a missing entry; {@see translate()} falls
	 * back when they do.
	 *
	 * @var callable(string, string): mixed
	 */
	private $callback;

	/**
	 * Wrap a translation function.
	 *
	 * @since 1.0.0
	 *
	 * @param callable(string, string): mixed $callback Receives the key
	 *                                                   and the English
	 *                                                   default, returns
	 *                                                   the translation.
	 * @param Translator|null                  $fallback Used when the
	 *                                                   callback returns
	 *                                                   nothing usable.
	 *                                                   Defaults to English.
	 */
	public function __construct( callable $callback, private readonly ?Translator $fallback = null ) {
		$this->callback = $callback;
	}

	/**
	 * Translations through WordPress's __(), in a plugin's text domain.
	 *
	 * The English source is looked up, as gettext does. A phrase the
	 * plugin's translation files do not cover — __() hands back the source
	 * unchanged — falls through to the built-in phrases of the fallback
	 * language, which is the site's own by default. So a German site gets
	 * German templates with no translation work at all, and a plugin that
	 * does translate a phrase gets its own wording.
	 *
	 * Outside WordPress, where there is no __(), the fallback alone.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $domain   The plugin's text domain.
	 * @param Language|null $fallback The built-in phrases to use when the
	 *                                plugin has none. Null for the site's
	 *                                language, from Language::site().
	 *
	 * @return self
	 */
	public static function wordpress( string $domain, ?Language $fallback = null ): self {
		$fallback ??= Language::site();

		return new self(
			static function ( string $key, string $default ) use ( $domain ): ?string {
				if ( ! function_exists( '__' ) ) {
					return null;
				}

				// phpcs:ignore WordPress.WP.I18n -- The template's own English source, translated at runtime.
				$translated = __( $default, $domain );

				return $translated === $default ? null : $translated;
			},
			$fallback
		);
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function translate( string $key, string $default ): string {
		$result = ( $this->callback )( $key, $default );

		// A translation system that returns null, false or an empty
		// string for a missing entry would otherwise blank the label.
		if ( ! is_string( $result ) || '' === trim( $result ) ) {
			return ( $this->fallback ?? Language::English )->translate( $key, $default );
		}

		return $result;
	}
}
