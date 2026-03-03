<?php
/**
 * Response helpers for All Sites Cron.
 *
 * @package All_Sites_Cron
 * @since   2.0.0
 */

namespace Soderlind\Multisite\AllSitesCron;

/**
 * Utility class for building REST responses and closing connections.
 */
class Response {

	/**
	 * Create a REST response based on mode (GitHub Actions plain-text or JSON).
	 *
	 * @param bool   $ga_mode    Whether GitHub Actions output mode is enabled.
	 * @param string $message    Human-readable message.
	 * @param int    $status     HTTP status code.
	 * @param array  $extra_data Additional data merged into JSON mode responses.
	 * @return \WP_REST_Response
	 */
	public static function create( bool $ga_mode, string $message, int $status = 200, array $extra_data = [] ): \WP_REST_Response {
		if ( $ga_mode ) {
			$prefix = $status >= 400 ? '::error::' : '::notice::';
			if ( 409 === $status ) {
				$prefix = '::warning::';
			}
			$txt      = "{$prefix}{$message}\n";
			$response = new \WP_REST_Response( $txt, $status );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			return $response;
		}

		$data = array_merge( [ 'message' => $message ], $extra_data );
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Create an error array (not a WP_REST_Response — used internally).
	 *
	 * @param string $error_message Error description.
	 * @return array{success: false, message: string}
	 */
	public static function error_array( string $error_message ): array {
		return [
			'success' => false,
			'message' => $error_message,
		];
	}

	/**
	 * Close the client connection and continue processing in the background.
	 *
	 * Supports FastCGI (Nginx + PHP-FPM, Apache + mod_fcgid) and falls back
	 * to Connection: close for Apache mod_php and similar setups.
	 *
	 * @param \WP_REST_Response $response Response to send before closing.
	 * @return void
	 */
	public static function close_connection_and_continue( \WP_REST_Response $response ): void {
		// FastCGI path (Nginx + PHP-FPM, etc.).
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			status_header( $response->get_status() );
			foreach ( $response->get_headers() as $header => $value ) {
				header( sprintf( '%s: %s', $header, $value ) );
			}
			echo wp_json_encode( $response->get_data() );

			fastcgi_finish_request();
			return;
		}

		// Fallback for Apache mod_php and similar.
		if ( ! headers_sent() ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			ob_start();

			status_header( $response->get_status() );
			foreach ( $response->get_headers() as $header => $value ) {
				header( sprintf( '%s: %s', $header, $value ) );
			}
			header( 'Connection: close' );
			header( 'Content-Encoding: none' );

			echo wp_json_encode( $response->get_data() );

			$size = ob_get_length();
			header( 'Content-Length: ' . $size );

			ob_end_flush();
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
