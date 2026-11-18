<?php
/**
 * RGB colour.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

use SugarCommerce\Text\Display\Color as Hex;

/**
 * Class Color
 *
 * PDF works in 0-1 floats per channel; this takes the 0-255 values
 * people actually have.
 *
 * @since 1.0.0
 */
final readonly class Color {

	/**
	 * Create a colour from 0-255 channel values.
	 *
	 * Out-of-range values are accepted here and clamped when written, so
	 * a computed channel cannot produce an operand a reader rejects.
	 *
	 * @since 1.0.0
	 *
	 * @param int $red   0-255.
	 * @param int $green 0-255.
	 * @param int $blue  0-255.
	 */
	public function __construct(
		public int $red = 0,
		public int $green = 0,
		public int $blue = 0,
	) {}

	/**
	 * Build from a hex string.
	 *
	 * Read by sugarcommerce/text's Display\Color, the one hex reader of the
	 * SugarCommerce packages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hex e.g. `#1a2b3c`, `1a2b3c` or `#abc`.
	 *
	 * @return self Black when it is not a colour.
	 */
	public static function hex( string $hex ): self {
		$rgb = Hex::rgb( $hex );

		return null === $rgb ? new self() : new self( ...$rgb );
	}

	/**
	 * A shade of grey.
	 *
	 * @since 1.0.0
	 *
	 * @param int $level 0 black to 255 white.
	 *
	 * @return self
	 */
	public static function grey( int $level ): self {
		return new self( $level, $level, $level );
	}

	/**
	 * Black.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function black(): self {
		return new self();
	}

	/**
	 * The PDF operator arguments for this colour.
	 *
	 * @since 1.0.0
	 *
	 * @return string Three space-separated floats.
	 */
	public function operands(): string {
		return sprintf(
			'%.3F %.3F %.3F',
			max( 0, min( 255, $this->red ) ) / 255,
			max( 0, min( 255, $this->green ) ) / 255,
			max( 0, min( 255, $this->blue ) ) / 255
		);
	}

	/**
	 * Whether this is pure black, the PDF default.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_black(): bool {
		return 0 === $this->red && 0 === $this->green && 0 === $this->blue;
	}
}
