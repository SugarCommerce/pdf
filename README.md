# PDF

Invoices, credit notes, quotes, receipts and statements as PDF files, written
directly by PHP with nothing to install: no binary, no HTML engine. Three small
SugarCommerce packages come with it: `sugarcommerce/money` does the arithmetic
in whole pence and cents, `sugarcommerce/qr` makes the payment codes, and
`sugarcommerce/text` reads a hex colour.

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

* Fill in an invoice, a receipt or a statement and get a finished document, with the seller's name, address, VAT and company numbers on it
* Issue credit notes, debit notes, quotes, proformas, delivery notes and the rest of the twelve kinds from the same template
* Work the figures out from whole minor units — lines, discounts, VAT per rate, the total — so the page always adds up
* Put a SEPA payment code, or any link, on the invoice as a QR code someone can scan
* Print reverse-charge, intra-community, export and small-business invoices with the wording the law asks for, in English and the customer's language
* Ask an invoice what it is missing before you issue it — and ask the drawn page whether it actually got printed
* Long descriptions, notes and payment terms wrap; tables run on over pages with their header; totals never split from their total
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

## The seller

`from` is printed under **FROM**, beside **BILLED TO** and the details: the
legal name, the address, the email, `VAT …` and `Company no. …`. Those are
mandatory particulars (Article 226 of the VAT Directive), and the wordmark at
the top is a trading name, often not the legal one.

```php
new Party(
	'Northwind Audio Ltd',
	[ '12 Example Street', 'Dublin 2', 'Ireland' ],
	email: 'accounts@northwind.example',
	tax_id: 'IE1234567X',
	registration: '654321', // printed as "Company no. 654321"
);
```

A `from` with no address of its own takes `Branding::$address`, so an address
set once on the branding is on every document. `Receipt` and `Statement` take a
`from:` too and print it under the wordmark.

## Figures from minor units

Amounts on the page are strings, so the document shows what your order screen
showed. When the figures have not been worked out yet, `Totals` does it in
integers and formats every amount from the same numbers:

```php
use SugarCommerce\Pdf\Templates\{Discount, Totals, TotalsLine};

$totals = new Totals(
	lines: [
		new TotalsLine( 'Drum Kit Vol. 2', 14900 ),                  // €149.00
		new TotalsLine( 'Vintage Keys', 8900, 2, note: 'Two seats' ),
		new TotalsLine( 'Printed workbook', 2500, rate: 0 ),         // its own rate
	],
	currency: 'EUR',
	rate: 23,
	discounts: [ Discount::percent( 'Discount (SPRING10)', 10 ), Discount::fixed( 'Voucher', 500 ) ],
);

new Invoice(
	// …
	items:     $totals->items(),
	totals:    $totals->rows(),       // Subtotal, each discount, VAT at each rate
	total:     $totals->total(),      // [ [ 'Total due', '€…' ] ]
	vat_lines: $totals->vat_lines(),
);

$totals->subtotal(); $totals->discount(); $totals->net(); $totals->tax(); $totals->gross(); // ints
$totals->by_rate(); // [ '23' => [ 'net' => …, 'tax' => … ], '0' => … ]
```

The rules are the ones a tax authority expects, each pinned by a test: VAT is
taken per rate on the total, not per line (they differ by a cent on some
invoices); half a cent rounds away from zero; discounts come off before tax, a
percentage being of the subtotal, and never take the total below nothing; with
mixed rates a discount is shared across them in proportion, largest remainder
first, so the shares add up to the cent. The exponent is the currency's — `¥`
has none, `KWD` three. `locale: 'de'` writes `1.234,56 €` through ext-intl; the
default `€1,234.56` is the same bytes on every server. An amount too large to
hold exactly throws rather than rounding, and so does a rate outside 0–100 or a
currency `sugarcommerce/money` does not know. `rows( Language::German )` gives
`Zwischensumme` and `MwSt. 19%`.

## Credit notes, quotes and the other kinds

```php
use SugarCommerce\Pdf\Templates\DocumentKind;

new Invoice(
	// …
	kind:      DocumentKind::CreditNote,
	reference: 'INV-2026-0042',                            // printed as "Original invoice"
	total:     $totals->total( DocumentKind::CreditNote->total_label() ), // "Total credited"
);
```

| Kind | Title | Notes |
|---|---|---|
| `Invoice` | INVOICE | |
| `CreditNote` | CREDIT NOTE | needs a `reference`; "This credit note reverses the invoice referenced above…" |
| `DebitNote` | DEBIT NOTE | needs a `reference` |
| `Quote` | QUOTE | not a tax document; notes headed TERMS |
| `Proforma` | PROFORMA | says it is not a VAT invoice |
| `Receipt` | RECEIPT | "Paid in full." |
| `Reminder` | REMINDER | |
| `Remittance` | REMITTANCE ADVICE | addressed PAID TO |
| `SelfBilling` | SELF-BILLED INVOICE | the words "Self-billing" on its face; the supplier's VAT number required |
| `DeliveryNote` | DELIVERY NOTE | no prices, no totals; addressed DELIVER TO |
| `PurchaseOrder` | PURCHASE ORDER | addressed SUPPLIER |
| `OrderConfirmation` | ORDER CONFIRMATION | |

Each kind knows its `title()`, `total_label()`, `recipient_heading()`,
`notes_heading()`, `reference_label()` and `standing_note()`, all translated,
and whether it `shows_money()` or `is_tax_document()`. `compliance_warnings()`
asks nothing of a quote or a delivery note, and asks a credit note for the
invoice it reverses. The kind titles and headings are in all eleven languages;
the standing notes are English unless your translator has them (their keys are
`credit_note_note`, `proforma_note` and so on).

## Payment codes

```php
use SugarCommerce\Pdf\Templates\PaymentCode;

new Invoice(
	// …
	notes:        'Pay by SEPA transfer, quoting INV-2026-0043.',
	payment_code: PaymentCode::epc( 'Northwind Audio Ltd', 'IE29 AIBK 9311 5212 3456 78', $totals->gross(), reference: 'INV-2026-0043' ),
);

new PaymentCode( 'https://example.com/verify/INV-2026-0043', 'Scan to verify' ); // any payload
```

The code is drawn beside the payment notes as vector squares, 92 points across,
with its caption under it ("Scan to pay", translated). `epc()` writes the
EPC069-12 payload a European banking app scans for a SEPA credit transfer, and
returns null rather than a code the app would reject: an amount that is not
0.01–999,999,999.99 euro (it takes cents), a name over 70 characters, a
reference over 140, something that is not an IBAN or a BIC. A newline in a field
cannot shift the fields after it. `Document::qr()` draws a code anywhere.

## VAT

```php
use SugarCommerce\Pdf\Templates\{Language, VatLine, VatTreatment};

new Invoice(
	// …
	vat: VatTreatment::ReverseCharge,
	vat_lines: [ new VatLine( rate: '0%', net: '€1,240.00', vat: '€0.00' ) ],
	supply_date: '1 August 2026',
	wording: Language::French, // or leave it to the document's language
);

$invoice->compliance_warnings();           // what the data is missing
$invoice->compliance_warnings_verifying(); // …and what the drawn page is missing
```

`Standard` prints nothing extra; `ReverseCharge` the Article 196 wording and
both VAT numbers; `IntraCommunitySupply` the Article 138 exemption; `Export`,
`SmallBusiness` (§19 UStG) and `Exempt` their notices. Beside the English, the
wording in German, French, Italian, Spanish or Dutch, citing that country's own
statute (`§ 13b UStG`, `article 283-2 du CGI`…): `wording:` picks one, a
document in one of those languages gets its own automatically, and
`german_notes: true` still means German. `VatTreatment::notes( Language )` and
`wording( Language )` give the lines. The wording wraps inside its box however
long it is.

The per-rate breakdown is printed where it adds something: with more than one
rate, or with a zero rating it evidences. At a single standard rate it would
repeat the totals, so it is left out.

`compliance_warnings()` checks what §14 UStG and Article 226 ask for — the
seller's name, address and VAT number, the customer's address, both VAT numbers
on a reverse charge, a number, a date of supply. `compliance_warnings_verifying()`
then asks the rendered page, as a reader would extract it, for the seller's
name, address lines and VAT number, the number, the date of supply, the total,
the customer's VAT number where it is required, the credit note's reference, the
VAT wording and the kind's standing note. A check of the data passes a template
that stopped drawing the seller; this one does not, and it reports a name the
base fonts could not print as written (`Ярослав` comes out as `???????`).

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

$pdf->language( 'en-GB' )   // /Lang, for screen readers and validators
	->keep_clear( 16 );     // a band above the bottom margin for the footer

$pdf->heading( 'Sales by product' )
	->text( 'November 2026', color: Color::grey( 120 ) )
	->rule();

( new Table( [ 'Product', 'Sold', 'Revenue' ] ) )
	->widths( [ 0.6, 0.15, 0.25 ] )
	->align( [ 1 => Align::Right, 2 => Align::Right ] )
	->striped()
	->wrap()                                       // long cells wrap instead of ending in ...
	->row( [ 'Drum Kit Vol. 2', '41', '£6,109.00' ], 'Studio and agency licences' ) // a note under the first cell
	->rows( $rows )
	->draw( $pdf ); // the header repeats on every page the table reaches

$pdf->block( $terms, $pdf->left(), $pdf->content_width(), 8.5 ); // wrapped, and onto the next page if need be
$pdf->qr( 'https://example.com', $pdf->right() - 90, 60, 90 );

$pdf->on_each_page( fn ( Document $pdf, int $page ) => $pdf->text_at( "Page {$page}", $pdf->right() - 40, 30, 8.0 ) );

$bytes = $pdf->render( [ 'Title' => 'Sales by product' ] );
```

`cell()`, `banded()` and `text_at()` place one line in a box, a band or at a
point; `block()` wraps text to a column and starts a new page when it reaches the
bottom, and `block_height()`, `lines()`, `width()` and `fit()` measure first.
`columns()`, `gap()`, `page_break()`, `break_if_needed()` and `page_room()` move
the flow. A wrapped table row grows to its tallest cell and is never split
across two pages unless it is taller than a page, when it continues line by line
under the repeated header. `svg_path()` draws the `d` of an SVG `<path>` — a
logo — and draws nothing for a value that is not one. `drawn_text()` lists every
run of text drawn, footers included, as a reader will extract it. Pass
`'CreationDate' => $timestamp` and the same document renders to the same bytes,
for a snapshot test; `Invoice::save( $path, [ 'CreationDate' => … ] )` passes it
through.

## Downloads

`Download::send( $bytes, $filename, $inline )` calls `nocache_headers()` when
WordPress is there, sends the headers and the bytes, and leaves the `exit` to
you; it sends nothing once output has started. `Download::headers()` gives the
same headers as an array, for a REST response or a framework. The file name
is usually an order number and a customer's name, and it goes into a header:
line breaks, quotes and path separators are taken out, `filename` is plain
ASCII (`Müller` becomes `Muller`), the name as written travels in
`filename*`, and it always ends `.pdf`.

## Tests

```bash
composer test                  # PHPUnit, with smalot/pdfparser reading every page back
UPDATE_GOLDEN=1 composer test  # after a deliberate change: rewrite the golden files, then read the diff
```

`tests/golden/` holds ten documents — an invoice, a reverse charge with a SEPA
code, a credit note, a proforma, a delivery note, mixed rates, a three-page
invoice, a French intra-community supply, a receipt and a statement — byte for
byte, and as `pdftotext -layout` reads them. They double as a gallery. With
poppler and qpdf installed (`brew install poppler qpdf`; CI installs
`poppler-utils` and `qpdf`) the suite also runs `qpdf --check` on each and
checks `pdffonts`; without them those tests are skipped, except in CI, where
they fail.

## What it does not do

* No images other than vector paths and QR codes — no JPEG or PNG yet
* No font embedding yet, so no scripts beyond Latin-1
* No PDF/A or Factur-X / ZUGFeRD e-invoices yet
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
| `Color::hex()`'s own hex parsing | the same method, reading through `SugarCommerce\Text\Display\Color::rgb()` (sugarcommerce/text, the one colour helper) |

## Requirements

* PHP 8.3 or later, with `iconv` and `mbstring` (`intl`, when present, is used to take accents off letters Windows-1252 lacks, and for `Totals`' `locale`)
* `sugarcommerce/money`, `sugarcommerce/qr` and `sugarcommerce/text`
* WordPress 7.1 or later — optional: without it the language is English and downloads skip `nocache_headers()`

## License

GPL-2.0-or-later
