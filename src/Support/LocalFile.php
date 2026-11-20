<?php
/**
 * Reading a file from this server, and nowhere else.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Support;

/**
 * Class LocalFile
 *
 * The one way a picture or a font is read from a path.
 *
 * PHP's file functions follow any stream wrapper a path names: ftp://
 * connects to another host even to ask whether a file is there, phar://
 * opens an archive, compress.zlib:// inflates as it reads. A logo or a
 * typeface is a file on this server, so a path with a scheme is refused
 * before anything touches it. And a file is measured before it is read,
 * then read no further than the limit, so a path to something enormous
 * never reaches memory.
 *
 * @internal Used by {@see \SugarCommerce\Pdf\Image::file()} and
 *           {@see \SugarCommerce\Pdf\Fonts\TrueType::file()}.
 *
 * @since 1.5.0
 */
final class LocalFile {

	/**
	 * The bytes of a file on this server.
	 *
	 * @since 1.5.0
	 *
	 * @param string $path   The path.
	 * @param int    $limit  The most bytes it may hold.
	 * @param string $kind   What it is, for the message: `image`, `font`.
	 * @param string $advice A sentence after the size message, saying what
	 *                       to do instead.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When it is not a readable file on
	 *                                   this server, or is over the limit.
	 */
	public static function read( string $path, int $limit, string $kind, string $advice = '' ): string {
		$missing = 'Could not read the ' . $kind . ' file ' . $path . '.';

		// A scheme -- ftp://, phar://, php:// -- or a NUL byte, which no
		// file name holds.
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) || str_contains( $path, "\0" ) ) {
			throw new \InvalidArgumentException( $missing . ' Only a path to a file on this server is read, never an address.' );
		}

		$size = is_file( $path ) ? filesize( $path ) : false;

		if ( false === $size ) {
			throw new \InvalidArgumentException( $missing );
		}

		// Measured before it is read: a file too large to use should not
		// be loaded into memory to find that out.
		if ( $size > $limit ) {
			throw new \InvalidArgumentException( trim( sprintf( '%s is %d bytes, more than the %d this reads. %s', basename( $path ), $size, $limit, $advice ) ) );
		}

		// Read no further than the limit, whatever the size said: a file
		// can grow between the two, and some report no size at all.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file; a failed read throws below, with the path.
		$data = @file_get_contents( $path, false, null, 0, $limit + 1 );

		if ( false === $data ) {
			throw new \InvalidArgumentException( $missing );
		}

		if ( strlen( $data ) > $limit ) {
			throw new \InvalidArgumentException( trim( sprintf( '%s is more than the %d bytes this reads. %s', basename( $path ), $limit, $advice ) ) );
		}

		return $data;
	}
}
