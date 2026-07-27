<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Cache {

	private const PREFIX = 'wxacg_catalog_';

	private const DEFAULT_TTL = 5 * MINUTE_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'save_post', [ $this, 'invalidate_post' ], 20, 3 );
		add_action( 'deleted_post', [ $this, 'invalidate_deleted_post' ] );
		add_action( 'set_object_terms', [ $this, 'invalidate_terms' ], 20, 6 );

		add_action( 'added_post_meta', [ $this, 'invalidate_meta' ], 20, 4 );
		add_action( 'updated_post_meta', [ $this, 'invalidate_meta' ], 20, 4 );
		add_action( 'deleted_post_meta', [ $this, 'invalidate_meta' ], 20, 4 );

		add_action( 'smacg_rating_added', [ __CLASS__, 'bump_version' ], 20 );
	}

	public function get( string $group, array $args ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return false;
		}

		return get_transient( $this->key( $group, $args ) );
	}

	public function set(
		string $group,
		array $args,
		$value,
		int $ttl = self::DEFAULT_TTL
	): bool {
		$ttl = max( 30, $ttl );

		/**
		 * 調整 Catalog API 快取時間。
		 */
		$ttl = (int) apply_filters(
			'wxacg_catalog_api_cache_ttl',
			$ttl,
			$group,
			$args
		);

		return set_transient(
			$this->key( $group, $args ),
			$value,
			max( 30, $ttl )
		);
	}

	private function key( string $group, array $args ): string {
		ksort( $args );

		return self::PREFIX
			. sanitize_key( $group )
			. '_'
			. self::version()
			. '_'
			. md5( wp_json_encode( $args ) );
	}

	public static function version(): int {
		return max(
			1,
			(int) get_option( 'wxacg_catalog_cache_version', 1 )
		);
	}

	public static function bump_version(): void {
		update_option(
			'wxacg_catalog_cache_version',
			self::version() + 1,
			false
		);
	}

	public function invalidate_post(
		int $post_id,
		WP_Post $post,
		bool $update
	): void {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( in_array( $post->post_type, [ 'anime', 'manga' ], true ) ) {
			self::bump_version();
		}
	}

	public function invalidate_deleted_post( int $post_id ): void {
		$post_type = get_post_type( $post_id );

		if ( in_array( $post_type, [ 'anime', 'manga' ], true ) ) {
			self::bump_version();
		}
	}

	public function invalidate_terms(
		int $object_id,
		array $terms,
		array $tt_ids,
		string $taxonomy,
		bool $append,
		array $old_tt_ids
	): void {
		unset( $terms, $tt_ids, $append, $old_tt_ids );

		if (
			in_array( get_post_type( $object_id ), [ 'anime', 'manga' ], true )
			&& in_array(
				$taxonomy,
				[
					'genre',
					'post_tag',
					'anime_season_tax',
					'anime_format_tax',
					'anime_series_tax',
					'anime_studio_tax',
				],
				true
			)
		) {
			self::bump_version();
		}
	}

	public function invalidate_meta(
		$meta_id,
		int $post_id,
		string $meta_key,
		$meta_value
	): void {
		unset( $meta_id, $meta_value );

		if ( ! in_array( get_post_type( $post_id ), [ 'anime', 'manga' ], true ) ) {
			return;
		}

		if (
			str_starts_with( $meta_key, 'anime_' )
			|| str_starts_with( $meta_key, 'manga_' )
			|| str_starts_with( $meta_key, '_smacg_' )
			|| str_starts_with( $meta_key, 'smacg_site_' )
		) {
			self::bump_version();
		}
	}
}
