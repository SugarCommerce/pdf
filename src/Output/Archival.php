<?php
/**
 * The pieces a PDF/A-3 file needs.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Output;

use SugarCommerce\Pdf\Document;

/**
 * Class Archival
 *
 * The XMP metadata packet, the sRGB profile and the file identifier that
 * {@see Writer} adds to a document made {@see Document::archival()}.
 *
 * PDF/A exists so a file means the same thing in twenty years as today,
 * opened by software nobody has written yet. So it carries everything it
 * depends on — fonts, the colour space its colours are in — and says
 * what it is in a form that software can read without understanding PDF.
 *
 * @since 1.5.0
 */
final class Archival {

	/**
	 * What the file says produced it, in the information dictionary and
	 * the XMP packet alike.
	 */
	public const string PRODUCER = 'sugarcommerce/pdf';

	/**
	 * The ICC profile, relative to the package.
	 *
	 * sRGB-v2-micro from Clinton Ingram's Compact-ICC-Profiles, released
	 * under CC0 1.0 (`icc/LICENSE.txt`): a version 2 profile of sRGB in
	 * 456 bytes, where the usual one is 3 KB.
	 */
	public const string PROFILE = 'icc/sRGB-v2-micro.icc';

	/**
	 * The sRGB profile the output intent carries.
	 *
	 * @since 1.5.0
	 *
	 * @return string The profile's bytes.
	 *
	 * @throws \RuntimeException When the file is missing from the package.
	 */
	public static function profile(): string {
		static $profile = null;

		if ( null === $profile ) {
			$path    = dirname( __DIR__, 2 ) . '/' . self::PROFILE;
			$profile = is_file( $path ) ? file_get_contents( $path ) : false;

			if ( ! is_string( $profile ) || strlen( $profile ) < 128 || 'acsp' !== substr( $profile, 36, 4 ) ) {
				$profile = null;

				throw new \RuntimeException( 'The sRGB profile a PDF/A file carries is missing from the package: ' . $path );
			}
		}

		return $profile;
	}

	/**
	 * The XMP packet.
	 *
	 * Dublin Core for the title, author and subject; the XMP and PDF
	 * schemas for the dates, keywords and producer; and the PDF/A
	 * identification, part 3, conformance B. Every value is the one the
	 * information dictionary holds — a validator compares them, and a
	 * file whose two halves disagree fails for that alone.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string> $fields  Title, Author, Subject, Keywords
	 *                                       and Creator, already cleaned.
	 * @param int                   $created Unix timestamp: the creation and
	 *                                       modification date.
	 * @param list<string>          $extras  Further `rdf:Description`
	 *                                       elements, written verbatim.
	 *
	 * @return string UTF-8, uncompressed, as PDF/A requires.
	 */
	public static function xmp( array $fields, int $created, array $extras = array() ): string {
		$stamp = gmdate( 'Y-m-d\TH:i:s\Z', $created );
		$dc    = '';
		$pdf   = '';

		if ( isset( $fields['Title'] ) ) {
			$dc .= "\n      <dc:title><rdf:Alt><rdf:li xml:lang=\"x-default\">" . self::escape( $fields['Title'] ) . '</rdf:li></rdf:Alt></dc:title>';
		}

		if ( isset( $fields['Author'] ) ) {
			$dc .= "\n      <dc:creator><rdf:Seq><rdf:li>" . self::escape( $fields['Author'] ) . '</rdf:li></rdf:Seq></dc:creator>';
		}

		if ( isset( $fields['Subject'] ) ) {
			$dc .= "\n      <dc:description><rdf:Alt><rdf:li xml:lang=\"x-default\">" . self::escape( $fields['Subject'] ) . '</rdf:li></rdf:Alt></dc:description>';
		}

		if ( isset( $fields['Keywords'] ) ) {
			$pdf .= "\n      <pdf:Keywords>" . self::escape( $fields['Keywords'] ) . '</pdf:Keywords>';
		}

		$tool = isset( $fields['Creator'] ) ? "\n      <xmp:CreatorTool>" . self::escape( $fields['Creator'] ) . '</xmp:CreatorTool>' : '';

		return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
			. "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
			. "  <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
			. '    <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n      <dc:format>application/pdf</dc:format>" . $dc . "\n    </rdf:Description>\n"
			. '    <rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">'
			. "\n      <xmp:CreateDate>" . $stamp . '</xmp:CreateDate>'
			. "\n      <xmp:ModifyDate>" . $stamp . '</xmp:ModifyDate>'
			. "\n      <xmp:MetadataDate>" . $stamp . '</xmp:MetadataDate>' . $tool . "\n    </rdf:Description>\n"
			. '    <rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'
			. "\n      <pdf:Producer>" . self::PRODUCER . '</pdf:Producer>' . $pdf . "\n    </rdf:Description>\n"
			. '    <rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">'
			. "\n      <pdfaid:part>3</pdfaid:part>\n      <pdfaid:conformance>B</pdfaid:conformance>\n    </rdf:Description>\n"
			. ( array() !== $extras ? implode( "\n", $extras ) . "\n" : '' )
			. "  </rdf:RDF>\n"
			. "</x:xmpmeta>\n"
			. '<?xpacket end="w"?>';
	}

	/**
	 * The file identifier: a digest of every object, so the same document
	 * written twice has the same one.
	 *
	 * @since 1.5.0
	 *
	 * @param array<int, string> $objects Object number => body.
	 *
	 * @return string 32 upper-case hex digits.
	 */
	public static function identifier( array $objects ): string {
		ksort( $objects );

		$context = hash_init( 'md5' );

		foreach ( $objects as $number => $body ) {
			hash_update( $context, $number . "\0" . $body . "\0" );
		}

		return strtoupper( hash_final( $context ) );
	}

	/**
	 * Text for an XML element or attribute.
	 *
	 * Markup is escaped, bytes that are not UTF-8 become U+FFFD, and so
	 * does any code point XML 1.0 does not allow -- the C0 controls, and
	 * U+FFFE and U+FFFF, which are valid UTF-8 -- since one of them in a
	 * name leaves a file no parser opens.
	 *
	 * @since 1.5.0
	 *
	 * @param string $text UTF-8.
	 *
	 * @return string
	 */
	public static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8' );
	}
}
