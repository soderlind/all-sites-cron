<?php
/**
 * Minimal WordPress class stubs for Brain Monkey tests.
 *
 * These provide just enough of WP_Error, WP_REST_Request, WP_REST_Response,
 * and wpdb to satisfy the plugin's type-hints and method calls without
 * requiring a full WordPress installation.
 *
 * @package All_Sites_Cron\Tests
 */

// phpcs:disable WordPress.NamingConventions.ValidFunctionName

// ---------------------------------------------------------------------------
// WP_Error
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {

		/** @var array<string, string[]> */
		protected array $errors = [];

		/** @var array<string, mixed> */
		protected array $error_data = [];

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/** @return string[] */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_code(): string {
			$codes = $this->get_error_codes();
			return $codes ? reset( $codes ) : '';
		}

		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			$messages = $this->errors[ $code ] ?? [];
			return $messages ? reset( $messages ) : '';
		}

		/**
		 * @return mixed
		 */
		public function get_error_data( string $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		/**
		 * @param mixed $data Error data.
		 */
		public function add( string $code, string $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// WP_REST_Request
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub.
	 */
	class WP_REST_Request {

		protected string $method;
		protected string $route;

		/** @var array<string, mixed> */
		protected array $params = [];

		/** @var array<string, string> */
		protected array $headers = [];

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		/**
		 * @return mixed|null
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param mixed $value Parameter value.
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		/** @return array<string, mixed> */
		public function get_params(): array {
			return $this->params;
		}
	}
}

// ---------------------------------------------------------------------------
// WP_REST_Response
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub.
	 */
	class WP_REST_Response {

		/** @var mixed */
		protected $data;

		protected int $status;

		/** @var array<string, string> */
		protected array $headers = [];

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, string $value, bool $replace = true ): void {
			$this->headers[ $key ] = $value;
		}

		/** @return array<string, string> */
		public function get_headers(): array {
			return $this->headers;
		}

		/**
		 * @param mixed $data Response data.
		 */
		public function set_data( $data ): void {
			$this->data = $data;
		}

		public function set_status( int $code ): void {
			$this->status = $code;
		}
	}
}

// ---------------------------------------------------------------------------
// wpdb
// ---------------------------------------------------------------------------

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal wpdb stub — just enough for Lock's DB path.
	 *
	 * Individual tests that exercise the database path should mock
	 * the relevant methods via Mockery.
	 */
	class wpdb {
		public string $sitemeta = 'wp_sitemeta';
		public string $options = 'wp_options';
		public string $prefix = 'wp_';
		public string $last_error = '';

		/**
		 * @return int|bool
		 */
		public function query( string $sql ) {
			return 0;
		}

		public function prepare( string $sql, ...$args ): string {
			return $sql;
		}

		/**
		 * @return string|null
		 */
		public function get_var( ?string $sql = null, int $x = 0, int $y = 0 ) {
			return null;
		}

		/** @return string[] */
		public function get_col( ?string $sql = null, int $x = 0 ): array {
			return [];
		}

		/**
		 * @param array<string, mixed>       $where        Conditions.
		 * @param string[]|null              $where_format Formats.
		 * @return int|false
		 */
		public function delete( string $table, array $where, ?array $where_format = null ) {
			return 0;
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}
	}
}

// ---------------------------------------------------------------------------
// WordPress helper functions
// ---------------------------------------------------------------------------

if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( '__return_zero' ) ) {
	function __return_zero(): int {
		return 0;
	}
}

if ( ! function_exists( '__return_empty_array' ) ) {
	function __return_empty_array(): array {
		return [];
	}
}

if ( ! function_exists( '__return_empty_string' ) ) {
	function __return_empty_string(): string {
		return '';
	}
}

if ( ! function_exists( '__return_null' ) ) {
	function __return_null() {
		return null;
	}
}
