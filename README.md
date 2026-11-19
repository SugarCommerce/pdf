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
that flows across pages, tables, rules, your logo, the VAT wording an EU
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
* Put your logo on every document as a PNG or JPEG — transparency kept, turned the right way up, embedded once however many pages it is on — or as an SVG path, sharp at any zoom and a few hundred bytes
* Know when a picture did not make it: a damaged, oversized or unsupported file is refused with the reason, never left out unsaid
* Write the templates in the site's language, or through your plugin's own translations
* Send a PDF to the browser with a safe file name and headers no cache will keep
* Put a customer's name anywhere without it breaking out of the document
* Print names and addresses in Polish, Czech, Turkish, Greek, Ukrainian or any other European alphabet — the bundled Noto Sans draws what Helvetica cannot, and only the letters used are embedded
* Set every document in your brand's typeface from its `.ttf` files, with copy and search working on the page

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
that stopped drawing the seller; this one does not, and it reports a name no
font in reach could print as written (`東京` comes out as `??`).

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

The built-in wording is in these eleven. Customer names, addresses, items and
your own wording can be in any European alphabet: see [Fonts](#fonts).

## Fonts

A document is set in the PDF base fonts — Helvetica, Helvetica-Bold,
Helvetica-Oblique, Courier — which every reader has, so nothing is embedded
and an invoice in English, German or French is about 4 KB. Their metrics are
carried, so columns of figures right-align to the point.

Those fonts know Windows-1252 and nothing else. A word they cannot print as
written is drawn in **Noto Sans**, which comes with the library and covers
Latin with every European accent, Greek and Cyrillic. Only the glyphs used are
embedded — a page with one Ukrainian address grows by about 5 KB — and the
text still copies, searches and reads aloud as the letters it is. Nothing to
set up:

```php
$invoice = new Invoice( to: new Party( 'Ярослав Ковальчук', [ 'вул. Хрещатик, 22', 'Київ 01001' ] ), /* … */ );
```

A word is drawn in one face, never half in each. Text is put in composed form
first (`e` + a combining acute is `é`), and `Café £5 €5 “Zoë”` stays in
Helvetica, transcoded a character at a time without iconv so it comes out the
same on every server.

**Your brand's typeface.** Give the branding a family and every document is set
in it, headings in its bold:

```php
use SugarCommerce\Pdf\FontFamily;

$brand = new Branding( 'Northwind Audio', typeface: FontFamily::files(
	__DIR__ . '/fonts/Inter-Regular.ttf',
	__DIR__ . '/fonts/Inter-Bold.ttf',
	__DIR__ . '/fonts/Inter-Italic.ttf',   // optional; italic falls back to the regular
) );

$brand = new Branding( 'Northwind Audio', typeface: FontFamily::noto_sans() ); // the bundled one, everywhere
```

A word the family lacks a glyph for falls back to the base font, then to Noto
Sans. On a `Document` of your own, `family( $family )` does the same, and
`fallback( $family )` chooses the fallback — a Noto CJK face for Japanese
names, say — or `fallback( null )` to embed nothing and print what
Windows-1252 lacks as its nearest letter (`Łódź` → `Lódz`) or `?`.
`substitutions()` lists every run that did not print as written. Layout code
asks for a role (`Font::Helvetica`, `Font::HelveticaBold`,
`Font::HelveticaOblique`, `Font::Courier`) and measures through the document —
`width()`, `fit()`, `lines()`, `ascender()`, `cap_height()`,
`baseline_in_band()` — so it is right in whichever face draws.

**What a font must be.** A static TrueType `.ttf`, read in PHP: `cmap` formats
4 and 12, `hmtx`, `glyf`/`loca` with composite glyphs (an `é` built from `e`
and an acute brings both), and the vertical metrics from `head`, `hhea`, `OS/2`
and `post`. It is embedded as a CIDFontType2 subset under Identity-H with a
`/W` widths array and a ToUnicode map, deflated, with a `ABCDEF+` subset tag.
Refused with a reason: PostScript-outline `.otf`, `.ttc` collections, WOFF,
variable fonts (a subset would print every weight as the default — use the
static instances), and fonts whose licence bits forbid embedding or subsetting.
No kerning or ligatures, as in the Swift writer this ports.

**Right-to-left and shaped scripts are out of scope.** Arabic, Hebrew, Syriac,
Thaana, N'Ko, the Indic scripts, Myanmar and Khmer need a shaping engine to
reorder and join letters; drawn a code point at a time they would come out
wrong in a way a reader who does not know the script could not tell. They are
printed as `?` and reported by `substitutions()` — even with a font that has
the glyphs. CJK and emoji are not refused: a fallback face that has them draws
them.

**The bundled font.** `fonts/NotoSans-Regular.ttf` and `NotoSans-Bold.ttf` are
Noto Sans 2.015 (the unhinted static build) with the shaping and kerning tables
and glyph names removed by `bin/trim-font` — 290 KB each, outlines untouched,
read only when a word needs them. Noto Sans is under the SIL Open Font License
1.1 (`fonts/OFL.txt`), with no Reserved Font Name. The OFL is a free licence
that lets the font be bundled and distributed with software under any licence,
GPL included — the font stays under the OFL as a separate work in the same
package, the way WordPress's own default themes ship OFL fonts — and it places
no condition on documents that embed it.

## Logos and pictures

Give the branding a PNG or JPEG and every invoice, receipt and statement
carries it at the top left, with the wordmark beside it:

```php
$brand = new Branding( 'Northwind Audio', logo_image: __DIR__ . '/assets/logo.png' );
```

The logo is as tall as the masthead's band — 34 points on an invoice, 30 on a
statement, 26 on a receipt — and as wide as its proportions make it, up to
four times the band; a wider one is drawn shorter, centred in the band, rather
than run into the title. A file that cannot be read falls back to `logo_path`,
the SVG path, when there is one. `logo_image` also takes an `Image` already
read, for a logo used on thousands of documents in one request.

On a document of your own:

```php
use SugarCommerce\Pdf\{Align, Document, Fit, Image};

$pdf  = new Document();
$logo = Image::file( __DIR__ . '/assets/logo.png' ); // read and checked once

$pdf->image( $logo, $pdf->left(), $pdf->height() - 90, 120, 40 );                       // whole, in proportion, centred in the box
$pdf->image( $logo, $pdf->left(), 700, 120, 40, Fit::Contain, Align::Left );           // ...against the box's left edge
$pdf->image( __DIR__ . '/team.jpg', 300, 500, 200, 200, Fit::Cover );                  // covering the box, cut off at its edges
$pdf->image( __DIR__ . '/signature.png', 56, 120, 160, 50, Fit::Fill );                 // stretched to the box exactly

[ $width, $height ] = $logo->fitted( 120, 40 ); // the size Fit::Contain will draw, to lay out around it
$logo->width;  $logo->height;  $logo->aspect_ratio();
```

The box is given by its bottom-left corner, as everything on a PDF page is.
The same picture drawn again — on every page, from a footer, or read from the
same file twice — is embedded once.

**What it reads.** JPEG is passed through as it is, because a PDF reader
decodes JPEG itself: baseline and progressive, greyscale, colour and CMYK
(Photoshop's inverted CMYK is turned back with a `/Decode` array). An EXIF
orientation turns the picture the way a photo viewer would, so a photo taken
on its side is not printed on its side. EXIF, the camera's thumbnail, ICC
profiles and comments are left out of the file — where a photograph was taken
has no business on an invoice — and anything after the end of the image goes
too.

PNG is read in PHP. An opaque greyscale, colour or palette PNG of up to eight
bits goes in as its own compressed rows, which a PDF reads with the PNG
predictor — no decoding, only a check that the data is whole. Transparency
(an alpha channel, or a `tRNS` colour or palette entries), sixteen-bit samples
and interlacing are decoded: alpha becomes a soft mask, sixteen bits come down
to eight, and a palette stays a palette. GD and Imagick are not used: neither
is on every host, so the PHP decoder would be needed anyway, and with two
paths the one the tests prove would not be the one every server runs — GD
also rounds alpha to seven bits and turns palettes into true colour, and its
output depends on the libpng and zlib it was built with.

SVG files are not read. A logo drawn as paths goes in as its path data, the
`d` of each `<path>`, through `logo_path` or `svg_path()` — which is what the
Swift writer this ports does too.

**What it refuses.** Every refusal says why and what to do, so nothing goes
missing unsaid: a damaged or cut-short file (a failed checksum, image data
that ends early, a row filter PNG does not have), GIF, WebP, HEIC, TIFF and
BMP by name, JPEGs no reader decodes (lossless, arithmetic-coded,
hierarchical, twelve-bit), and anything too large — over 32 MB
(`Image::MAX_BYTES`), over 50 megapixels (`Image::MAX_PIXELS`), or, for a PNG
that has to be decoded, over 4 megapixels (`Image::MAX_DECODED_PIXELS`, about
half a second and 60 MB to decode). A deflate bomb is stopped as soon as it
passes the size the image can be, without inflating the rest. `Image::file()`
and `new Image( $bytes, 'logo.png' )` throw an `InvalidArgumentException`;
`image()` given a path records the reason instead and returns `false`, as does
a box with no size. A picture placed partly off the page is drawn and
reported, since the reader cuts it off. Ask the document:

```php
$pdf = $invoice->render();

foreach ( $pdf->image_warnings() as $warning ) { // footers included
	error_log( $warning ); // "logo.png is cut short: its image data ends before its last row."
}
```

`load_image( $path )` reads a picture the same way — an `Image`, or null and a
warning — for laying out around it before drawing.

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
logo — and draws nothing for a value that is not one; `image()` draws a PNG or
JPEG (see [Logos and pictures](#logos-and-pictures)). `drawn_text()` lists every
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

`tests/golden/` holds fourteen documents — an invoice, a reverse charge with a
SEPA code, a credit note, a proforma, a delivery note, mixed rates, a
three-page invoice, a French intra-community supply, a receipt, a statement,
an invoice in Cyrillic, Greek and Polish, one set in Noto Sans, an invoice
with a transparent PNG logo and a receipt with a JPEG one — as PDFs
and as `pdftotext -layout` reads them. They double as a gallery. A PDF is
compared with every stream inflated, so every object, operator and font byte
counts but two zlib builds deflating alike does not. With poppler and qpdf
installed (`brew install poppler qpdf`; CI installs `poppler-utils` and
`qpdf`) the suite also runs `qpdf --check` on each, checks with `pdffonts`
that every font is a base font or an embedded subset, and reads the Cyrillic,
Greek, Polish, `€` and `£` back through both poppler and smalot, and lists
every picture with `pdfimages -list`; without the tools those tests are
skipped, except in CI, where they fail.

The image readers are tested against PNGs the suite builds to order — every
colour type at every bit depth, each row filter, interlaced and not, palette
and colour-key transparency — whose pixels are compared three ways: with what
was built, with what poppler's `pdfimages` decodes from the finished PDF, and
(where GD is installed) with what libpng reads from the file. Each EXIF
orientation is rendered by `pdftoppm` and checked against the specification's
own description of it; so is a CMYK JPEG's colour. Damaged, truncated,
oversized and hostile files — a deflate bomb among them — are each refused with
their reason. The decoder, the placement and the embedding were mutation-checked:
every change that alters a result fails the suite.

The TrueType reader and subsetter are tested against fonts the suite builds to
order — format 4 and 12 maps, short and long `loca`, composites of composites
with every transform size, damaged and hostile files — and against the bundled
Noto Sans.

## What it does not do

* No GIF, WebP, HEIC or TIFF, and no SVG files — PNG and JPEG, plus SVG path data (see [Logos and pictures](#logos-and-pictures))
* No right-to-left or shaped scripts — Arabic, Hebrew, the Indic scripts — and no kerning or ligatures (see [Fonts](#fonts)); mPDF does those
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

* PHP 8.3 or later, with `iconv`, `mbstring` and `zlib` (`intl`, when present, composes accents before printing, takes them off letters Windows-1252 lacks when there is no fallback font, and serves `Totals`' `locale`)
* `sugarcommerce/money`, `sugarcommerce/qr` and `sugarcommerce/text`
* WordPress 7.1 or later — optional: without it the language is English and downloads skip `nocache_headers()`

## License

GPL-2.0-or-later
