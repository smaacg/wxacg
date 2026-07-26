<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Rest_Controller {

	private const NAMESPACE = 'wxacg-catalog/v1';

	public function __construct(
		private Wxacg_Catalog_Api_Catalog_Query $query,
		private Wxacg_Catalog_Api_Schema $schema,
		private Wxacg_Catalog_Api_Cache $cache
	) {}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ], 20 );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/items',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'get_items' ],
				'args'                => $this->collection_args(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/items/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'get_item' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool =>
							absint( $value ) > 0,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'search' ],
				'args'                => array_merge(
					$this->collection_args(),
					[
						'q' => [
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static fn( $value ): bool =>
								is_string( $value )
								&& trim( $value ) !== ''
								&& mb_strlen( $value ) <= 100,
						],
					]
				),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lookup',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'lookup' ],
				'args'                => [
					'anilist_ids' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type' => [
						'default'           => 'anime',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool =>
							in_array( $value, [ 'anime', 'manga' ], true ),
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/taxonomies/(?P<taxonomy>[a-z-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'get_terms' ],
				'args'                => [
					'taxonomy' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'page' => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool =>
							absint( $value ) >= 1,
					],
					'per_page' => [
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool =>
							absint( $value ) >= 1
							&& absint( $value ) <= 100,
					],
					'search' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool =>
							is_string( $value )
							&& mb_strlen( $value ) <= 100,
					],
					'hide_empty' => [
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<slug>[a-z0-9-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'get_series' ],
				'args'                => [
					'slug' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
						'validate_callback' => static fn( $value ): bool =>
							is_string( $value )
							&& sanitize_title( $value ) !== '',
					],
					'type' => [
						'default'           => 'anime',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool =>
							in_array(
								$value,
								[ 'anime', 'manga', 'all' ],
								true
							),
					],
					'page' => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool =>
							absint( $value ) >= 1,
					],
					'per_page' => [
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool =>
							absint( $value ) >= 1
							&& absint( $value ) <= 100,
					],
				],
			]
		);
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		return $this->collection_response( $request->get_params() );
	}

	public function search( WP_REST_Request $request ): WP_REST_Response {
		$params           = $request->get_params();
		$params['search'] = $request->get_param( 'q' );

		return $this->collection_response( $params );
	}

	private function collection_response( array $params ): WP_REST_Response {
		$cache_args = $this->normalize_cache_args( $params );
		$cached     = $this->cache->get( 'collection', $cache_args );

		if (
			is_array( $cached )
			&& isset( $cached['body'] )
			&& is_array( $cached['body'] )
		) {
			return $this->response(
				$cached['body'],
				200,
				isset( $cached['headers'] ) && is_array( $cached['headers'] )
					? $cached['headers']
					: []
			);
		}

		$query = $this->query->query( $params );
		$items = [];

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->schema->summary( $post );
			}
		}

		$page     = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page = min(
			50,
			max( 1, absint( $params['per_page'] ?? 20 ) )
		);

		$body = [
			'data' => array_values( $items ),
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			],
		];

		$headers = [
			'X-WP-Total'      => (string) $query->found_posts,
			'X-WP-TotalPages' => (string) $query->max_num_pages,
		];

		$this->cache->set(
			'collection',
			$cache_args,
			[
				'body'    => $body,
				'headers' => $headers,
			]
		);

		return $this->response( $body, 200, $headers );
	}

	public function get_item( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		$cached = $this->cache->get( 'item', [ 'id' => $id ] );

		if ( is_array( $cached ) ) {
			return $this->response( $cached );
		}

		$post = $this->query->find_published( $id );

		if ( ! $post ) {
			return new WP_Error(
				'wxacg_catalog_not_found',
				'找不到已發布的作品。',
				[ 'status' => 404 ]
			);
		}

		$data = $this->schema->detail( $post );

		$this->cache->set( 'item', [ 'id' => $id ], $data );

		return $this->response( $data );
	}

	public function lookup( WP_REST_Request $request ) {
		$ids = $this->query->sanitize_ids(
			$request->get_param( 'anilist_ids' )
		);

		if ( empty( $ids ) ) {
			return new WP_Error(
				'wxacg_catalog_invalid_ids',
				'anilist_ids 至少需要一個有效 ID。',
				[ 'status' => 400 ]
			);
		}

		if ( count( $ids ) > 100 ) {
			return new WP_Error(
				'wxacg_catalog_too_many_ids',
				'一次最多查詢 100 個 AniList ID。',
				[ 'status' => 400 ]
			);
		}

		$type  = sanitize_key( $request->get_param( 'type' ) ?: 'anime' );
		$posts = $this->query->find_many_by_anilist_ids( $ids, $type );
		$map   = [];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$item = $this->schema->lookup( $post );

			if (
				isset( $item['anilist_id'] )
				&& absint( $item['anilist_id'] ) > 0
			) {
				$map[ (string) absint( $item['anilist_id'] ) ] = $item;
			}
		}

		return $this->response(
			[
				'data' => $map,
			]
		);
	}

	public function get_terms( WP_REST_Request $request ) {
		$alias = sanitize_key( $request->get_param( 'taxonomy' ) );

		$taxonomy = Wxacg_Catalog_Api_Catalog_Query::TAXONOMIES[ $alias ]
			?? '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wxacg_catalog_invalid_taxonomy',
				'不支援的 taxonomy。',
				[ 'status' => 400 ]
			);
		}

		$page = max(
			1,
			absint( $request->get_param( 'page' ) ?: 1 )
		);

		$per_page = min(
			100,
			max(
				1,
				absint( $request->get_param( 'per_page' ) ?: 50 )
			)
		);

		$offset = ( $page - 1 ) * $per_page;

		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => rest_sanitize_boolean(
				$request->get_param( 'hide_empty' )
			),
			'number'     => $per_page,
			'offset'     => $offset,
			'orderby'    => 'name',
			'order'      => 'ASC',
		];

		$search = sanitize_text_field(
			(string) $request->get_param( 'search' )
		);

		if ( '' !== $search ) {
			$args['search'] = mb_substr( $search, 0, 100 );
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = $args;

		unset(
			$count_args['number'],
			$count_args['offset'],
			$count_args['orderby'],
			$count_args['order']
		);

		$count_args['fields'] = 'count';

		$total_result = get_terms( $count_args );

		if ( is_wp_error( $total_result ) ) {
			return $total_result;
		}

		$total = (int) $total_result;

		$data = array_map(
			static function ( WP_Term $term ): array {
				$link = get_term_link( $term );

				return [
					'id'          => (int) $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => (int) $term->parent,
					'count'       => (int) $term->count,
					'url'         => is_wp_error( $link ) ? '' : $link,
				];
			},
			$terms
		);

		/*
		 * get_terms() 或其他 filter 可能保留非連續數字索引。
		 * array_values() 可確保 JSON 輸出為陣列 []，而不是物件 {}。
		 */
		$data = array_values( $data );

		$headers = [
			'X-WP-Total'      => (string) $total,
			'X-WP-TotalPages' => (string) ceil(
				$total / $per_page
			),
		];

		return $this->response(
			[
				'data' => $data,
				'pagination' => [
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => (int) ceil(
						$total / $per_page
					),
				],
			],
			200,
			$headers
		);
	}

	public function get_series( WP_REST_Request $request ) {
		$slug = sanitize_title( $request->get_param( 'slug' ) );
		$term = get_term_by( 'slug', $slug, 'anime_series_tax' );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'wxacg_catalog_series_not_found',
				'找不到此作品系列。',
				[ 'status' => 404 ]
			);
		}

		$type = sanitize_key(
			(string) ( $request->get_param( 'type' ) ?: 'anime' )
		);

		$page = max(
			1,
			absint( $request->get_param( 'page' ) ?: 1 )
		);

		$per_page = min(
			100,
			max(
				1,
				absint( $request->get_param( 'per_page' ) ?: 20 )
			)
		);

		$post_type = 'all' === $type
			? [ 'anime', 'manga' ]
			: [ $type ];

		$cache_args = [
			'term_id'  => (int) $term->term_id,
			'type'     => $type,
			'page'     => $page,
			'per_page' => $per_page,
		];

		$cached = $this->cache->get( 'series', $cache_args );

		if (
			is_array( $cached )
			&& isset( $cached['body'] )
			&& is_array( $cached['body'] )
		) {
			return $this->response(
				$cached['body'],
				200,
				isset( $cached['headers'] ) && is_array( $cached['headers'] )
					? $cached['headers']
					: []
			);
		}

		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'tax_query'              => [
					[
						'taxonomy' => 'anime_series_tax',
						'field'    => 'term_id',
						'terms'    => [ (int) $term->term_id ],
					],
				],
				'orderby' => [
					'date' => 'ASC',
					'ID'   => 'ASC',
				],
			]
		);

		$items = [];

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->schema->summary( $post );
			}
		}

		$root_anilist_id = absint(
			get_term_meta(
				$term->term_id,
				'anime_series_root_id',
				true
			)
		);

		$total       = (int) $query->found_posts;
		$total_pages = (int) $query->max_num_pages;

		$body = [
			'series' => [
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'root_anilist_id' => $root_anilist_id > 0
					? $root_anilist_id
					: null,
			],
			'data' => array_values( $items ),
			'pagination' => [
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total_pages,
			],
		];

		$headers = [
			'X-WP-Total'      => (string) $total,
			'X-WP-TotalPages' => (string) $total_pages,
		];

		$this->cache->set(
			'series',
			$cache_args,
			[
				'body'    => $body,
				'headers' => $headers,
			]
		);

		return $this->response( $body, 200, $headers );
	}

	private function collection_args(): array {
		return [
			'type' => [
				'default'           => 'anime',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool =>
					in_array( $value, [ 'anime', 'manga' ], true ),
			],
			'page' => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool =>
					absint( $value ) >= 1,
			],
			'per_page' => [
				'default'           => 20,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool =>
					absint( $value ) >= 1
					&& absint( $value ) <= 50,
			],
			'search' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $value ): bool =>
					is_string( $value )
					&& mb_strlen( $value ) <= 100,
			],
			'genre' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'season' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'format' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'series' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'studio' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'tag' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status' => [
				'sanitize_callback' => 'sanitize_key',
			],
			'year' => [
				'sanitize_callback' => 'absint',
			],
			'include' => [
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby' => [
				'default'           => 'date',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool =>
					in_array(
						$value,
						[
							'date',
							'modified',
							'title',
							'popularity',
							'score',
							'site_score',
							'season_year',
							'start_date',
							'rand',
						],
						true
					),
			],
			'order' => [
				'default' => 'DESC',
				'sanitize_callback' => static fn( $value ): string =>
					strtoupper(
						sanitize_text_field( (string) $value )
					),
				'validate_callback' => static fn( $value ): bool =>
					in_array(
						strtoupper( (string) $value ),
						[ 'ASC', 'DESC' ],
						true
					),
			],
		];
	}

	private function response(
		$data,
		int $status = 200,
		array $headers = []
	): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );

		$encoded = wp_json_encode( $data );

		if ( false === $encoded ) {
			$encoded = serialize( $data );
		}

		/*
		 * 使用 weak ETag，避免 Cloudflare／gzip 在內容語意不變時，
		 * 因傳輸編碼不同而讓條件式請求失效。
		 */
		$etag = 'W/"' . md5( (string) $encoded ) . '"';

		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		$response->header(
			'X-WXACG-Catalog-Version',
			WXACG_CATALOG_API_VERSION
		);

		foreach ( $headers as $name => $value ) {
			$response->header( $name, (string) $value );
		}

		/*
		 * If-None-Match 可包含：
		 *
		 * W/"hash"
		 * "hash"
		 * W/"hash", "another-hash"
		 * *
		 *
		 * GET 的 If-None-Match 採 weak comparison，因此比較時移除 W/。
		 */
		if (
			200 === $status
			&& $this->request_etag_matches( $etag )
		) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		return $response;
	}

	private function request_etag_matches( string $etag ): bool {
		if ( ! isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}

		$header = trim(
			(string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] )
		);

		if ( '' === $header ) {
			return false;
		}

		/*
		 * 限制 Header 長度，避免不必要地處理異常大型輸入。
		 */
		$header = substr( $header, 0, 4096 );

		if ( '*' === $header ) {
			return true;
		}

		$expected = $this->normalize_etag( $etag );

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = $this->normalize_etag(
				trim( $candidate )
			);

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

	private function normalize_cache_args( array $params ): array {
		$allowed = [
			'type',
			'page',
			'per_page',
			'search',
			'q',
			'genre',
			'season',
			'format',
			'series',
			'studio',
			'tag',
			'status',
			'year',
			'include',
			'orderby',
			'order',
		];

		return array_intersect_key(
			$params,
			array_flip( $allowed )
		);
	}
}
