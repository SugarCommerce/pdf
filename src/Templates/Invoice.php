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

use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Color;
use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Models\Orientation;
use SugarCommerce\Pdf\Models\PageSize;
use SugarCommerce\Pdf\Models\Templates\Branding;
use SugarCommerce\Pdf\Models\Templates\DocumentKind;
use SugarCommerce\Pdf\Models\Templates\Party;
use SugarCommerce\Pdf\Models\Templates\PaymentCode;
use SugarCommerce\Pdf\Models\Templates\VatTreatment;
use SugarCommerce\Pdf\Traits\Invoice\{Compliance, EInvoice, Figures, Header, Notes};

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
 * pass the result — or let {@see \SugarCommerce\Pdf\Models\Templates\Totals} work the figures out from
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
 * The layout and the file are here; the compliance checks, the e-invoice,
 * and the header, figures and notes sections are traits in
 * Traits/Invoice.
 *
 * @since 1.0.0
 */
final readonly class Invoice {

	use Compliance;
	use EInvoice;
	use Header;
	use Figures;
	use Notes;

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
