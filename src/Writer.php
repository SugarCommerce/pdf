<?php
/**
 * PDF object serialisation.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf;

/**
 * Class Writer
 *
 * Turns page content streams into a PDF file.
 *
 * The cross-reference table is the part that has to be exact: readers
 * seek directly to the byte offsets it records, so an off-by-one
 * produces a file that opens in one viewer and fails in another. Offsets
 * are therefore measured from the assembled output rather than
 * calculated.
 *
 * @since 1.0.0
 */
final readonly class Writer {

	/**
	 * Serialise pages into a PDF.
	 *
	 * Objects are numbered as they are needed: the catalogue, the page
	 * tree and the information dictionary first, then each font the pages
	 * use — one object for a base-14 font, five for an embedded subset —
	 * then each image — one, or two with a soft mask — then each page and
	 * its content stream.
	 *
	 * Content streams are deflated (since 1.3.0). Text and drawing
	 * operators compress to about a fifth, and zlib is in every PHP build.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string>                   $pages    Content streams.
	 * @param float                          $width    Page width in points.
	 * @param float                          $height   Page height in points.
	 * @param array<string, string|int>      $metadata Title, Author, Subject,
	 *                                                 Keywords, Creator, and
	 *                                                 CreationDate as a Unix
	 *                                                 timestamp.
	 * @param string                         $language BCP 47 tag for the catalogue's
	 *                                                 `/Lang` (since 1.2.0), or empty
	 *                                                 for none. Anything else than
	 *                                                 letters, digits and hyphens is
	 *                                                 dropped.
	 * @param array<string, Font|FontSubset> $fonts    Resource name => font, every
	 *                                                 one the pages show text in
	 *                                                 (since 1.3.0).
	 * @param array<string, Image>           $images   Resource name => image, every
	 *                                                 one the pages draw, once each
	 *                                                 (since 1.4.0).
	 * @param list<Attachment>               $files    Files carried inside the
	 *                                                 document (since 1.5.0).
	 * @param bool                           $archival Write PDF/A-3b (since 1.5.0):
	 *                                                 see {@see Document::archival()}.
	 * @param list<string>                   $xmp      Extra `rdf:Description`
	 *                                                 elements for a PDF/A file's
	 *                                                 XMP packet (since 1.5.0).
	 *
	 * @return string Raw PDF bytes.
	 *
	 * @throws \LogicException When a PDF/A file is asked to carry what the
	 *                         standard forbids: a base-14 font, which cannot
	 *                         be embedded, or a CMYK image under its sRGB
	 *                         output intent. {@see Document} never asks.
	 */
	public static function write(
		array $pages,
		float $width,
		float $height,
		array $metadata = array(),
		string $language = '',
		array $fonts = array(),
		array $images = array(),
		array $files = array(),
		bool $archival = false,
		array $xmp = array()
	): string {
		$pages = array() === $pages ? array( '' ) : $pages;

		if ( $archival ) {
			self::refuse_unarchivable( $fonts, $images );
		}

		// One moment for every date the file states: the information
		// dictionary and the XMP packet must agree, to the second.
		$created  = self::timestamp( $metadata['CreationDate'] ?? $metadata['creationdate'] ?? null );
		$language = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $language );
		$objects  = array();

		$objects[3] = self::info( $metadata, $created, $archival );

		$next      = 4;
		$resources = array();

		foreach ( $fonts as $resource => $font ) {
			$resources[] = '/' . $resource . ' ' . $next . ' 0 R';

			if ( $font instanceof Font ) {
				$objects[ $next++ ] = $font->dictionary();
				continue;
			}

			$objects += $font->objects( $next );
			$next    += 5;
		}

		// Images after the fonts: one object each, and a second for the
		// soft mask of one with transparency.
		$xobjects = array();

		foreach ( $images as $resource => $image ) {
			$xobjects[] = '/' . $resource . ' ' . $next . ' 0 R';
			$parts      = $image->objects( $next );
			$objects   += $parts;
			$next      += count( $parts );
		}

		$resources = '/Resources <</Font <<' . implode( ' ', $resources ) . '>>'
			. ( array() !== $xobjects ? ' /XObject <<' . implode( ' ', $xobjects ) . '>>' : '' ) . '>> ';
		$page_refs = array();

		foreach ( $pages as $stream ) {
			$page_object    = $next++;
			$content_object = $next++;
			$page_refs[]    = $page_object . ' 0 R';
			$compressed     = (string) gzcompress( $stream, 9 );

			$objects[ $page_object ]    = '<</Type /Page /Parent 2 0 R ' . $resources . '/Contents ' . $content_object . ' 0 R>>';
			$objects[ $content_object ] = '<</Length ' . strlen( $compressed ) . " /Filter /FlateDecode>>\nstream\n" . $compressed . "\nendstream";
		}

		$objects[2] = '<</Type /Pages /Kids [' . implode( ' ', $page_refs ) . '] /Count ' . count( $pages )
			. sprintf( ' /MediaBox [0 0 %.2F %.2F]>>', $width, $height );

		$catalog = '/Type /Catalog /Pages 2 0 R' . ( '' !== $language ? ' /Lang (' . $language . ')' : '' );

		// Each file is a file specification and the stream it points at.
		// The name tree must be sorted by its keys, as bytes.
		$names      = array();
		$associated = array();

		foreach ( $files as $file ) {
			$key                  = self::text_string( $file->name );
			$names[ $key ]        = $key . ' ' . $next . ' 0 R';
			$associated[]         = $next . ' 0 R';
			$objects[ $next ]     = self::file_specification( $file, $next + 1, $archival );
			$objects[ $next + 1 ] = self::embedded_file( $file, $file->modified ?? $created );
			$next                += 2;
		}

		if ( array() !== $names ) {
			ksort( $names, SORT_STRING );

			$catalog .= ' /Names <</EmbeddedFiles <</Names [' . implode( ' ', $names ) . ']>>>>';
		}

		$identifier = '';

		if ( $archival ) {
			$packet             = Archival::xmp( self::fields( $metadata ), $created, $xmp );
			$objects[ $next ]   = '<</Type /Metadata /Subtype /XML /Length ' . strlen( $packet ) . ">>\nstream\n" . $packet . "\nendstream";
			$catalog           .= ' /Metadata ' . $next . ' 0 R';
			$profile            = (string) gzcompress( Archival::profile(), 9 );
			$objects[ ++$next ] = '<</N 3 /Length ' . strlen( $profile ) . " /Filter /FlateDecode>>\nstream\n" . $profile . "\nendstream";
			$catalog           .= ' /OutputIntents [<</Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1)'
				. ' /RegistryName (http://www.color.org) /Info (sRGB IEC61966-2.1) /DestOutputProfile ' . $next . ' 0 R>>]';
			++$next;

			if ( array() !== $associated ) {
				$catalog .= ' /AF [' . implode( ' ', $associated ) . ']';
			}

			$identifier = Archival::identifier( $objects );
		}

		$objects[1] = '<<' . $catalog . '>>';

		return self::serialise( $objects, $next - 1, $archival ? '1.7' : '1.4', $identifier );
	}

	/**
	 * Refuse what a PDF/A file cannot carry.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, Font|FontSubset> $fonts  The fonts.
	 * @param array<string, Image>           $images The images.
	 *
	 * @return void
	 *
	 * @throws \LogicException Naming the first.
	 */
	private static function refuse_unarchivable( array $fonts, array $images ): void {
		foreach ( $fonts as $font ) {
			if ( $font instanceof Font ) {
				throw new \LogicException( 'A PDF/A file must embed every font, and ' . $font->value . ' is one of the base fonts, which cannot be embedded.' );
			}
		}

		foreach ( $images as $image ) {
			if ( 'cmyk' === $image->color_space ) {
				throw new \LogicException( $image->name . ' is CMYK, which a PDF/A file with an sRGB output intent cannot carry.' );
			}
		}
	}

	/**
	 * A file specification: the name a reader saves the file under, what
	 * it is, and how it relates to the document.
	 *
	 * `/F` is the name in ASCII for readers that predate `/UF`, which
	 * holds it as written. PDF/A-3 requires both.
	 *
	 * @since 1.5.0
	 *
	 * @param Attachment $file     The file.
	 * @param int        $stream   The object number of its stream.
	 * @param bool       $archival Whether to state its relationship.
	 *
	 * @return string
	 */
	private static function file_specification( Attachment $file, int $stream, bool $archival ): string {
		$ascii = (string) preg_replace( '/[^\x20-\x7E]/u', '_', $file->name );

		return '<</Type /Filespec /F (' . Document::escape( $ascii ) . ') /UF ' . self::text_string( $file->name )
			. ( '' !== trim( $file->description ) ? ' /Desc ' . self::text_string( trim( $file->description ) ) : '' )
			. ( $archival || Relationship::Unspecified !== $file->relationship ? ' /AFRelationship /' . $file->relationship->value : '' )
			. ' /EF <</F ' . $stream . ' 0 R /UF ' . $stream . ' 0 R>>>>';
	}

	/**
	 * The stream that holds a file: compressed, with its media type, size
	 * and modification date.
	 *
	 * @since 1.5.0
	 *
	 * @param Attachment $file     The file.
	 * @param int        $modified Unix timestamp.
	 *
	 * @return string
	 */
	private static function embedded_file( Attachment $file, int $modified ): string {
		$compressed = (string) gzcompress( $file->contents, 9 );

		return '<</Type /EmbeddedFile /Subtype /' . self::name( $file->mime_type )
			. ' /Params <</Size ' . strlen( $file->contents ) . ' /ModDate ' . self::date( self::timestamp( $modified ) )
			. ' /CheckSum <' . strtoupper( md5( $file->contents ) ) . '>>> /Length ' . strlen( $compressed )
			. " /Filter /FlateDecode>>\nstream\n" . $compressed . "\nendstream";
	}

	/**
	 * A name object's characters, with anything a name cannot hold as
	 * itself written `#xx`: `text/xml` is `text#2Fxml`.
	 *
	 * @since 1.5.0
	 *
	 * @param string $value Raw name.
	 *
	 * @return string Without the leading slash.
	 */
	private static function name( string $value ): string {
		return (string) preg_replace_callback(
			'/[^!-~]|[#%()\/<>\[\]{}]/',
			static fn( array $match ): string => sprintf( '#%02X', ord( $match[0] ) ),
			$value
		);
	}

	/**
	 * A PDF date: `(D:20260131120000Z)`.
	 *
	 * @since 1.5.0
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	private static function date( int $timestamp ): string {
		return '(D:' . gmdate( 'YmdHis', $timestamp ) . 'Z)';
	}

	/**
	 * The document information fields a caller can set, cleaned once.
	 *
	 * Trimmed, invalid UTF-8 replaced and control characters taken out —
	 * the same values for the information dictionary and the XMP packet,
	 * which a PDF/A validator compares character for character.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string|int> $metadata Caller-supplied values.
	 *
	 * @return array<string, string> Field => value, empty ones left out.
	 */
	private static function fields( array $metadata ): array {
		$fields = array();

		foreach ( array( 'Title', 'Author', 'Subject', 'Keywords', 'Creator' ) as $field ) {
			$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', mb_scrub( (string) ( $metadata[ $field ] ?? $metadata[ strtolower( $field ) ] ?? '' ), 'UTF-8' ) ) );

			if ( '' !== $value ) {
				$fields[ $field ] = $value;
			}
		}

		return $fields;
	}

	/**
	 * Build the document information dictionary.
	 *
	 * @since 1.0.0
	 *
	 * `CreationDate` (since 1.1.0), a Unix timestamp, fixes the one value
	 * that otherwise changes from run to run — so the same document renders
	 * to the same bytes, which is what a content-addressed store or a
	 * snapshot test needs. Without it, the time of rendering.
	 *
	 * @param array<string, string|int> $metadata Metadata values.
	 * @param int                       $created  Unix timestamp.
	 * @param bool                      $archival Whether to add `ModDate` (since 1.5.0).
	 *
	 * @return string
	 */
	private static function info( array $metadata, int $created, bool $archival = false ): string {
		$parts = array();

		foreach ( self::fields( $metadata ) as $field => $value ) {
			$parts[] = '/' . $field . ' ' . self::text_string( $value );
		}

		$parts[] = '/Producer (' . Archival::PRODUCER . ')';
		$parts[] = '/CreationDate ' . self::date( $created );

		// The XMP packet states a modification date, and the two must agree.
		if ( $archival ) {
			$parts[] = '/ModDate ' . self::date( $created );
		}

		return '<<' . implode( ' ', $parts ) . '>>';
	}

	/**
	 * Encode a value as a PDF text string for the information dictionary.
	 *
	 * Plain ASCII stays a readable literal. Anything else is written as
	 * UTF-16BE with a byte-order mark, in hex: the information dictionary
	 * is read as PDFDocEncoding or UTF-16, never Windows-1252, so the
	 * WinAnsi bytes used on the page put the wrong character in a viewer's
	 * title bar — an em dash showed as an unrelated letter and a euro sign
	 * as a bullet. UTF-16 also keeps names the page fonts cannot draw, and
	 * a hex string has no delimiter to break out of.
	 *
	 * Control characters are removed either way, and invalid UTF-8 is
	 * replaced rather than passed through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value UTF-8 text.
	 *
	 * @return string A complete PDF string object, delimiters included.
	 */
	private static function text_string( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', mb_scrub( $value, 'UTF-8' ) );

		if ( 1 !== preg_match( '/[^\x20-\x7E]/', $value ) ) {
			return '(' . Document::escape( $value ) . ')';
		}

		return '<FEFF' . strtoupper( bin2hex( mb_convert_encoding( $value, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}

	/**
	 * The creation time to record.
	 *
	 * A number, or a numeric string, is taken as a Unix timestamp;
	 * anything else — absent, empty, a date string — means now. Only
	 * years 0001-9999 fit the date format, so a value outside them is
	 * clamped rather than written as a five-digit or negative year, and
	 * rather than cast from a float too large for an int.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $created Caller-supplied value.
	 *
	 * @return int Unix timestamp.
	 */
	private static function timestamp( mixed $created ): int {
		if ( ! is_int( $created ) && ! is_float( $created ) && ! ( is_string( $created ) && is_numeric( $created ) ) ) {
			return time();
		}

		$created = (float) $created;

		if ( is_nan( $created ) ) {
			return time();
		}

		// 0001-01-01 00:00:00 to 9999-12-31 23:59:59, UTC.
		return (int) max( -62135596800.0, min( 253402300799.0, $created ) );
	}

	/**
	 * Serialise objects with a correct cross-reference table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $objects    Object number => body.
	 * @param int                $max        Highest object number.
	 * @param string             $version    The header's version (since 1.5.0).
	 * @param string             $identifier The file identifier, in hex, or
	 *                                       empty for none (since 1.5.0).
	 *
	 * @return string
	 */
	private static function serialise( array $objects, int $max, string $version = '1.4', string $identifier = '' ): string {
		// The comment of high bytes tells a transfer tool the file is
		// binary, now that its streams are compressed.
		$pdf     = '%PDF-' . $version . "\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		for ( $n = 1; $n <= $max; $n++ ) {
			$offsets[ $n ] = strlen( $pdf );
			$pdf          .= $n . " 0 obj\n" . ( $objects[ $n ] ?? '<<>>' ) . "\nendobj\n";
		}

		$xref = strlen( $pdf );

		$pdf .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";

		for ( $n = 1; $n <= $max; $n++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $n ] );
		}

		$id = '' !== $identifier ? ' /ID [<' . $identifier . '> <' . $identifier . '>]' : '';

		return $pdf . "trailer\n<</Size " . ( $max + 1 ) . ' /Root 1 0 R /Info 3 0 R' . $id . ">>\nstartxref\n" . $xref . "\n%%EOF";
	}
}
