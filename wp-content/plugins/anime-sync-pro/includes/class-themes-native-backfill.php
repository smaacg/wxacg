<?php
/**
 * 主題曲日文原文回填
 *
 * 站上主題曲只有 29% 的歌名、34% 的歌手顯示日文，其餘是 AnimeThemes 的
 * 羅馬字。這多半不是「沒有資料」，而是「當初存進來時沒有、之後也補不到」：
 * 每 15 分鐘的 themes_episodes 同步在遇到已存在的主題曲時，原本只補空的
 * audio_url / video_url，不會回頭補 title_native；而已完結的舊作品只有在
 * 主題曲整個是空陣列時才會重新排進佇列。兩者相加的結果就是早期匯入的
 * 作品永遠停在羅馬字。
 *
 * 同步端的行為已在 class-cron-manager.php 修正（改為一併補空的日文欄位），
 * 本檔負責把「不會再被排進佇列」的既有作品補完。
 *
 * 用法：
 *   wp anime backfill-theme-natives --dry-run        # 先看影響範圍，不寫入
 *   wp anime backfill-theme-natives --limit=100      # 分批跑
 *   wp anime backfill-theme-natives --local-only     # 只做零 API 成本的歌手回填
 *   wp anime backfill-theme-natives                  # 全部跑
 *
 * 特性：
 *   - 只補空值，永遠不覆蓋既有內容（手動改過的日文歌名不會被動到）
 *   - 尊重 anime_themes_locked_keys 的逐首鎖定
 *   - 只寫 anime_themes 這一個 meta，不動其他任何欄位
 *   - 可重複執行，中斷後再跑一次即可接續
 *   - 歌手日文走站內既有資料反查，零 API 成本；歌名要打 MAL，有節流
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Themes_Native_Backfill {

	/** 每次 WP_Query 取幾筆 */
	private const PAGE_SIZE = 200;

	/** 兩次 MAL 請求之間的間隔（毫秒）。MAL 官方 API 沒有公布明確上限，取保守值。 */
	private const MAL_INTERVAL_MS = 1200;

	private ?Anime_Sync_API_Handler $api = null;


	/**
	 * @param array{dry_run?:bool,limit?:int,local_only?:bool} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {
		$dry_run    = ! empty( $args['dry_run'] );
		$local_only = ! empty( $args['local_only'] );
		$limit      = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		$stats = [
			'scanned'        => 0,
			'posts_changed'  => 0,
			'title_filled'         => 0,
			'artist_filled'        => 0,
			'mal_calls'      => 0,
			'mal_no_data'    => 0,
			'skipped_locked' => 0,
			'samples'        => [],
		];

		if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) {
			return $stats;
		}
		$this->api = new Anime_Sync_API_Handler();

		$paged     = 1;
		$processed = 0;

		while ( true ) {
			$ids = get_posts( [
				'post_type'      => 'anime',
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => self::PAGE_SIZE,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'     => 'anime_themes',
						'value'   => [ '', '[]' ],
						'compare' => 'NOT IN',
					],
				],
			] );

			if ( ! $ids ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				$post_id = (int) $post_id;
				$stats['scanned']++;

				$result = $this->backfill_post( $post_id, $dry_run, $local_only, $stats );

				if ( $result ) {
					$stats['posts_changed']++;
					$processed++;

					if ( $limit > 0 && $processed >= $limit ) {
						return $stats;
					}
				}
			}

			$paged++;
		}

		return $stats;
	}

	/**
	 * 回填單一作品。回傳是否有變更。
	 */
	private function backfill_post( int $post_id, bool $dry_run, bool $local_only, array &$stats ): bool {
		$themes = json_decode( (string) get_post_meta( $post_id, 'anime_themes', true ), true );

		if ( ! is_array( $themes ) || ! $themes ) {
			return false;
		}

		$locked_keys  = json_decode( (string) get_post_meta( $post_id, 'anime_themes_locked_keys', true ), true );
		$locked_index = is_array( $locked_keys ) ? array_flip( $locked_keys ) : [];

		/* 先看有沒有缺，沒缺就不必打 MAL */
		$needs_title  = false;
		$needs_artist = false;

		foreach ( $themes as $t ) {
			if ( trim( (string) ( $t['title_native'] ?? '' ) ) === ''
				&& trim( (string) ( $t['title'] ?? '' ) ) !== '' ) {
				$needs_title = true;
			}
			foreach ( (array) ( $t['artists'] ?? [] ) as $a ) {
				if ( trim( (string) ( $a['name_native'] ?? '' ) ) === ''
					&& trim( (string) ( $a['name'] ?? '' ) ) !== '' ) {
					$needs_artist = true;
				}
			}
		}

		if ( ! $needs_title && ! $needs_artist ) {
			return false;
		}

		/* 歌名日文要打 MAL；--local-only 時跳過 */
		$mal_map = [];

		if ( $needs_title && ! $local_only ) {
			$mal_id = (int) get_post_meta( $post_id, 'anime_mal_id', true );

			if ( $mal_id > 0 ) {
				usleep( self::MAL_INTERVAL_MS * 1000 );
				$mal_map = $this->api->get_theme_natives_public( $mal_id );
				$stats['mal_calls']++;

				if ( ! $mal_map ) {
					$stats['mal_no_data']++;
				}
			}
		}

		$changed = false;

		foreach ( $themes as $i => $theme ) {
			$key = ( $theme['type'] ?? '' ) . ':' . ( $theme['sequence'] ?? '' );

			if ( isset( $locked_index[ $key ] ) ) {
				$stats['skipped_locked']++;
				continue;
			}

			/* ── 歌名 ── */
			$title = trim( (string) ( $theme['title'] ?? '' ) );

			if ( $mal_map
				&& $title !== ''
				&& trim( (string) ( $theme['title_native'] ?? '' ) ) === '' ) {

				$native = $mal_map[ $this->api->normalize_title_public( $title ) ] ?? '';

				if ( $native !== '' ) {
					$themes[ $i ]['title_native'] = $native;
					$stats['title_filled']++;
					$changed = true;

					if ( count( $stats['samples'] ) < 12 ) {
						$stats['samples'][] = $title . ' → ' . $native;
					}
				}
			}

			/* ── 歌手（純本地查詢，不打 API）── */
			foreach ( (array) ( $theme['artists'] ?? [] ) as $ai => $artist ) {
				$name = trim( (string) ( $artist['name'] ?? '' ) );

				if ( $name === '' || trim( (string) ( $artist['name_native'] ?? '' ) ) !== '' ) {
					continue;
				}

				/*
				 * 共用 API Handler 的查詢鏈：站內既有主題曲 → 人物表羅馬字。
				 * 兩層都是純本地查詢，不打任何外部 API。
				 *
				 * 這條鏈同時也接在同步流程上（class-api-handler.php 組 artists
				 * 陣列時），所以新匯入的作品會自動有日文歌手名，不必再手動回填。
				 * 本指令只負責補「同步流程不會再回頭處理」的既有資料。
				 */
				$native = $this->api->lookup_artist_native_public( $name );

				/*
				 * 反查結果與羅馬字相同（HYDE → HYDE）或根本不含日文字元時，
				 * 寫進去只是多一份一模一樣的資料，顯示層看不出差別。跳過。
				 */
				if ( $native === $name
					|| ! preg_match( '/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $native ) ) {
					continue;
				}

				if ( $native !== '' ) {
					$themes[ $i ]['artists'][ $ai ]['name_native'] = $native;
					$stats['artist_filled']++;
					$changed = true;

					if ( count( $stats['samples'] ) < 12 ) {
						$stats['samples'][] = $name . ' → ' . $native;
					}
				}
			}
		}

		if ( $changed && ! $dry_run ) {
			update_post_meta(
				$post_id,
				'anime_themes',
				wp_slash( wp_json_encode( array_values( $themes ), JSON_UNESCAPED_UNICODE ) )
			);
		}

		return $changed;
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime backfill-theme-natives', function ( $args, $assoc_args ) {
		$dry_run    = isset( $assoc_args['dry-run'] );
		$local_only = isset( $assoc_args['local-only'] );
		$limit      = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		WP_CLI::log( $dry_run ? '=== DRY RUN（不寫入）===' : '=== 開始回填主題曲日文原文 ===' );

		if ( $local_only ) {
			WP_CLI::log( '模式：--local-only（只做歌手回填，不打 MAL）' );
		}
		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限：--limit=' . $limit . ' 部（以「有變更」計）' );
		}

		$backfill = new Anime_Sync_Themes_Native_Backfill();
		$stats    = $backfill->run( [
			'dry_run'    => $dry_run,
			'limit'      => $limit,
			'local_only' => $local_only,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃描作品      : ' . $stats['scanned'] );
		WP_CLI::log( '有變更的作品  : ' . $stats['posts_changed'] );
		WP_CLI::log( '補到歌名日文  : ' . $stats['title_filled'] );
		WP_CLI::log( '補到歌手日文  : ' . $stats['artist_filled'] );
		WP_CLI::log( 'MAL 請求次數  : ' . $stats['mal_calls'] );
		WP_CLI::log( 'MAL 無資料    : ' . $stats['mal_no_data'] );
		WP_CLI::log( '因鎖定而略過  : ' . $stats['skipped_locked'] );

		if ( ! empty( $stats['samples'] ) ) {
			WP_CLI::log( '─── 樣本 ───' );
			foreach ( $stats['samples'] as $s ) {
				WP_CLI::log( '  ' . $s );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry run 完成（未寫入）' : '回填完成' );
	} );
}
