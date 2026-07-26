<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Schema {

	public function summary( WP_Post $post ): array {
		$id = (int) $post->ID;

		return [
			'id'        => $id,
			'type'      => $post->post_type,
			'slug'      => $post->post_name,
			'url'       => get_permalink( $id ),
			'titles'    => $this->titles( $id, $post ),
			'image'     => $this->image( $id, 'cover_image' ),
			'format'    => $this->text( $id, 'format' ),
			'status'    => $this->text( $id, 'status' ),
			'season'    => [
				'name' => $this->text( $id, 'season' ),
				'year' => $this->integer( $id, 'season_year' ),
			],
			'counts'    => [
				'episodes' => $this->integer( $id, 'episodes' ),
				'volumes'  => $this->integer(
					$id,
					'volumes',
					[ 'manga_volumes' ]
				),
				'chapters' => $this->integer(
					$id,
					'chapters',
					[ 'manga_chapters' ]
				),
			],
			'scores'    => $this->scores( $id ),
			'genres'    => $this->terms( $id, 'genre' ),
			'series'    => $this->terms( $id, 'anime_series_tax' ),
			'updated_at' => get_post_modified_time( DATE_ATOM, true, $post ),
		];
	}

	public function detail( WP_Post $post ): array {
		$id   = (int) $post->ID;
		$data = $this->summary( $post );

		$data['external_ids'] = [
			'anilist'     => $this->integer( $id, 'anilist_id' ),
			'mal'         => $this->integer( $id, 'mal_id' ),
			'bangumi'     => $this->integer(
				$id,
				'bangumi_id',
				[ 'bangumi_id' ]
			),
			'animethemes' => $this->text( $id, 'animethemes_id' ),
		];

		$data['source'] = $this->text( $id, 'source' );

		$data['dates'] = [
			'start' => $this->date( $this->text( $id, 'start_date' ) ),
			'end'   => $this->date( $this->text( $id, 'end_date' ) ),
		];

		$data['duration'] = $this->integer( $id, 'duration' );

		$data['synopsis'] = $this->text(
			$id,
			'synopsis_chinese',
			[ 'anime_synopsis' ]
		);

		if ( '' === $data['synopsis'] ) {
			$data['synopsis'] = wp_strip_all_tags(
				apply_filters( 'the_content', $post->post_content )
			);
		}

		$data['images'] = [
			'cover'  => $this->image( $id, 'cover_image' ),
			'banner' => esc_url_raw( $this->text( $id, 'banner_image' ) ),
		];

		$data['trailer_url'] = esc_url_raw(
			$this->text( $id, 'trailer_url' )
		);

		$data['popularity'] = $this->integer( $id, 'popularity' );

		$data['taxonomies'] = [
			'genres'  => $this->terms( $id, 'genre' ),
			'formats' => $this->terms( $id, 'anime_format_tax' ),
			'seasons' => $this->terms( $id, 'anime_season_tax' ),
			'series'  => $this->terms( $id, 'anime_series_tax' ),
			'studios' => $this->terms( $id, 'anime_studio_tax' ),
			'tags'    => $this->terms( $id, 'post_tag' ),
		];

		$data['production'] = [
			'studios' => $this->text( $id, 'studios' ),
			'author'  => $this->first_meta(
				$id,
				[ 'manga_author' ]
			),
			'artist'  => $this->first_meta(
				$id,
				[ 'manga_artist' ]
			),
		];

		$data['streaming'] = $this->json( $id, 'streaming' );
		$data['themes']    = $this->json( $id, 'themes' );
		$data['staff']     = $this->json( $id, 'staff_json' );
		$data['cast']      = $this->json( $id, 'cast_json' );
		$data['episodes']  = $this->json( $id, 'episodes_json' );

		$data['external_links'] = $this->external_links( $id, $post->post_type );
		$data['related']        = $this->related( $id );

		$data['published_at'] = get_post_time( DATE_ATOM, true, $post );

		/**
		 * 允許站內擴充單篇作品 schema。
		 */
		return apply_filters(
			'wxacg_catalog_api_item_schema',
			$data,
			$post
		);
	}

	public function lookup( WP_Post $post ): array {
		$id = (int) $post->ID;

		return [
			'id'         => $id,
			'anilist_id' => $this->integer( $id, 'anilist_id' ),
			'type'       => $post->post_type,
			'slug'       => $post->post_name,
			'url'        => get_permalink( $id ),
		];
	}

	private function titles( int $id, WP_Post $post ): array {
		return [
			'display' => $this->text( $id, 'title_chinese' )
				?: get_the_title( $post ),
			'chinese' => $this->text( $id, 'title_chinese' ),
			'native'  => $this->text( $id, 'title_native' ),
			'romaji'  => $this->text( $id, 'title_romaji' ),
			'english' => $this->text( $id, 'title_english' ),
		];
	}

	private function scores( int $id ): array {
		return [
			'anilist' => $this->score_to_ten(
				$this->number( $id, 'score_anilist' )
			),
			'mal'     => $this->score_to_ten(
				$this->number( $id, 'score_mal' )
			),
			'bangumi' => $this->score_to_ten(
				$this->number( $id, 'score_bangumi' )
			),
			'site'    => $this->number(
				$id,
				'score_site',
				[ 'smacg_site_score' ]
			),
			'votes'   => $this->integer(
				$id,
				'score_site_count',
				[ 'smacg_site_score_count' ]
			),
		];
	}

	private function score_to_ten( float $score ): float {
		if ( $score > 10 ) {
			$score /= 10;
		}

		return $score > 0 ? round( $score, 1 ) : 0.0;
	}

	private function external_links( int $id, string $post_type ): array {
		$links = [
			'official'  => esc_url_raw( $this->text( $id, 'official_site' ) ),
			'wikipedia' => esc_url_raw( $this->text( $id, 'wikipedia_url' ) ),
			'twitter'   => esc_url_raw( $this->text( $id, 'twitter_url' ) ),
			'tiktok'    => esc_url_raw( $this->text( $id, 'tiktok_url' ) ),
		];

		if ( 'manga' === $post_type ) {
			$links['manga'] = $this->json(
				$id,
				'external_links',
				[ 'manga_external_links' ]
			);
		}

		return $links;
	}

	private function related( int $post_id ): array {
		$relations = $this->json( $post_id, 'relations_json' );

		if ( empty( $relations ) ) {
			return [];
		}

		$ids = [];

		foreach ( $relations as $relation ) {
			if ( ! is_array( $relation ) ) {
				continue;
			}

			$id = absint(
				$relation['anilist_id']
				?? $relation['id']
				?? 0
			);

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 50 );

		if ( empty( $ids ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => [ 'anime', 'manga' ],
				'post_status'    => 'publish',
				'posts_per_page' => 50,
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

		$map = [];

		foreach ( $posts as $post ) {
			$anilist_id = $this->integer( $post->ID, 'anilist_id' );

			if ( $anilist_id > 0 ) {
				$map[ $anilist_id ] = $post;
			}
		}

		$output = [];

		foreach ( $relations as $relation ) {
			if ( ! is_array( $relation ) ) {
				continue;
			}

			$anilist_id = absint(
				$relation['anilist_id']
					?? $relation['id']
					?? 0
			);

			if ( ! isset( $map[ $anilist_id ] ) ) {
				continue;
			}

			$post = $map[ $anilist_id ];

			$output[] = [
				'relation_type' => sanitize_key(
					$relation['relation_type']
						?? $relation['type']
						?? ''
				),
				'item' => $this->summary( $post ),
			];
		}

		return $output;
	}

	private function terms( int $post_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return [];
		}

		return array_values(
			array_map(
				static fn( WP_Term $term ): array => [
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'parent' => (int) $term->parent,
				],
				$terms
			)
		);
	}

	private function image( int $id, string $key ): string {
		$image = esc_url_raw( $this->text( $id, $key ) );

		if ( $image !== '' ) {
			return $image;
		}

		return (string) get_the_post_thumbnail_url( $id, 'large' );
	}

	private function json(
		int $id,
		string $logical_key,
		array $extra_keys = []
	): array {
		$raw = $this->first_meta(
			$id,
			array_merge(
				[
					'_smacg_' . $logical_key,
					'anime_' . $logical_key,
				],
				$extra_keys
			)
		);

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return [];
		}

		$value = json_decode( $raw, true );

		return is_array( $value ) ? $value : [];
	}

	private function date( string $date ): string {
		$date = trim( $date );

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $matches ) ) {
			return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		return $date;
	}

	private function text(
		int $id,
		string $logical_key,
		array $extra_keys = []
	): string {
		$value = $this->first_meta(
			$id,
			array_merge(
				[
					'_smacg_' . $logical_key,
					'anime_' . $logical_key,
				],
				$extra_keys
			)
		);

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function integer(
		int $id,
		string $logical_key,
		array $extra_keys = []
	): int {
		return (int) $this->text( $id, $logical_key, $extra_keys );
	}

	private function number(
		int $id,
		string $logical_key,
		array $extra_keys = []
	): float {
		$value = $this->text( $id, $logical_key, $extra_keys );

		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	private function first_meta( int $post_id, array $keys ) {
		foreach ( array_unique( $keys ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( $value !== '' && $value !== null && $value !== false ) {
				return $value;
			}
		}

		return '';
	}
}
