<?php
/**
 * Subview Routing — 作品頁的子檢視路由。
 *
 *   /anime/{slug}/music/       相關專輯（Bangumi）
 *   /anime/{slug}/games/       相關遊戲
 *   /anime/{slug}/liveaction/  真人版・改編
 *
 * 為什麼要拆：
 *
 *   實測孤獨搖滾動畫頁全部區塊合計 7,107px。主題曲播放器每張約 220px，
 *   火影 60 首就是 13,000px；專輯最多 133 張；真人版單獨也有 699px。
 *   把量體大又不是主要搜尋意圖的內容移到子頁，作品頁維持可讀長度。
 *
 *   主題曲留在作品頁——那是使用者最常找的東西，不該多一次點擊。
 *
 * ★ 兩個設計重點
 *
 *   1. rewrite 目標保留 anime 查詢變數：
 *        index.php?anime=$matches[1]&asp_subview=$matches[2]
 *      主查詢仍解析成該篇 anime，is_singular('anime') 為真，既有的
 *      條件式資源載入、麵包屑、Rank Math、留言全部照舊。
 *
 *   2. 不換模板。single-anime.php 自己判斷 current()，只把 <main>
 *      的內容換掉，Hero／導覽列／側欄照常輸出。這樣「直接輸入網址」
 *      與「站內 JS 抽換 #asd-main」看到的畫面完全一致，Hero 那
 *      一千多行也不必抽成 partial。
 *
 * Changelog:
 *   1.1.0 (2026-08-29) — 由 class-music-routing.php 改寫，
 *                        從只支援 music 擴充成三種子檢視。
 *   1.0.0 (2026-08-29) — 初版（music）。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Subview_Routing {

	const QV = 'asp_subview';

	/**
	 * slug => [ tab 標籤, 圖示, 頁面標題後綴 ]
	 *
	 * 陣列的 key 同時是網址片段、rewrite 白名單、tab 列的順序來源，
	 * 新增子檢視只要加一列。總覽（作品頁本身）不在這裡，它沒有 slug。
	 *
	 * 主題曲留在總覽（使用者指定）——音樂 tab 只放 Bangumi 的相關專輯。
	 */
	const VIEWS = [
		'characters' => [ 'label' => '角色', 'icon' => '🎭', 'title' => '角色・聲優一覽' ],
		'staff'      => [ 'label' => '製作', 'icon' => '🎬', 'title' => '製作人員一覽' ],
		'episodes'   => [ 'label' => '集數', 'icon' => '📺', 'title' => '集數列表' ],
		/* 標籤叫「專輯」不叫「音樂」——主題曲在總覽，這個 tab 只有專輯，
		   叫音樂會讓人以為主題曲也在裡面 */
		'music'      => [ 'label' => '專輯', 'icon' => '🎼', 'title' => '相關專輯一覽' ],
		'related'    => [ 'label' => '相關', 'icon' => '🔗', 'title' => '相關遊戲・真人版' ],
	];

	/*
	 * 評論不做成 tab（使用者指定放總覽）。
	 * 網址若已被人收藏或被搜尋引擎收錄，維持可用並導回作品頁，
	 * 不要直接變 404。
	 */
	const RETIRED_VIEWS = [ 'reviews', 'games', 'liveaction' ];

	public static function init() {
		add_action( 'init',             [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars',       [ __CLASS__, 'add_query_var' ] );
		add_action( 'template_redirect',  [ __CLASS__, 'redirect_retired' ], 1 );
		add_filter( 'template_include',   [ __CLASS__, 'maybe_404' ] );

		/* 子檢視要有自己的 <title>,否則幾個網址同標題等於重複內容 */
		add_filter( 'pre_get_document_title',   [ __CLASS__, 'filter_title' ], 99 );
		add_filter( 'rank_math/frontend/title', [ __CLASS__, 'filter_title' ], 99 );

		/*
		 * ★ 子檢視一律 canonical 指回作品頁 + noindex。
		 *
		 *   作品頁現在一次輸出所有面板的完整內容（見 single-anime.php
		 *   的 .asd-panel），子檢視網址只是「同一份內容、預設開哪個 tab」。
		 *   讓它們各自進索引等於自己跟自己競爭，還會把權重切成六份。
		 *
		 *   量測依據（正式站 1,742 部）：若讓子頁各自進索引，會產生
		 *   6,872 個網址，其中 2,743 個（40%）內容很薄——專輯頁有 66%
		 *   只有 1~4 張、相關頁有 72% 只有 1~2 部。這個站被判過薄內容，
		 *   不該再製造一批。
		 *
		 *   noindex 但 follow：連結權重照樣傳遞，使用者也照樣能用、能分享。
		 */
		add_filter( 'rank_math/frontend/canonical',   [ __CLASS__, 'filter_canonical' ], 99 );
		add_filter( 'rank_math/opengraph/url',        [ __CLASS__, 'filter_canonical' ], 99 );
		add_filter( 'rank_math/frontend/robots',      [ __CLASS__, 'filter_robots' ], 99 );
		add_filter( 'wp_robots',                      [ __CLASS__, 'filter_wp_robots' ], 99 );

		/*
		 * 結構化資料掛在「作品頁本身」，不是子檢視。
		 *
		 * 作品頁現在一次輸出角色／製作／集數／專輯／相關的完整內容，
		 * 所以那幾份 ItemList 也該掛在這裡——AI 引擎抓一個網址就能
		 * 同時拿到「這是什麼作品」與「有哪些聲優／製作／專輯」。
		 */
		add_filter( 'rank_math/json_ld', [ __CLASS__, 'filter_json_ld' ], 99, 2 );
	}

	/**
	 * canonical / og:url 指回作品頁本身。
	 */
	public static function filter_canonical( $canonical ) {
		$view = self::current();

		if ( '' === $view ) {
			return $canonical;
		}

		$url = get_permalink( get_queried_object_id() );

		return is_string( $url ) && '' !== $url ? $url : $canonical;
	}

	/**
	 * 子檢視 noindex, follow。
	 */
	public static function filter_robots( $robots ) {
		if ( '' === self::current() || ! is_array( $robots ) ) {
			return $robots;
		}

		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		return $robots;
	}

	/**
	 * 沒有 Rank Math 時的後備。
	 */
	public static function filter_wp_robots( $robots ) {
		if ( '' === self::current() || ! is_array( $robots ) ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;

		unset( $robots['index'] );

		return $robots;
	}

	/**
	 * 每個子檢視自己的 meta description。
	 *
	 * 六頁共用作品頁那份描述會被判定內容近似；而且對 AI 引擎來說，
	 * description 常常就是它引用時的摘要，寫得具體才有機會被採用。
	 */
	public static function filter_description( $desc ) {
		$view = self::current();

		if ( '' === $view ) {
			return $desc;
		}

		$post_id = get_queried_object_id();
		$name    = get_the_title( $post_id );

		if ( '' === $name ) {
			return $desc;
		}

		$counts = self::item_count( $post_id, $view );

		switch ( $view ) {
			case 'characters':
				return sprintf(
					'《%s》完整角色與聲優對照表，收錄 %d 位登場角色的中文名、日文原名與配音員資料，可查各角色的其他出演作品。',
					$name,
					$counts
				);

			case 'staff':
				return sprintf(
					'《%s》製作團隊一覽，收錄監督、腳本、人物設定、動畫製作等 %d 位工作人員名單與職位，可查各人的其他參與作品。',
					$name,
					$counts
				);

			case 'episodes':
				return sprintf(
					'《%s》全 %d 集分集列表，含各集標題、播出日期與劇情大綱。',
					$name,
					$counts
				);

			case 'music':
				return sprintf(
					'《%s》相關專輯一覽，收錄原聲帶、角色歌、廣播劇與主題曲單曲共 %d 張，依類型分組整理。',
					$name,
					$counts
				);

			case 'related':
				return sprintf(
					'《%s》衍生作品整理，包含改編遊戲、真人版電影、電視劇與舞台劇等 %d 部相關作品。',
					$name,
					$counts
				);
		}

		return $desc;
	}

	/**
	 * 這個子檢視有幾筆資料（給 description 與 ItemList 用）。
	 */
	private static function item_count( int $post_id, string $view ): int {
		switch ( $view ) {
			case 'characters':
				return count( self::json_meta( $post_id, 'anime_cast_json' ) );

			case 'staff':
				return count( self::json_meta( $post_id, 'anime_staff_json' ) );

			case 'episodes':
				return count( self::json_meta( $post_id, 'anime_episodes_json' ) );

			case 'music':
				if ( ! class_exists( 'Anime_Sync_Anime_Music_Data' ) ) {
					return 0;
				}

				$d = Anime_Sync_Anime_Music_Data::get( $post_id );

				return (int) $d['albums_total'];

			case 'related':
				if ( ! class_exists( 'Anime_Sync_Subject_Relations_Repository' ) ) {
					return 0;
				}

				$repo = new Anime_Sync_Subject_Relations_Repository();

				return $repo->count_by_type( $post_id, Anime_Sync_Subject_Relations_Repository::TYPE_GAME )
					+ $repo->count_by_type( $post_id, Anime_Sync_Subject_Relations_Repository::TYPE_REAL );
		}

		return 0;
	}

	/**
	 * 子檢視的結構化資料。
	 *
	 * 做兩件事：
	 *
	 *   1. 拿掉會重複的那些（FAQPage 等）——同一份 FAQ 在六個網址各輸出
	 *      一次是明確的重複訊號，只留在作品頁。
	 *   2. 補上這一頁真正的內容：ItemList（角色／製作／集數／專輯）
	 *      與 BreadcrumbList。
	 *
	 * ItemList 是 GEO/AEO 的重點——AI 引擎回答「XXX 有哪些聲優」時，
	 * 抓的就是這種清單式結構化資料，純 HTML 表格牠不一定解得出來。
	 *
	 * @param array $data  Rank Math 準備輸出的 schema
	 * @param mixed $jsonld
	 */
	public static function filter_json_ld( $data, $jsonld ) {
		if ( ! is_array( $data ) || ! is_singular( 'anime' ) ) {
			return $data;
		}

		/* 子檢視是 noindex 的同一份內容，schema 只掛作品頁一次 */
		if ( '' !== self::current() ) {
			return $data;
		}

		$post_id = get_queried_object_id();
		$name    = get_the_title( $post_id );
		$url     = get_permalink( $post_id );

		if ( '' === $name || ! is_string( $url ) || '' === $url ) {
			return $data;
		}

		/*
		 * 作品頁一次輸出所有面板，所以五份 ItemList 都掛在這裡。
		 * 只加真的有內容的那幾份，空清單對爬蟲沒有意義。
		 */
		foreach ( array_keys( self::VIEWS ) as $view ) {
			if ( ! self::has_content( $post_id, $view ) ) {
				continue;
			}

			$list = self::item_list_schema( $post_id, $view, $name, $url );

			if ( $list ) {
				$data[ 'asp_itemlist_' . $view ] = $list;
			}
		}

		return $data;
	}

	private static function breadcrumb_schema( int $post_id, string $view, string $name ): array {
		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => '首頁',
					'item'     => home_url( '/' ),
				],
				[
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => '動漫',
					'item'     => get_post_type_archive_link( 'anime' ) ?: home_url( '/anime/' ),
				],
				[
					'@type'    => 'ListItem',
					'position' => 3,
					'name'     => $name,
					'item'     => get_permalink( $post_id ),
				],
				[
					'@type'    => 'ListItem',
					'position' => 4,
					'name'     => self::VIEWS[ $view ]['label'],
					'item'     => self::url( $post_id, $view ),
				],
			],
		];
	}

	/**
	 * 各子檢視的 ItemList。列太長對爬蟲沒有額外好處，取前 50 筆。
	 */
	private static function item_list_schema( int $post_id, string $view, string $name, string $url ): array {
		$max      = 50;
		$elements = [];
		$title    = '';

		if ( 'characters' === $view ) {
			$title = $name . ' 角色・聲優一覽';

			foreach ( array_slice( self::json_meta( $post_id, 'anime_cast_json' ), 0, $max ) as $i => $c ) {
				if ( ! is_array( $c ) || '' === trim( (string) ( $c['name'] ?? '' ) ) ) {
					continue;
				}

				$va = '';

				if ( ! empty( $c['voice_actors'][0]['name'] ) ) {
					$va = (string) $c['voice_actors'][0]['name'];
				}

				$item = [
					'@type' => 'Person',
					'name'  => (string) $c['name'],
				];

				if ( '' !== $va ) {
					/* 角色由誰配音——AI 回答「誰配XXX」時要的就是這層關係 */
					$item['description'] = '配音：' . $va;
				}

				if ( ! empty( $c['image'] ) ) {
					$item['image'] = (string) $c['image'];
				}

				$elements[] = [
					'@type'    => 'ListItem',
					'position' => count( $elements ) + 1,
					'item'     => $item,
				];
			}
		} elseif ( 'staff' === $view ) {
			$title = $name . ' 製作人員一覽';

			foreach ( array_slice( self::json_meta( $post_id, 'anime_staff_json' ), 0, $max ) as $s ) {
				if ( ! is_array( $s ) || '' === trim( (string) ( $s['name'] ?? '' ) ) ) {
					continue;
				}

				$item = [
					'@type' => 'Person',
					'name'  => (string) $s['name'],
				];

				if ( ! empty( $s['role'] ) ) {
					$item['jobTitle'] = (string) $s['role'];
				}

				if ( ! empty( $s['image'] ) ) {
					$item['image'] = (string) $s['image'];
				}

				$elements[] = [
					'@type'    => 'ListItem',
					'position' => count( $elements ) + 1,
					'item'     => $item,
				];
			}
		} elseif ( 'episodes' === $view ) {
			$title = $name . ' 分集列表';

			foreach ( array_slice( self::json_meta( $post_id, 'anime_episodes_json' ), 0, $max ) as $e ) {
				if ( ! is_array( $e ) ) {
					continue;
				}

				$ep_name = trim( (string) ( $e['title'] ?? $e['name'] ?? '' ) );
				$ep_num  = (int) ( $e['number'] ?? $e['episode'] ?? 0 );

				if ( '' === $ep_name && $ep_num <= 0 ) {
					continue;
				}

				$item = [
					'@type' => 'TVEpisode',
					'name'  => '' !== $ep_name ? $ep_name : ( '第 ' . $ep_num . ' 集' ),
				];

				if ( $ep_num > 0 ) {
					$item['episodeNumber'] = $ep_num;
				}

				if ( ! empty( $e['airdate'] ) ) {
					$item['datePublished'] = (string) $e['airdate'];
				}

				$elements[] = [
					'@type'    => 'ListItem',
					'position' => count( $elements ) + 1,
					'item'     => $item,
				];
			}
		} elseif ( 'music' === $view ) {
			$title = $name . ' 相關專輯一覽';

			if ( class_exists( 'Anime_Sync_Anime_Music_Data' ) ) {
				$d = Anime_Sync_Anime_Music_Data::get( $post_id );

				foreach ( $d['albums'] as $group ) {
					foreach ( $group['items'] as $al ) {
						if ( count( $elements ) >= $max ) {
							break 2;
						}

						$elements[] = [
							'@type'    => 'ListItem',
							'position' => count( $elements ) + 1,
							'item'     => [
								'@type'      => 'MusicAlbum',
								'name'       => (string) $al['title'],
								'albumTypeOf' => (string) $group['label'],
							],
						];
					}
				}
			}
		} elseif ( 'related' === $view ) {
			$title = $name . ' 相關遊戲・真人版';

			if ( class_exists( 'Anime_Sync_Subject_Relations_Repository' ) ) {
				$repo = new Anime_Sync_Subject_Relations_Repository();

				$sets = [
					Anime_Sync_Subject_Relations_Repository::TYPE_GAME => 'VideoGame',
					Anime_Sync_Subject_Relations_Repository::TYPE_REAL => 'CreativeWork',
				];

				foreach ( $sets as $type => $schema_type ) {
					foreach ( $repo->get_grouped( $post_id, $type ) as $group ) {
						foreach ( $group['items'] as $it ) {
							if ( count( $elements ) >= $max ) {
								break 3;
							}

							$elements[] = [
								'@type'    => 'ListItem',
								'position' => count( $elements ) + 1,
								'item'     => [
									'@type' => $schema_type,
									'name'  => (string) $it['title'],
								],
							];
						}
					}
				}
			}
		}

		if ( ! $elements ) {
			return [];
		}

		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'@id'             => $url . '#itemlist-' . $view,
			'url'             => $url,
			'name'            => $title,
			'numberOfItems'   => count( $elements ),
			/* 這一頁是講哪部作品——把清單綁回作品，AI 才接得起來 */
			'about'           => [
				'@type' => 'TVSeries',
				'name'  => $name,
				'@id'   => get_permalink( $post_id ) . '#tvseries',
			],
			'itemListElement' => $elements,
		];
	}

	private static function json_meta( int $post_id, string $key ): array {
		$raw = get_post_meta( $post_id, $key, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * 必須用 'top'——否則會被 CPT 自己的 /anime/(.+?)/ 規則先吃掉，
	 * 子檢視片段會被當成文章 slug 的一部分而 404。
	 */
	public static function add_rewrite() {
		add_rewrite_rule(
			'^anime/([^/]+)/(' . implode( '|', array_keys( self::VIEWS ) ) . ')/?$',
			'index.php?anime=$matches[1]&' . self::QV . '=$matches[2]',
			'top'
		);

		/*
		 * 停用過的子檢視：曾經上線過的網址（/reviews/ 與早期的
		 * /games/ /liveaction/）改成 301 導回作品頁，不要留 404。
		 */
		add_rewrite_rule(
			'^anime/([^/]+)/(' . implode( '|', self::RETIRED_VIEWS ) . ')/?$',
			'index.php?anime=$matches[1]&' . self::QV . '=retired',
			'top'
		);
	}

	/**
	 * 停用網址導回作品頁。掛 template_redirect 才來得及送 301。
	 */
	public static function redirect_retired() {
		if ( 'retired' !== get_query_var( self::QV ) || ! is_singular( 'anime' ) ) {
			return;
		}

		$url = get_permalink( get_queried_object_id() );

		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::QV;

		return $vars;
	}

	/**
	 * 目前的子檢視 slug，不是子檢視就回空字串。
	 */
	public static function current(): string {
		if ( ! is_singular( 'anime' ) ) {
			return '';
		}

		$view = (string) get_query_var( self::QV );

		return isset( self::VIEWS[ $view ] ) ? $view : '';
	}

	/**
	 * 子檢視網址：/anime/{slug}/{view}/
	 *
	 * 用 get_permalink() 當基底而非自己拼字串，以後改了 CPT 的
	 * rewrite slug 也不會壞掉。
	 */
	public static function url( int $post_id, string $view ): string {
		if ( ! isset( self::VIEWS[ $view ] ) ) {
			return '';
		}

		$base = get_permalink( $post_id );

		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . $view . '/';
	}

	/**
	 * 這個子檢視有沒有內容。
	 *
	 * tab 列與 404 判斷共用同一個方法——沒有內容的 tab 不顯示，
	 * 直接輸入網址也回 404，兩邊不會不一致。
	 */
	public static function has_content( int $post_id, string $view ): bool {
		switch ( $view ) {
			/*
			 * ★ 用 postmeta 判斷，不要查 wp_anime_relations。
			 *   模板的 $cast_list / $staff_list 讀的是 anime_cast_json /
			 *   anime_staff_json；relations 表是實體頁（/character/ 等）
			 *   用的另一套資料，兩邊涵蓋範圍不同。查錯來源會出現
			 *   「作品頁看得到 CAST，tab 卻 404」。
			 */
			case 'characters':
				return self::has_json_meta( $post_id, 'anime_cast_json' );

			case 'staff':
				return self::has_json_meta( $post_id, 'anime_staff_json' );

			case 'episodes':
				return self::has_json_meta( $post_id, 'anime_episodes_json' );

			case 'music':
				if ( ! class_exists( 'Anime_Sync_Anime_Music_Data' ) ) {
					return false;
				}

				$data = Anime_Sync_Anime_Music_Data::get( $post_id );

				return ( (int) $data['albums_total'] ) > 0;

			case 'related':
				if ( ! class_exists( 'Anime_Sync_Subject_Relations_Repository' ) ) {
					return false;
				}

				$repo = new Anime_Sync_Subject_Relations_Repository();

				return $repo->count_by_type( $post_id, Anime_Sync_Subject_Relations_Repository::TYPE_GAME ) > 0
					|| $repo->count_by_type( $post_id, Anime_Sync_Subject_Relations_Repository::TYPE_REAL ) > 0;
		}

		return false;
	}

	/**
	 * 某個 JSON postmeta 有沒有實際內容。
	 * 空字串、'[]'、'{}' 與解不開的都算沒有。
	 */
	private static function has_json_meta( int $post_id, string $key ): bool {
		$raw = get_post_meta( $post_id, $key, true );

		if ( is_array( $raw ) ) {
			return ! empty( $raw );
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return false;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) && ! empty( $decoded );
	}

	/**
	 * 沒有內容就回 404，不要給一頁「目前沒有收錄」的空頁。
	 *
	 * 站上入口連結本來就只在有資料時才出現，使用者不會走到這裡；
	 * 但爬蟲會自己拼網址，回 200 的空頁等於 soft-404，
	 * 對一個已經被判定過薄內容的站是不必要的風險。
	 *
	 * 有內容時原樣回傳 $template——single-anime.php 會自己判斷要
	 * 渲染哪一種 <main>，不換模板。
	 */
	public static function maybe_404( $template ) {
		$view = self::current();

		if ( '' === $view ) {
			return $template;
		}

		if ( self::has_content( get_queried_object_id(), $view ) ) {
			return $template;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		return get_404_template() ?: $template;
	}

	/**
	 * 「作品名 相關專輯一覽｜站名」
	 *
	 * 子檢視的搜尋意圖跟作品頁不同（「XXX 原聲帶」「XXX 真人版」），
	 * 標題直接對上，不要沿用作品頁那組關鍵字。
	 */
	public static function filter_title( $title ) {
		$view = self::current();

		if ( '' === $view ) {
			return $title;
		}

		$name = get_the_title( get_queried_object_id() );

		if ( '' === $name ) {
			return $title;
		}

		return sprintf(
			'%s %s - %s',
			$name,
			self::VIEWS[ $view ]['title'],
			get_bloginfo( 'name' )
		);
	}
}
