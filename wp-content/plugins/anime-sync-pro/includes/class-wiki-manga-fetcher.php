<?php
/**
 * 檔案名稱: includes/class-wiki-manga-fetcher.php
 * 從中文維基百科漫畫條目「出版」章節解析每卷各地區發售日+ISBN,並抓 infobox fallback。
 * @package Anime_Sync_Pro
 * @version 2.1.0
 *
 * v2.1.0 (2026-07-17) ★修:
 *   1. clean_wikitext() 在 strip_tags 前先把 <br> 系列轉成分隔符 |||,避免多地區黏死。
 *   2. parse_infobox_manga() 出版社/雜誌改用地區拆分 split_regional_field();
 *      雜誌空值時 fallback 抓「網路」欄位;加 label 等無效參數黑名單;開始日改 開始日 優先。
 *   3. 新增 split_regional_field():依地區關鍵字+全半形分隔符,拆進 jp/tw/hk/cn。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Wiki_Manga_Fetcher {

	const ZH_WIKI_API  = 'https://zh.wikipedia.org/w/api.php';
	const USER_AGENT   = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com; anime-sync-pro)';
	const HTTP_TIMEOUT = 20;

	// ★改:漫畫子章節放最前(優先於含小說的母章節);補簡體/相关书籍/单行本等
	/**
	 * 單行本／卷數表格可能出現在哪些章節底下。
	 *
	 * 比對是精確比對（^== 章節名 ==$），因此每種寫法都要列出來。
	 * 迴圈取第一個「命中且內含表格」的章節，所以順序有意義:
	 * 越明確、越不會誤抓的寫法排前面。
	 *
	 * ★ 網路漫畫類（韓國網漫）放在最後。
	 *   實例:《我獨自升級》的卷數在「網路漫畫」章節（4,500 字，內含 16 個
	 *   {{Graphic novel list}}），但清單裡只有「漫畫」，精確比對對不上，
	 *   於是整篇的卷數、單行本、獲獎全部抓不到——維基狀態卻仍記為 ok，
	 *   因為 infobox 有解析成功。
	 *
	 * ★ 刻意不收「網路小說」。同一篇條目的「網路小說」章節同樣含表格，
	 *   收進來會把小說的卷數寫進漫畫欄位。原作是小說的作品很常見，
	 *   這個誤抓會安靜地發生而且很難察覺。
	 */
	private const PUBLISH_SECTION_TITLES = [
		'漫畫', '漫画',
		'出版書籍', '單行本一覽', '單行本', '单行本',
		'相關書籍', '相关书籍', '書籍', '书籍',
		'出版情況', '出版情况', '出版信息', '出版資訊',
		'發行情況', '發行情况', '發行', '发行', '發售', '出版',
		'網路漫畫', '網絡漫畫', '网络漫画', '網漫', '网漫',
	];

	public function fetch_by_title( string $title ): array {
		$title = trim( $title );
		if ( $title === '' ) return [];

		$out = [
			'volumes'          => [],
			'volumes_markdown' => '',
			'infobox'          => [],
			'raw_section_wt'   => '',
		];

		$full_wt = $this->fetch_wikitext( $title, null );
		if ( $full_wt === '' ) return $out;

		$out['infobox'] = $this->parse_infobox_manga( $full_wt );

		$section_wt = $this->extract_publish_section( $full_wt );
		if ( $section_wt === '' ) return $out;

		// {{:子頁}} transclusion
		if ( preg_match( '/\{\{:\s*([^}|]+)\}\}/', $section_wt, $mt ) ) {
			$sub_wt = $this->fetch_wikitext( trim( $mt[1] ), null );
			if ( $sub_wt !== '' ) $section_wt = $sub_wt;
		}

		$out['raw_section_wt']   = $section_wt;
		$out['volumes']          = $this->parse_publish_table( $section_wt );
		$out['volumes_markdown'] = $this->volumes_to_markdown( $out['volumes'] );

		return $out;
	}

	/**
	 * ★改:支援 == 二級 == 與 === 三級 === 標題;命中後必須內含表格
	 * ({| 或 Graphic novel list 或 {{:子頁}} transclusion) 才回傳,
	 * 否則繼續找下一個關鍵字。
	 */
	private function extract_publish_section( string $full_wt ): string {
		foreach ( self::PUBLISH_SECTION_TITLES as $needle ) {
			$pattern = '/^(={2,3})\s*' . preg_quote( $needle, '/' ) . '\s*\1\s*$'
			         . '(.*?)(?=^={2,3}\s*[^=\s]|\z)/sum';
			if ( preg_match( $pattern, $full_wt, $m ) ) {
				$body = $m[2];
				if ( strpos( $body, '{|' ) !== false
				  || strpos( $body, '{{Graphic novel list' ) !== false
				  || preg_match( '/\{\{:\s*[^}|]+\}\}/', $body ) ) {
					return $body;
				}
			}
		}
		return '';
	}


	private function fetch_wikitext( string $title, $section = null ): string {
		$params = [
			'action'        => 'parse',
			'page'          => $title,
			'prop'          => 'wikitext',
			'format'        => 'json',
			'formatversion' => 2,
			'redirects'     => 1,
		];
		if ( $section !== null ) $params['section'] = (int) $section;

		$body = $this->http_get( self::ZH_WIKI_API . '?' . http_build_query( $params ) );
		if ( $body === '' ) return '';
		$json = json_decode( $body, true );
		return (string) ( $json['parse']['wikitext'] ?? '' );
	}

	private function parse_infobox_manga( string $wikitext ): array {
		/*
		 * 取值前先拿掉引用註解。
		 *
		 * <ref>{{cite web |url=… |title=…}}</ref> 內部的 | 會被下面的參數
		 * 比對誤認成 infobox 的下一個參數，導致值在半途被截斷，留下
		 * 「{{ubl|…{{cite web」這種殘骸（實例：躲在超市後門抽菸的兩人）。
		 * 引用來源對 infobox 取值毫無用處，先清掉最單純。
		 */
		$wikitext = preg_replace( '/<ref[^>]*>.*?<\/ref>/su', '', $wikitext );
		$wikitext = preg_replace( '/<ref[^>]*\/>/u', '', $wikitext );

		$out = [
			'author'       => '',
			'publisher_jp' => '', 'publisher_tw' => '', 'publisher_hk' => '', 'publisher_cn' => '',
			'magazine'     => '',
			'start_date'   => '',
			'volumes_jp' => '', 'volumes_tw' => '', 'volumes_hk' => '', 'volumes_cn' => '',
		];

		if ( ! preg_match( '/\{\{Infobox animanga\/Manga(.*?)\}\}\s*(?:\{\{Infobox|$)/s', $wikitext, $m ) ) {
			$block = $wikitext;
		} else {
			$block = $m[1];
		}

		// ★改:無效參數黑名單(label/其他/data 等),避免抓到殘留參數當雜誌
		$blacklist = [ 'label', '其他', '其它', 'data', '備註', '备注', '說明', '说明' ];

		$get = function( array $keys ) use ( $block, $blacklist ) {
			foreach ( $keys as $k ) {
				if ( in_array( $k, $blacklist, true ) ) continue;
				if ( preg_match( '/\|\s*' . preg_quote( $k, '/' ) . '\s*=\s*([^\n]*)/u', $block, $mm ) ) {
					$v = trim( $mm[1] );
					// ★改:排除下一個參數名開頭(避免抓到空值後貼到別欄)
					if ( $v !== '' && $v !== '=' && strpos( $v, '|' ) !== 0 ) return $v;
				}
			}
			return '';
		};

		// ---- 作者 ----
		$out['author'] = $this->clean_wikitext( $get( [ '作者', '原作', '漫畫', 'author' ] ) );

		// ---- 出版社(★改:地區拆分) ----
		$pub_raw = $get( [ '出版社', '出版商', 'publisher' ] );
		if ( $pub_raw !== '' ) {
			$pub = $this->split_regional_field( $pub_raw );
			$out['publisher_jp'] = $pub['jp'];
			$out['publisher_tw'] = $pub['tw'];
			$out['publisher_hk'] = $pub['hk'];
			$out['publisher_cn'] = $pub['cn'];
		}

		// ---- 連載雜誌(★改:空值時 fallback 抓「網路」欄位) ----
		$mag_raw = $get( [ '連載雜誌', '雜誌', '发表期刊', 'magazine' ] );
		if ( $mag_raw === '' ) {
			$mag_raw = $get( [ '網路', '網絡', '网络', '连载网站' ] );
		}
		if ( $mag_raw !== '' ) {
			// 雜誌通常只取日本那段;若有地區前綴,拆完取 jp,沒有則取全部清乾淨
			$mag_split = $this->split_regional_field( $mag_raw );
			$out['magazine'] = $mag_split['jp'] !== ''
				? $mag_split['jp']
				: $this->clean_wikitext( $mag_raw );
		}

		// ---- 開始日(★改:開始日 優先) ----
		$out['start_date'] = $this->normalize_date(
			$this->clean_wikitext( $get( [ '開始日', '開始', '开始', '开始日', '發售日' ] ) )
		);

		// ---- 冊數(地區拆分) ----
		$vol_raw = $get( [ '冊數', '卷數', '册数' ] );
		if ( $vol_raw !== '' ) {
			$out['volumes_jp'] = $this->extract_volume_count( $vol_raw, [ '日本', 'Japan', 'JPN' ] );
			$out['volumes_tw'] = $this->extract_volume_count( $vol_raw, [ 'TWN', 'Taiwan', '臺灣', '台灣', '台湾' ] );
			$out['volumes_hk'] = $this->extract_volume_count( $vol_raw, [ 'HKG', 'HK', '香港' ] );
			$out['volumes_cn'] = $this->extract_volume_count( $vol_raw, [ '中國大陸', '中国大陆', 'CHN', 'China', '中國', '中国' ] );
		}
		return $out;
	}

	/**
	 * ★新增:多地區欄位拆分器。
	 * 輸入如「日本；集英社<br>臺灣：東立出版社<br>香港：玉皇朝」,
	 * (經 clean_wikitext 後 <br> 已變 |||),依地區關鍵字拆進 jp/tw/hk/cn。
	 * 無地區前綴時整段當 jp。
	 */
	private function split_regional_field( string $raw ): array {
		$out = [ 'jp' => '', 'tw' => '', 'hk' => '', 'cn' => '' ];

		/*
		 * 地區旗幟模板 → 地區名，必須趕在 clean_wikitext 之前。
		 * 那邊會把 {{JPN}}／{{TWN}} 這類模板整個移除，先轉成文字才留得住
		 * 地區資訊，下面的前綴比對也才有東西可認。
		 */
		$raw = preg_replace( '/\{\{\s*(?:JPN|Japan|日本)\s*\}\}/u',                          '日本',     $raw );
		$raw = preg_replace( '/\{\{\s*(?:TWN|ROC|臺灣|台灣|台湾|Taiwan)\s*\}\}/u',           '台灣',     $raw );
		$raw = preg_replace( '/\{\{\s*(?:HKG|HK|香港|Hong Kong)\s*\}\}/u',                   '香港',     $raw );
		$raw = preg_replace( '/\{\{\s*(?:CHNML|CHN|中國大陸|中国大陆|中國|中国|China)\s*\}\}/u', '中國大陸', $raw );

		$cleaned = $this->clean_wikitext( $raw ); // <br> 已轉 |||
		if ( $cleaned === '' ) return $out;

		// 依 ||| 或原始換行拆段
		$segments = preg_split( '/\s*\|\|\|\s*/u', $cleaned );
		$segments = array_filter( array_map( 'trim', $segments ), fn( $x ) => $x !== '' );

		$region_map = [
			'jp' => [ '日本', 'Japan', 'JPN' ],
			'tw' => [ '臺灣', '台灣', '台湾', 'Taiwan', 'TWN', 'ROC' ],
			'hk' => [ '香港', 'Hong Kong', 'HKG' ],
			'cn' => [ '中國大陸', '中国大陆', '中國', '中国', 'China', 'CHN' ],
		];

		$matched_any = false;
		foreach ( $segments as $seg ) {
			$hit = '';
			foreach ( $region_map as $reg => $needles ) {
				foreach ( $needles as $n ) {
					if ( strpos( $seg, $n ) === 0 ) { $hit = $reg; break 2; }
				}
			}
			if ( $hit === '' ) {
				// 無地區前綴:若還沒填 jp 就當 jp
				if ( $out['jp'] === '' ) { $out['jp'] = $seg; $matched_any = true; }
				continue;
			}
			// 去掉地區前綴 + 全半形分隔符(；：: ;)
			$val = preg_replace(
				'/^(?:日本|臺灣|台灣|台湾|Taiwan|TWN|ROC|香港|Hong Kong|HKG|中國大陸|中国大陆|中國|中国|China|CHN|Japan|JPN)\s*[；：:;、]?\s*/u',
				'',
				$seg
			);
			$val = trim( $val );
			if ( $val !== '' && $out[ $hit ] === '' ) {
				$out[ $hit ] = $val;
				$matched_any = true;
			}
		}

		// 完全沒命中地區:整段當 jp
		if ( ! $matched_any && $out['jp'] === '' ) {
			$out['jp'] = trim( str_replace( '|||', ' ', $cleaned ) );
		}
		return $out;
	}

	private function extract_volume_count( string $raw, array $needles ): string {
		foreach ( $needles as $n ) {
			if ( preg_match( '/' . preg_quote( $n, '/' ) . '[^\d]{0,10}(\d+)\s*[卷冊册]/u', $raw, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * 解析出版表。先試 GNL 樣板,否則解析 wikitable。
	 * ★v2.2.0 修:region_order 改用「表頭 colspan 標題的實際出現順序」判定,
	 *   不再寫死 [jp,tw,hk,cn],避免日港台/日台港欄序不同造成 tw/hk 對調。
	 */
	private function parse_publish_table( string $wt ): array {
		$gnl = $this->parse_graphic_novel_list( $wt );
		if ( ! empty( $gnl ) ) return $gnl;

		if ( ! preg_match( '/\{\|\s*class="?[^"\n]*wikitable[^"\n]*"?(.*?)\|\}/s', $wt, $m ) ) {
			return [];
		}
		$table = $m[1];
		$lines = preg_split( '/\r?\n/', $table );
		$nlines = count( $lines );

		// ---- ★v2.2.0:依表頭 colspan 標題出現順序建立 region_order ----
		// 掃描每一行的地區標題(含 Flagicon 或地區名/出版社名),依出現順序記地區。
		$region_order = [];
		$seen = [];
		foreach ( $lines as $ln ) {
			$lt = ltrim( $ln );
			// 只看含 colspan 或 Flagicon 的表頭欄(地區欄通常 colspan="2")
			if ( strpos( $lt, '!' ) !== 0 ) continue;
			if ( stripos( $lt, 'colspan' ) === false && stripos( $lt, 'Flagicon' ) === false ) continue;

			$reg = '';
			if ( preg_match( '/Flagicon\|\s*Japan|JPN|日本/u', $lt ) )                              $reg = 'jp';
			elseif ( preg_match( '/Flagicon\|\s*Hong Kong|HKG|香港|玉皇朝|天下出版/u', $lt ) )       $reg = 'hk';
			elseif ( preg_match( '/Flagicon\|\s*Taiwan|TWN|ROC|臺灣|台灣|台湾|東立|青文|尖端/u', $lt ) ) $reg = 'tw';
			elseif ( preg_match( '/Flagicon\|\s*China|CHNML|CHN|中國大陸|中国大陆|中國|中国/u', $lt ) )  $reg = 'cn';

			if ( $reg !== '' && empty( $seen[ $reg ] ) ) {
				$region_order[] = $reg;
				$seen[ $reg ]   = true;
			}
		}
		// fallback:表頭抓不到就沿用舊的整表偵測
		if ( empty( $region_order ) ) {
			$has_region = [ 'jp' => false, 'tw' => false, 'hk' => false, 'cn' => false ];
			if ( preg_match( '/(?:JPN|Japan|日本)/u', $table ) )                     $has_region['jp'] = true;
			if ( preg_match( '/(?:TWN|ROC|Taiwan|台灣|台湾|臺灣|東立)/u', $table ) ) $has_region['tw'] = true;
			if ( preg_match( '/(?:HKG|Hong Kong|香港)/u', $table ) )                 $has_region['hk'] = true;
			if ( preg_match( '/(?:CHNML|CHN|China|中國大陸|中国大陆|中國|中国)/u', $table ) ) $has_region['cn'] = true;
			foreach ( [ 'jp', 'tw', 'hk', 'cn' ] as $r ) {
				if ( $has_region[ $r ] ) $region_order[] = $r;
			}
			if ( empty( $region_order ) ) $region_order = [ 'jp', 'tw' ];
		}

		// ---- 逐列收集 cells ----
		$rows        = [];
		$current     = [];
		$current_vol = null;

		$flush = function() use ( &$rows, &$current, &$current_vol ) {
			if ( $current_vol !== null && ! empty( $current ) ) {
				$rows[] = [ 'vol' => $current_vol, 'cells' => $current ];
			}
			$current = [];
			$current_vol = null;
		};

		for ( $i = 0; $i < $nlines; $i++ ) {
			$line = rtrim( $lines[ $i ] );
			$lt   = ltrim( $line );
			if ( $lt === '' ) continue;

			if ( strpos( $lt, '|-' ) === 0 ) { $flush(); continue; }
			if ( strpos( $lt, '|}' ) === 0 ) { $flush(); break; }

			if ( $current_vol === null
			  && preg_match( '/^!\s*(?:(?:rowspan|colspan|scope|style|align)[^|]*\|\s*)?(\d+)\b/u', $lt, $mv ) ) {
				$current_vol = (int) $mv[1];
				$rest = preg_replace( '/^!\s*(?:(?:rowspan|colspan|scope|style|align)[^|]*\|\s*)?\d+\s*/u', '', $lt );
				if ( preg_match( '/^(?:!!|\|\|)/', $rest ) ) {
					$rest = preg_replace( '/^(?:!!|\|\|)\s*/', '', $rest );
					foreach ( preg_split( '/\s*(?:!!|\|\|)\s*/', $rest ) as $c ) {
						$current[] = $this->strip_cell_attrs( $c );
					}
				}
				continue;
			}

			if ( strpos( $lt, '!' ) === 0 ) {
				if ( $current_vol === null ) continue;
				$body = preg_replace( '/^!\s*/', '', $lt );
				foreach ( preg_split( '/\s*(?:!!|\|\|)\s*/', $body ) as $c ) {
					$current[] = $this->strip_cell_attrs( $c );
				}
				continue;
			}

			if ( strpos( $lt, '|' ) === 0 ) {
				$content = preg_replace( '/^\|+/', '', $lt );
				foreach ( preg_split( '/\s*\|\|\s*/', $content ) as $c ) {
					$current[] = $this->strip_cell_attrs( $c );
				}
			}
		}
		$flush();

		// ---- 每列:依 region_order 把「日期、ISBN」配到對應地區 ----
		$results = [];
		foreach ( $rows as $row ) {
			$dates = [];
			$isbns = [];
			$notes = [];
			foreach ( $row['cells'] as $cell ) {
				if ( $cell === null || $cell === '' ) continue;
				$isbn = $this->extract_isbn( $cell );
				if ( $isbn !== '' ) {
					$isbns[] = $isbn;
					$notes[] = $this->extract_note( $cell );
					continue;
				}
				$d = $this->normalize_date( $this->clean_wikitext( $cell ) );
				if ( preg_match( '/^\d{4}(?:-\d{2}){0,2}$/', $d ) ) {
					$dates[] = $d;
				}
			}

			$item = [ 'vol' => $row['vol'] ];
			foreach ( $region_order as $ri => $reg ) {
				$item[ $reg ] = [
					'date' => $dates[ $ri ] ?? '',
					'isbn' => $isbns[ $ri ] ?? '',
					'note' => $notes[ $ri ] ?? '',
				];
			}
			$results[] = $item;
		}
		return $results;
	}


	/**
	 * 剝掉 cell 開頭的 HTML 屬性(rowspan/colspan/style/align/scope),回傳內容部分。
	 */
	private function strip_cell_attrs( string $cell ): string {
		$cell = ltrim( $cell );
		if ( preg_match( '/^((?:rowspan|colspan|style|align|scope|class|width|bgcolor)[^|]*\|)\s*(.*)$/us', $cell, $m ) ) {
			return trim( $m[2] );
		}
		return trim( $cell );
	}

	private function parse_graphic_novel_list( string $wt ): array {
		if ( strpos( $wt, '{{Graphic novel list' ) === false ) return [];

		$region_slots = [ 'jp', 'tw', 'hk', 'cn' ];

		if ( preg_match( '/\{\{Graphic novel list\/header(?:ofja)?([^{}]*(?:\{\{[^{}]*\}\}[^{}]*)*)\}\}/', $wt, $mh ) ) {
			$hdr = $mh[1];
			$header_langs = [];
			foreach ( [ '語言', '語言2', '語言3', '語言4', 'Language', 'Language2' ] as $lk ) {
				if ( preg_match( '/\|\s*' . preg_quote( $lk, '/' ) . '\s*=\s*((?:[^|{}]|\{\{[^{}]*\}\})*)/u', $hdr, $mm ) ) {
					$header_langs[ $lk ] = trim( $mm[1] );
				}
			}
			$region_slots = [];
			foreach ( $header_langs as $lang ) {
				if ( preg_match( '/JPN|Japan|日本|日/u', $lang ) )                       $region_slots[] = 'jp';
				elseif ( preg_match( '/CHN|China|中國大陸|中国大陆|大陸|大陆/u', $lang ) ) $region_slots[] = 'cn';
				elseif ( preg_match( '/TWN|ROC|Taiwan|台灣|台湾|臺灣/u', $lang ) )         $region_slots[] = 'tw';
				elseif ( preg_match( '/HKG|Hong Kong|香港/u', $lang ) )                  $region_slots[] = 'hk';
				else                                                                    $region_slots[] = 'other';
			}
			if ( empty( $region_slots ) ) $region_slots = [ 'jp', 'tw', 'hk', 'cn' ];
		}

		$gnl_bodies = $this->extract_balanced_templates( $wt, 'Graphic novel list' );
		if ( empty( $gnl_bodies ) ) return [];

		$results = [];
		foreach ( $gnl_bodies as $body ) {
			$fields = $this->split_template_params( $body );

			$vol = (int) ( $fields['VolumeNumber'] ?? $fields['集數'] ?? $fields['卷數'] ?? 0 );
			if ( $vol <= 0 ) continue;

			$item = [ 'vol' => $vol ];

			if ( isset( $fields['OriginalRelDate'] ) || isset( $fields['OriginalISBN'] ) ) {
				$this->assign_gnl_slot( $item, $region_slots[0] ?? 'jp',
					$fields['OriginalRelDate'] ?? '', $fields['OriginalISBN'] ?? '' );
			}
			if ( isset( $fields['LicensedRelDate'] ) || isset( $fields['LicensedISBN'] ) ) {
				$this->assign_gnl_slot( $item, $region_slots[1] ?? 'tw',
					$fields['LicensedRelDate'] ?? '', $fields['LicensedISBN'] ?? '' );
			}

			foreach ( [ 0 => '', 1 => '2', 2 => '3', 3 => '4' ] as $i => $suffix ) {
				$dk = '發售日期' . $suffix;
				$ik = 'ISBN' . $suffix;
				if ( ! isset( $fields[ $dk ] ) && ! isset( $fields[ $ik ] ) ) continue;
				$region = $region_slots[ $i ] ?? 'other';
				$this->assign_gnl_slot( $item, $region, $fields[ $dk ] ?? '', $fields[ $ik ] ?? '' );
			}

			if ( count( $item ) > 1 ) $results[] = $item;
		}
		return $results;
	}

	private function extract_balanced_templates( string $wt, string $name ): array {
		$out = [];
		$len = strlen( $wt );
		$i   = 0;
		$needle_open = '{{' . $name;

		while ( ( $i = strpos( $wt, $needle_open, $i ) ) !== false ) {
			$after_name = $i + strlen( $needle_open );
			if ( $after_name < $len && $wt[ $after_name ] === '/' ) { $i = $after_name; continue; }
			if ( $after_name < $len && preg_match( '/[A-Za-z0-9]/', $wt[ $after_name ] ) ) { $i = $after_name; continue; }

			$depth = 1;
			$j     = $i + 2;
			while ( $j < $len - 1 && $depth > 0 ) {
				$two = substr( $wt, $j, 2 );
				if ( $two === '{{' )     { $depth++; $j += 2; }
				elseif ( $two === '}}' ) { $depth--; $j += 2; }
				else                     { $j++; }
			}
			if ( $depth !== 0 ) break;

			$out[] = substr( $wt, $after_name, ( $j - 2 ) - $after_name );
			$i     = $j;
		}
		return $out;
	}

	private function assign_gnl_slot( array &$item, string $region, string $date_raw, string $isbn_raw ): void {
		$date_raw = trim( $date_raw );
		$isbn_raw = trim( $isbn_raw );
		if ( $date_raw === '' && $isbn_raw === '' ) return;
		if ( $date_raw === '—' || $date_raw === '-' ) $date_raw = '';

		$item[ $region ] = [
			'date' => $this->normalize_date( $this->clean_wikitext( $date_raw ) ),
			'isbn' => $this->extract_isbn( $isbn_raw ) ?: $this->clean_wikitext( $isbn_raw ),
			'note' => $this->extract_note( $isbn_raw ),
		];
	}

	private function split_template_params( string $body ): array {
		$fields = [];
		$len    = strlen( $body );
		$parts  = [];
		$buf    = '';
		$depth  = 0;

		for ( $i = 0; $i < $len; $i++ ) {
			$c   = $body[ $i ];
			$two = substr( $body, $i, 2 );
			if ( $two === '{{' ) { $depth++; $buf .= '{{'; $i++; continue; }
			if ( $two === '}}' ) { $depth--; $buf .= '}}'; $i++; continue; }
			if ( $c === '[' && ( $body[ $i + 1 ] ?? '' ) === '[' ) { $depth++; $buf .= '[['; $i++; continue; }
			if ( $c === ']' && ( $body[ $i + 1 ] ?? '' ) === ']' ) { $depth--; $buf .= ']]'; $i++; continue; }
			if ( $c === '|' && $depth === 0 ) { $parts[] = $buf; $buf = ''; continue; }
			$buf .= $c;
		}
		if ( $buf !== '' ) $parts[] = $buf;

		foreach ( $parts as $p ) {
			$eq = strpos( $p, '=' );
			if ( $eq === false ) continue;
			$k = trim( substr( $p, 0, $eq ) );
			$v = trim( substr( $p, $eq + 1 ) );
			if ( $k === '' ) continue;
			$fields[ $k ] = $v;
		}
		return $fields;
	}

	private function extract_isbn( string $raw ): string {
		if ( preg_match( '/ISBN\s*([\d\-Xx]{10,20})/', $raw, $m ) ) {
			return trim( $m[1], '-' );
		}
		return '';
	}

	private function extract_note( string $raw ): string {
		if ( preg_match( '/\{\{small\|（([^）\}]+)）\}\}/u', $raw, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function normalize_date( string $raw ): string {
		if ( $raw === '' ) return '';
		if ( preg_match( '/(\d{4})年(\d{1,2})月(\d{1,2})日/u', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/(\d{4})年(\d{1,2})月/u', $raw, $m ) ) {
			return sprintf( '%04d-%02d', $m[1], $m[2] );
		}
		if ( preg_match( '/(\d{4})年/u', $raw, $m ) ) {
			return (string) $m[1];
		}
		return $raw;
	}

	private function clean_wikitext( string $s ): string {
		// ★改:strip_tags 前先把 <br> 系列轉成分隔符 |||,避免多地區黏死
		$s = preg_replace( '/<br\s*\/?>/i', '|||', $s );

		$s = preg_replace( '/<ref[^>]*>.*?<\/ref>/s', '', $s );
		$s = preg_replace( '/<ref[^>]*\/>/', '', $s );
		$s = preg_replace( '/<!--.*?-->/s', '', $s );

		$s = preg_replace_callback( '/\[\[([^\]|]+)\|([^\]]+)\]\]/', fn( $m ) => $m[2], $s );
		$s = preg_replace_callback( '/\[\[([^\]]+)\]\]/', fn( $m ) => $m[1], $s );

		$s = preg_replace( '/\{\{(?:flagicon|Flagicon|flag|Flag)\|[^}]+\}\}/', '', $s );
		$s = preg_replace( '/\{\{(?:TWN|HK|HKG|CHN|CHNML|ROC|JPN|Japan|日本|中國大陸|中国大陆|香港|台灣|台湾)\}\}/', '', $s );

		/*
		 * ubl / plainlist 是純排版模板，內容才是資料。
		 * 不先展開的話，下面的通用移除會把整組 {{ubl|A|B|C}} 連同 A B C
		 * 一起刪光。展開時把 | 轉成 |||，好讓 split_regional_field 拆得開。
		 */
		$s = preg_replace_callback(
			'/\{\{\s*(?:ubl|ublist|unbulleted list|plainlist)\s*\|((?:[^{}]|\{\{[^{}]*\}\})*)\}\}/iu',
			fn( $m ) => str_replace( '|', '|||', $m[1] ),
			$s
		);

		for ( $k = 0; $k < 3; $k++ ) {
			$s = preg_replace_callback(
				'/\{\{(?:nobr|nowrap|lang(?:x)?|small|Nihongo|nihongo|日文|隱藏)\|(?:[a-z-]+\|)?((?:[^{}]|\{\{[^{}]*\}\})*)\}\}/u',
				fn( $m ) => $m[1],
				$s
			);
		}

		for ( $k = 0; $k < 5; $k++ ) {
			$new = preg_replace( '/\{\{[^{}]*\}\}/', '', $s );
			if ( $new === $s ) break;
			$s = $new;
		}

		/*
		 * ★ 收尾保險：來源 wikitext 被截斷、或條目本身括號不成對時，
		 *   上面所有規則都依賴「模板有閉合」，會整段原封不動留下來，
		 *   使用者就會在前台看到 {{ubl|…{{cite web 這種原始標記。
		 *   實例：post 3753《躲在超市後門抽菸的兩人》的日本出版社欄位。
		 *
		 *   寧可少掉一些文字，也不要把 wiki 標記呈現出去。
		 */
		if ( false !== strpos( $s, '{{' ) ) {

			// 未閉合的排版模板：內容才是資料，展開而非丟棄（| 轉成 ||| 供拆分）
			$s = preg_replace_callback(
				'/\{\{\s*(?:ubl|ublist|unbulleted list|plainlist)\s*\|(.*)$/isu',
				static fn( $m ) => str_replace( '|', '|||', $m[1] ),
				$s
			);

			// 其餘未閉合模板：從 {{ 起丟到結尾。會走到這裡代表尾端本來就不完整。
			$s = preg_replace( '/\{\{.*$/su', '', $s );
			$s = str_replace( [ '{{', '}}' ], '', $s );
		}

		// 未閉合的內部連結同理
		if ( false !== strpos( $s, '[[' ) ) {
			$s = preg_replace( '/\[\[.*$/su', '', $s );
			$s = str_replace( [ '[[', ']]' ], '', $s );
		}

		$s = strip_tags( $s );
		// ★改:清掉分隔符旁多餘空白,但保留 ||| 分隔(供 split_regional_field 用)
		$s = preg_replace( '/[ \t]+/', ' ', $s );
		$s = preg_replace( '/\s*\|\|\|\s*/', '|||', $s );
		$s = trim( $s, " \t\n\r\0\x0B|" );
		return $s;
	}

	private function volumes_to_markdown( array $volumes ): string {
		if ( empty( $volumes ) ) return '';

		$regions = [];
		foreach ( [ 'jp', 'tw', 'hk', 'cn' ] as $r ) {
			foreach ( $volumes as $v ) {
				if ( ! empty( $v[ $r ]['date'] ) || ! empty( $v[ $r ]['isbn'] ) ) {
					$regions[] = $r; break;
				}
			}
		}
		if ( empty( $regions ) ) return '';

		$region_label = [ 'jp' => '日版', 'tw' => '台版', 'hk' => '港版', 'cn' => '陸版' ];

		$hdr = [ '卷' ];
		foreach ( $regions as $r ) {
			$hdr[] = $region_label[ $r ] . '發售日';
			$hdr[] = $region_label[ $r ] . ' ISBN';
		}
		$md  = '| ' . implode( ' | ', $hdr ) . " |\n";
		$md .= '|' . str_repeat( '---|', count( $hdr ) ) . "\n";

		foreach ( $volumes as $v ) {
			$row = [ (string) ( $v['vol'] ?? '' ) ];
			foreach ( $regions as $r ) {
				$row[] = $v[ $r ]['date'] ?? '';
				$isbn  = $v[ $r ]['isbn'] ?? '';
				$note  = $v[ $r ]['note'] ?? '';
				if ( $isbn !== '' && $note !== '' ) $isbn .= ' (' . $note . ')';
				$row[] = $isbn;
			}
			$md .= '| ' . implode( ' | ', $row ) . " |\n";
		}
		return $md;
	}

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
