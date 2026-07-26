<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Catalog_Query {

	public const POST_TYPES = [ 'anime', 'manga' ];

	public const TAXONOMIES = [
		'genre'  => 'genre',
		'season' => 'anime_season_tax',
		'format' => 'anime_format_tax',
		'series' => 'anime_series_tax',
		'studio' => 'anime_studio_tax',
		'tag'    => 'post_tag',
	];

	public function query( array $params ): WP_Query {
		$page     = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, absint( $params['per_page'] ?? 20 ) ) );
		$type     = sanitize_key( $params['type'] ?? 'anime' );

		if ( ! in_array( $type, self::POST_TYPES, true ) ) {
			$type = 'anime';
		}

		$args = [
			'post_type'              => $type,
			'post_status'            => 'publish',
			'paged'                  => $page,
			'posts_per_page'         => $per_page,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		];

		$this->apply_ordering( $args, $params );
		$this->apply_taxonomies( $args, $params );
		$this->apply_meta_filters( $args, $params );

		$include = $this->sanitize_ids( $params['include'] ?? [] );

		if ( $include ) {
			$args['post__in'] = array_slice( $include, 0, 100 );
		}

		$search = sanitize_text_field(
			wp_unslash( (string) ( $params['search'] ?? '' ) )
		);

		if ( $search !== '' ) {
			$search_ids = $this->search_ids( $search, $type );

			if ( empty( $search_ids ) ) {
				$args['post__in'] = [ 0 ];
			} elseif ( isset( $args['post__in'] ) ) {
				$args['post__in'] = array_values(
					array_intersect( $args['post__in'], $search_ids )
				);

				if ( empty( $args['post__in'] ) ) {
					$args['post__in'] = [ 0 ];
				}
			} else {
				$args['post__in'] = $search_ids;
			}
		}

		return new WP_Query( $args );
	}

	public function find_published( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );

		if (
			! $post instanceof WP_Post
			|| 'publish' !== $post->post_status
			|| ! in_array( $post->post_type, self::POST_TYPES, true )
		) {
			return null;
		}

		return $post;
	}

	public function find_by_anilist_id(
		int $anilist_id,
		string $type = 'anime'
	): ?WP_Post {
		if ( $anilist_id <= 0 || ! in_array( $type, self::POST_TYPES, true ) ) {
			return null;
		}

		$posts = get_posts(
			[
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'     => 'anime_anilist_id',
						'value'   => $anilist_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
				],
			]
		);

		return $posts[0] ?? null;
	}

	public function find_many_by_anilist_ids(
		array $ids,
		string $type = 'anime'
	): array {
		$ids = array_slice( $this->sanitize_ids( $ids ), 0, 100 );

		if ( empty( $ids ) || ! in_array( $type, self::POST_TYPES, true ) ) {
			return [];
		}

		return get_posts(
			[
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'     => 'anime_anilist_id',
						'value'   => $ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
	}

	public function sanitize_ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $value )
				)
			)
		);
	}

	private function apply_taxonomies( array &$args, array $params ): void {
		$tax_query = [];

		foreach ( self::TAXONOMIES as $param => $taxonomy ) {
			$value = $params[ $param ] ?? '';

			if ( ! is_string( $value ) || trim( $value ) === '' ) {
				continue;
			}

			$slugs = array_values(
				array_filter(
					array_map(
						'sanitize_title',
						explode( ',', $value )
					)
				)
			);

			if ( $slugs ) {
				$tax_query[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array_slice( $slugs, 0, 20 ),
					'operator' => 'IN',
				];
			}
		}

		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}

			$args['tax_query'] = $tax_query;
		}
	}

	private function apply_meta_filters( array &$args, array $params ): void {
		$meta_query = [];

		if ( ! empty( $params['status'] ) ) {
			$meta_query[] = [
				'key'     => 'anime_status',
				'value'   => sanitize_key( $params['status'] ),
				'compare' => '=',
			];
		}

		$year = absint( $params['year'] ?? 0 );

		if ( $year >= 1900 && $year <= 2200 ) {
			$meta_query[] = [
				'key'     => 'anime_season_year',
				'value'   => $year,
				'compare' => '=',
				'type'    => 'NUMERIC',
			];
		}

		if ( $meta_query ) {
			$args['meta_query'] = $meta_query;
		}
	}

	private function apply_ordering( array &$args, array $params ): void {
		$orderby = sanitize_key( $params['orderby'] ?? 'date' );
		$order   = strtoupper( (string) ( $params['order'] ?? 'DESC' ) );

		$args['order'] = in_array( $order, [ 'ASC', 'DESC' ], true )
			? $order
			: 'DESC';

		$meta_order = [
			'popularity'  => 'anime_popularity',
			'score'       => 'anime_score_anilist',
			'site_score'  => 'anime_score_site',
			'season_year' => 'anime_season_year',
			'start_date'  => 'anime_start_date',
		];

		if ( isset( $meta_order[ $orderby ] ) ) {
			$args['meta_key'] = $meta_order[ $orderby ];
			$args['orderby']  = 'meta_value_num';
			return;
		}

		$args['orderby'] = match ( $orderby ) {
			'title'    => 'title',
			'modified' => 'modified',
			'rand'     => 'rand',
			default    => 'date',
		};
	}

	private function search_ids( string $search, string $type ): array {
		global $wpdb;

		$search = trim( mb_substr( $search, 0, 100 ) );

		if ( '' === $search ) {
			return [];
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$meta_keys = [
			'anime_title_chinese',
			'anime_title_native',
			'anime_title_romaji',
			'anime_title_english',
		];

		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$sql = "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm
				ON p.ID = pm.post_id
				AND pm.meta_key IN ({$placeholders})
			WHERE p.post_type = %s
				AND p.post_status = 'publish'
				AND (
					p.post_title LIKE %s
					OR pm.meta_value LIKE %s
				)
			ORDER BY p.post_date DESC
			LIMIT 500
		";

		$values   = array_merge( $meta_keys, [ $type, $like, $like ] );
		$prepared = $wpdb->prepare( $sql, $values );

		return array_map(
			'absint',
			(array) $wpdb->get_col( $prepared )
		);
	}
}
