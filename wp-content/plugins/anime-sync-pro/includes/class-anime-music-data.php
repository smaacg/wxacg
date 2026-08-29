<?php
/**
 * Anime Music Data — 動畫音樂資料的單一來源。
 *
 * single-anime.php 的兩種檢視都要這份資料：一般檢視顯示「5 首主題曲・
 * 21 張相關專輯 →」的摘要入口，音樂檢視（/anime/{slug}/music/）渲染完整
 * 內容。邏輯放這裡，兩邊呼叫同一個方法，避免各解析一份之後慢慢長歪。
 *
 * 兩個來源：
 *   openings / endings — AnimeThemes（postmeta anime_themes），有影音可播
 *   albums             — Bangumi 關聯（wp_wxacg_subject_relations），純清單
 *
 * Changelog:
 *   1.0.0 (2026-08-29) — 初版。OP/ED 解析由 single-anime.php 搬入。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Anime_Music_Data {

	/**
	 * @return array{
	 *   openings:array, endings:array, albums:array,
	 *   themes_total:int, albums_total:int, has_any:bool
	 * }
	 */
	public static function get( int $post_id ): array {
		$empty = [
			'openings'     => [],
			'endings'      => [],
			'albums'       => [],
			'themes_total' => 0,
			'albums_total' => 0,
			'has_any'      => false,
		];

		if ( $post_id <= 0 ) {
			return $empty;
		}

		[ $openings, $endings ] = self::parse_themes( $post_id );

		$albums       = self::get_albums( $post_id );
		$albums_total = 0;

		foreach ( $albums as $group ) {
			$albums_total += (int) $group['count'];
		}

		$themes_total = count( $openings ) + count( $endings );

		return [
			'openings'     => $openings,
			'endings'      => $endings,
			'albums'       => $albums,
			'themes_total' => $themes_total,
			'albums_total' => $albums_total,
			'has_any'      => ( $themes_total > 0 || $albums_total > 0 ),
		];
	}

	/**
	 * AnimeThemes 的 anime_themes 拆成 OP 與 ED 兩組。
	 *
	 * 去重依據 slug；沒有 slug 才退回「型別＋標題」。同一首歌在資料裡
	 * 常有多筆版本（TV size／完整版），slug 相同的只留第一筆。
	 *
	 * @return array{0:array,1:array} [ openings, endings ]
	 */
	private static function parse_themes( int $post_id ): array {
		$raw = get_post_meta( $post_id, 'anime_themes', true );

		/* 與 single-anime.php 的 $decode_json 同行為:陣列直接用、
		   空字串或非陣列一律回空陣列,不讓 json_decode 的 null 漏下去 */
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$list    = is_array( $decoded ) ? $decoded : [];
		} else {
			$list = [];
		}

		if ( empty( $list ) ) {
			return [ [], [] ];
		}

		$seen     = [];
		$openings = [];
		$endings  = [];

		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type  = strtoupper( trim( (string) ( $item['type'] ?? '' ) ) );
			$slug  = trim( (string) ( $item['slug'] ?? '' ) );
			$title = trim( (string) ( $item['title'] ?? '' ) );

			$key = '' !== $slug ? $slug : $type . '||' . $title;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			/* 型別是 OP1／ED2 這種帶編號的字串,只比對開頭 */
			if ( 0 === strpos( $type, 'OP' ) ) {
				$openings[] = $item;
			} elseif ( 0 === strpos( $type, 'ED' ) ) {
				$endings[] = $item;
			}
		}

		return [ $openings, $endings ];
	}

	/**
	 * Bangumi 關聯的專輯,已依類型分組。
	 */
	private static function get_albums( int $post_id ): array {
		if ( ! class_exists( 'Anime_Sync_Subject_Relations_Repository' ) ) {
			return [];
		}

		$repo = new Anime_Sync_Subject_Relations_Repository();

		return $repo->get_grouped(
			$post_id,
			Anime_Sync_Subject_Relations_Repository::TYPE_MUSIC
		);
	}

	/**
	 * 音樂頁網址：/anime/{slug}/music/
	 *
	 * 用 get_permalink() 當基底而非自己拼字串,這樣以後改了 CPT 的
	 * rewrite slug 也不會壞掉。
	 */
	public static function page_url( int $post_id ): string {
		$base = get_permalink( $post_id );

		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . 'music/';
	}
}
