<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_V2_Router {

	private const NAMESPACE = 'wxacg-catalog/v2';

	private const API_VERSION = '2.0';

	private const DEFAULT_PAGE = 1;

	private const DEFAULT_PER_PAGE = 20;

	private const MAX_PER_PAGE = 50;

	private const MAX_SEARCH_LENGTH = 100;

	/**
	 * Supported collection ordering fields.
	 */
	private const ALLOWED_ORDERBY = [
		'date',
		'modified',
		'title',
		'popularity',
		'score',
		'site_score',
		'season_year',
		'start_date',
	];

	public function __construct(
		private Wxacg_Catalog_Api_V2_Anime_Controller $anime,
		private Wxacg_Catalog_Api_V2_Search_Controller $search,
		private Wxacg_Catalog_Api_Language $language
	) {}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ], 21 );
	}

	/**
	 * Register v2 REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/anime',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ Wxacg_Catalog_Api_Rest_Controller::class, 'check_permission' ],
				'callback'            => [ $this, 'dispatch_anime_collection' ],
				'args'                => $this->collection_args(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/anime/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ Wxacg_Catalog_Api_Rest_Controller::class, 'check_permission' ],
				'callback'            => [ $this, 'dispatch_anime_item' ],
				'args'                => [
					'id' => [
						'description' => 'WordPress/WXACG anime ID.',
					],
					'lang' => $this->language_arg(),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => [ Wxacg_Catalog_Api_Rest_Controller::class, 'check_permission' ],
				'callback'            => [ $this, 'dispatch_search' ],
				'args'                => array_merge(
					$this->collection_args(),
					[
						'q' => [
							'description' => 'Multilingual search term.',
						],
					]
				),
			]
		);
	}

	/**
	 * Validate and dispatch the anime collection request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch_anime_collection( WP_REST_Request $request ) {
		$error = $this->prepare_collection_request( $request );

		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		return $this->anime->collection( $request );
	}

	/**
	 * Validate and dispatch a single anime request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch_anime_item( WP_REST_Request $request ) {
		$language_error = $this->prepare_language( $request );

		if ( $language_error instanceof WP_REST_Response ) {
			return $language_error;
		}

		$id = $this->positive_integer(
			$request->get_param( 'id' )
		);

		if ( null === $id ) {
			return $this->invalid_parameter(
				'id',
				'作品 ID 必須是大於 0 的整數。',
				$request
			);
		}

		$request->set_param( 'id', $id );

		return $this->anime->item( $request );
	}

	/**
	 * Validate and dispatch a search request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch_search( WP_REST_Request $request ) {
		$error = $this->prepare_collection_request( $request );

		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		$query = $request->get_param( 'q' );

		if ( is_array( $query ) || is_object( $query ) ) {
			return $this->invalid_parameter(
				'q',
				'搜尋關鍵字必須是字串。',
				$request
			);
		}

		$query = sanitize_text_field( (string) $query );
		$query = trim( $query );

		if ( '' === $query ) {
			return $this->missing_parameter(
				'q',
				'缺少搜尋關鍵字。',
				$request
			);
		}

		if ( $this->string_length( $query ) > self::MAX_SEARCH_LENGTH ) {
			return $this->invalid_parameter(
				'q',
				sprintf(
					'搜尋關鍵字不得超過 %d 個字元。',
					self::MAX_SEARCH_LENGTH
				),
				$request
			);
		}

		$request->set_param( 'q', $query );

		return $this->search->search( $request );
	}

	/**
	 * Prepare and validate common collection parameters.
	 *
	 * Returns null when validation succeeds.
	 */
	private function prepare_collection_request(
		WP_REST_Request $request
	): ?WP_REST_Response {
		$language_error = $this->prepare_language( $request );

		if ( $language_error instanceof WP_REST_Response ) {
			return $language_error;
		}

		$page = $this->positive_integer(
			$request->get_param( 'page' )
		);

		if ( null === $page ) {
			return $this->invalid_parameter(
				'page',
				'page 必須是大於 0 的整數。',
				$request
			);
		}

		$per_page = $this->positive_integer(
			$request->get_param( 'per_page' )
		);

		if ( null === $per_page || $per_page > self::MAX_PER_PAGE ) {
			return $this->invalid_parameter(
				'per_page',
				sprintf(
					'per_page 必須是 1 至 %d 之間的整數。',
					self::MAX_PER_PAGE
				),
				$request
			);
		}

		$request->set_param( 'page', $page );
		$request->set_param( 'per_page', $per_page );

		$orderby = sanitize_key(
			(string) $request->get_param( 'orderby' )
		);

		if ( ! in_array( $orderby, self::ALLOWED_ORDERBY, true ) ) {
			return $this->invalid_parameter(
				'orderby',
				'不支援指定的排序欄位。',
				$request,
				[
					'allowed' => self::ALLOWED_ORDERBY,
				]
			);
		}

		$order = strtoupper(
			sanitize_text_field(
				(string) $request->get_param( 'order' )
			)
		);

		if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
			return $this->invalid_parameter(
				'order',
				'order 只支援 ASC 或 DESC。',
				$request,
				[
					'allowed' => [ 'ASC', 'DESC' ],
				]
			);
		}

		$request->set_param( 'orderby', $orderby );
		$request->set_param( 'order', $order );

		$text_parameters = [
			'genre',
			'season',
			'format',
			'series',
			'studio',
			'tag',
		];

		foreach ( $text_parameters as $parameter ) {
			$value = $request->get_param( $parameter );

			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				return $this->invalid_parameter(
					$parameter,
					sprintf(
						'%s 必須是字串。',
						$parameter
					),
					$request
				);
			}

			$request->set_param(
				$parameter,
				sanitize_text_field( (string) $value )
			);
		}

		$status = $request->get_param( 'status' );

		if ( null !== $status && '' !== $status ) {
			if ( is_array( $status ) || is_object( $status ) ) {
				return $this->invalid_parameter(
					'status',
					'status 必須是字串。',
					$request
				);
			}

			$request->set_param(
				'status',
				sanitize_key( (string) $status )
			);
		}

		$year = $request->get_param( 'year' );

		if ( null !== $year && '' !== $year ) {
			$year = $this->positive_integer( $year );

			if ( null === $year ) {
				return $this->invalid_parameter(
					'year',
					'year 必須是大於 0 的整數。',
					$request
				);
			}

			$request->set_param( 'year', $year );
		}

		return null;
	}

	/**
	 * Validate and normalize the requested language.
	 */
	private function prepare_language(
		WP_REST_Request $request
	): ?WP_REST_Response {
		$value = $request->get_param( 'lang' );

		if ( null === $value || '' === $value ) {
			$value = Wxacg_Catalog_Api_Language::DEFAULT_LANGUAGE;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			return $this->invalid_parameter(
				'lang',
				'lang 必須是字串。',
				$request
			);
		}

		$value = sanitize_text_field( (string) $value );
		$value = trim( $value );

		if ( ! $this->language->is_supported( $value ) ) {
			return $this->invalid_parameter(
				'lang',
				'不支援指定的語言。',
				$request,
				[
					'allowed' => [
						'zh-TW',
						'zh-HK',
						'zh-CN',
						'ja-JP',
						'ja-Latn',
						'en',
					],
				]
			);
		}

		$request->set_param( 'lang', $value );

		return null;
	}

	/**
	 * Common collection route argument declarations.
	 *
	 * Validation is intentionally performed inside the dispatch methods.
	 * WordPress validate_callback errors use the native WP error shape and
	 * would bypass the v2 response envelope.
	 */
	private function collection_args(): array {
		return [
			'lang' => $this->language_arg(),
			'page' => [
				'default'     => self::DEFAULT_PAGE,
				'description' => 'Collection page number.',
			],
			'per_page' => [
				'default'     => self::DEFAULT_PER_PAGE,
				'description' => 'Number of records per page; maximum 50.',
			],
			'genre' => [
				'description' => 'Genre term slug or name.',
			],
			'season' => [
				'description' => 'Season term slug or name.',
			],
			'format' => [
				'description' => 'Anime format term slug or name.',
			],
			'series' => [
				'description' => 'Series term slug or name.',
			],
			'studio' => [
				'description' => 'Studio term slug or name.',
			],
			'tag' => [
				'description' => 'Tag term slug or name.',
			],
			'status' => [
				'description' => 'Anime status.',
			],
			'year' => [
				'description' => 'Season or release year.',
			],
			'orderby' => [
				'default'     => 'date',
				'description' => 'Collection ordering field.',
			],
			'order' => [
				'default'     => 'DESC',
				'description' => 'Collection ordering direction.',
			],
		];
	}

	/**
	 * Language route argument declaration.
	 */
	private function language_arg(): array {
		return [
			'default'     => Wxacg_Catalog_Api_Language::DEFAULT_LANGUAGE,
			'description' => 'Response language.',
		];
	}

	/**
	 * Convert a value to a positive integer.
	 */
	private function positive_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( ! preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Return a multibyte-safe string length.
	 */
	private function string_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		return strlen( $value );
	}

	/**
	 * Return a unified missing-parameter error response.
	 */
	private function missing_parameter(
		string $parameter,
		string $message,
		WP_REST_Request $request
	): WP_REST_Response {
		return $this->error_response(
			400,
			$message,
			'missing_parameter',
			[
				'parameter' => $parameter,
			],
			$request
		);
	}

	/**
	 * Return a unified invalid-parameter error response.
	 */
	private function invalid_parameter(
		string $parameter,
		string $message,
		WP_REST_Request $request,
		array $extra_details = []
	): WP_REST_Response {
		return $this->error_response(
			400,
			$message,
			'invalid_parameter',
			array_merge(
				[
					'parameter' => $parameter,
				],
				$extra_details
			),
			$request
		);
	}

	/**
	 * Build the standard v2 error envelope.
	 */
	private function error_response(
		int $status,
		string $message,
		string $type,
		array $details,
		WP_REST_Request $request
	): WP_REST_Response {
		$language = $request->get_param( 'lang' );

		if (
			! is_string( $language )
			|| ! $this->language->is_supported( $language )
		) {
			$language = Wxacg_Catalog_Api_Language::DEFAULT_LANGUAGE;
		}

		$response = new WP_REST_Response(
			[
				'success' => false,
				'code'    => $status,
				'message' => $message,
				'error'   => [
					'type'    => $type,
					'details' => $details,
				],
				'meta'    => [
					'api_version' => self::API_VERSION,
					'language'    => $language,
					'generated_at' => gmdate( 'c' ),
				],
			],
			$status
		);

		$response->header(
			'X-WXACG-API-Version',
			self::API_VERSION
		);

		if ( defined( 'WXACG_CATALOG_API_VERSION' ) ) {
			$response->header(
				'X-WXACG-Catalog-Version',
				WXACG_CATALOG_API_VERSION
			);
		}

		$response->header(
			'Cache-Control',
			'no-store, no-cache, must-revalidate'
		);

		return $response;
	}
}
