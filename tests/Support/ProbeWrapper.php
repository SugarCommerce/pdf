<?php
/**
 * A stream wrapper that records being touched.
 *
 * Registered as probe://, it answers like a readable file holding
 * whatever bytes a test gives it, and notes every call. A loader that
 * reads only local files never calls it at all; one that hands any path
 * to PHP's file functions reaches it the same way it would reach ftp://
 * or phar://.
 *
 * @package SugarCommerce\Pdf
 */

declare( strict_types=1 );

namespace SugarCommerce\Pdf\Tests\Support;

/**
 * Class ProbeWrapper
 */
final class ProbeWrapper {

	/**
	 * What every probe:// file holds.
	 */
	public static string $bytes = '';

	/**
	 * Every call, by name.
	 *
	 * @var list<string>
	 */
	public static array $touched = array();

	/**
	 * Set by PHP for the wrapper.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Where the read has got to.
	 */
	private int $position = 0;

	/**
	 * Register the wrapper, empty-handed.
	 *
	 * @param string $bytes What a probe:// file holds.
	 *
	 * @return void
	 */
	public static function register( string $bytes ): void {
		self::$bytes   = $bytes;
		self::$touched = array();

		if ( in_array( 'probe', stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( 'probe' );
		}

		stream_wrapper_register( 'probe', self::class );
	}

	/**
	 * Unregister it.
	 *
	 * @return void
	 */
	public static function unregister(): void {
		if ( in_array( 'probe', stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( 'probe' );
		}
	}

	public function url_stat( string $path, int $flags ): array {
		self::$touched[] = 'url_stat';

		return $this->stat();
	}

	public function stream_open( string $path, string $mode, int $options, ?string &$opened ): bool {
		self::$touched[] = 'stream_open';

		return true;
	}

	public function stream_read( int $count ): string {
		$chunk           = substr( self::$bytes, $this->position, $count );
		$this->position += strlen( $chunk );

		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen( self::$bytes );
	}

	public function stream_stat(): array {
		return $this->stat();
	}

	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}

	/**
	 * A regular file the size of the bytes.
	 *
	 * @return array<string, int>
	 */
	private function stat(): array {
		return array(
			'mode' => 0100644,
			'size' => strlen( self::$bytes ),
		);
	}
}
