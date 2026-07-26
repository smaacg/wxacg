<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Compatibility {

	public function __construct(
		private Wxacg_Catalog_Api_Catalog_Query $query,
		private Wxacg_Catalog_Api_Schema $schema
	) {}

	public function register_hooks(): void {
		/*
		 * priority 30：讓 wxacg-api 先註冊原路由。
		 * 只有原路由不存在時才提供 fallback，避免重複覆蓋。
		 */
		add_action( 'rest_api_init', [ $this, 'register_routes' ], 30 );
	}

	public function register_routes(): void {
		$routes = rest_get_server()->get_routes();

		if ( isset( $routes['/weixiaoacg/v1/anime-url'] ) ) {
			return;
		}

		register_rest_route(
			'weixiaoacg/v1',
			'/anime-url',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'anime_url' ],
				'args'                => [
					'ids' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function anime_url( WP_REST_Request $request ) {
		$ids = $this->query->sanitize_ids(
			$request->get_param( 'ids' )
		);

		if ( empty( $ids ) ) {
			return new WP_Error(
				'no_ids',
				'ids 參數必填',
				[ 'status' => 400 ]
			);
		}

		if ( count( $ids ) > 100 ) {
			return new WP_Error(
				'too_many_ids',
				'一次最多查詢 100 個 AniList ID',
				[ 'status' => 400 ]
			);
		}

		$posts = $this->query->find_many_by_anilist_ids( $ids, 'anime' );
		$map   = [];

		foreach ( $posts as $post ) {
			$item = $this->schema->lookup( $post );

			if ( $item['anilist_id'] > 0 ) {
				$map[ (string) $item['anilist_id'] ] = [
					'url'  => $item['url'],
					'slug' => $item['slug'],
				];
			}
		}

		return rest_ensure_response( $map );
	}
}
