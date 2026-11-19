<?php
/**
 * The external validators the conformance tests run, fetched by
 * `bin/validators` into `.validator/`.
 *
 * Missing on a workbench, a test skips and says how to fetch them; missing
 * in CI, it fails — the workflow fetches them, and a suite that quietly
 * skips its strictest checks is not passing.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Class Validators
 */
final class Validators {

	/**
	 * Where `bin/validators` puts everything.
	 *
	 * @return string
	 */
	public static function folder(): string {
		return dirname( __DIR__, 2 ) . '/.validator';
	}

	/**
	 * The veraPDF command.
	 *
	 * @return string
	 */
	public static function verapdf(): string {
		$path = self::folder() . '/verapdf/verapdf';

		return is_executable( $path ) ? $path : self::missing( 'veraPDF', 'pdf' );
	}

	/**
	 * The CII D16B schema.
	 *
	 * @return string
	 */
	public static function xsd(): string {
		$path = self::folder() . '/xsd/CrossIndustryInvoice_100pD16B.xsd';

		return is_file( $path ) ? $path : self::missing( 'The CII D16B schema', 'xml' );
	}

	/**
	 * The command that runs `bin/validate-facturx.py`.
	 *
	 * @return list<string> Interpreter and script.
	 */
	public static function facturx(): array {
		$python = self::folder() . '/venv/bin/python';

		if ( ! is_executable( $python ) || ! is_file( self::folder() . '/schematron/FACTUR-X_EN16931.sch' ) ) {
			self::missing( 'The Factur-X Schematron', 'xml' );
		}

		return array( $python, dirname( __DIR__, 2 ) . '/bin/validate-facturx.py' );
	}

	/**
	 * A fresh folder inside the package for files a validator reads.
	 *
	 * @return string
	 */
	public static function scratch(): string {
		$folder = dirname( __DIR__ ) . '/.tmp/' . bin2hex( random_bytes( 8 ) );

		mkdir( $folder, 0777, true );

		return $folder;
	}

	/**
	 * Remove a folder from scratch() and what is in it.
	 *
	 * @param string $folder The folder.
	 *
	 * @return void
	 */
	public static function remove( string $folder ): void {
		foreach ( glob( $folder . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}

		rmdir( $folder );
	}

	/**
	 * Run a command, its arguments escaped.
	 *
	 * @param list<string> $command The command and its arguments.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	public static function run( array $command ): array {
		$output = array();
		$code   = 0;

		exec( implode( ' ', array_map( 'escapeshellarg', $command ) ) . ' 2>&1', $output, $code );

		return array( $code, implode( "\n", $output ) );
	}

	/**
	 * Skip, or fail in CI.
	 *
	 * @param string $what What is missing.
	 * @param string $part The `bin/validators` part that fetches it.
	 *
	 * @return never
	 */
	private static function missing( string $what, string $part ): never {
		if ( false !== getenv( 'CI' ) && '' !== getenv( 'CI' ) ) {
			Assert::fail( $what . ' is not installed; CI runs bin/validators before the tests.' );
		}

		Assert::markTestSkipped( $what . ' is not installed (bin/validators ' . $part . ').' );
	}
}
