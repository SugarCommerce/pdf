# PDF

Invoices, receipts and statements as PDF files, written directly by PHP with
nothing to install: no binary, no HTML engine, no Composer dependencies.

## What it does

A store has to hand people documents: an invoice to file, a receipt for a
sale, a statement of account. The usual answers are shelling out to
`wkhtmltopdf`, pulling in Dompdf and its dependency tree, or paying an API to
render a document that never needed to leave the server.

This writes the PDF itself. It covers what a business document needs — text
that flows across pages, tables, rules, a vector logo, the VAT wording an EU
invoice is invalid without — and it fills in invoice, receipt and statement
templates from plain values. Inside WordPress it speaks the site's language
and sends the download; outside it, it still works.

## Features

* Fill in an invoice, a receipt or a statement and get a finished document
* Print reverse-charge, intra-community, export and small-business invoices with the wording the law asks for
* Ask an invoice what it is missing before you issue it — a VAT number, a date of supply
* Lay out your own document: paragraphs that break across pages, tables whose header repeats on every page, footers
* Draw a logo from an SVG path, sharp at any zoom and a few hundred bytes
* Write the templates in the site's language, or through your plugin's own translations
* Send a PDF to the browser with a safe file name and headers no cache will keep
* Put a customer's name anywhere without it breaking out of the document

## Installation

```bash
composer require sugarcommerce/pdf
```

## Quick start

An order's invoice, sent to the person who asked for it:

```php
use SugarCommerce\Pdf\Download;
use SugarCommerce\Pdf\Templates\{Branding, Invoice, LineItem, Party, Translations};

$invoice = new Invoice(
	branding: new Branding( 'Northwind Audio', 'Sample packs and presets' ),
	number: 'INV-2026-0042',
	from: new Party( 'Northwind Audio Ltd', [ '12 Example Street', 'Dublin 2', 'Ireland' ], tax_id: 'IE1234567X' ),
	to: new Party( 'Ada Lovelace', [ '4 Marine Parade', 'Brighton BN2 1TL' ], email: 'ada@example.com' ),
	items: [
		new LineItem( 'Drum Kit Vol. 2 — studio licence', '€149.00' ),
		new LineItem( 'Vintage Keys', '€178.00', quantity: '2', unit_price: '€89.00' ),
	],
	totals: [ 'Subtotal' => '€327.00', 'VAT at 23%' => '€75.21' ],
	total: [ 'Total paid' => '€402.21' ],
	details: [ 'Issued' => '18 November 2026', 'Paid by' => 'Card ending 4242' ],
	supply_date: '18 November 2026',
	translator: Translations::wordpress( 'my-plugin' ),
);

Download::send( $invoice->render()->render(), 'Invoice INV-2026-0042' );
exit;
```

Amounts are strings you have already formatted, so the document shows exactly
what the order screen did. `$invoice->save( $path )` writes a file instead.
`Receipt` (A5, for a completed sale) and `Statement` (a ledger with a running
balance, over as many pages as it takes) take the same parts.

Every label and value block also takes a list of pairs —
`[ [ 'Discount', '-€1.00' ], [ 'Discount', '-€2.00' ] ]` — which is what a loop
builds, and the only way to write the same label twice.

## VAT

```php
use SugarCommerce\Pdf\Templates\{VatLine, VatTreatment};

new Invoice(
	// …
	vat: VatTreatment::ReverseCharge,
	vat_lines: [ new VatLine( rate: '0%', net: '€1,240.00', vat: '€0.00' ) ],
	supply_date: '1 August 2026',
);

$invoice->compliance_warnings(); // what is missing before this can be issued
```

`Standard` adds the rate table; `ReverseCharge` the Article 196 wording and
both VAT numbers; `IntraCommunitySupply` the Article 138 exemption; `Export`,
`SmallBusiness` (§19 UStG) and `Exempt` their notices. `german_notes: true`
adds the German wording alongside. `compliance_warnings()` checks what §14
UStG asks for — both VAT numbers on a reverse charge, a date of supply, a
number — rather than letting a document that looks right go out invalid.

## Languages

English, German, French, Spanish, Italian, Dutch, Portuguese, Swedish, Danish,
Norwegian and Finnish are built in:

```php
use SugarCommerce\Pdf\Templates\Language;

Language::site();               // the site's language, from determine_locale(); English outside WordPress
Language::from_locale( 'de_AT' );
Translations::wordpress( 'my-plugin' );                   // your translations first, then the site's language
Translations::wordpress( 'my-plugin', Language::German ); // your translations first, then German
```

`Translations::wordpress()` looks each English phrase up with `__()` in your
text domain, and a phrase your translation files do not cover falls through
to the built-in language — so a German site gets German invoices with no
translation work. `new Translations( $callable )` wraps any other translator.

The list stops at Latin-1 for a concrete reason: the PDF base fonts are
WinAnsi (Windows-1252), so Polish or Czech text would lose its accents and
Greek or Cyrillic would print as question marks. Customer names are under the
same limit.

## Characters

Text is transcoded to Windows-1252 a character at a time, without iconv, so it
comes out the same on every server: `Café £5 €5 Zoë` prints as typed, with the
curly quotes, dashes, `…` and `™` too. A letter Windows-1252 lacks loses its
accent (`Wąchock` → `Wachock`, `Dvořák` → `Dvorak`) or takes a stand-in
(`Łódź` → `Lódz`); anything else — Greek, Cyrillic, CJK, emoji — is a `?`, and
the rest of the line is untouched. Scripts beyond Latin need an embedded font,
which this package does not do.

## A document of your own

```php
use SugarCommerce\Pdf\{Align, Color, Document, PageSize, Table};

$pdf = new Document( PageSize::A4, margin: 48.0 );

$pdf->heading( 'Sales by product' )
	->text( 'November 2026', color: Color::grey( 120 ) )
	->rule();

( new Table( [ 'Product', 'Sold', 'Revenue' ] ) )
	->widths( [ 0.6, 0.15, 0.25 ] )
	->align( [ 1 => Align::Right, 2 => Align::Right ] )
	->striped()
	->rows( $rows )
	->draw( $pdf ); // the header repeats on every page the table reaches

$pdf->on_each_page( fn ( Document $pdf, int $page ) => $pdf->text_at( "Page {$page}", $pdf->right() - 40, 30, 8.0 ) );

$bytes = $pdf->render( [ 'Title' => 'Sales by product' ] );
```

`cell()`, `banded()` and `text_at()` place text in a box, a band or at a
point; `columns()`, `gap()`, `page_break()` and `break_if_needed()` move the
flow. `svg_path()` draws the `d` of an SVG `<path>` — a logo — and draws
nothing for a value that is not one. Pass `'CreationDate' => $timestamp` and
the same document renders to the same bytes, for a snapshot test.

## Downloads

`Download::send( $bytes, $filename, $inline )` calls `nocache_headers()` when
WordPress is there, sends the headers and the bytes, and leaves the `exit` to
you; it sends nothing once output has started. `Download::headers()` gives the
same headers as an array, for a REST response or a framework. The file name
is usually an order number and a customer's name, and it goes into a header:
line breaks, quotes and path separators are taken out, `filename` is plain
ASCII (`Müller` becomes `Muller`), the name as written travels in
`filename*`, and it always ends `.pdf`.

## What it does not do

* No images other than vector paths — no JPEG or PNG
* No font embedding, so no scripts beyond Latin-1
* No HTML or CSS
* No forms, encryption or signatures, and no reading existing PDFs

For those, use a full library. This one is for documents you generate.

## Moving from the SugarCommerce SaaS package

From the retired `sugarcommerce/text-pdf` (`SugarCommerce\TextPdf`). Everything
not listed keeps its name and behaviour.

| Old | New |
|---|---|
| `sugarcommerce/text-pdf` | `sugarcommerce/pdf` |
| `SugarCommerce\TextPdf\*` | `SugarCommerce\Pdf\*` |
| `SugarCommerce\TextPdf\Templates\*` | `SugarCommerce\Pdf\Templates\*` |
| PHP 8.5 | PHP 8.3 — nothing in the code needed 8.4 or 8.5 |
| `new Translations( fn ( $key, $default ) => __( $default, 'my-plugin' ) )` | `Translations::wordpress( 'my-plugin' )` — falls through to the site's built-in language as well |
| `Language::from_locale( get_locale() )` | `Language::site()` |
| headers written by hand to stream a PDF | `Download::send()` and `Download::headers()` |

## Requirements

* PHP 8.3 or later, with `iconv` and `mbstring` (`intl`, when present, is used to take accents off letters Windows-1252 lacks)
* WordPress 7.1 or later — optional: without it the language is English and downloads skip `nocache_headers()`

## License

GPL-2.0-or-later
