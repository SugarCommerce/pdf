<?php
/**
 * Rules, lines, boxes, SVG paths and QR codes.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Images\Svg;
use SugarCommerce\Pdf\Models\Color;
use SugarCommerce\Qr\QR;

/**
 * Everything drawn with path operators rather than text or pictures.
 *
 * @since 1.0.0
 */
trait Vector {

	/**
	 * Draw a horizontal rule at the cursor.
	 *
	 * @since 1.0.0
	 *
	 * @param Color|null $color     Colour.
	 * @param float      $thickness Line width.
	 * @param float      $spacing   Space above and below.
	 *
	 * @return self
	 */
	public function rule( ?Color $color = null, float $thickness = 0.5, float $spacing = 6.0 ): self {
		$this->y -= $spacing;
		$this->line( $this->margin, $this->y, $this->right(), $this->y, $color, $thickness );
		$this->y -= $spacing;

		return $this;
	}

	/**
	 * Draw a straight line.
	 *
	 * @since 1.0.0
	 *
	 * @param float      $x1        Start x.
	 * @param float      $y1        Start y.
	 * @param float      $x2        End x.
	 * @param float      $y2        End y.
	 * @param Color|null $color     Colour.
	 * @param float      $thickness Line width.
	 *
	 * @return self
	 */
	public function line( float $x1, float $y1, float $x2, float $y2, ?Color $color = null, float $thickness = 0.5 ): self {
		$color ??= Color::grey( 190 );

		$this->current .= sprintf(
			"q\n%s RG\n%.2F w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
			$color->operands(),
			self::number( $thickness ),
			self::number( $x1 ),
			self::number( $y1 ),
			self::number( $x2 ),
			self::number( $y2 )
		);

		return $this;
	}

	/**
	 * Draw a filled rectangle.
	 *
	 * @since 1.0.0
	 *
	 * @param float      $x      Left.
	 * @param float      $y      Bottom.
	 * @param float      $width  Width.
	 * @param float      $height Height.
	 * @param Color|null $color  Fill colour.
	 *
	 * @return self
	 */
	public function rect( float $x, float $y, float $width, float $height, ?Color $color = null ): self {
		$color ??= Color::grey( 242 );

		$this->current .= sprintf(
			"q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n",
			$color->operands(),
			self::number( $x ),
			self::number( $y ),
			self::number( $width ),
			self::number( $height )
		);

		return $this;
	}

	/**
	 * Draw an SVG path as vector artwork — a logo, typically.
	 *
	 * Stays vector, so it is sharp at any zoom and adds a few hundred
	 * bytes rather than an embedded bitmap. See {@see Svg} for what
	 * converts and what does not.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $path_data  SVG `d` attribute.
	 * @param float      $x          Left position.
	 * @param float      $y          Bottom position.
	 * @param float      $scale      Scale applied to path units.
	 * @param Color|null $color      Fill colour.
	 * @param float      $svg_height viewBox height, for the Y flip.
	 *
	 * @return self
	 */
	public function svg_path(
		string $path_data,
		float $x,
		float $y,
		float $scale = 1.0,
		?Color $color = null,
		float $svg_height = 100.0
	): self {
		$operators = Svg::to_pdf_path( $path_data, $scale, $svg_height );

		if ( '' === $operators ) {
			return $this;
		}

		$color ??= Color::black();

		$this->current .= sprintf(
			"q\n%s rg\n1 0 0 1 %.2F %.2F cm\n%sf\nQ\n",
			$color->operands(),
			self::number( $x ),
			self::number( $y ),
			$operators
		);

		return $this;
	}

	/**
	 * Draw a QR code with its bottom-left corner at a point.
	 *
	 * Drawn as vector squares — each run of dark modules in a row is one
	 * rectangle — so it is sharp at any zoom and on any printer, where a
	 * bitmap at the wrong scale arrives soft-edged. The modules come from
	 * sugarcommerce/qr.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $data  What the code says.
	 * @param float       $x     Left edge.
	 * @param float       $y     Bottom edge.
	 * @param float       $size  Width and height of the square, quiet zone
	 *                           included. Below about 70 points a printed
	 *                           code stops scanning reliably.
	 * @param Color|null  $color Colour of the dark modules.
	 * @param string|null $level L, M, Q or H; null for M, falling back to L
	 *                           for text too long for M.
	 * @param int         $quiet Clear margin in modules. The standard asks
	 *                           for four, and scanners do fail without it.
	 *
	 * @return bool Whether a code was drawn: false for empty text, text too
	 *              long for any code, or a level that is not one of the four.
	 */
	public function qr( string $data, float $x, float $y, float $size, ?Color $color = null, ?string $level = null, int $quiet = 4 ): bool {
		if ( '' === trim( $data ) || $size <= 0 ) {
			return false;
		}

		try {
			$matrix = QR::matrix( $data, $level );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		$count  = count( $matrix );
		$margin = max( 0, $quiet );
		$module = $size / ( $count + ( $margin * 2 ) );
		$path   = '';

		foreach ( $matrix as $row => $modules ) {
			$modules[] = false;
			$start     = null;

			foreach ( $modules as $column => $dark ) {
				if ( $dark && null === $start ) {
					$start = $column;
				} elseif ( ! $dark && null !== $start ) {
					// The matrix reads top down and the page counts up from
					// the bottom; written straight out, the code is mirrored.
					$path .= sprintf(
						"%.3F %.3F %.3F %.3F re\n",
						self::number( $x + ( ( $start + $margin ) * $module ) ),
						self::number( $y + $size - ( ( $row + $margin + 1 ) * $module ) ),
						self::number( ( $column - $start ) * $module ),
						self::number( $module )
					);
					$start = null;
				}
			}
		}

		if ( '' === $path ) {
			return false;
		}

		$this->current .= "q\n" . ( $color ?? Color::black() )->operands() . " rg\n" . $path . "f\nQ\n";

		return true;
	}
}
