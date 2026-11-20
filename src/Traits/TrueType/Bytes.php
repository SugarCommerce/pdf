<?php
/**
 * Big-endian integers in and out of a font file.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\TrueType;

/**
 * Reads that refuse to run past the end of the data, and the writes
 * that match them.
 *
 * @since 1.3.0
 */
trait Bytes {

	/**
	 * An unsigned 16-bit value from the file, 0 past its end.
	 *
	 * @since 1.3.0
	 *
	 * @param int $offset Byte offset.
	 *
	 * @return int
	 */
	private function u16( int $offset ): int {
		return self::read16( $this->data, $offset );
	}

	/**
	 * An unsigned 32-bit value from the file, 0 past its end.
	 *
	 * @since 1.3.0
	 *
	 * @param int $offset Byte offset.
	 *
	 * @return int
	 */
	private function u32( int $offset ): int {
		return self::read32( $this->data, $offset );
	}

	/**
	 * An unsigned 16-bit big-endian value, 0 past the end.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes  Bytes.
	 * @param int    $offset Byte offset.
	 *
	 * @return int
	 */
	private static function read16( string $bytes, int $offset ): int {
		if ( $offset < 0 || $offset + 2 > strlen( $bytes ) ) {
			return 0;
		}

		return ( ord( $bytes[ $offset ] ) << 8 ) | ord( $bytes[ $offset + 1 ] );
	}

	/**
	 * An unsigned 32-bit big-endian value, 0 past the end.
	 *
	 * @since 1.3.0
	 *
	 * @param string $bytes  Bytes.
	 * @param int    $offset Byte offset.
	 *
	 * @return int
	 */
	private static function read32( string $bytes, int $offset ): int {
		if ( $offset < 0 || $offset + 4 > strlen( $bytes ) ) {
			return 0;
		}

		return (int) unpack( 'N', $bytes, $offset )[1];
	}

	/**
	 * A 16-bit value read as signed — an FWord.
	 *
	 * Descenders are negative: read unsigned, -220 becomes 65316 and every
	 * line acquires a 65-em gap.
	 *
	 * @since 1.3.0
	 *
	 * @param int $value 0-65535.
	 *
	 * @return int
	 */
	private static function signed( int $value ): int {
		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	/**
	 * A 32-bit value read as signed.
	 *
	 * @since 1.3.0
	 *
	 * @param int $value 0-4294967295.
	 *
	 * @return int
	 */
	private static function signed32( int $value ): int {
		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}
}
