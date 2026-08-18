<?php
/**
 * 檔案名稱: includes/class-wikidata-fetcher.php
 *
 * 從 Wikidata 拉取漫畫作品的結構化資料(精簡版,面向台港澳)。
 * 主要用途:
 *   1. 用 AniList ID 反查 QID → 拿到正確的中文維基頁標題(解決譯名對不上問題)
 *   2. 補充可靠的外部 ID(AniList / MyAnimeList / Bangumi)與作者/出版社/雜誌
 *
 * 卷數/日期/ISBN 明細請以 class-wiki-manga-fetcher.php 的出版表為主,
 * 本類別只做補充。
 *
 * @package Anime_Sync_Pro
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Wikidata_Fetcher {

	const WIKIDATA_API    = 'https://www.wikidata.org/w/api.php';
	const WIKIDATA_SPARQL = 'https://query.wikidata.org/sparql';
	const ZH_WIKI_API     = 'https://zh.wikipedia.org/w/api.php';
	const USER_AGENT      = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com; anime-sync-pro)';
	const HTTP_TIMEOUT    = 20;

	/**
	 * 簡單值 property → meta key(只保留確定正確、且網站會用到的)
	 * ★改:砍掉全部冷門日陸站 ID,只留這 4 個可靠欄位。
	 */
	private const SIMPLE_PROPS = [
		'P577'  => 'manga_first_publish_date',   // 首次發行日期
		'P8731' => 'anime_anilist_id',            // AniList manga ID(已驗證)
		'P4087' => 'anime_mal_id',                // MyAnimeList manga ID(已驗證)
		'P5732' => 'anime_bangumi_id',            // Bangumi subject ID(已驗證)
	];

	/**
	 * 需要展開 label 的 entity 型 property(通用屬性,可靠)
	 */
	private const ENTITY_PROPS = [
		'P50'   => 'manga_authors_wikidata',   // 作者
		'P123'  => 'manga_jp_publishers',       // 出版社
		'P1433' => 'manga_magazine',            // 刊載雜誌
		'P166'  => 'manga_awards',              // 獲獎
	];

	/**
	 * 透過 AniList manga ID (P8731) 反查 Wikidata QID。
	 * ★改:改用 wbsearchentities 的 haswbstatement 走 API,避開 SPARQL 限流。
	 *      SPARQL 保留為 fallback。
	 */
	public function find_qid_by_anilist( int $anilist_id ): string {
		if ( $anilist_id <= 0 ) return '';

		// --- 方式 1:query API 的 haswbstatement 搜尋(較不易限流) ---
		$url = self::WIKIDATA_API . '?' . http_build_query( [
			'action'   => 'query',
			'list'     => 'search',
			'srsearch' => 'haswbstatement:P8731=' . intval( $anilist_id ),
			'srlimit'  => 1,
			'format'   => 'json',
		] );
		$body = $this->http_get( $url );
		if ( $body !== '' ) {
			$json = json_decode( $body, true );
			$hit  = $json['query']['search'][0]['title'] ?? '';
			if ( preg_match( '/^Q\d+$/', $hit ) ) return $hit;
		}

		// --- 方式 2:SPARQL fallback ---
		$sparql = 'SELECT ?item WHERE { ?item wdt:P8731 "' . intval( $anilist_id ) . '" . } LIMIT 1';
		$url    = self::WIKIDATA_SPARQL . '?query=' . rawurlencode( $sparql ) . '&format=json';
		$body   = $this->http_get( $url );
		if ( $body === '' ) return '';

		$json     = json_decode( $body, true );
		$bindings = $json['results']['bindings'] ?? [];
		if ( empty( $bindings ) ) return '';

		$uri = $bindings[0]['item']['value'] ?? '';
		if ( ! preg_match( '#/entity/(Q\d+)$#', $uri, $m ) ) return '';
		return $m[1];
	}

	/**
	 * 透過 Wikipedia 中文頁標題反查 QID。
	 */
	public function find_qid_by_zh_title( string $title ): string {
		$title = trim( $title );
		if ( $title === '' ) return '';

		$url = self::ZH_WIKI_API . '?' . http_build_query( [
			'action'        => 'query',
			'prop'          => 'pageprops',
			'titles'        => $title,
			'format'        => 'json',
			'formatversion' => 2,
			'redirects'     => 1,
		] );

		$body = $this->http_get( $url );
		if ( $body === '' ) return '';

		$json  = json_decode( $body, true );
		$pages = $json['query']['pages'] ?? [];
		foreach ( $pages as $p ) {
			$qid = $p['pageprops']['wikibase_item'] ?? '';
			if ( $qid !== '' ) return $qid;
		}
		return '';
	}

	/**
	 * ★新增:給 AniList ID,回傳該作品的中文維基頁標題(zhwiki sitelink)。
	 * 這是解決「間諜過家家 vs SPY×FAMILY」譯名對不上的關鍵——
	 * 用它拿到正確頁名,再餵給 class-wiki-manga-fetcher。
	 * 同時回傳繁中/港中 label 供備選。
	 */
	public function get_zh_title_by_anilist( int $anilist_id ): array {
		$out = [ 'qid' => '', 'zhwiki_title' => '', 'label_zhtw' => '', 'label_zhhk' => '' ];

		$qid = $this->find_qid_by_anilist( $anilist_id );
		if ( $qid === '' ) return $out;
		$out['qid'] = $qid;

		$url = self::WIKIDATA_API . '?' . http_build_query( [
			'action'    => 'wbgetentities',
			'ids'       => $qid,
			'props'     => 'labels|sitelinks',
			'languages' => 'zh-tw|zh-hk|zh-hant|zh',
			'format'    => 'json',
		] );
		$body = $this->http_get( $url );
		if ( $body === '' ) return $out;

		$json = json_decode( $body, true );
		$ent  = $json['entities'][ $qid ] ?? null;
		if ( ! $ent ) return $out;

		$out['zhwiki_title'] = $ent['sitelinks']['zhwiki']['title'] ?? '';
		$out['label_zhtw']   = $ent['labels']['zh-tw']['value'] ?? ( $ent['labels']['zh-hant']['value'] ?? '' );
		$out['label_zhhk']   = $ent['labels']['zh-hk']['value'] ?? '';
		return $out;
	}

	/**
	 * 拉取單一 QID 的資料,回傳扁平化陣列。
	 */
	public function fetch_entity( string $qid ): array {
		if ( ! preg_match( '/^Q\d+$/', $qid ) ) return [];

		$url = self::WIKIDATA_API . '?' . http_build_query( [
			'action'    => 'wbgetentities',
			'ids'       => $qid,
			'props'     => 'labels|claims|sitelinks',
			'languages' => 'zh-tw|zh-hk|zh-hant|zh|ja|en',
			'format'    => 'json',
		] );

		$body = $this->http_get( $url );
		if ( $body === '' ) return [];

		$json = json_decode( $body, true );
		$ent  = $json['entities'][ $qid ] ?? null;
		if ( ! $ent ) return [];

		$out = [
			'manga_wikidata_qid'  => $qid,
			'manga_wikipedia_url' => isset( $ent['sitelinks']['zhwiki']['title'] )
				? 'https://zh.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $ent['sitelinks']['zhwiki']['title'] ) )
				: '',
			'manga_zhwiki_title'  => $ent['sitelinks']['zhwiki']['title'] ?? '',
		];

		$claims = $ent['claims'] ?? [];

		// ---- 簡單值 property ----
		foreach ( self::SIMPLE_PROPS as $pid => $meta_key ) {
			if ( empty( $claims[ $pid ] ) ) continue;
			$c = $claims[ $pid ][0]['mainsnak']['datavalue']['value'] ?? null;
			if ( $c === null ) continue;

			if ( is_array( $c ) ) {
				if ( isset( $c['time'] ) ) {
					$time = ltrim( $c['time'], '+' );
					$out[ $meta_key ] = substr( $time, 0, 10 );   // YYYY-MM-DD
				} elseif ( isset( $c['id'] ) ) {
					$out[ $meta_key ] = $c['id'];
				}
			} else {
				$out[ $meta_key ] = (string) $c;
			}
		}

		// ---- entity property(展 label) ----
		$need_labels = [];
		$entity_map  = [];
		foreach ( self::ENTITY_PROPS as $pid => $meta_key ) {
			if ( empty( $claims[ $pid ] ) ) continue;
			$qids = [];
			foreach ( $claims[ $pid ] as $c ) {
				$id = $c['mainsnak']['datavalue']['value']['id'] ?? '';
				if ( $id !== '' ) {
					$qids[] = $id;
					$need_labels[ $id ] = true;
				}
			}
			$entity_map[ $meta_key ] = $qids;
		}

		if ( ! empty( $need_labels ) ) {
			$labels = $this->batch_get_labels( array_keys( $need_labels ) );
			foreach ( $entity_map as $meta_key => $qids ) {
				$names = [];
				foreach ( $qids as $q ) {
					if ( ! empty( $labels[ $q ] ) ) $names[] = $labels[ $q ];
				}
				$out[ $meta_key ]          = implode( ', ', $names );
				$out[ $meta_key . '_qids' ] = implode( ',', $qids );
			}
		}

		return $out;
	}

	/**
	 * 便捷方法:給 AniList ID 直接拿一切能拿的資料。
	 */
	public function fetch_by_anilist( int $anilist_id ): array {
		$qid = $this->find_qid_by_anilist( $anilist_id );
		if ( $qid === '' ) return [];
		return $this->fetch_entity( $qid );
	}

	/**
	 * 批次拿 QID 的 label(繁中/港中優先,fallback 中→日→英)
	 * ★改:語言順序改成 zh-tw / zh-hk 優先(面向台港澳)。
	 */
	private function batch_get_labels( array $qids ): array {
		if ( empty( $qids ) ) return [];

		$chunks = array_chunk( array_unique( $qids ), 50 );
		$result = [];

		foreach ( $chunks as $chunk ) {
			$url = self::WIKIDATA_API . '?' . http_build_query( [
				'action'    => 'wbgetentities',
				'ids'       => implode( '|', $chunk ),
				'props'     => 'labels',
				'languages' => 'zh-tw|zh-hk|zh-hant|zh|ja|en',
				'format'    => 'json',
			] );

			$body = $this->http_get( $url );
			if ( $body === '' ) continue;

			$json = json_decode( $body, true );
			foreach ( ( $json['entities'] ?? [] ) as $q => $ent ) {
				$labels = $ent['labels'] ?? [];
				foreach ( [ 'zh-tw', 'zh-hk', 'zh-hant', 'zh', 'ja', 'en' ] as $lang ) {
					$value = trim( (string) ( $labels[ $lang ]['value'] ?? '' ) );

					if ( $value === '' || ! $this->label_looks_sane( $value ) ) {
						continue;   // 換下一個語言，不要把壞資料當答案
					}

					$result[ $q ] = $value;
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * 判斷 Wikidata 標籤看起來是不是完整的。
	 *
	 * ★ 上游資料是眾人編輯的，偶爾會出現被截斷的標籤。實例:
	 *   Q11389400(マンガ大賞)的 zh-hant 標籤是
	 *   「全國書店員が選んだおすすめコミック）」——原文應為
	 *   「マンガ大賞（全国書店員が選んだおすすめコミック）」，
	 *   有人存成只剩括號後半段，尾巴多一個右括號。
	 *
	 *   語言優先序是 zh-tw → zh-hk → zh-hant → zh → …，因此這個壞標籤
	 *   會蓋掉排在後面、其實正確的 zh 標籤「日本書店店員票選推薦漫畫」，
	 *   最後印在漫畫頁的獲獎紀錄上。
	 *
	 *   這裡只做括號配對檢查:不配對就跳過該語言，讓後面的語言接手。
	 *   刻意不做「自動去掉多餘括號」——截斷的標籤補完括號仍然是殘缺的，
	 *   換一個語言拿到完整名稱才是對的。
	 *
	 * @param string $label 待檢查的標籤。
	 * @return bool 括號配對正常回傳 true。
	 */
	private function label_looks_sane( string $label ): bool {
		$pairs = [
			'（' => '）',
			'(' => ')',
			'「' => '」',
			'『' => '』',
			'【' => '】',
			'《' => '》',
		];

		foreach ( $pairs as $open => $close ) {
			if ( substr_count( $label, $open ) !== substr_count( $label, $close ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * HTTP GET,含退避重試(429/5xx 退避後重試,4xx 直接放棄)。
	 * ★改:補上退避重試,避免 WDQS/API 限流時整批失敗。
	 */
	private function http_get( string $url ): string {
		$backoffs = [ 0, 1, 3 ];
		foreach ( $backoffs as $wait ) {
			if ( $wait > 0 ) sleep( $wait );
			$resp = wp_remote_get( $url, [
				'timeout'    => self::HTTP_TIMEOUT,
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
