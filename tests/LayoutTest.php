<?php
/**
 * Layout: wrapped blocks and cells, tables over several pages, blocks
 * that are kept together, and nothing drawn off the page.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Pdf\Document;
use SugarCommerce\Pdf\Models\Align;
use SugarCommerce\Pdf\Models\Font;
use SugarCommerce\Pdf\Models\Templates\DocumentKind;
use SugarCommerce\Pdf\Models\Templates\LineItem;
use SugarCommerce\Pdf\Models\Templates\Party;
use SugarCommerce\Pdf\Models\Templates\Totals;
use SugarCommerce\Pdf\Models\Templates\TotalsLine;
use SugarCommerce\Pdf\Table;
use SugarCommerce\Pdf\Templates\Invoice;
use SugarCommerce\Pdf\Tests\Support\{Fixtures, Reader};

final class LayoutTest extends TestCase {

	private const CREATED = array( 'CreationDate' => Fixtures::CREATED );

	/* ─── Blocks ───────────────────────────────────────────────────── */

	public function test_a_block_wraps_to_its_width_and_keeps_its_newlines(): void {
		$pdf = new Document();
		$pdf->block( "First paragraph, long enough that it has to wrap at least once in a column this narrow.\nSecond.", 100, 150, 9 );

		$runs = Reader::runs( $pdf->render( self::CREATED ) )[0];

		$this->assertGreaterThan( 2, count( $runs ) );
		$this->assertSame( 'Second.', end( $runs )['text'] );

		foreach ( $runs as $run ) {
			$this->assertSame( 100.0, $run['x'] );
			$this->assertLessThanOrEqual( 250.0, $run['x'] + $run['width'] );
		}
	}

	public function test_a_block_moves_the_cursor_by_its_measured_height(): void {
		$pdf    = new Document();
		$text   = str_repeat( 'Words that wrap. ', 30 );
		$before = $pdf->cursor();

		$pdf->block( $text, $pdf->left(), 200, 9, leading: 12 );

		$this->assertEqualsWithDelta( $pdf->block_height( $text, 200, 9, leading: 12 ), $before - $pdf->cursor(), 0.001 );
		$this->assertSame( count( $pdf->lines( $text, 200, 9 ) ) * 12.0, $pdf->block_height( $text, 200, 9, leading: 12 ) );
	}

	/**
	 * The bug this replaces: a payment note was drawn on one line, and
	 * everything past the right edge was not there.
	 */
	public function test_a_block_too_long_for_the_page_continues_on_the_next(): void {
		$pdf = new Document();
		$pdf->block( str_repeat( 'A sentence that goes on. ', 600 ), $pdf->left(), $pdf->content_width() );

		$pages = Reader::runs( $pdf->render( self::CREATED ) );

		$this->assertGreaterThan( 1, count( $pages ) );

		foreach ( $pages as $runs ) {
			foreach ( $runs as $run ) {
				$this->assertGreaterThanOrEqual( $pdf->margin - 3, $run['y'] );
				$this->assertLessThanOrEqual( $pdf->right() + 0.01, $run['x'] + $run['width'] );
			}
		}

		$this->assertSame( 600, substr_count( Reader::text( $pdf->render( self::CREATED ) ), 'goes on.' ) );
	}

	public function test_a_word_wider_than_the_column_is_cut_not_lost(): void {
		$pdf   = new Document();
		$word  = 'https://example.com/' . str_repeat( 'x', 200 );
		$lines = $pdf->lines( $word, 100, 9 );

		$this->assertGreaterThan( 1, count( $lines ) );
		$this->assertSame( $word, implode( '', $lines ) );
	}

	public function test_width_and_fit_measure_what_is_drawn(): void {
		$pdf = new Document();

		$this->assertSame( Font::Helvetica->width_of( "\x80", 10 ), $pdf->width( '€', 10 ) );
		$this->assertSame( 'Short', $pdf->fit( 'Short', 100, 10 ) );

		$fitted = $pdf->fit( str_repeat( 'é', 100 ), 50, 10 );

		$this->assertStringEndsWith( '...', $fitted );
		$this->assertTrue( mb_check_encoding( $fitted, 'UTF-8' ) );
		$this->assertLessThanOrEqual( 50.0, $pdf->width( $fitted, 10 ) );
	}

	/* ─── The page ──────────────────────────────────────────────────── */

	public function test_a_band_kept_clear_moves_every_break_up(): void {
		$pdf = new Document( margin: 50 );

		$this->assertSame( $pdf->cursor() - 50, $pdf->remaining() );
		$this->assertSame( $pdf->height() - 100, $pdf->page_room() );

		$pdf->keep_clear( 30 );

		$this->assertSame( $pdf->cursor() - 80, $pdf->remaining() );
		$this->assertSame( $pdf->height() - 130, $pdf->page_room() );

		for ( $i = 0; $i < 100; $i++ ) {
			$pdf->text( 'Line' );
		}

		foreach ( Reader::runs( $pdf->render( self::CREATED ) ) as $runs ) {
			foreach ( $runs as $run ) {
				$this->assertGreaterThan( 80.0 - 10, $run['y'] );
			}
		}

		$this->assertSame( 0.0, ( new Document() )->keep_clear( -5 )->page_room() - ( new Document() )->page_room() );
	}

	public function test_the_language_is_written_and_a_bad_one_is_not(): void {
		$this->assertStringContainsString( '/Type /Catalog /Pages 2 0 R /Lang (de-AT)>>', ( new Document() )->language( 'de_AT' )->render( self::CREATED ) );
		$this->assertStringContainsString( '/Type /Catalog /Pages 2 0 R>>', ( new Document() )->language( 'en) /OpenAction' )->render( self::CREATED ) );
		$this->assertSame( '', ( new Document() )->language( 'en) /OpenAction' )->language_tag() );
		$this->assertStringContainsString( '/Lang (en)', \SugarCommerce\Pdf\Output\Writer::write( array( '' ), 10, 10, array(), 'e(n)' ) );
	}

	public function test_drawn_text_is_what_a_reader_extracts(): void {
		$pdf   = ( new Document() )->text( 'Łódź' )->text( 'Ярослав' )->text( '€5' )->text( 'Tokyo 東京' );
		$bytes = $pdf->render( self::CREATED );

		$this->assertSame( array( 'Łódź', 'Ярослав', '€5', 'Tokyo ??' ), $pdf->drawn_text() );
		$this->assertSame( $pdf->drawn_text(), array_column( Reader::runs( $bytes )[0], 'text' ) );
	}

	/**
	 * With the fallback switched off, the base fonts print what they can,
	 * as before there was one.
	 */
	public function test_drawn_text_without_a_fallback_is_what_windows_1252_holds(): void {
		$pdf = ( new Document() )->fallback( null )->text( 'Łódź' )->text( 'Ярослав' )->text( '€5' );

		$this->assertSame( array( 'Lódz', '???????', '€5' ), $pdf->drawn_text() );
		$this->assertSame( array( 'Łódź', 'Ярослав' ), $pdf->substitutions() );
	}

	public function test_drawn_text_has_the_footers_once_however_often_it_is_rendered(): void {
		$pdf = ( new Document() )->text( 'Body' );
		$pdf->on_each_page( static fn( Document $doc, int $page, int $total ) => $doc->text_at( "Page {$page} of {$total}", 0, 20, 8 ) );

		$pdf->render( self::CREATED );
		$pdf->render( self::CREATED );

		$this->assertSame( array( 'Body', 'Page 1 of 1' ), $pdf->drawn_text() );
		$this->assertSame( array( 'Body', 'Page 1 of 1' ), $pdf->drawn_text() );
	}

	/* ─── Tables ────────────────────────────────────────────────────── */

	public function test_a_wrapped_row_grows_and_its_note_sits_under_the_first_cell(): void {
		$pdf = new Document();

		( new Table( array( 'Description', 'Amount' ) ) )
			->widths( array( 0.3, 0.7 ) )
			->align( array( 1 => Align::Right ) )
			->wrap()
			->row( array( 'A description long enough to need three lines or more in this column', '€1.00' ), 'The note, which is not lost' )
			->draw( $pdf, 9 );

		$runs  = Reader::runs( $pdf->render( self::CREATED ) )[0];
		$texts = array_column( $runs, 'text' );

		$this->assertContains( 'The note, which is not lost', $texts );
		$this->assertNotContains( '...', array_map( static fn( string $t ): string => substr( $t, -3 ), $texts ) );

		$note   = $runs[ array_search( 'The note, which is not lost', $texts, true ) ];
		$amount = $runs[ array_search( '€1.00', $texts, true ) ];

		$this->assertLessThan( $amount['y'], $note['y'] );
		$this->assertSame( $amount['y'], $runs[2]['y'], 'Every column starts on the first line.' );
	}

	public function test_without_wrap_a_cell_is_cut_short_and_the_row_keeps_its_height(): void {
		$pdf = new Document();

		( new Table() )->row_height( 20 )->row( array( str_repeat( 'long ', 100 ) ) )->draw( $pdf );

		$this->assertStringEndsWith( '...', Reader::runs( $pdf->render( self::CREATED ) )[0][0]['text'] );
		$this->assertEqualsWithDelta( $pdf->height() - $pdf->margin - 20, $pdf->cursor(), 0.001 );
	}

	/**
	 * A row that fits on a page is never split across two: it moves to
	 * the next page whole, under the repeated header.
	 */
	public function test_a_row_that_fits_a_page_is_never_split(): void {
		$pdf   = new Document();
		$table = ( new Table( array( 'Item', 'Amount' ) ) )->wrap();

		for ( $i = 0; $i < 60; $i++ ) {
			$table->row( array( "Row {$i} " . str_repeat( 'wrapping words ', 12 ) . "end{$i}", '1' ), "note{$i}" );
		}

		$table->draw( $pdf, 9 );

		$pages = Reader::runs( $pdf->render( self::CREATED ) );

		$this->assertGreaterThan( 2, count( $pages ) );

		foreach ( $pages as $runs ) {
			$texts = array_column( $runs, 'text' );

			$this->assertSame( 'Item', $texts[0], 'The header repeats on every page.' );

			foreach ( $texts as $text ) {
				if ( preg_match( '/^Row (\d+)/', $text, $m ) ) {
					$this->assertContains( 'note' . $m[1], $texts, "Row {$m[1]} was split from its note." );
				}
			}
		}
	}

	/**
	 * A row taller than a whole page splits, line by line, with the
	 * header repeated — rather than running off the bottom.
	 */
	public function test_a_row_taller_than_a_page_is_split_across_pages(): void {
		$pdf  = new Document();
		$text = implode( ' ', array_map( static fn( int $i ): string => "word{$i}", range( 1, 3000 ) ) );

		( new Table( array( 'Item', 'Amount' ) ) )->widths( array( 0.8, 0.2 ) )->wrap()->row( array( $text, '€1.00' ) )->draw( $pdf, 9 );

		$pages = Reader::runs( $pdf->render( self::CREATED ) );
		$all   = '';

		$this->assertGreaterThan( 2, count( $pages ) );

		foreach ( $pages as $runs ) {
			$this->assertSame( 'Item', $runs[0]['text'] );

			foreach ( $runs as $run ) {
				$this->assertGreaterThanOrEqual( $pdf->margin - 3, $run['y'] );

				if ( str_starts_with( $run['text'], 'word' ) ) {
					$all .= ' ' . $run['text'];
				}
			}
		}

		$this->assertSame( $text, trim( $all ) );
	}

	/* ─── Templates ─────────────────────────────────────────────────── */

	/**
	 * The subtotal rows and the total are kept on one page, whatever
	 * number of lines pushes them to the foot of one.
	 */
	public function test_the_totals_are_never_split_from_their_total(): void {
		for ( $count = 8; $count <= 40; $count++ ) {
			$lines = array();

			for ( $i = 0; $i < $count; $i++ ) {
				$lines[] = new TotalsLine( "Line {$i}", 1000 );
			}

			$totals = new Totals( $lines, 'EUR', 23 );
			$bytes  = ( new Invoice(
				Fixtures::branding(),
				'INV-1',
				Fixtures::seller(),
				new Party( 'Buyer', array( 'Somewhere' ) ),
				$totals->items(),
				$totals->rows(),
				$totals->total( DocumentKind::CreditNote->total_label() ),
				kind: DocumentKind::CreditNote,
				reference: 'INV-0',
			) )->render()->render( self::CREATED );

			$pages = array();

			foreach ( Reader::runs( $bytes ) as $index => $runs ) {
				foreach ( $runs as $run ) {
					$pages[ $run['text'] ] ??= $index;
				}
			}

			$this->assertSame( $pages['Subtotal'], $pages['Total credited'], "{$count} lines" );
			$this->assertSame( $pages['Subtotal'], $pages['This credit note reverses the invoice referenced above. Retain both for your'] ?? $pages['This credit note reverses the invoice referenced above. Retain both for your records.'] ?? null, "{$count} lines" );
		}
	}

	/**
	 * The long fixture: forty items whose descriptions wrap and whose
	 * notes are all on the page, a payment note of three paragraphs, and
	 * not one run of text past the right margin or under the bottom one.
	 */
	public function test_nothing_is_dropped_or_drawn_off_the_page(): void {
		$invoice = Fixtures::long();
		$pdf     = $invoice->render();
		$bytes   = $pdf->render( self::CREATED );
		$text    = Reader::squeezed( Reader::text( $bytes ) );

		$this->assertSame( 40, substr_count( $text, 'Hours:2.5' ) );
		$this->assertSame( 40, substr_count( $text, 'runstoasecondline' ) );
		$this->assertStringContainsString( Reader::squeezed( $invoice->notes ), $text );

		foreach ( Reader::runs( $bytes ) as $runs ) {
			foreach ( $runs as $run ) {
				$this->assertLessThanOrEqual( $pdf->right() + 0.01, $run['x'] + $run['width'], $run['text'] );
				$this->assertGreaterThanOrEqual( 20.0, $run['y'] );
			}
		}
	}

	/**
	 * The comparison's stress case: sixty lines of description too long
	 * for the column, each with a note, in scripts the base fonts lack.
	 */
	public function test_long_descriptions_keep_their_notes(): void {
		$items = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$items[] = new LineItem( "Item {$i} — a fairly long description that will not fit in the column width at all, Ярослав Łódź 東京", '€10.00', '1', '€10.00', 'Licence period 2026' );
		}

		$bytes = ( new Invoice( Fixtures::branding(), 'INV-1', Fixtures::seller(), new Party( 'Ada', array( 'Brighton' ) ), $items ) )->render()->render( self::CREATED );
		$text  = Reader::text( $bytes );

		$this->assertSame( 60, substr_count( $text, 'Licence period 2026' ) );
		$this->assertSame( 60, substr_count( $text, 'Ярослав Łódź' ) );
		$this->assertStringNotContainsString( 'col...', $text );
	}
}
