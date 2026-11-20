<?php
/**
 * A document made to PDF/A-3b, and the files it carries.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Traits\Document;

use SugarCommerce\Pdf\Attachment;

/**
 * The archival switch, the files attached to a document and the XMP
 * description an e-invoice adds.
 *
 * @since 1.0.0
 */
trait Archiving {

	/**
	 * Write the file as PDF/A-3b: archivable, and allowed to carry files.
	 *
	 * The level Factur-X and ZUGFeRD e-invoices are built on. What it
	 * changes:
	 *
	 * - **Every font is embedded.** The base-14 fonts belong to the reader,
	 *   so they cannot be used at all: text is set in the document's
	 *   {@see family()}, or the bundled Noto Sans when it has none, and
	 *   monospaced text in that family's regular face when it has no
	 *   monospaced one. A character no font in reach has prints as `?` in
	 *   the family and is listed by {@see substitutions()}, as before.
	 * - **Colour is sRGB.** The file carries an sRGB output intent (a
	 *   456-byte CC0 profile, `icc/`), and a CMYK JPEG cannot be drawn
	 *   under it: {@see image()} refuses one and says so in
	 *   {@see image_warnings()}. Convert it to RGB.
	 * - **The file says what it is.** An XMP metadata packet that agrees
	 *   with the information dictionary and declares PDF/A-3 part B, a
	 *   file identifier made from the content, and a `%PDF-1.7` header.
	 *   Every file {@see attach()}ed is listed as an associated file with
	 *   its relationship.
	 *
	 * Set it before drawing anything: what is drawn is set in the fonts
	 * the mode allows.
	 *
	 * @since 1.5.0
	 *
	 * @return self
	 *
	 * @throws \LogicException When something has already been drawn.
	 */
	public function archival(): self {
		if ( array() !== $this->pages || '' !== $this->current || array() !== $this->resources || array() !== $this->images ) {
			throw new \LogicException( 'Make a document archival before drawing on it: what is already drawn may be set in fonts a PDF/A file cannot carry.' );
		}

		$this->archival = true;
		$this->resolved = array();

		return $this;
	}

	/**
	 * Whether the file is written as PDF/A-3b.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public function is_archival(): bool {
		return $this->archival;
	}

	/**
	 * Carry a file inside the document.
	 *
	 * A reader lists it and offers to save it out. In a PDF/A-3 it is also
	 * an associated file of the document, with its relationship — which
	 * is how a Factur-X reader finds the invoice XML. A second file under
	 * the same name replaces the first.
	 *
	 * @since 1.5.0
	 *
	 * @param Attachment $file The file.
	 *
	 * @return self
	 */
	public function attach( Attachment $file ): self {
		$this->attachments[ $file->name ] = $file;

		return $this;
	}

	/**
	 * The files the document carries, in the order attached.
	 *
	 * @since 1.5.0
	 *
	 * @return list<Attachment>
	 */
	public function attachments(): array {
		return array_values( $this->attachments );
	}

	/**
	 * Add an `rdf:Description` to the XMP packet of a PDF/A file.
	 *
	 * PDF/A allows only the XMP properties a file declares a schema for,
	 * so a description using a schema of its own must come with its
	 * extension schema — Factur-X's `fx:` properties are the case in mind.
	 * Written verbatim: it must be well-formed RDF/XML.
	 *
	 * @since 1.5.0
	 *
	 * @param string $description One or more `rdf:Description` elements.
	 *
	 * @return self
	 */
	public function xmp( string $description ): self {
		$this->xmp[] = $description;

		return $this;
	}
}
