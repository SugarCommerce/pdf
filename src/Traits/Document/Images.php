<?php
/**
 * Pictures on the page.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Image;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Fit;

/**
 * PNG and JPEG pictures, read once per document and placed in a box.
 *
 * @since 1.0.0
 */
trait Images {

	/**
	 * Read an image for drawing, or record why it cannot be.
	 *
	 * For a template that needs the image's size before it draws: a path
	 * that does not give an image returns null and is listed by
	 * {@see image_warnings()}, so nothing goes missing unsaid. So does a
	 * CMYK JPEG in an {@see archival()} document (since 1.5.0).
	 *
	 * @since 1.4.0
	 *
	 * @param Image|string $image An image, or the path to a JPEG or PNG.
	 *
	 * @return Image|null
	 */
	public function load_image( Image|string $image ): ?Image {
		if ( ! $image instanceof Image ) {
			try {
				$image = Image::file( $image );
			} catch ( \InvalidArgumentException $error ) {
				$this->warn( $error->getMessage() );

				return null;
			}
		}

		// A PDF/A file's colour is sRGB, from its output intent, and a CMYK
		// picture has no meaning under it. Refused rather than converted:
		// turning ink into light needs the press's profile, which a writer
		// does not have, and a guess shifts a brand colour.
		if ( $this->archival && 'cmyk' === $image->color_space ) {
			$this->warn( $image->name . ' is a CMYK JPEG, which a PDF/A file in sRGB cannot carry, and was not drawn. Save it as RGB.' );

			return null;
		}

		return $image;
	}

	/**
	 * Draw an image into a box.
	 *
	 * The box is given by its bottom-left corner, as everything on a PDF
	 * page is. How the picture fills it is `$fit`: whole and in proportion
	 * (the default), covering it and cut off at its edges, or stretched to
	 * it. Where the picture and the box differ in shape, `$align` places it
	 * across the box; it is centred top to bottom.
	 *
	 * Drawing the same image again — on every page, say — embeds it once.
	 *
	 * @since 1.4.0
	 *
	 * @param Image|string $image  An image, or the path to a JPEG or PNG.
	 * @param float        $x      Left of the box.
	 * @param float        $y      Bottom of the box.
	 * @param float        $width  Width of the box.
	 * @param float        $height Height of the box.
	 * @param Fit          $fit    How the image fills it.
	 * @param Align        $align  Where across the box the image sits.
	 *
	 * @return bool Whether it was drawn. When it was not — a file that is
	 *              not an image, a damaged or oversized one, an empty box —
	 *              {@see image_warnings()} says why.
	 */
	public function image( Image|string $image, float $x, float $y, float $width, float $height, Fit $fit = Fit::Contain, Align $align = Align::Center ): bool {
		$image = $this->load_image( $image );

		if ( null === $image ) {
			return false;
		}

		$x      = self::number( $x );
		$y      = self::number( $y );
		$width  = self::number( $width );
		$height = self::number( $height );

		if ( $width <= 0 || $height <= 0 ) {
			$this->warn( sprintf( '%s was given a box of %s × %s points and was not drawn.', $image->name, self::points( $width ), self::points( $height ) ) );

			return false;
		}

		[ $drawn_width, $drawn_height ] = match ( $fit ) {
			Fit::Fill    => array( $width, $height ),
			Fit::Contain => $image->fitted( $width, $height ),
			Fit::Cover   => $width / $height > $image->aspect_ratio()
				? array( $width, $width / $image->aspect_ratio() )
				: array( $height * $image->aspect_ratio(), $height ),
		};

		$left = match ( $align ) {
			Align::Left   => $x,
			Align::Right  => $x + $width - $drawn_width,
			Align::Center => $x + ( ( $width - $drawn_width ) / 2 ),
		};

		$bottom = $y + ( ( $height - $drawn_height ) / 2 );

		// Covering overhangs the box; the clip is what keeps the overhang
		// off the page.
		$clip = Fit::Cover === $fit ? sprintf( "%.3F %.3F %.3F %.3F re\nW n\n", $x, $y, $width, $height ) : '';

		$this->current .= "q\n" . $clip . $image->placement( $left, $bottom, $drawn_width, $drawn_height ) . '/' . $this->image_resource( $image ) . " Do\nQ\n";

		// A reader cuts the page off at its edge, and nothing about what is
		// left shows that anything was.
		$tolerance = 0.01;

		if ( $x < -$tolerance || $y < -$tolerance || $x + $width > $this->page_width + $tolerance || $y + $height > $this->page_height + $tolerance ) {
			$this->warn( $image->name . ' runs off the edge of the page, where a reader cuts it off.' );
		}

		return true;
	}

	/**
	 * Every image that was not drawn, or not drawn whole: one sentence each.
	 *
	 * A file that is not an image, a damaged or cut-short one, one too
	 * large to embed, a box with no size, a picture placed partly off the
	 * page. A missing logo looks like a design choice rather than a fault,
	 * so this is the one place that says so. It includes what the footer
	 * draws on each page.
	 *
	 * @since 1.4.0
	 *
	 * @return list<string> Empty when every image arrived whole.
	 */
	public function image_warnings(): array {
		return $this->with_footers()[1];
	}

	/**
	 * The resource name an image is drawn under, registered on first use.
	 *
	 * Keyed by what is embedded rather than by the object, so the same
	 * file read twice is still one image in the file.
	 *
	 * @since 1.4.0
	 *
	 * @param Image $image The image.
	 *
	 * @return string
	 */
	private function image_resource( Image $image ): string {
		foreach ( $this->images as $name => $known ) {
			if ( $known->digest === $image->digest ) {
				return $name;
			}
		}

		$name                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $name ] = $image;

		return $name;
	}

	/**
	 * Record an image warning, once however often it recurs.
	 *
	 * @since 1.4.0
	 *
	 * @param string $warning One sentence.
	 *
	 * @return void
	 */
	private function warn( string $warning ): void {
		if ( ! in_array( $warning, $this->image_warnings, true ) ) {
			$this->image_warnings[] = $warning;
		}
	}

	/**
	 * A measurement for a message: whole points without a fraction.
	 *
	 * @since 1.4.0
	 *
	 * @param float $points Points.
	 *
	 * @return string
	 */
	private static function points( float $points ): string {
		return rtrim( rtrim( sprintf( '%.2F', $points ), '0' ), '.' );
	}
}
