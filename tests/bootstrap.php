<?php
/**
 * PHPUnit bootstrap.
 *
 * The library needs no WordPress. These stand in for the few core functions
 * it reaches for when they exist — the locale, __() and nocache_headers() —
 * and for header() and headers_sent() inside the library's namespace, so a
 * download can be sent and inspected without a web server.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

	/**
	 * What the stubs answer, and what they were told.
	 *
	 * @var array{locale: string, translations: array<string, array<string, string>>, nocache: int, headers: list<string>, sent: bool}
	 */
	$GLOBALS['pdf_wp'] = array();

	/**
	 * Put the stubs back as a fresh site would have them.
	 *
	 * @return void
	 */
	function pdf_reset_wp(): void {
		$GLOBALS['pdf_wp'] = array(
			'locale'       => 'en_US',
			'translations' => array(),
			'nocache'      => 0,
			'headers'      => array(),
			'sent'         => false,
		);
	}

	pdf_reset_wp();

	if ( ! function_exists( 'determine_locale' ) ) {
		/**
		 * The request's locale.
		 *
		 * @return string
		 */
		function determine_locale(): string {
			return $GLOBALS['pdf_wp']['locale'];
		}
	}

	if ( ! function_exists( '__' ) ) {
		/**
		 * Translation stub: a phrase from the domain's table, or the source.
		 *
		 * @param string $text   Source text.
		 * @param string $domain Text domain.
		 *
		 * @return string
		 */
		function __( string $text, string $domain = 'default' ): string {
			return $GLOBALS['pdf_wp']['translations'][ $domain ][ $text ] ?? $text;
		}
	}

	if ( ! function_exists( 'nocache_headers' ) ) {
		/**
		 * Count the calls.
		 *
		 * @return void
		 */
		function nocache_headers(): void {
			++$GLOBALS['pdf_wp']['nocache'];
		}
	}
}

namespace SugarCommerce\Pdf {

	/**
	 * Record a header instead of sending it.
	 *
	 * @param string $header The header line.
	 *
	 * @return void
	 */
	function header( string $header ): void {
		$GLOBALS['pdf_wp']['headers'][] = $header;
	}

	/**
	 * Whether output has started, as a test says.
	 *
	 * @return bool
	 */
	function headers_sent(): bool {
		return $GLOBALS['pdf_wp']['sent'];
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}
