<?php
/**
 * Handing a rendered PDF to a browser.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Download
 *
 * The headers a "download invoice" link answers with, and the one call that
 * sends them. The file name is the part worth care: it is usually built from
 * an order number and a customer's name, and it lands in a header, where a
 * line break starts a header of somebody else's choosing and a quote ends
 * the value early. So the plain `filename` is reduced to safe ASCII, and the
 * name as written travels percent-encoded in `filename*` (RFC 6266 and
 * RFC 5987), which every current browser prefers.
 *
 * Not cached anywhere on the way: an invoice is one person's, and a shared
 * cache that kept it would hand it to the next person asking for the URL.
 *
 * @since 1.0.0
 */
final readonly class Download {

	/**
	 * The longest file name sent, in characters, before `.pdf`.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	public const int MAX_NAME = 150;

	/**
	 * The response headers for a PDF.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename The name to save it as. Any extension is
	 *                         replaced with `.pdf`.
	 * @param int    $length   The size of the body, in bytes.
	 * @param bool   $inline   True to open it in the browser's viewer,
	 *                         false to save it.
	 *
	 * @return array<string, string> Header name => value.
	 */
	public static function headers( string $filename, int $length, bool $inline = false ): array {
		return array(
			'Content-Type'           => 'application/pdf',
			'Content-Disposition'    => sprintf(
				'%s; filename="%s"; filename*=UTF-8\'\'%s',
				$inline ? 'inline' : 'attachment',
				self::ascii_name( $filename ),
				rawurlencode( self::name( $filename ) )
			),
			'Content-Length'         => (string) max( 0, $length ),
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'private, no-store, max-age=0',
		);
	}

	/**
	 * Send a PDF to the browser.
	 *
	 * Inside WordPress, nocache_headers() goes first, so every header core
	 * and the server know to send for an uncached response is sent too.
	 * Nothing is sent once output has started — the headers would be
	 * ignored and the PDF would arrive as text in the middle of a page.
	 *
	 * The caller exits afterwards; this does not, so a test or a caller
	 * with something to log can carry on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes    The rendered PDF, from render().
	 * @param string $filename The name to save it as.
	 * @param bool   $inline   True to open it in the browser's viewer.
	 *
	 * @return bool False when output had already started and nothing was
	 *              sent.
	 */
	public static function send( string $bytes, string $filename, bool $inline = false ): bool {
		if ( headers_sent() ) {
			return false;
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		foreach ( self::headers( $filename, strlen( $bytes ), $inline ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF, not HTML.

		return true;
	}

	/**
	 * The file name as written, made safe for a header.
	 *
	 * Control characters, path separators and quotes removed, length capped,
	 * and `.pdf` on the end in place of any other extension.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename The name asked for.
	 *
	 * @return string
	 */
	public static function name( string $filename ): string {
		if ( ! mb_check_encoding( $filename, 'UTF-8' ) ) {
			$filename = mb_convert_encoding( $filename, 'UTF-8', 'ISO-8859-1' );
		}

		$name = (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\"]+|\p{C}+/u', '', $filename );
		$name = (string) preg_replace( '/\.pdf$/i', '', trim( $name, " .\t" ) );
		$name = trim( mb_substr( $name, 0, self::MAX_NAME ), ' .' );

		return ( '' === $name ? 'document' : $name ) . '.pdf';
	}

	/**
	 * The file name in plain ASCII, for the `filename` parameter.
	 *
	 * Letters, digits, dots, hyphens and underscores; anything else becomes
	 * a hyphen. Accents are transliterated where iconv can, so `Müller`
	 * becomes `Muller` rather than `M-ller`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename The name asked for.
	 *
	 * @return string
	 */
	public static function ascii_name( string $filename ): string {
		$name  = substr( self::name( $filename ), 0, -4 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed transliteration falls back to the name as written.
		$plain = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name );
		// Some iconv builds spell an accent out beside its letter (M"uller,
		// cafe') rather than dropping it; the marks go, the letters stay.
		$plain = str_replace( array( '"', "'", '^', '`', '~' ), '', false === $plain ? $name : $plain );
		$plain = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $plain );
		$plain = trim( (string) preg_replace( '/-{2,}/', '-', $plain ), '-.' );

		return ( '' === $plain ? 'document' : $plain ) . '.pdf';
	}
}
