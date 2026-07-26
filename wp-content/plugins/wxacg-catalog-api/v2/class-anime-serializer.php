<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_V2_Anime_Serializer {

	public function __construct(
		private Wxacg_Catalog_Api_Language $language
	) {}

	/**
	 * Serialize an anime item for list and search responses.
	 *
	 * @param array  $item     Schema item.
	 * @param string $language Requested language.
	 *
	 * @return array
	 */
	public function summary( array $item, string $language ): array {
		$id           = absint( $item['id'] ?? 0 );
		$external_ids = $this->resolve_external_ids( $item, $id );

		return [
			'id'   => $id,
			'type' => 'anime',
			'ids'  => [
				'wxacg'   => $id,
				'anilist' => $external_ids['anilist'],
				'mal'     => $external_ids['mal'],
				'bangumi' => $external_ids['bangumi'],
				'tmdb'    => $external_ids['tmdb'],
			],
			'slug'       => (string) ( $item['slug'] ?? '' ),
			'url'        => (string) ( $item['url'] ?? '' ),
			'title'      => $this->language->title(
				is_array( $item['titles'] ?? null )
					? $item['titles']
					: [],
				$language
			),
			'image'      => $item['image'] ?? null,
			'format'     => $item['format'] ?? null,
			'status'     => $item['status'] ?? null,
			'season'     => $item['season'] ?? null,
			'counts'     => is_array( $item['counts'] ?? null )
				? $item['counts']
				: [],
			'scores'     => is_array( $item['scores'] ?? null )
				? $item['scores']
				: [],
			'genres'     => is_array( $item['genres'] ?? null )
				? array_values( $item['genres'] )
				: [],
			'series'     => is_array( $item['series'] ?? null )
				? array_values( $item['series'] )
				: [],
			'updated_at' => $item['updated_at'] ?? null,
		];
	}

	/**
	 * Serialize a single anime detail response.
	 *
	 * @param array  $item     Schema item.
	 * @param string $language Requested language.
	 *
	 * @return array
	 */
	public function detail( array $item, string $language ): array {
		$data = $this->summary( $item, $language );

		$fields = [
			'source',
			'dates',
			'duration',
			'synopsis',
			'images',
			'trailer_url',
			'popularity',
			'taxonomies',
			'production',
			'streaming',
			'themes',
			'relations',
			'characters',
			'staff',
			'episodes',
		];

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $item ) ) {
				$data[ $field ] = $item[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Resolve external IDs from the schema item and WordPress post meta.
	 *
	 * Summary schema records may omit external_ids, while detail schema
	 * records include them. This method keeps list, search and detail ID
	 * mappings consistent.
	 *
	 * @param array $item    Schema item.
	 * @param int   $post_id WordPress post ID.
	 *
	 * @return array{
	 *     anilist:?int,
	 *     mal:?int,
	 *     bangumi:?int,
	 *     tmdb:?int
	 * }
	 */
	private function resolve_external_ids( array $item, int $post_id ): array {
		$resolved = [
			'anilist' => null,
			'mal'     => null,
			'bangumi' => null,
			'tmdb'    => null,
		];

		/*
		 * First use external_ids already returned by the schema.
		 */
		if ( is_array( $item['external_ids'] ?? null ) ) {
			$this->merge_external_ids(
				$resolved,
				$item['external_ids']
			);
		}

		/*
		 * Support schemas that expose IDs directly at the item root.
		 */
		$this->merge_external_ids( $resolved, $item );

		if ( $this->all_external_ids_resolved( $resolved ) || $post_id <= 0 ) {
			return $resolved;
		}

		/*
		 * Retrieve all post meta once. This avoids issuing a separate
		 * database query for every external-ID provider.
		 */
		$all_meta = get_post_meta( $post_id );

		if ( ! is_array( $all_meta ) || [] === $all_meta ) {
			return $resolved;
		}

		/*
		 * Check known scalar post-meta keys first.
		 */
		$meta_key_map = [
			'anilist' => [
				'anilist',
				'anilist_id',
				'_anilist_id',
				'anime_anilist_id',
				'_anime_anilist_id',
				'wxacg_anilist_id',
				'_wxacg_anilist_id',
			],
			'mal' => [
				'mal',
				'mal_id',
				'_mal_id',
				'anime_mal_id',
				'_anime_mal_id',
				'myanimelist_id',
				'_myanimelist_id',
				'my_anime_list_id',
				'wxacg_mal_id',
				'_wxacg_mal_id',
			],
			'bangumi' => [
				'bangumi',
				'bangumi_id',
				'_bangumi_id',
				'anime_bangumi_id',
				'_anime_bangumi_id',
				'bgm_id',
				'_bgm_id',
				'wxacg_bangumi_id',
				'_wxacg_bangumi_id',
			],
			'tmdb' => [
				'tmdb',
				'tmdb_id',
				'_tmdb_id',
				'anime_tmdb_id',
				'_anime_tmdb_id',
				'wxacg_tmdb_id',
				'_wxacg_tmdb_id',
			],
		];

		foreach ( $meta_key_map as $provider => $keys ) {
			if ( null !== $resolved[ $provider ] ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $all_meta ) ) {
					continue;
				}

				$value = $this->first_meta_value( $all_meta[ $key ] );
				$value = $this->positive_int_or_null( $value );

				if ( null !== $value ) {
					$resolved[ $provider ] = $value;
					break;
				}
			}
		}

		if ( $this->all_external_ids_resolved( $resolved ) ) {
			return $resolved;
		}

		/*
		 * Some installations store all mappings in one serialized or JSON
		 * meta field. Inspect only known container fields so scores and
		 * unrelated numeric metadata cannot be mistaken for external IDs.
		 */
		$container_keys = [
			'external_ids',
			'_external_ids',
			'anime_external_ids',
			'_anime_external_ids',
			'wxacg_external_ids',
			'_wxacg_external_ids',
			'ids',
			'_ids',
		];

		foreach ( $container_keys as $container_key ) {
			if ( ! array_key_exists( $container_key, $all_meta ) ) {
				continue;
			}

			$container = $this->decode_meta_container(
				$this->first_meta_value( $all_meta[ $container_key ] )
			);

			if ( ! is_array( $container ) ) {
				continue;
			}

			$this->merge_external_ids( $resolved, $container );

			if ( $this->all_external_ids_resolved( $resolved ) ) {
				break;
			}
		}

		return $resolved;
	}

	/**
	 * Merge supported external IDs into the resolved mapping.
	 *
	 * Existing non-null values take priority.
	 *
	 * @param array $resolved Resolved IDs, passed by reference.
	 * @param array $source   Source array.
	 *
	 * @return void
	 */
	private function merge_external_ids( array &$resolved, array $source ): void {
		$aliases = [
			'anilist' => [
				'anilist',
				'anilist_id',
				'anilistId',
				'anime_anilist_id',
			],
			'mal' => [
				'mal',
				'mal_id',
				'malId',
				'myanimelist',
				'myanimelist_id',
				'anime_mal_id',
			],
			'bangumi' => [
				'bangumi',
				'bangumi_id',
				'bangumiId',
				'bgm',
				'bgm_id',
				'anime_bangumi_id',
			],
			'tmdb' => [
				'tmdb',
				'tmdb_id',
				'tmdbId',
				'anime_tmdb_id',
			],
		];

		foreach ( $aliases as $provider => $keys ) {
			if ( null !== $resolved[ $provider ] ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $source ) ) {
					continue;
				}

				$value = $this->positive_int_or_null( $source[ $key ] );

				if ( null !== $value ) {
					$resolved[ $provider ] = $value;
					break;
				}
			}
		}
	}

	/**
	 * Return the first actual value from get_post_meta() output.
	 *
	 * @param mixed $value Meta value.
	 *
	 * @return mixed
	 */
	private function first_meta_value( $value ) {
		if ( is_array( $value ) && array_is_list( $value ) ) {
			return $value[0] ?? null;
		}

		return $value;
	}

	/**
	 * Decode a serialized or JSON external-ID container.
	 *
	 * @param mixed $value Raw meta value.
	 *
	 * @return array|null
	 */
	private function decode_meta_container( $value ): ?array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = maybe_unserialize( $value );

		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Determine whether every supported provider has been resolved.
	 *
	 * @param array $resolved Resolved IDs.
	 *
	 * @return bool
	 */
	private function all_external_ids_resolved( array $resolved ): bool {
		foreach ( [ 'anilist', 'mal', 'bangumi', 'tmdb' ] as $provider ) {
			if ( null === ( $resolved[ $provider ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert a value to a positive integer or null.
	 *
	 * Numeric strings and provider URLs ending in a numeric ID are accepted.
	 * Scores, decimals, negative values and malformed strings are rejected.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return int|null
	 */
	private function positive_int_or_null( $value ): ?int {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				$value = $value[0] ?? null;
			} else {
				return null;
			}
		}

		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_float( $value ) ) {
			if ( $value <= 0 || floor( $value ) !== $value ) {
				return null;
			}

			return (int) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return (int) $value;
		}

		/*
		 * Accept provider URLs such as:
		 * https://anilist.co/anime/166873
		 * https://myanimelist.net/anime/55888
		 * https://bgm.tv/subject/444557
		 * https://www.themoviedb.org/tv/12345
		 */
		if (
			filter_var( $value, FILTER_VALIDATE_URL )
			&& preg_match( '~/([1-9][0-9]*)/?(?:[?#].*)?$~', $value, $matches )
		) {
			return (int) $matches[1];
		}

		return null;
	}
}
