<?php
/**
 * 檔案名稱: includes/class-bgm-volume-covers.php
 *
 * 從 Bangumi 關聯條目 API 抓「單行本」每卷封面，下載 400px 到本地單一檔
 * (不進媒體庫、不生縮圖，省 inode)，回傳 [{vol,cover,bgm_id}, ...]。
 *
 * 資料源: GET /v0/subjects/{id}/subjects → 過濾 relation === "单行本"。
 * 卷號: 從卷名 "ダンダダン (N)" 的括號數字取；無括號則跳過。
 *
 * @package Anime_Sync_Pro
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_BGM_Volume_Covers {

	const API_BASE   = 'https://api.bgm.tv/v0/subjects/';
	const USER_AGENT = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com; anime-sync-pro)';
	const TIMEOUT    = 20;

	/**
	 * 主入口：給 Bangumi 主 subject id + post_id，回傳每卷封面陣列。
	 * 封面已下載到本地，cover 欄位是本地 URL。
	 *
	 * @return array [ ['vol'=>1,'cover'=>'本地url','bgm_id'=>344360], ... ]
	 */
	public function fetch( int $bangumi_id, int $post_id ): array {
		if ( $bangumi_id <= 0 || $post_id <= 0 ) return [];

		$url  = self::API_BASE . $bangumi_id . '/subjects';
		$body = $this->http_get( $url );
		if ( $body === '' ) return [];

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) return [];

		$volumes = [];
		foreach ( $json as $rel ) {
			if ( ( $rel['relation'] ?? '' ) !== '单行本' ) continue;

			$name = (string) ( $rel['name'] ?? '' );
			// 從 "ダンダダン (12)" 取卷號
			if ( ! preg_match( '/\((\d+)\)\s*$/u', $name, $m ) ) continue;
			$vol = (int) $m[1];
			if ( $vol <= 0 ) continue;

			$bgm_sub_id  = (int) ( $rel['id'] ?? 0 );
			$remote_cover = $rel['images']['common']    // 400px
				?? $rel['images']['medium']
				?? $rel['images']['large']
				?? '';

			$local_cover = '';
			if ( $remote_cover !== '' ) {
				$local_cover = $this->download_cover( $remote_cover, $post_id, $vol );
			}

			$volumes[] = [
				'vol'    => $vol,
				'cover'  => $local_cover,   // 空字串代表無圖(如未出的最新卷)
				'bgm_id' => $bgm_sub_id,
			];
		}

		// 依卷號排序
		usort( $volumes, fn( $a, $b ) => $a['vol'] <=> $b['vol'] );

		return $volumes;
	}

	/**
	 * 下載單張封面到 uploads，單一檔、不生縮圖、不進媒體庫。
	 * 已存在同名檔就直接回傳既有 URL(避免重複下載)。
	 *
	 * @return string 本地 URL；失敗回空字串。
	 */
	private function download_cover( string $remote_url, int $post_id, int $vol ): string {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) return '';

		$filename  = sprintf( 'manga-%d-vol-%d.jpg', $post_id, $vol );
		$file_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$file_url  = trailingslashit( $upload_dir['url'] )  . $filename;

		if ( file_exists( $file_path ) ) {
			return $file_url;
		}

		$resp = wp_remote_get( $remote_url, [
			'timeout'    => self::TIMEOUT,
			'user-agent' => self::USER_AGENT,
			'headers'    => [ 'Referer' => 'https://bgm.tv/' ], // 過防盜鏈
		] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return '';
		}
		$img = wp_remote_retrieve_body( $resp );
		if ( strlen( $img ) < 512 ) return ''; // 太小視為失敗

		if ( file_put_contents( $file_path, $img ) === false ) return '';

		return $file_url;
	}

	private function http_get( string $url ): string {
		$backoffs = [ 0, 1, 3 ];
		foreach ( $backoffs as $wait ) {
			if ( $wait > 0 ) sleep( $wait );
			$resp = wp_remote_get( $url, [
				'timeout'    => self::TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'headers'    => [ 'Accept' => 'application/json' ],
			] );
			if ( is_wp_error( $resp ) ) continue;
			$code = wp_remote_retrieve_response_code( $resp );
			if ( $code === 200 ) return (string) wp_remote_retrieve_body( $resp );
			if ( $code >= 400 && $code < 500 && $code !== 429 ) return '';
		}
		return '';
	}
}
