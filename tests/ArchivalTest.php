<?php
/**
 * PDF/A-3b: what an archival file carries that an ordinary one does not,
 * and what it refuses to carry.
 *
 * veraPDF passes the whole of every golden document in ConformanceTest;
 * these pin each rule on its own, so a regression names the rule it broke
 * on any machine, with or without Java.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\{Attachment, Document, Font, FontFamily, Image, Relationship};
use SugarCommerce\Pdf\Fonts\TrueType;
use SugarCommerce\Pdf\Output\{Archival, Writer};
use SugarCommerce\Pdf\Tests\Support\{FontBuilder, Pictures, Reader};

final class ArchivalTest extends TestCase {

	private const CREATED = 1795000000;

	/**
	 * A small archival document.
	 *
	 * @param array<string, string|int> $metadata Extra metadata.
	 *
	 * @return string
	 */
	private static function archival( array $metadata = array() ): string {
		return ( new Document() )->archival()->text( 'Northwind Audio — Łódź' )->render( $metadata + array( 'CreationDate' => self::CREATED ) );
	}

	/**
	 * The one object whose dictionary matches.
	 *
	 * @param string $bytes   A PDF.
	 * @param string $pattern Regular expression on the dictionary.
	 *
	 * @return array{dict: string, stream: string|null}
	 */
	private function object( string $bytes, string $pattern ): array {
		$found = array_values( array_filter( Reader::objects( $bytes ), static fn( array $object ): bool => 1 === preg_match( $pattern, $object['dict'] ) ) );

		$this->assertCount( 1, $found, $pattern );

		return $found[0];
	}

	/**
	 * The XMP packet, parsed.
	 *
	 * @param string $bytes A PDF.
	 *
	 * @return \DOMXPath
	 */
	private function xmp( string $bytes ): \DOMXPath {
		$catalog = $this->object( $bytes, '/\/Type \/Catalog/' )['dict'];

		$this->assertSame( 1, preg_match( '/\/Metadata (\d+) 0 R/', $catalog, $number ) );

		$metadata = Reader::objects( $bytes )[ (int) $number[1] ];

		// PDF/A wants the packet readable without a PDF decoder.
		$this->assertStringContainsString( '/Type /Metadata /Subtype /XML', $metadata['dict'] );
		$this->assertStringNotContainsString( '/Filter', $metadata['dict'] );
		$this->assertStringStartsWith( "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>", (string) $metadata['stream'] );
		$this->assertStringEndsWith( '<?xpacket end="w"?>', (string) $metadata['stream'] );

		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( (string) $metadata['stream'] ), 'the XMP packet is not well-formed' );

		$xpath = new \DOMXPath( $dom );

		foreach ( array(
			'rdf'    => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
			'dc'     => 'http://purl.org/dc/elements/1.1/',
			'xmp'    => 'http://ns.adobe.com/xap/1.0/',
			'pdf'    => 'http://ns.adobe.com/pdf/1.3/',
			'pdfaid' => 'http://www.aiim.org/pdfa/ns/id/',
			'fx'     => 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#',
		) as $prefix => $uri ) {
			$xpath->registerNamespace( $prefix, $uri );
		}

		return $xpath;
	}

	/**
	 * A text string from the information dictionary, decoded.
	 *
	 * @param string $info  The dictionary.
	 * @param string $field e.g. `Title`.
	 *
	 * @return string|null
	 */
	private static function info_field( string $info, string $field ): ?string {
		if ( 1 === preg_match( '/\/' . $field . ' <FEFF([0-9A-F]*)>/', $info, $hex ) ) {
			return (string) mb_convert_encoding( (string) hex2bin( $hex[1] ), 'UTF-8', 'UTF-16BE' );
		}

		if ( 1 === preg_match( '/\/' . $field . ' \(((?:\\\\.|[^\\\\)])*)\)/', $info, $literal ) ) {
			return (string) preg_replace( '/\\\\(.)/', '$1', $literal[1] );
		}

		return null;
	}

	/* ─── The file says what it is ──────────────────────────────────── */

	public function test_the_header_is_pdf_1_7_with_the_binary_comment(): void {
		$this->assertStringStartsWith( "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n", self::archival() );
		$this->assertStringStartsWith( '%PDF-1.4', ( new Document() )->text( 'x' )->render() );
	}

	public function test_the_xmp_packet_declares_pdf_a_3b(): void {
		$xpath = $this->xmp( self::archival() );

		$this->assertSame( '3', $xpath->evaluate( 'string(//pdfaid:part)' ) );
		$this->assertSame( 'B', $xpath->evaluate( 'string(//pdfaid:conformance)' ) );
	}

	/**
	 * A title with U+FFFF in it -- an order number, a customer's name --
	 * left the XMP packet a file no XML parser opens, and so a PDF/A that
	 * fails validation.
	 */
	public function test_a_non_character_cannot_make_the_xmp_unreadable(): void {
		$packet = Archival::xmp( array( 'Title' => "Invoice\u{FFFF} for \u{FFFE}Ana" ), 0 );
		$dom    = new \DOMDocument();

		libxml_use_internal_errors( true );
		$loaded = $dom->loadXML( $packet );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		$this->assertTrue( $loaded, 'The XMP packet is well-formed.' );
		$this->assertStringContainsString( "Invoice\u{FFFD} for \u{FFFD}Ana", $packet );
	}

	public function test_every_information_field_agrees_with_the_xmp_packet(): void {
		$metadata = array(
			'Title'    => "Invoice  INV-1 & <Łódź> \x01",
			'Author'   => 'Ярослав Ковальчук',
			'Subject'  => 'Invoice for "Acme" (Ltd)',
			'Keywords' => 'invoice, factur-x',
			'Creator'  => 'SugarCart',
		);
		$bytes    = self::archival( $metadata );
		$info     = $this->object( $bytes, '/\/Producer/' )['dict'];
		$xpath    = $this->xmp( $bytes );

		$pairs = array(
			'Title'    => 'string(//dc:title/rdf:Alt/rdf:li[@xml:lang="x-default"])',
			'Author'   => 'string(//dc:creator/rdf:Seq/rdf:li)',
			'Subject'  => 'string(//dc:description/rdf:Alt/rdf:li[@xml:lang="x-default"])',
			'Keywords' => 'string(//pdf:Keywords)',
			'Creator'  => 'string(//xmp:CreatorTool)',
			'Producer' => 'string(//pdf:Producer)',
		);

		foreach ( $pairs as $field => $query ) {
			$this->assertNotNull( self::info_field( $info, $field ), $field );
			$this->assertSame( self::info_field( $info, $field ), $xpath->evaluate( $query ), $field );
		}

		$this->assertSame( 'Invoice  INV-1 & <Łódź>', self::info_field( $info, 'Title' ) );
		$this->assertSame( 1.0, $xpath->evaluate( 'count(//dc:creator/rdf:Seq/rdf:li)' ) );
		$this->assertSame( Archival::PRODUCER, self::info_field( $info, 'Producer' ) );
	}

	public function test_the_dates_agree_to_the_second(): void {
		$bytes = self::archival();
		$info  = $this->object( $bytes, '/\/Producer/' )['dict'];
		$xpath = $this->xmp( $bytes );
		$stamp = gmdate( 'YmdHis', self::CREATED );
		$iso   = gmdate( 'Y-m-d\TH:i:s\Z', self::CREATED );

		$this->assertStringContainsString( '/CreationDate (D:' . $stamp . 'Z)', $info );
		$this->assertStringContainsString( '/ModDate (D:' . $stamp . 'Z)', $info );
		$this->assertSame( $iso, $xpath->evaluate( 'string(//xmp:CreateDate)' ) );
		$this->assertSame( $iso, $xpath->evaluate( 'string(//xmp:ModifyDate)' ) );
		$this->assertSame( $iso, $xpath->evaluate( 'string(//xmp:MetadataDate)' ) );
	}

	public function test_an_empty_field_is_in_neither_half(): void {
		$bytes = self::archival( array( 'Title' => "  \x01 " ) );

		$this->assertNull( self::info_field( $this->object( $bytes, '/\/Producer/' )['dict'], 'Title' ) );
		$this->assertSame( 0.0, $this->xmp( $bytes )->evaluate( 'count(//dc:title)' ) );
	}

	public function test_extra_xmp_descriptions_are_carried(): void {
		$description = '<rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#"><fx:Version>1.0</fx:Version></rdf:Description>';
		$bytes       = ( new Document() )->archival()->xmp( $description )->text( 'x' )->render();

		$this->assertSame( '1.0', $this->xmp( $bytes )->evaluate( 'string(//fx:Version)' ) );
	}

	public function test_the_file_identifier_is_from_the_content(): void {
		$this->assertSame( 1, preg_match( '/\/ID \[<([0-9A-F]{32})> <\1>\]/', self::archival(), $first ) );

		// The same document, the same identifier: the same bytes.
		$this->assertSame( self::archival(), self::archival() );

		$this->assertSame( 1, preg_match( '/\/ID \[<([0-9A-F]{32})>/', ( new Document() )->archival()->text( 'Other' )->render( array( 'CreationDate' => self::CREATED ) ), $other ) );
		$this->assertNotSame( $first[1], $other[1] );

		$this->assertStringNotContainsString( '/ID [', ( new Document() )->text( 'x' )->render() );
	}

	/* ─── Colour ────────────────────────────────────────────────────── */

	public function test_the_output_intent_carries_the_srgb_profile(): void {
		$bytes   = self::archival();
		$catalog = $this->object( $bytes, '/\/Type \/Catalog/' )['dict'];

		$this->assertSame( 1, preg_match( '/\/OutputIntents \[<<\/Type \/OutputIntent \/S \/GTS_PDFA1 \/OutputConditionIdentifier \(sRGB IEC61966-2\.1\).* \/DestOutputProfile (\d+) 0 R>>\]/', $catalog, $intent ) );

		$profile = Reader::objects( $bytes )[ (int) $intent[1] ];

		$this->assertStringStartsWith( '<</N 3 ', $profile['dict'] );
		$this->assertSame( file_get_contents( dirname( __DIR__ ) . '/' . Archival::PROFILE ), $profile['stream'] );
		$this->assertSame( Archival::profile(), $profile['stream'] );
	}

	public function test_the_bundled_profile_is_an_srgb_display_profile_of_version_2(): void {
		$profile = Archival::profile();

		$this->assertSame( strlen( $profile ), unpack( 'N', substr( $profile, 0, 4 ) )[1] );
		$this->assertSame( 2, ord( $profile[8] ), 'PDF/A-3 takes ICC versions up to 4; version 2 every reader knows' );
		$this->assertSame( 'mntr', substr( $profile, 12, 4 ) );
		$this->assertSame( 'RGB ', substr( $profile, 16, 4 ) );
		$this->assertSame( 'acsp', substr( $profile, 36, 4 ) );
		$this->assertStringContainsString( 'CC0', (string) file_get_contents( dirname( __DIR__ ) . '/icc/LICENSE.txt' ) );
	}

	public function test_a_cmyk_jpeg_is_refused_and_said_so(): void {
		$cmyk = Image::file( Pictures::path( 'cmyk.jpg' ) );
		$pdf  = ( new Document() )->archival();

		$this->assertFalse( $pdf->image( $cmyk, 56, 600, 100, 100 ) );
		$this->assertNull( $pdf->load_image( Pictures::path( 'cmyk.jpg' ) ) );
		$this->assertCount( 1, $pdf->image_warnings() );
		$this->assertStringContainsString( 'CMYK', $pdf->image_warnings()[0] );
		$this->assertStringNotContainsString( '/DeviceCMYK', Reader::plain( $pdf->render() ) );

		// An ordinary document draws it as before.
		$this->assertTrue( ( new Document() )->image( $cmyk, 56, 600, 100, 100 ) );
	}

	public function test_rgb_grey_and_palette_pictures_are_drawn(): void {
		$pdf = ( new Document() )->archival();

		foreach ( array( 'photo.jpg', 'gray.jpg', 'logo.png', 'palette.png' ) as $file ) {
			$this->assertTrue( $pdf->image( Pictures::path( $file ), 56, 600, 100, 100 ), $file );
		}

		$this->assertSame( array(), $pdf->image_warnings() );
		$this->assertStringNotContainsString( '/Interpolate', Reader::plain( $pdf->render() ) );
	}

	/* ─── Fonts ─────────────────────────────────────────────────────── */

	public function test_no_base_font_is_used_in_any_role(): void {
		$pdf = ( new Document() )->archival();

		foreach ( Font::cases() as $font ) {
			$pdf->text( 'Plain ASCII in ' . $font->value, null, $font );
		}

		$plain = Reader::plain( $pdf->render() );

		$this->assertStringNotContainsString( '/Subtype /Type1', $plain );
		$this->assertStringNotContainsString( '/BaseFont /Helvetica', $plain );
		$this->assertStringNotContainsString( '/BaseFont /Courier', $plain );
		$this->assertSame( 2, substr_count( $plain, '/Subtype /CIDFontType2' ), 'Noto Sans regular, for regular, italic and monospaced, and bold' );
	}

	public function test_a_chosen_family_is_used_and_the_bundled_one_is_not_added(): void {
		$family = FontFamily::noto_sans();
		$pdf    = ( new Document() )->archival()->family( $family )->text( 'Hello', null, Font::Courier );

		$this->assertSame( $family, $pdf->font_family() );
		$this->assertCount( 1, Reader::embedded_fonts( $pdf->render() ) );
	}

	public function test_text_reads_back_as_written(): void {
		$bytes = ( new Document() )->archival()->text( 'Ярослав Ковальчук, Łódź — €1,234.56' )->render();

		$this->assertStringContainsString( 'Ярослав Ковальчук, Łódź — €1,234.56', Reader::text( $bytes ) );
	}

	/**
	 * A character no font in reach has prints as the base font would have
	 * printed it — `?` — but in the family, and never as glyph 0, the
	 * `.notdef` PDF/A forbids showing.
	 */
	public function test_what_nothing_can_print_is_a_question_mark_in_the_family_and_reported(): void {
		$pdf = ( new Document() )->archival()->text( 'Name: مرحبا 東京' );

		$this->assertSame( array( 'Name: مرحبا 東京' ), $pdf->substitutions() );
		$this->assertSame( array( 'Name: ????? ??' ), $pdf->drawn_text() );

		$bytes = $pdf->render();

		$this->assertStringContainsString( 'Name: ????? ??', Reader::text( $bytes ) );

		foreach ( Reader::streams( $bytes ) as $stream ) {
			preg_match_all( '/<([0-9A-F]*)> Tj/', $stream, $shows );

			$this->assertNotEmpty( $shows[1] );

			foreach ( $shows[1] as $hex ) {
				$this->assertNotContains( '0000', str_split( $hex, 4 ), 'the .notdef glyph was shown' );
			}
		}
	}

	public function test_bytes_that_are_not_utf8_print_as_question_marks(): void {
		$pdf = ( new Document() )->archival()->text( "Caf\xE9" );

		$this->assertSame( array( 'Caf?' ), $pdf->drawn_text() );
		$this->assertStringNotContainsString( '/Type1', Reader::plain( $pdf->render() ) );
	}

	public function test_a_letter_the_base_font_would_strip_keeps_the_same_stand_in(): void {
		$family = new FontFamily( FontFamily::noto_sans()->regular );
		$plain  = ( new Document() )->fallback( null )->text( 'Act Ⅱ ①' );
		$pdf    = ( new Document() )->archival()->family( $family )->fallback( null )->text( 'Act Ⅱ ①' );

		$this->assertSame( array( 'Act II (1)' ), $pdf->drawn_text() );
		$this->assertSame( $plain->drawn_text(), $pdf->drawn_text() );
		$this->assertSame( $plain->substitutions(), $pdf->substitutions() );
	}

	/**
	 * A family without `€` — the built test font has `A`, `B` and a few
	 * accented capitals, and no `€`, `a` or `?` — leaves the euro to the
	 * fallback, as Helvetica would have drawn it in an ordinary file.
	 */
	public function test_what_the_family_lacks_goes_to_the_fallback_not_the_base_font(): void {
		$pdf = ( new Document() )->archival()->family( new FontFamily( new TrueType( FontBuilder::font() ) ) )->text( "AB € A€\u{1F600}" );

		// The last word is drawn a letter at a time: the family has the A
		// and the emoji, the fallback only the euro.
		$this->assertSame( array(), $pdf->substitutions() );
		$this->assertSame( array( "AB € A€\u{1F600}" ), $pdf->drawn_text() );
		$this->assertSame( array( 'NotoSans-Regular' ), array_values( array_filter( array_map( static fn( string $name ): string => substr( $name, 7 ), array_keys( Reader::embedded_fonts( $pdf->render() ) ) ), static fn( string $name ): bool => str_starts_with( $name, 'Noto' ) ) ) );
	}

	/**
	 * With no fallback and a family that has neither the letter nor `?`,
	 * the letter is left out and reported — never shown as glyph 0.
	 */
	public function test_a_family_without_a_question_mark_leaves_the_letter_out_rather_than_show_notdef(): void {
		$pdf = ( new Document() )->archival()->family( new FontFamily( new TrueType( FontBuilder::font() ) ) )->fallback( null )->text( 'AB €' );

		$this->assertSame( array( 'AB €' ), $pdf->substitutions() );
		$this->assertSame( array( 'AB ' ), $pdf->drawn_text() );

		foreach ( Reader::streams( $pdf->render() ) as $stream ) {
			preg_match_all( '/<([0-9A-F]*)> Tj/', $stream, $shows );

			foreach ( $shows[1] as $hex ) {
				$this->assertNotContains( '0000', str_split( $hex, 4 ), 'the .notdef glyph was shown' );
			}
		}
	}

	public function test_it_must_be_chosen_before_anything_is_drawn(): void {
		$this->expectException( \LogicException::class );

		( new Document() )->text( 'Too late' )->archival();
	}

	public function test_the_writer_refuses_a_base_font_or_a_cmyk_image_in_pdf_a(): void {
		try {
			Writer::write( array( '' ), 595, 842, fonts: array( 'F1' => Font::Helvetica ), archival: true );
			$this->fail( 'A base font was written into a PDF/A file.' );
		} catch ( \LogicException $error ) {
			$this->assertStringContainsString( 'Helvetica', $error->getMessage() );
		}

		$this->expectException( \LogicException::class );

		Writer::write( array( '' ), 595, 842, images: array( 'Im1' => Image::file( Pictures::path( 'cmyk.jpg' ) ) ), archival: true );
	}

	/* ─── Attachments ───────────────────────────────────────────────── */

	public function test_an_attached_file_is_an_associated_file_with_its_relationship(): void {
		$xml   = '<?xml version="1.0"?><invoice>€</invoice>';
		$bytes = ( new Document() )->archival()
			->attach( new Attachment( 'factur-x.xml', $xml, 'text/xml', 'Factur-X invoice', Relationship::Alternative, 1794900000 ) )
			->text( 'x' )
			->render( array( 'CreationDate' => self::CREATED ) );

		$objects = Reader::objects( $bytes );
		$catalog = $this->object( $bytes, '/\/Type \/Catalog/' )['dict'];

		$this->assertSame( 1, preg_match( '/\/Names <<\/EmbeddedFiles <<\/Names \[\(factur-x\.xml\) (\d+) 0 R\]>>>>/', $catalog, $tree ) );
		$this->assertStringContainsString( '/AF [' . $tree[1] . ' 0 R]', $catalog );

		$specification = $objects[ (int) $tree[1] ]['dict'];

		$this->assertStringContainsString( '/Type /Filespec /F (factur-x.xml) /UF (factur-x.xml)', $specification );
		$this->assertStringContainsString( '/Desc (Factur-X invoice)', $specification );
		$this->assertStringContainsString( '/AFRelationship /Alternative', $specification );
		$this->assertSame( 1, preg_match( '/\/EF <<\/F (\d+) 0 R \/UF \1 0 R>>/', $specification, $stream ) );

		$file = $objects[ (int) $stream[1] ];

		$this->assertStringContainsString( '/Type /EmbeddedFile /Subtype /text#2Fxml', $file['dict'] );
		$this->assertStringContainsString( '/Params <</Size ' . strlen( $xml ) . ' /ModDate (D:' . gmdate( 'YmdHis', 1794900000 ) . 'Z) /CheckSum <' . strtoupper( md5( $xml ) ) . '>>>', $file['dict'] );
		$this->assertSame( $xml, $file['stream'] );
	}

	public function test_poppler_saves_the_attachment_out_byte_for_byte(): void {
		$xml    = "<?xml version=\"1.0\"?>\n<x>Łódź</x>\n";
		$bytes  = ( new Document() )->archival()->attach( new Attachment( 'factur-x.xml', $xml, 'text/xml' ) )->text( 'x' )->render();
		$folder = dirname( __DIR__ ) . '/tests/.tmp/' . bin2hex( random_bytes( 6 ) );

		mkdir( $folder, 0777, true );

		[ $code, $output ] = Reader::run( Reader::tool( 'pdfdetach' ), array( '-saveall', '-o', $folder ), $bytes );

		$this->assertSame( 0, $code, $output );
		$this->assertSame( $xml, file_get_contents( $folder . '/factur-x.xml' ) );

		unlink( $folder . '/factur-x.xml' );
		rmdir( $folder );
	}

	public function test_the_name_tree_is_sorted_and_a_name_replaces_its_namesake(): void {
		$pdf = ( new Document() )
			->attach( new Attachment( 'b.txt', 'one' ) )
			->attach( new Attachment( 'a.txt', 'two' ) )
			->attach( new Attachment( 'b.txt', 'three' ) );

		$this->assertSame( array( 'b.txt', 'a.txt' ), array_map( static fn( Attachment $file ): string => $file->name, $pdf->attachments() ) );
		$this->assertSame( 'three', $pdf->attachments()[0]->contents );
		$this->assertSame( 1, preg_match( '/\/EmbeddedFiles <<\/Names \[\(a\.txt\) \d+ 0 R \(b\.txt\) \d+ 0 R\]/', $pdf->text( 'x' )->render() ) );
	}

	public function test_an_ordinary_document_carries_files_without_claiming_pdf_a(): void {
		$plain = Reader::plain( ( new Document() )->attach( new Attachment( 'notes.txt', 'hello', 'text/plain' ) )->text( 'x' )->render() );

		$this->assertStringContainsString( '/EmbeddedFiles', $plain );
		$this->assertStringNotContainsString( '/AF [', $plain );
		$this->assertStringNotContainsString( '/AFRelationship', $plain );
		$this->assertStringNotContainsString( '/Metadata', $plain );
		$this->assertStringNotContainsString( '/OutputIntents', $plain );
	}

	public function test_a_name_outside_ascii_is_kept_in_uf_and_stood_in_for_in_f(): void {
		$plain = Reader::plain( ( new Document() )->attach( new Attachment( 'Łódź.xml', '<x/>' ) )->text( 'x' )->render() );

		$this->assertStringContainsString( '/F (__d_.xml) /UF <FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( 'Łódź.xml', 'UTF-16BE', 'UTF-8' ) ) ) . '>', $plain );
	}

	public function test_an_attachment_name_loses_its_folders_and_control_characters(): void {
		$this->assertSame( 'invoice.xml', ( new Attachment( "../../etc/\x01invoice.xml", '' ) )->name );
		$this->assertSame( 'invoice.xml', ( new Attachment( 'C:\\Users\\x\\invoice.xml', '' ) )->name );
		$this->assertSame( 'application/octet-stream', ( new Attachment( 'a', '', 'not a type' ) )->mime_type );
		$this->assertSame( 'text/xml', ( new Attachment( 'a', '', ' TEXT/XML ' ) )->mime_type );

		$this->expectException( \InvalidArgumentException::class );

		new Attachment( '../', 'x' );
	}
}
