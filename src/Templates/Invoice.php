<?php
/**
 * Invoice template.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

use SugarCommerce\Pdf\{Align, Attachment, Color, Document, Font, Orientation, PageSize, Table};

/**
 * Class Invoice
 *
 * A complete invoice from data alone — no layout code at the call site.
 * The same layout makes a credit note, a quote, a proforma, a receipt and
 * the rest of {@see DocumentKind}: they differ in wording and in which
 * blocks appear, not in shape.
 *
 * Everything money-shaped is a pre-formatted string. Rendering an amount
 * correctly means knowing the currency's decimal exponent, its thousands
 * convention and its symbol placement, and a layout class has no
 * business guessing at any of that. Format it where the money lives and
 * pass the result — or let {@see Totals} work the figures out from
 * minor units and format them for you.
 *
 * Typical use:
 *
 *   $pdf = new Invoice(
 *       branding: $branding,
 *       number:   'INV-2026-0042',
 *       from:     $seller,
 *       to:       $buyer,
 *       items:    $items,
 *       totals:   [ 'Subtotal' => '£901.00', 'VAT at 20%' => '£162.18' ],
 *       total:    [ 'Total due' => '£973.08' ],
 *   );
 *
 *   $pdf->render()->save( 'invoice.pdf' );
 *
 * @since 1.0.0
 */
final readonly class Invoice {

	/**
	 * Describe an invoice.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 The kind, reference, wording and payment code.
	 *
	 * @param Branding              $branding     Visual identity.
	 * @param string                $number       Document reference.
	 * @param Party                 $from         Who is issuing it. Printed under
	 *                                            FROM; with no address of its
	 *                                            own, the branding's address.
	 * @param Party                 $to           Who it is addressed to.
	 * @param array<int, LineItem>  $items        Billable lines.
	 * @param array<string, string> $totals       Subtotal rows: label => amount,
	 *                                            or a list of [label, amount] pairs.
	 * @param array<string, string> $total        The headline total, one entry.
	 * @param array<string, string> $details      Dates, terms, references.
	 * @param string                $notes        Payment instructions or terms.
	 * @param string                $status       Optional stamp, e.g. `PAID`.
	 * @param PageSize              $size         Paper size.
	 * @param VatTreatment          $vat          How VAT is being handled. Anything
	 *                                            other than Standard prints the
	 *                                            mandatory wording.
	 * @param array<int, VatLine>   $vat_lines    Per-rate breakdown. Required once
	 *                                            more than one rate appears.
	 * @param string                $supply_date  Date of supply, which German law
	 *                                            requires separately from the
	 *                                            invoice date and which is the
	 *                                            particular most often left off.
	 * @param bool                  $german_notes Print the German wording as well
	 *                                            as the English.
	 * @param Translator            $translator   Source of the template's own
	 *                                            wording.
	 * @param DocumentKind          $kind         Invoice, credit note, quote…
	 * @param string                $reference    The document this one cites: the
	 *                                            original invoice for a credit
	 *                                            note, the overdue one for a
	 *                                            reminder.
	 * @param Language|null         $wording      A language whose VAT wording to
	 *                                            print beside the English. Null
	 *                                            for the document's own language
	 *                                            when it has wording, or German
	 *                                            with `german_notes`.
	 * @param PaymentCode|null      $payment_code A code to scan, printed beside
	 *                                            the payment notes.
	 */
	public function __construct(
		public Branding $branding,
		public string $number,
		public Party $from,
		public Party $to,
		public array $items,
		public array $totals = array(),
		public array $total = array(),
		public array $details = array(),
		public string $notes = '',
		public string $status = '',
		public PageSize $size = PageSize::A4,
		public VatTreatment $vat = VatTreatment::Standard,
		public array $vat_lines = array(),
		public string $supply_date = '',
		public bool $german_notes = false,
		public Translator $translator = Language::English,
		public DocumentKind $kind = DocumentKind::Invoice,
		public string $reference = '',
		public ?Language $wording = null,
		public ?PaymentCode $payment_code = null,
	) {}

	/* ─── Compliance ────────────────────────────────────────────────── */

	/**
	 * Mandatory particulars that are missing.
	 *
	 * Checks the invoice against the fields §14 UStG and Article 226 of
	 * the VAT Directive require. An invoice missing any of them can be
	 * refused as evidence for the recipient's input-tax deduction — which
	 * makes it their problem and your support ticket. Documents that are
	 * not tax documents — a quote, a delivery note — have none to miss.
	 *
	 * Not tax advice, and not exhaustive: it verifies the particulars this
	 * template can see, not whether the treatment you chose is correct.
	 * {@see compliance_warnings_verifying()} asks the drawn page as well.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Human-readable problems; empty when nothing is missing.
	 */
	public function compliance_warnings(): array {
		if ( ! $this->kind->is_tax_document() ) {
			return array();
		}

		$missing = array();
		$seller  = $this->seller();

		if ( '' === trim( $seller->name ) ) {
			$missing[] = 'The supplier name is missing.';
		}

		if ( '' === trim( $seller->tax_id ) ) {
			$missing[] = 'The supplier VAT or tax number is missing.';
		}

		if ( array() === self::filled( $seller->address ) ) {
			$missing[] = 'The supplier address is missing.';
		}

		if ( array() === self::filled( $this->to->address ) ) {
			$missing[] = 'The customer address is missing.';
		}

		if ( '' === trim( $this->number ) ) {
			$missing[] = 'A sequential invoice number is required.';
		}

		if ( '' === trim( $this->supply_date ) ) {
			$missing[] = 'The date of supply is required, separately from the invoice date.';
		}

		if ( $this->vat->requires_both_vat_numbers() && '' === trim( $this->to->tax_id ) ) {
			$missing[] = 'The customer VAT number is required for this VAT treatment.';
		}

		// A self-billed invoice is the supplier's invoice, raised by the
		// customer: the supplier's number is on it whatever the treatment.
		if ( DocumentKind::SelfBilling === $this->kind && '' === trim( $this->to->tax_id ) ) {
			$missing[] = "A self-billed invoice must show the supplier's VAT number.";
		}

		if ( $this->kind->requires_reference() && '' === trim( $this->reference ) ) {
			$missing[] = DocumentKind::CreditNote === $this->kind
				? 'A credit note must reference the invoice it reverses.'
				: 'A debit note must reference the document it adjusts.';
		}

		if ( count( $this->vat_lines ) > 1 ) {
			return $missing;
		}

		if ( VatTreatment::Standard === $this->vat && array() === $this->vat_lines && array() === $this->totals ) {
			$missing[] = 'The taxable amount and VAT must be shown per rate.';
		}

		return $missing;
	}

	/**
	 * Mandatory particulars missing from the page as drawn.
	 *
	 * {@see compliance_warnings()} inspects the data: it can tell you a
	 * reverse charge was declared and a VAT number was given, not that
	 * either reached the page. This asks the finished document, which is
	 * the question that matters — the recipient's deduction turns on what
	 * is printed, and a template that stopped drawing the seller's VAT
	 * number would pass every check that only reads the fields.
	 *
	 * Text is compared as a reader extracts it, so a name the base fonts
	 * cannot print as written — Cyrillic, a Polish ł — is reported too.
	 *
	 * Still not tax advice.
	 *
	 * @since 1.2.0
	 *
	 * @param Document|null $document The rendered document; rendered here when
	 *                                null.
	 *
	 * @return list<string> The data's warnings, then what the page lacks.
	 */
	public function compliance_warnings_verifying( ?Document $document = null ): array {
		$missing = $this->compliance_warnings();
		$page    = self::squeezed( implode( ' ', ( $document ?? $this->render() )->drawn_text() ) );
		$on_page = static fn( string $text ): bool => '' === self::squeezed( $text ) || str_contains( $page, self::squeezed( $text ) );

		foreach ( $this->vat->notes( $this->vat_wording() ?? false ) as $note ) {
			if ( ! $on_page( $note ) ) {
				$missing[] = 'The wording "' . $note . '" is required and did not reach the page.';
			}
		}

		$standing = $this->kind->standing_note( $this->translator );

		if ( ! $on_page( $standing ) ) {
			$missing[] = 'The wording "' . $standing . '" did not reach the page.';
		}

		// A delivery note prints no money, so there is no total to find.
		foreach ( $this->kind->shows_money() ? Rows::pairs( $this->total ) : array() as [ , $value ] ) {
			if ( ! $on_page( $value ) ) {
				$missing[] = 'The total "' . $value . '" did not reach the page.';
			}
		}

		if ( ! $this->kind->is_tax_document() ) {
			return $missing;
		}

		$seller = $this->seller();
		$checks = array(
			'The document number'      => $this->number,
			'The date of supply'       => $this->supply_date,
			'The supplier name'        => $seller->name,
			'The supplier VAT number'  => $seller->tax_id,
			'The customer name'        => $this->to->name,
			'The reference'            => $this->kind->requires_reference() ? $this->reference : '',
			'The customer VAT number'  => $this->vat->requires_both_vat_numbers() || DocumentKind::SelfBilling === $this->kind ? $this->to->tax_id : '',
		);

		foreach ( self::filled( $seller->address ) as $line ) {
			$checks[ 'The supplier address line "' . $line . '"' ] = $line;
		}

		foreach ( $checks as $what => $value ) {
			if ( ! $on_page( $value ) ) {
				$missing[] = str_contains( $what, '"' )
					? $what . ' did not reach the page.'
					: $what . ' "' . $value . '" did not reach the page.';
			}
		}

		return $missing;
	}

	/* ─── Rendering ─────────────────────────────────────────────────── */

	/**
	 * Lay the invoice out.
	 *
	 * @since 1.0.0
	 *
	 * @since 1.5.0 Archival, for PDF/A-3b.
	 *
	 * @param bool $archival Write it as PDF/A-3b: see {@see Document::archival()}.
	 *                       {@see factur_x()} is the e-invoice.
	 *
	 * @return Document Ready to save or render.
	 */
	public function render( bool $archival = false ): Document {
		$pdf   = new Document( $this->size, Orientation::Portrait, margin: 48, font_size: 9.5, leading: 13 );
		$brand = $this->branding;

		if ( $archival ) {
			$pdf->archival();
		}

		$pdf->family( $brand->typeface );

		$pdf->language( $this->language()?->value ?? '' );

		// The footnotes' rule sits above the bottom margin, and the page
		// number just inside it: the flow stops short of both.
		$pdf->keep_clear( array() !== $brand->footnotes ? 18 : 8 );

		$this->masthead( $pdf );
		$this->parties( $pdf );
		$this->items( $pdf );
		$this->vat_breakdown( $pdf );
		$this->totals( $pdf );
		$this->vat_notes( $pdf );
		$this->notes( $pdf );

		$translator = $this->translator;

		$pdf->on_each_page( function ( Document $doc, int $page, int $total ) use ( $brand, $translator ): void {
			if ( array() !== $brand->footnotes ) {
				$doc->line( $doc->left(), 58, $doc->right(), 58, $brand->hairline() );

				$y = 46;

				foreach ( $brand->footnotes as $note ) {
					$doc->text_at( $note, $doc->left(), $y, 7.5, Font::Helvetica, Color::grey( 150 ) );
					$y -= 10;
				}
			}

			if ( $total > 1 ) {
				$doc->text_at(
					$translator->translate( 'page', 'Page' ) . ' ' . $page . ' ' . $translator->translate( 'of', 'of' ) . ' ' . $total,
					$doc->left(),
					46,
					7.5,
					Font::Helvetica,
					Color::grey( 150 ),
					Align::Right,
					$doc->content_width()
				);
			}
		} );

		return $pdf;
	}

	/* ─── E-invoice ─────────────────────────────────────────────────── */

	/**
	 * What stops this invoice being sent as a Factur-X e-invoice.
	 *
	 * Stricter than {@see compliance_warnings()}: an e-invoice is checked
	 * by a machine against EN 16931, and each sentence names the rule.
	 * The page and the XML must also be the same invoice — the total
	 * printed must be the one the figures make.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return list<string> One sentence each; empty when it can be sent.
	 */
	public function factur_x_problems( FacturX $details ): array {
		return FacturXWriter::problems( $this, $details );
	}

	/**
	 * The invoice as Factur-X XML: UN/CEFACT Cross Industry Invoice D16B.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return string UTF-8 XML.
	 *
	 * @throws FacturXException Listing every problem, when there are any.
	 */
	public function factur_x_xml( FacturX $details ): string {
		return FacturXWriter::xml( $this, $details );
	}

	/**
	 * The invoice as a Factur-X / ZUGFeRD e-invoice: the page a person
	 * reads, as PDF/A-3b, carrying `factur-x.xml` for the buyer's system.
	 *
	 * ```php
	 * $details = new FacturX( $totals, new DateTimeImmutable( '2026-11-18' ), due: new DateTimeImmutable( '2026-12-18' ) );
	 *
	 * $invoice->factur_x( $details )->save( 'INV-2026-0042.pdf', $invoice->metadata() );
	 * ```
	 *
	 * Refused, rather than written to fail a validator, when the XML has
	 * a problem ({@see factur_x_problems()}) or when the page could not
	 * print a name as written — the XML would then say one thing and the
	 * page another.
	 *
	 * @since 1.5.0
	 *
	 * @param FacturX $details The figures and dates the XML states.
	 *
	 * @return Document Ready to save, render or send.
	 *
	 * @throws FacturXException Listing every problem, when there are any.
	 */
	public function factur_x( FacturX $details ): Document {
		$xml = $this->factur_x_xml( $details );
		$pdf = $this->render( true );

		if ( array() !== $pdf->substitutions() ) {
			throw new FacturXException(
				array_map(
					static fn( string $text ): string => '"' . $text . '" could not be printed as written, so the page would not say what the XML does: set the invoice in a family that has these letters.',
					$pdf->substitutions()
				)
			);
		}

		return $pdf
			->xmp( FacturXWriter::xmp( $details->profile ) )
			->attach( new Attachment( FacturXWriter::FILE_NAME, $xml, 'text/xml', 'Factur-X invoice', $details->profile->relationship(), $details->issued->getTimestamp() ) );
	}

	/**
	 * Save straight to a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path     Destination.
	 * @param array  $metadata Extra metadata, e.g. a `CreationDate` for the
	 *                         same bytes on every render (since 1.2.0).
	 *
	 * @phpstan-param array<string, string|int> $metadata
	 *
	 * @return int Bytes written.
	 */
	public function save( string $path, array $metadata = array() ): int {
		return $this->render()->save( $path, $this->metadata() + $metadata );
	}

	/**
	 * The document information a saved file carries.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	public function metadata(): array {
		$title = $this->kind->title( $this->translator );

		return array(
			'Title'   => $title . ' ' . $this->number,
			'Author'  => $this->seller()->name,
			'Subject' => ( DocumentKind::Invoice === $this->kind ? 'Invoice' : $title ) . ' for ' . $this->to->name,
		);
	}

	/**
	 * The seller as printed: the `from` party, with the branding's
	 * address when it has none of its own, and the branding's name when
	 * it has no name.
	 *
	 * @since 1.2.0
	 *
	 * @return Party
	 */
	public function seller(): Party {
		return $this->branding->seller( $this->from );
	}

	/* ─── Sections ──────────────────────────────────────────────────── */

	/**
	 * Logo, wordmark and document title.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function masthead( Document $pdf ): void {
		$brand = $this->branding;
		$top   = $pdf->height() - 48;
		$x     = $pdf->left();

		$mark = $brand->logo( $pdf, $x, $top, 34, $brand->accent_color() );
		$x   += $mark > 0 ? $mark + 12 : 0;

		$pdf->text_at( strtoupper( $brand->name ), $x, $top - 14, 15, Font::HelveticaBold, $brand->accent_color() );

		if ( '' !== $brand->tagline ) {
			$pdf->text_at( $brand->tagline, $x, $top - 27, 8, Font::Helvetica, $brand->muted() );
		}

		// Title, reference and status stack down the right edge. Each
		// needs its own band — overlaying them puts the stamp across the
		// reference number, which is the one thing on an invoice a
		// customer has to quote back. A long title ("ORDER CONFIRMATION")
		// steps down in size rather than running into the wordmark.
		$title = $this->kind->title( $this->translator );
		$room  = $pdf->content_width() * 0.58;
		$size  = min( 26.0, 26.0 * $room / max( 1.0, $pdf->width( $title, 26, Font::HelveticaBold ) ) );

		$pdf->text_at( $title, $pdf->left(), $top - 20, $size, Font::HelveticaBold, $brand->ink(), Align::Right, $pdf->content_width() );
		$pdf->text_at( $this->number, $pdf->left(), $top - 36, 9.5, Font::Helvetica, $brand->muted(), Align::Right, $pdf->content_width() );

		$rule_y = $top - 66;

		if ( '' !== $this->status ) {
			$this->stamp( $pdf, $top - 58 );
			$rule_y = $top - 78;
		}

		$pdf->move_to( $rule_y );
		$pdf->line( $pdf->left(), $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 0.75 );
		$pdf->move_to( $pdf->cursor() - 28 );
	}

	/**
	 * A status stamp such as PAID or OVERDUE.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 * @param float    $y   Baseline for the stamp box.
	 *
	 * @return void
	 */
	private function stamp( Document $pdf, float $y ): void {
		$brand  = $this->branding;
		$label  = strtoupper( $this->status );
		$width  = $pdf->width( $label, 8, Font::HelveticaBold ) + 22;
		$height = 16.0;
		$left   = $pdf->right() - $width;
		$bottom = $y - 4;

		// Outlined rather than filled: reversed-out white text on a light
		// accent is unreadable, and on a mono palette it disappears
		// entirely.
		$pdf->line( $left, $bottom, $left + $width, $bottom, $brand->ink(), 0.75 );
		$pdf->line( $left, $bottom + $height, $left + $width, $bottom + $height, $brand->ink(), 0.75 );
		$pdf->line( $left, $bottom, $left, $bottom + $height, $brand->ink(), 0.75 );
		$pdf->line( $left + $width, $bottom, $left + $width, $bottom + $height, $brand->ink(), 0.75 );

		$pdf->text_at(
			$label,
			$left,
			$bottom + ( ( $height - $pdf->cap_height( 8, Font::HelveticaBold ) ) / 2 ),
			8,
			Font::HelveticaBold,
			$brand->ink(),
			Align::Center,
			$width
		);
	}

	/**
	 * Seller, recipient and document details, side by side.
	 *
	 * The seller's name, address and VAT number are mandatory particulars
	 * in their own right (Article 226 (5)), so they have a column of their
	 * own rather than relying on the wordmark, which is a trading name and
	 * often not the legal one.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function parties( Document $pdf ): void {
		$brand   = $this->branding;
		$top     = $pdf->cursor();
		$details = $this->detail_rows();
		$gutter  = 16.0;
		$wide    = $pdf->content_width();
		$detail  = array() !== $details ? $wide * 0.36 : 0.0;
		$column  = ( $wide - $detail - ( array() !== $details ? $gutter * 2 : $gutter ) ) / 2;

		$bottom = min(
			$this->party( $pdf, $this->translator->translate( 'from', 'FROM' ), $this->seller(), $pdf->left(), $column, $top ),
			$this->party( $pdf, $this->kind->recipient_heading( $this->translator ), $this->to, $pdf->left() + $column + $gutter, $column, $top )
		);

		if ( array() !== $details ) {
			$x = $pdf->right() - $detail;

			$pdf->text_at( $this->translator->translate( 'details', 'DETAILS' ), $x, $top, 7, Font::HelveticaBold, $brand->muted() );

			$y = $top - 16;

			foreach ( $details as [ $label, $value ] ) {
				$room = $detail - $pdf->width( $label, 9 ) - 8;

				$pdf->text_at( $label, $x, $y, 9, Font::Helvetica, $brand->muted() );
				$pdf->text_at( $pdf->fit( $value, max( 0.0, $room ), 9, Font::HelveticaBold ), $x, $y, 9, Font::HelveticaBold, $brand->ink(), Align::Right, $detail );
				$y -= 13;
			}

			$bottom = min( $bottom, $y );
		}

		$pdf->move_to( $bottom - 18 );
	}

	/**
	 * One party's column: heading, name, then the address block, wrapped.
	 *
	 * @since 1.2.0
	 *
	 * @param Document $pdf     Target.
	 * @param string   $heading e.g. `FROM`, `BILLED TO`.
	 * @param Party    $party   Who.
	 * @param float    $x       Left edge.
	 * @param float    $width   Column width.
	 * @param float    $top     Baseline of the heading.
	 *
	 * @return float The baseline below the last line.
	 */
	private function party( Document $pdf, string $heading, Party $party, float $x, float $width, float $top ): float {
		$brand = $this->branding;

		$pdf->text_at( $heading, $x, $top, 7, Font::HelveticaBold, $brand->muted() );

		// block() takes the top of the first line; the name's baseline is
		// sixteen points under the heading's, as it always was.
		$pdf->move_to( $top - 16 + $pdf->ascender( 10.5, Font::HelveticaBold ) );
		$pdf->block( $party->name, $x, $width, 10.5, Font::HelveticaBold, $brand->ink(), Align::Left, 13 );

		// The address's first baseline is thirteen points under the name's
		// last one.
		$pdf->move_to( $pdf->cursor() - $pdf->ascender( 10.5, Font::HelveticaBold ) + $pdf->ascender( 9 ) );
		$pdf->block( implode( "\n", $party->translated_lines( $this->translator ) ), $x, $width, 9, Font::Helvetica, $brand->muted(), Align::Left, 11.5 );

		return $pdf->cursor() - $pdf->ascender( 9 ) + 11.5;
	}

	/**
	 * The rows of the details column: the caller's, then the date of
	 * supply and the reference when they are not among them.
	 *
	 * @since 1.2.0
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function detail_rows(): array {
		$rows   = Rows::pairs( $this->details );
		$labels = array_map( static fn( array $row ): string => mb_strtolower( $row[0] ), $rows );

		// The supply date is a mandatory particular in its own right and
		// is the one most often left off, so it is added rather than
		// relying on the caller to remember it.
		$supply = $this->translator->translate( 'date_of_supply', 'Date of supply' );

		if ( '' !== trim( $this->supply_date ) && ! in_array( mb_strtolower( $supply ), $labels, true ) ) {
			$rows[] = array( $supply, $this->supply_date );
		}

		$reference = $this->kind->reference_label( $this->translator );

		if ( '' !== trim( $this->reference ) && ! in_array( mb_strtolower( $reference ), $labels, true ) ) {
			$rows[] = array( $reference, $this->reference );
		}

		return array_values( $rows );
	}

	/**
	 * The line-item table.
	 *
	 * Descriptions wrap rather than ending in `...`, and an item's note
	 * goes on its own quieter line under the description, where it reads
	 * as detail rather than as part of what was sold.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function items( Document $pdf ): void {
		if ( array() === $this->items ) {
			return;
		}

		$money    = $this->kind->shows_money();
		$has_unit = false;

		foreach ( $this->items as $item ) {
			if ( '' !== $item->unit_price ) {
				$has_unit = true;
				break;
			}
		}

		$t = $this->translator;

		[ $headers, $widths, $aligns ] = match ( true ) {
			! $money  => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ) ),
				array( 0.85, 0.15 ),
				array( 1 => Align::Center ),
			),
			$has_unit => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ), $t->translate( 'unit_price', 'Unit price' ), $t->translate( 'amount', 'Amount' ) ),
				array( 0.50, 0.10, 0.20, 0.20 ),
				array(
					1 => Align::Center,
					2 => Align::Right,
					3 => Align::Right,
				),
			),
			default   => array(
				array( $t->translate( 'description', 'Description' ), $t->translate( 'quantity', 'Qty' ), $t->translate( 'amount', 'Amount' ) ),
				array( 0.66, 0.14, 0.20 ),
				array(
					1 => Align::Center,
					2 => Align::Right,
				),
			),
		};

		$table = ( new Table( $headers ) )->widths( $widths )->align( $aligns )->row_height( 22 )->wrap();

		foreach ( $this->items as $item ) {
			$table->row(
				match ( true ) {
					! $money  => array( $item->description, $item->quantity ),
					$has_unit => array( $item->description, $item->quantity, $item->unit_price, $item->amount ),
					default   => array( $item->description, $item->quantity, $item->amount ),
				},
				$item->note
			);
		}

		$table->draw( $pdf, 9, $this->branding->wash(), $this->branding->muted() );
	}

	/**
	 * Whether the per-rate breakdown earns its place on the page.
	 *
	 * It exists because §14 UStG and Article 226 want the taxable amount
	 * against each rate when rates are mixed. At a single standard rate it
	 * repeats the totals block — the same two figures again — so it is
	 * left out. A non-standard treatment keeps it at one rate: the 0% row
	 * is what evidences the zero rating.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function shows_vat_breakdown(): bool {
		return $this->kind->shows_money()
			&& array() !== $this->vat_lines
			&& ( count( $this->vat_lines ) > 1 || VatTreatment::Standard !== $this->vat );
	}

	/**
	 * Per-rate VAT breakdown.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function vat_breakdown( Document $pdf ): void {
		if ( ! $this->shows_vat_breakdown() ) {
			return;
		}

		$brand = $this->branding;
		$rows  = count( $this->vat_lines );

		// Heading, header and every row together, so the heading is never
		// left at the foot of one page with its table on the next.
		$pdf->gap( 18 );
		$pdf->break_if_needed( min( $pdf->page_room(), 14 + ( 18 * ( $rows + 2 ) ) ) );

		$pdf->cell( $this->translator->translate( 'vat_breakdown', 'VAT BREAKDOWN' ), $pdf->left(), $pdf->content_width() * 0.55, 7, Font::HelveticaBold, $brand->muted() );
		$pdf->gap( 14 );

		$table = ( new Table( array(
			$this->translator->translate( 'rate', 'Rate' ),
			$this->translator->translate( 'net', 'Net' ),
			$this->translator->translate( 'vat', 'VAT' ),
		) ) )
			->widths( array( 0.4, 0.3, 0.3 ) )
			->align( array(
				1 => Align::Right,
				2 => Align::Right,
			) )
			->row_height( 18 );

		foreach ( $this->vat_lines as $line ) {
			$table->row( array( '' !== $line->label ? $line->label . ' (' . $line->rate . ')' : $line->rate, $line->net, $line->vat ) );
		}

		// The cursor stays wherever the table left it — near the top of a
		// new page when it broke. Clamping it back to where this page
		// started dragged everything after a page late.
		$table->draw( $pdf, 8.5, $brand->wash() );
	}

	/**
	 * Subtotals, the headline total, and the kind's standing wording.
	 *
	 * Kept together: the whole block is measured first and moves to the
	 * next page as one when it does not fit, so a total is never printed
	 * on a page of its own away from the rows that make it up.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function totals( Document $pdf ): void {
		$brand    = $this->branding;
		$standing = $this->kind->standing_note( $this->translator );
		$rows     = Rows::pairs( $this->totals );
		$total    = Rows::pairs( $this->total );
		$money    = $this->kind->shows_money();

		if ( ! $money ) {
			$rows  = array();
			$total = array();
		}

		$height = ( 14 + ( 15 * count( $rows ) ) )
			+ ( array() !== $total ? 26 + ( 26 * count( $total ) ) : 0 )
			+ ( '' !== $standing ? 6 + $pdf->block_height( $standing, $pdf->content_width(), 8.5, Font::Helvetica, 12 ) : 0 );

		$pdf->break_if_needed( min( $pdf->page_room(), $height ) );

		$width = $pdf->content_width() * 0.42;
		$x     = $pdf->right() - $width;

		$pdf->gap( 14 );

		foreach ( $rows as [ $label, $value ] ) {
			$pdf->cell( $label, $x, $width, 9, Font::Helvetica, $brand->muted() );
			$pdf->cell( $value, $x, $width, 9, Font::Helvetica, $brand->ink(), Align::Right );
			$pdf->gap( 15 );
		}

		if ( array() !== $total ) {
			// A rule and a change of weight, rather than a filled block. It
			// reads as clearly, prints the same on any device, and cannot
			// look subtly wrong the way a band of colour with text in it can.
			$pdf->gap( 6 );
			$pdf->line( $x, $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->ink(), 1 );
			$pdf->gap( 20 );

			foreach ( $total as [ $label, $value ] ) {
				$baseline = $pdf->cursor();

				$pdf->text_at( $label, $x, $baseline, 11, Font::HelveticaBold, $brand->ink() );
				$pdf->text_at( $value, $x, $baseline, 14, Font::HelveticaBold, $brand->ink(), Align::Right, $width );

				$pdf->gap( 12 );
				$pdf->line( $x, $pdf->cursor(), $pdf->right(), $pdf->cursor(), $brand->hairline(), 0.5 );
				$pdf->gap( 14 );
			}
		}

		if ( '' !== $standing ) {
			$pdf->gap( 6 );
			$pdf->block( $standing, $pdf->left(), $pdf->content_width(), 8.5, Font::Helvetica, $brand->muted(), Align::Left, 12 );
		}
	}

	/**
	 * The mandatory VAT wording.
	 *
	 * Printed prominently rather than buried in the small print, because
	 * its absence is what invalidates the recipient's deduction. Each note
	 * wraps inside the box, and the box is measured from what it holds.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function vat_notes( Document $pdf ): void {
		$notes = $this->vat->notes( $this->vat_wording() ?? false );

		if ( array() === $notes ) {
			return;
		}

		$brand   = $this->branding;
		$padding = 11.0;
		$indent  = 15.0;
		$inner   = $pdf->content_width() - ( $indent * 2 );
		$content = 0.0;

		foreach ( $notes as $note ) {
			$content += $pdf->block_height( $note, $inner, 8.5, Font::HelveticaBold, 12 );
		}

		// A block's height counts a full leading under its last line, but
		// the ink stops at the descender: for the base-14 faces ascender
		// plus descender is the point size, so the phantom is the leading
		// less the size. Left in, the text sits high in its own box.
		$height = $content - ( 12 - 8.5 ) + ( $padding * 2 );

		$pdf->gap( 24 );
		$pdf->break_if_needed( min( $pdf->page_room(), $height + 16 ) );

		$top    = $pdf->cursor();
		$bottom = $top - $height;

		$pdf->line( $pdf->left(), $top, $pdf->right(), $top, $brand->hairline(), 0.5 );
		$pdf->line( $pdf->left(), $bottom, $pdf->right(), $bottom, $brand->hairline(), 0.5 );
		$pdf->line( $pdf->right(), $top, $pdf->right(), $bottom, $brand->hairline(), 0.5 );

		// A heavier stub on the left edge draws the eye without needing
		// colour, which matters because this is the wording that makes
		// the invoice valid for the recipient's deduction.
		$pdf->line( $pdf->left(), $top, $pdf->left(), $bottom, $brand->ink(), 2 );

		$pdf->move_to( $top - $padding );

		foreach ( $notes as $index => $note ) {
			$pdf->block( $note, $pdf->left() + $indent, $inner, 8.5, 0 === $index ? Font::HelveticaBold : Font::Helvetica, $brand->ink(), Align::Left, 12 );
		}

		$pdf->move_to( min( $pdf->cursor(), $bottom ) );
	}

	/**
	 * Payment instructions, wrapped, with the payment code beside them.
	 *
	 * @since 1.0.0
	 *
	 * @param Document $pdf Target.
	 *
	 * @return void
	 */
	private function notes( Document $pdf ): void {
		$text = trim( $this->notes );
		$code = $this->payment_code;

		if ( '' === $text && null === $code ) {
			return;
		}

		// The code sits against the right edge with the wording beside it,
		// so the two read as one instruction. Sized to be scanned from a
		// printed page at arm's length.
		$square = 92.0;
		$gutter = 18.0;
		$width  = null !== $code ? $pdf->content_width() - $square - $gutter : $pdf->content_width();
		$brand  = $this->branding;

		$pdf->gap( 26 );
		$pdf->break_if_needed( min( $pdf->page_room(), max( null !== $code ? $square + 46 : 0.0, 34 + $pdf->block_height( $text, $width, 8.5, Font::Helvetica, 12 ) ) ) );

		$pdf->line( $pdf->left(), $pdf->cursor() + 12, $pdf->right(), $pdf->cursor() + 12, $brand->hairline(), 0.5 );
		$pdf->gap( 6 );
		$pdf->cell( $this->kind->notes_heading( $this->translator ), $pdf->left(), 200, 7, Font::HelveticaBold, $brand->muted() );
		$pdf->gap( 14 );

		$top   = $pdf->cursor();
		$drawn = false;

		// The code goes in first, from the top of the block, so a long note
		// neither pushes it down the page nor carries it onto the next.
		if ( null !== $code ) {
			$drawn = $pdf->qr( $code->payload, $pdf->right() - $square, $top - $square, $square, $brand->ink() );

			if ( $drawn ) {
				$caption = 'Scan to pay' === $code->caption ? $this->translator->translate( 'scan_to_pay', 'Scan to pay' ) : $code->caption;

				$pdf->text_at( $caption, $pdf->right() - $square, $top - $square - 11, 7, Font::Helvetica, $brand->muted(), Align::Center, $square );
			}
		}

		$page = $pdf->page_count();

		if ( '' !== $text ) {
			$pdf->block( $text, $pdf->left(), $width, 8.5, Font::Helvetica, $brand->ink(), Align::Left, 12 );
		}

		// Whichever ran longer decides where the page carries on — unless
		// the note went on to another page, which the code is not on.
		if ( $drawn && $pdf->page_count() === $page ) {
			$pdf->move_to( min( $pdf->cursor(), $top - $square - 18 ) );
		}
	}

	/* ─── Internals ─────────────────────────────────────────────────── */

	/**
	 * The language the document is written in, where it can be told.
	 *
	 * @since 1.2.0
	 *
	 * @return Language|null
	 */
	private function language(): ?Language {
		return match ( true ) {
			$this->translator instanceof Language     => $this->translator,
			$this->translator instanceof Translations => $this->translator->language(),
			default                                   => null,
		};
	}

	/**
	 * The second language of the VAT wording, if any.
	 *
	 * German wording is added when asked for and whenever the document
	 * itself is in a language with wording of its own — a German invoice
	 * needs the German note, and remembering to set both is a trap.
	 *
	 * @since 1.2.0
	 *
	 * @return Language|null
	 */
	private function vat_wording(): ?Language {
		if ( null !== $this->wording ) {
			return $this->wording;
		}

		if ( $this->german_notes ) {
			return Language::German;
		}

		$language = $this->language();

		return null !== $language && VatTreatment::has_wording( $language ) ? $language : null;
	}

	/**
	 * Lines with something in them.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, mixed> $lines Lines.
	 *
	 * @return list<string>
	 */
	private static function filled( array $lines ): array {
		return array_values( array_filter( array_map( 'strval', array_filter( $lines, 'is_scalar' ) ), static fn( string $line ): bool => '' !== trim( $line ) ) );
	}

	/**
	 * Text with every space taken out, so wrapping cannot hide a match.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function squeezed( string $text ): string {
		return (string) preg_replace( '/\s+/u', '', $text );
	}
}
