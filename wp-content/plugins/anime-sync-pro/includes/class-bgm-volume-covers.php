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
	 * 逐卷抓明細時的請求間隔。
	 *
	 * Bangumi 沒有公布明確的速率上限，只要求合理使用。0.7 秒約等於每分鐘 85 次，
	 * 對一個公益站台的公開 API 算客氣。首次同步 400 卷約 4.7 分鐘，之後每輪
	 * 只抓新出的幾卷，實際不會再付這個成本（見 fetch_volume_details 註解）。
	 */
	const DETAIL_SLEEP_US = 700000;

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
			// 第 0 卷是真實存在的（前傳／番外，例如《呪術廻戦 0》），要保留。
			// 這裡不必擔心「卷號缺失」被誤當成 0：上面的 preg_match 已經確保
			// 括號裡有數字，抓不到的在那一行就 continue 了。
			if ( $vol < 0 ) continue;

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
	 * 抓每卷的發售日與 ISBN。
	 *
	 * ★ 為什麼要逐卷再打一次 API：
	 *   /v0/subjects/{id}/subjects 這份關聯清單只回傳 id / name / images /
	 *   relation / type，實測不含 date 也不含 infobox。發售日與 ISBN 只存在於
	 *   單卷條目本身，所以每一卷都必須再打一次 /v0/subjects/{卷id}。
	 *
	 * ★ 為什麼這個成本可以接受：
	 *   單行本一旦出版，發售日與 ISBN 就不會再變。$known 帶入上一輪的結果，
	 *   已經有資料的卷直接沿用、不再發請求；首次同步付一次全量成本（目前
	 *   14 部共約 400 卷），之後每輪只抓新出的那幾卷。這與 download_cover()
	 *   既有的「檔案已存在就跳過」是同一個模式。
	 *
	 * ★ 為什麼只填 jp：
	 *   Bangumi 的單卷條目是日版書目，發售日與 ISBN 都是日版。infobox 裡雖然
	 *   有「版本:东立版」之類的欄位，但只有書名與別名，沒有台版發售日與 ISBN，
	 *   硬塞進 tw 欄會變成把日版資料標成台版——寧可留空，也不要誤導讀者。
	 *
	 * @param int   $bangumi_id 主條目 id。
	 * @param array $known      已知資料，以 bgm_id 為鍵。
	 * @return array [ ['vol'=>1,'bgm_id'=>4931,'jp'=>['date'=>'','isbn'=>'','note'=>'']], ... ]
	 */
	public function fetch_volume_details( int $bangumi_id, array $known = [] ): array {
		if ( $bangumi_id <= 0 ) return [];

		$body = $this->http_get( self::API_BASE . $bangumi_id . '/subjects' );
		if ( $body === '' ) return [];

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) return [];

		$out = [];

		foreach ( $json as $rel ) {
			if ( ( $rel['relation'] ?? '' ) !== '单行本' ) continue;

			$name = (string) ( $rel['name'] ?? '' );
			if ( ! preg_match( '/\((\d+)\)\s*$/u', $name, $m ) ) continue;

			$vol    = (int) $m[1];
			$sub_id = (int) ( $rel['id'] ?? 0 );
			// $vol 用 < 0：第 0 卷要保留（理由同上，regex 已保證卷號存在）。
			// $sub_id 維持 <= 0：那是 Bangumi 的條目 ID，0 代表沒有，確實無效。
			if ( $vol < 0 || $sub_id <= 0 ) continue;

			// 已經抓過而且抓到東西 → 沿用，不再發請求
			$cached = $known[ $sub_id ] ?? null;
			if ( is_array( $cached )
			  && ( ! empty( $cached['jp']['date'] ) || ! empty( $cached['jp']['isbn'] ) ) ) {
				$cached['vol']    = $vol;
				$cached['bgm_id'] = $sub_id;
				$out[]            = $cached;
				continue;
			}

			// 只有真的要發請求時才等，沿用快取的卷不必付這個延遲
			usleep( self::DETAIL_SLEEP_US );

			$detail = $this->fetch_subject_detail( $sub_id );

			$out[] = [
				'vol'    => $vol,
				'bgm_id' => $sub_id,
				'jp'     => [
					'date' => $detail['date'] ?? '',
					'isbn' => $detail['isbn'] ?? '',
					'note' => '',
				],
			];
		}

		usort( $out, fn( $a, $b ) => $a['vol'] <=> $b['vol'] );

		return $out;
	}

	/**
	 * 取單卷條目的發售日與 ISBN。
	 *
	 * 發售日優先讀 infobox 的「发售日」，沒有才退回頂層 date——兩者通常一致，
	 * 但 infobox 是書目本身的欄位，語意比較明確。
	 *
	 * @return array{date:string,isbn:string}
	 */
	private function fetch_subject_detail( int $subject_id ): array {
		$empty = [ 'date' => '', 'isbn' => '' ];

		$body = $this->http_get( self::API_BASE . $subject_id );
		if ( $body === '' ) return $empty;

		$s = json_decode( $body, true );
		if ( ! is_array( $s ) ) return $empty;

		$date = '';
		$isbn = '';

		foreach ( (array) ( $s['infobox'] ?? [] ) as $box ) {
			$key = trim( (string) ( $box['key'] ?? '' ) );
			$val = $box['value'] ?? '';
			// 「版本:东立版」這類欄位的 value 是陣列，直接略過
			if ( ! is_string( $val ) ) continue;
			$val = trim( $val );
			if ( $val === '' ) continue;

			if ( $date === '' && ( '发售日' === $key || '發售日' === $key ) ) {
				$date = $val;
			} elseif ( $isbn === '' && 'ISBN' === strtoupper( $key ) ) {
				$isbn = $val;
			}
		}

		if ( $date === '' ) {
			$date = trim( (string) ( $s['date'] ?? '' ) );
		}

		// 只接受 YYYY-MM-DD / YYYY-MM / YYYY，其餘視為無效，不塞髒資料給讀者
		$date = preg_match( '/^\d{4}(?:-\d{2}){0,2}$/', $date ) ? $date : '';

		return [ 'date' => $date, 'isbn' => $isbn ];
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
