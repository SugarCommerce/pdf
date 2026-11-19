<?php
/**
 * A file carried inside a PDF.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Attachment
 *
 * A file a reader offers to save out, and — in a PDF/A-3 — one the
 * catalogue lists as an associated file, with how it relates to the page.
 * Factur-X is the case in mind: the invoice as XML, travelling inside the
 * invoice a person reads.
 *
 * @since 1.5.0
 */
final readonly class Attachment {

	/**
	 * The file name a reader offers when saving it out.
	 */
	public string $name;

	/**
	 * Its media type, e.g. `text/xml`.
	 */
	public string $mime_type;

	/**
	 * Describe a file to carry.
	 *
	 * @since 1.5.0
	 *
	 * @param string       $name         File name. Control characters and any
	 *                                   folder part are taken out: a reader
	 *                                   saves it under this name, and a name
	 *                                   with a path in it is how a file lands
	 *                                   somewhere it was not meant to.
	 * @param string       $contents     The bytes.
	 * @param string       $mime_type    Media type. Anything that is not
	 *                                   `type/subtype` is taken as
	 *                                   `application/octet-stream`.
	 * @param string       $description  Shown beside it in a reader's list.
	 * @param Relationship $relationship How it relates to the document.
	 * @param int|null     $modified     When it last changed, as a Unix
	 *                                   timestamp; null for the document's
	 *                                   creation date.
	 *
	 * @throws \InvalidArgumentException When the name is empty.
	 */
	public function __construct(
		string $name,
		public string $contents,
		string $mime_type = 'application/octet-stream',
		public string $description = '',
		public Relationship $relationship = Relationship::Unspecified,
		public ?int $modified = null,
	) {
		$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', basename( str_replace( '\\', '/', $name ) ) ) );

		if ( '' === $name || '.' === $name || '..' === $name ) {
			throw new \InvalidArgumentException( 'An attached file needs a name to be saved under.' );
		}

		$this->name      = $name;
		$this->mime_type = 1 === preg_match( '/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/', strtolower( trim( $mime_type ) ) )
			? strtolower( trim( $mime_type ) )
			: 'application/octet-stream';
	}
}
