<?php
/**
 * How a file carried inside a document relates to it.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Enum Relationship
 *
 * PDF/A-3's `/AFRelationship`, written on every attached file. Not
 * decoration: a Factur-X reader looks at it to find the invoice XML, and
 * a validator checks that it agrees with the profile the XML declares.
 *
 * @since 1.5.0
 */
enum Relationship: string {

	/**
	 * The same content in another form: the whole invoice as XML.
	 */
	case Alternative = 'Alternative';

	/**
	 * What the document was made from.
	 */
	case Source = 'Source';

	/**
	 * Data the document's content is drawn from or refers to: a part of
	 * the invoice as XML, as Factur-X's Minimum and Basic WL are.
	 */
	case Data = 'Data';

	/**
	 * Supplementary material.
	 */
	case Supplement = 'Supplement';

	/**
	 * None of the above, or not known.
	 */
	case Unspecified = 'Unspecified';
}
