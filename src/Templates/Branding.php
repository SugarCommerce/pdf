<?php
/**
 * Shared visual identity for the built-in templates.
 *
 * @package   SugarCommerce\Pdf
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Templates;

use SugarCommerce\Pdf\Color;
use SugarCommerce\Pdf\FontFamily;

/**
 * Class Branding
 *
 * Everything about how a document looks, separated from what it says, so
 * one definition styles every invoice, statement and receipt you issue.
 *
 * @since 1.0.0
 */
final readonly class Branding {

	/**
	 * Define a visual identity.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $name      Trading name, printed as the wordmark.
	 * @param string             $tagline   Optional line under the name.
	 * @param string             $accent    Brand colour as hex. Black by default:
	 *                                      a monochrome invoice is harder to date,
	 *                                      prints correctly on any device, stays
	 *                                      legible when photocopied or faxed to an
	 *                                      accounts department, and never clashes
	 *                                      with whatever the recipient's own
	 *                                      paperwork looks like.
	 * @param string|null        $logo_path SVG `d` attribute for a vector mark.
	 * @param float              $logo_box  viewBox height of that path.
	 * @param array<int, string> $address   Your address, one line per element.
	 * @param array<int, string> $footnotes Small print for the page foot.
	 * @param FontFamily|null    $typeface  The family every document is set in
	 *                                      (since 1.3.0): your brand's face, or
	 *                                      {@see FontFamily::noto_sans()}. Null
	 *                                      sets them in Helvetica, the smallest
	 *                                      file, with Noto Sans drawing only the
	 *                                      words Helvetica cannot.
	 */
	public function __construct(
		public string $name,
		public string $tagline = '',
		public string $accent = '#111111',
		public ?string $logo_path = null,
		public float $logo_box = 100.0,
		public array $address = array(),
		public array $footnotes = array(),
		public ?FontFamily $typeface = null,
	) {}

	/**
	 * The seller as a document prints them.
	 *
	 * The party given, with this branding's address when it has none of
	 * its own and this branding's name when it has no name — so a seller
	 * who set their address once, here, has it on every document.
	 *
	 * @since 1.2.0
	 *
	 * @param Party|null $from The issuing party, if the document has one.
	 *
	 * @return Party
	 */
	public function seller( ?Party $from = null ): Party {
		$from ??= new Party( '' );
		$filled = array_filter( $from->address, static fn( mixed $line ): bool => is_scalar( $line ) && '' !== trim( (string) $line ) );

		if ( array() !== $filled && '' !== trim( $from->name ) ) {
			return $from;
		}

		return new Party(
			'' !== trim( $from->name ) ? $from->name : $this->name,
			array() !== $filled ? $from->address : $this->address,
			$from->email,
			$from->tax_id,
			$from->registration
		);
	}

	/**
	 * The lines a compact masthead prints under the wordmark: the legal
	 * name when it is not the wordmark, then the address, email, VAT and
	 * registration numbers.
	 *
	 * @since 1.2.0
	 *
	 * @param Party      $seller     The seller, from {@see seller()}.
	 * @param Translator $translator Wording source.
	 *
	 * @return list<string>
	 */
	public function seller_lines( Party $seller, Translator $translator ): array {
		$lines = $seller->translated_lines( $translator );

		if ( '' !== trim( $seller->name ) && mb_strtolower( trim( $seller->name ) ) !== mb_strtolower( trim( $this->name ) ) ) {
			array_unshift( $lines, $seller->name );
		}

		return $lines;
	}

	/**
	 * The accent colour.
	 *
	 * @since 1.0.0
	 *
	 * @return Color
	 */
	public function accent_color(): Color {
		return Color::hex( $this->accent );
	}

	/**
	 * Secondary text colour.
	 *
	 * @since 1.0.0
	 *
	 * @return Color
	 */
	public function muted(): Color {
		return Color::grey( 115 );
	}

	/**
	 * Primary text colour.
	 *
	 * Not pure black — slightly lifted reads better in print and on
	 * screen, and is what most well-set documents actually use.
	 *
	 * @since 1.0.0
	 *
	 * @return Color
	 */
	public function ink(): Color {
		return Color::grey( 32 );
	}

	/**
	 * Faint fill for banded rows and panels.
	 *
	 * @since 1.0.0
	 *
	 * @return Color
	 */
	public function wash(): Color {
		return Color::grey( 250 );
	}

	/**
	 * Whether this branding is effectively monochrome.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_monochrome(): bool {
		$accent = $this->accent_color();

		return $accent->red === $accent->green && $accent->green === $accent->blue;
	}

	/**
	 * Hairline colour for rules and borders.
	 *
	 * @since 1.0.0
	 *
	 * @return Color
	 */
	public function hairline(): Color {
		return Color::grey( 214 );
	}
}
