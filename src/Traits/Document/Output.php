<?php
/**
 * The finished file.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Fonts\FontSubset;
use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Output\Writer;

/**
 * The pages, with their footers, as the bytes of a PDF.
 *
 * @since 1.0.0
 */
trait Output {

	/**
	 * Serialise to PDF bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string|int> $metadata Title, Author, Subject,
	 *                                            Keywords, Creator, and
	 *                                            CreationDate (a Unix
	 *                                            timestamp, since 1.1.0)
	 *                                            for the same bytes on
	 *                                            every render.
	 *
	 * @return string
	 */
	public function render( array $metadata = array() ): string {
		$pages = $this->pages;

		if ( '' !== $this->current || array() === $pages ) {
			$pages[] = $this->current;
		}

		$saved   = $this->drawn;
		$stamped = $this->apply_footers( $pages );

		// The footers' text belongs to this render, not to the document:
		// rendering twice must not record the page numbers twice.
		$this->drawn = $saved;

		$fonts = array();

		foreach ( $this->resources as $resource => $font ) {
			$fonts[ $resource ] = $font instanceof Font ? $font : $this->subsets[ $font ];
		}

		return Writer::write(
			$stamped,
			$this->page_width,
			$this->page_height,
			$metadata,
			$this->language,
			$fonts,
			$this->images,
			array_values( $this->attachments ),
			$this->archival,
			$this->xmp
		);
	}

	/**
	 * Write the document to a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $path     Destination.
	 * @param array<string, string|int> $metadata Document metadata, as
	 *                                            for {@see render()}.
	 *
	 * @return int Bytes written.
	 *
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function save( string $path, array $metadata = array() ): int {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed write throws below, with the path; the warning would only repeat it.
		$written = @file_put_contents( $path, $this->render( $metadata ) );

		if ( false === $written ) {
			throw new \RuntimeException( 'Could not write the PDF to: ' . $path );
		}

		return $written;
	}

	/**
	 * Draw the registered footer onto every page.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $pages Page streams.
	 *
	 * @return list<string>
	 */
	private function apply_footers( array $pages ): array {
		if ( null === $this->footer ) {
			return $pages;
		}

		$total    = count( $pages );
		$callback = $this->footer;
		$saved    = $this->current;
		$saved_y  = $this->y;

		foreach ( $pages as $index => $stream ) {
			$this->current = '';
			$callback( $this, $index + 1, $total );
			$pages[ $index ] = $stream . $this->current;
		}

		$this->current = $saved;
		$this->y       = $saved_y;

		return $pages;
	}

	/**
	 * What the footers would add, asked without drawing them for good.
	 *
	 * @since 1.4.0
	 *
	 * @return array{0: list<string>, 1: list<string>} The drawn text and the
	 *                                                 image warnings, footers
	 *                                                 included.
	 */
	private function with_footers(): array {
		if ( null === $this->footer ) {
			return array( $this->drawn, $this->image_warnings );
		}

		// The footers are drawn to be read, not kept: the glyphs, fonts and
		// images they use are put back too, or asking would change the file.
		$saved          = $this->drawn;
		$resources      = $this->resources;
		$substituted    = $this->substituted;
		$subsets        = array_map( static fn( FontSubset $subset ): FontSubset => clone $subset, $this->subsets );
		$images         = $this->images;
		$image_warnings = $this->image_warnings;
		$pages          = $this->pages;
		$pages[]        = $this->current;

		$this->apply_footers( $pages );

		$result = array( array_merge( $saved, array_slice( $this->drawn, count( $saved ) ) ), $this->image_warnings );

		$this->drawn          = $saved;
		$this->resources      = $resources;
		$this->substituted    = $substituted;
		$this->subsets        = $subsets;
		$this->images         = $images;
		$this->image_warnings = $image_warnings;

		return $result;
	}
}
