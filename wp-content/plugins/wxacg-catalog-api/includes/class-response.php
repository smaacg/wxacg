<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Response {

	private const API_VERSION = '2.0';

	public function success(
		$data,
		string $language,
		?array $pagination = null,
		array $headers = []
	): WP_REST_Response {
		$body = [
			'success' => true,
			'code'    => 200,
			'message' => 'OK',
			'data'    => $data,
		];

		if ( null !== $pagination ) {
			$body['pagination'] = $pagination;
		}

		$body['meta'] = [
			'api_version'  => self::API_VERSION,
			'language'     => $language,
			'cached'       => false,
			'generated_at' => gmdate( 'c' ),
		];

		return $this->make_response(
			$body,
			200,
			[
				'data'       => $data,
				'pagination' => $pagination,
				'language'   => $language,
			],
			$headers
		);
	}

	public function error(
		string $type,
		string $message,
		int $status,
		string $language
	): WP_REST_Response {
		$body = [
			'success' => false,
			'code'    => $status,
			'message' => $message,
			'error'   => [
				'type'    => $type,
				'details' => [],
			],
			'meta' => [
				'api_version'  => self::API_VERSION,
				'language'     => $language,
				'generated_at' => gmdate( 'c' ),
			],
		];

		return $this->make_response(
			$body,
			$status,
			[
				'type'     => $type,
				'status'   => $status,
				'language' => $language,
			]
		);
	}

	private function make_response(
		array $body,
		int $status,
		array $stable_data,
		array $headers = []
	): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );

		$encoded = wp_json_encode( $stable_data );

		if ( false === $encoded ) {
			$encoded = serialize( $stable_data );
		}

		$etag = 'W/"' . md5( (string) $encoded ) . '"';

		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		$response->header( 'X-WXACG-API-Version', self::API_VERSION );
		$response->header(
			'X-WXACG-Catalog-Version',
			WXACG_CATALOG_API_VERSION
		);

		foreach ( $headers as $name => $value ) {
			$response->header( $name, (string) $value );
		}

		if ( 200 === $status && $this->etag_matches( $etag ) ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		return $response;
	}

	private function etag_matches( string $etag ): bool {
		if ( ! isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}

		$header = substr(
			trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ),
			0,
			4096
		);

		if ( '*' === $header ) {
			return true;
		}

		$expected = $this->normalize_etag( $etag );

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = $this->normalize_etag( $candidate );

			if (
				$candidate !== ''
				&& strlen( $candidate ) === strlen( $expected )
				&& hash_equals( $expected, $candidate )
			) {
				return true;
			}
		}

		return false;
	}

	private function normalize_etag( string $etag ): string {
		$etag = trim( $etag );

		if ( 0 === stripos( $etag, 'W/' ) ) {
			$etag = substr( $etag, 2 );
		}

		return trim( $etag );
	}
}