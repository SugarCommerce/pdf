<?php
/**
 * How an image fills its box.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Models;

/**
 * Enum Fit
 *
 * The same three choices as CSS's `object-fit`, for the same reason: a
 * box and a picture rarely share a shape, and something has to give.
 *
 * @since 1.4.0
 */
enum Fit {

	/**
	 * As large as fits inside the box, in proportion. What a logo wants:
	 * all of it, undistorted, with room left on one axis.
	 */
	case Contain;

	/**
	 * As small as covers the box, in proportion, cut off at the box's
	 * edges. What a photograph in a frame wants.
	 */
	case Cover;

	/**
	 * Stretched to the box exactly, proportions or not.
	 */
	case Fill;
}
