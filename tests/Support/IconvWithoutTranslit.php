<?php
/**
 * An iconv with no //TRANSLIT, as on musl and Alpine.
 *
 * Declared in the namespace of Document's Encoding trait, so its
 * unqualified iconv() calls find this one first. Loaded only in a process
 * of its own.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

/**
 * Refuse every //TRANSLIT conversion of anything but ASCII.
 *
 * @param string $from From encoding.
 * @param string $to   To encoding.
 * @param string $text Text.
 *
 * @return string|false
 */
function iconv( string $from, string $to, string $text ): string|false {
	if ( str_contains( $to, '//TRANSLIT' ) && preg_match( '/[\x80-\xFF]/', $text ) ) {
		return false;
	}

	return \iconv( $from, $to, $text );
}
