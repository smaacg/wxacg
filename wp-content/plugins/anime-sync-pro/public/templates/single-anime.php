<?php
/**
 * Single Anime Template
 *
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-anime.php
 *
 * @version 15.8 — 2026-08-13
 *
 * 15.8:
 * - 修正角色/聲優連結網址雙重編碼（rawurlencode(sanitize_title()) → 單次編碼），
 *   避免 /character/{id}/%25e8... 這種亂碼網址
 *
 * 15.7:
 * - 管理員 thin 提示改為區分原因：已有短評但未指定審核者時，明確提示「需填審核者
 *   解除 noindex」，而非誤導的「缺乏編輯內容」（對齊 wxacg_is_thin_anime_page 邏輯）
 *
 * 15.6:
 * - 移除不完整的 trailer VideoObject（缺 uploadDate 會被 Search Console 判無效）；
 *   預告片改由嵌入的 YouTube iframe 讓 Google 直接偵測，避免結構化資料錯誤
 *
 * 15.5:
 * - 統一 YouTube 影片／播放清單解析，播放清單優先於影片
 * - 支援 youtube-nocookie.com、live、shorts 與 videoseries
 * - STAFF 排序及 JSON-LD 共用主要導演判斷
 * - 排除作畫、攝影、美術、音響、音樂等非主要導演職位
 * - 移除錯誤使用動畫首播日作為 YouTube uploadDate
 * - 補強 mainEntityOfPage、dateModified、isPartOf 等 Schema
 * - Template 優先使用全站 wxacg_is_thin_anime_page()
 * - YouTube iframe 改用隱私強化網域
 * - 保留 CAST／STAFF 實體連結、評分、追番、FAQ 與 JSON-LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	$post_id = get_the_ID();

	/* =========================================================
	 * Helpers
	 * ======================================================= */

	$get_meta = static function ( $key, $default = '' ) use ( $post_id ) {
		$value = get_post_meta( $post_id, $key, true );

		return ( $value === '' || $value === null )
			? $default
			: $value;
	};

	$decode_json = static function ( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	};

	$format_date = static function ( $raw ) {
		if ( empty( $raw ) ) {
			return '';
		}

		$raw = trim( (string) $raw );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw;
		}

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $matches ) ) {
			return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
		}

		$timestamp = strtotime( $raw );

		return $timestamp !== false
			? gmdate( 'Y-m-d', $timestamp )
			: '';
	};

	$starts_with = static function ( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;

		return $needle !== '' && strpos( $haystack, $needle ) === 0;
	};

	$substr_safe = static function ( $text, $start, $length = null ) {
		$text = (string) $text;

		if ( function_exists( 'mb_substr' ) ) {
			return $length === null
				? mb_substr( $text, $start, null, 'UTF-8' )
				: mb_substr( $text, $start, $length, 'UTF-8' );
		}

		return $length === null
			? substr( $text, $start )
			: substr( $text, $start, $length );
	};

	$strlen_safe = static function ( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strlen' )
			? mb_strlen( $text, 'UTF-8' )
			: strlen( $text );
	};

	$strpos_safe = static function ( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;

		if ( $needle === '' ) {
			return false;
		}

		return function_exists( 'mb_strpos' )
			? mb_strpos( $haystack, $needle, 0, 'UTF-8' )
			: strpos( $haystack, $needle );
	};

	$strtolower_safe = static function ( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $text, 'UTF-8' )
			: strtolower( $text );
	};

	$fallback_text = static function ( $text, $length = 2 ) use ( $substr_safe ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		return $text === ''
			? 'AN'
			: $substr_safe( $text, 0, $length );
	};

	$normalize_news_item = static function ( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$title = trim(
			(string) (
				$item['title']
				?? $item['name']
				?? $item['headline']
				?? ''
			)
		);

		$url = trim(
			(string) (
				$item['url']
					?? $item['link']
					?? ''
			)
		);

		if ( $title === '' ) {
			return null;
		}

		return [
			'title' => $title,
			'url'   => $url,
		];
	};

	/**
	 * 產生站內人物／角色詳情 URL。
	 *
	 * 路徑格式：
	 * /person/{id}/{slug}
	 * /character/{id}/{slug}
	 */
	$entity_url = static function ( $type, $id, $name ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return '';
		}

		$type = $type === 'character'
			? 'character'
			: 'person';

		$name = trim( (string) $name );

		// sanitize_title() 會先把中文名 percent-encode 成 %e8...，再 rawurlencode
		// 就會把 % 又編一次（%25e8...）造成網址雙重編碼。改為單次編碼，與
		// class-entity-repository.php 的 url_slug() 一致。
		$slug = $name !== ''
			? rawurlencode( str_replace( ' ', '-', $name ) )
			: '';

		$path = '/' . $type . '/' . $id;

		if ( $slug !== '' ) {
			$path .= '/' . $slug;
		}

		return home_url( $path );
	};

	/**
	 * 將配音觀看欄位解析成多個平台。
	 *
	 * 支援：
	 * 平台名稱|網址
	 * 網址
	 *
	 * 可使用逗號、頓號、分號或換行分隔。
	 */
	$parse_dub_urls = static function ( $raw, $default_label ) {
		$items = [];

		if ( empty( $raw ) ) {
			return $items;
		}

		$entries = preg_split(
			'/[,，、;；\r\n]+/u',
			(string) $raw
		);

		if ( ! is_array( $entries ) ) {
			return $items;
		}

		$seen = [];

		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );

			if ( $entry === '' ) {
				continue;
			}

			$label = '';
			$url   = $entry;

			if ( strpos( $entry, '|' ) !== false ) {
				$parts = array_map(
					'trim',
					explode( '|', $entry, 2 )
				);

				$label = $parts[0] ?? '';
				$url   = $parts[1] ?? '';
			}

			$url = trim( (string) $url );

			if ( $url === '' || ! wp_http_validate_url( $url ) ) {
				continue;
			}

			$unique_key = strtolower( $url );

			if ( isset( $seen[ $unique_key ] ) ) {
				continue;
			}

			$seen[ $unique_key ] = true;

			$items[] = [
				'label'     => $label !== ''
					? $label
					: $default_label,
				'url'       => $url,
				'has_label' => $label !== '',
			];
		}

		return $items;
	};

	/**
	 * 統一解析 YouTube 影片與播放清單。
	 *
	 * 播放清單必須優先於影片判斷，避免：
	 * watch?v=VIDEO_ID&list=PLAYLIST_ID
	 * 被錯誤視為單支影片。
	 */
	$parse_youtube_resource = static function ( $input, $allow_playlist = true ) {
		$input = trim(
			html_entity_decode(
				(string) $input,
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			)
		);

		if ( $input === '' ) {
			return null;
		}

		/*
		 * 容錯：貼上的內容可能夾雜 Markdown 連結或 Google 搜尋轉址格式，
		 * 例如「[https://youtu.be/xxx](https://www.google.com/search?q=https://youtu.be/xxx)」，
		 * 直接從整段文字裡抓出真正的 YouTube 網址再繼續往下解析，
		 * 避免因為外層包裝格式導致整支影片被判定無效而消失。
		 */
		if (
			preg_match(
				'#https?://(?:www\.)?(?:youtu\.be/[A-Za-z0-9_-]{11}|(?:m\.|music\.)?youtube(?:-nocookie)?\.com/[^\s\]\)]+)#i',
				$input,
				$embedded_youtube_url
			)
		) {
			$input = $embedded_youtube_url[0];
		}

		/*
		 * 純 11 碼 YouTube 影片 ID。
		 */
		if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $input ) ) {
			return [
				'type' => 'video',
				'id'   => $input,
			];
		}

		/*
		 * 容忍沒有 scheme 的 YouTube URL。
		 */
		if (
			preg_match(
				'#^(?:www\.)?(?:youtube\.com|m\.youtube\.com|music\.youtube\.com|youtube-nocookie\.com|youtu\.be)/#i',
				$input
			)
		) {
			$input = 'https://' . $input;
		}

		$url = wp_parse_url( $input );

		if ( ! is_array( $url ) ) {
			return null;
		}

		$host = strtolower(
			(string) ( $url['host'] ?? '' )
		);

		$host = preg_replace( '/^www\./', '', $host );

		$youtube_hosts = [
			'youtube.com',
			'm.youtube.com',
			'music.youtube.com',
			'youtube-nocookie.com',
			'youtu.be',
		];

		if ( ! in_array( $host, $youtube_hosts, true ) ) {
			return null;
		}

		$query = [];

		if ( ! empty( $url['query'] ) ) {
			parse_str(
				(string) $url['query'],
				$query
			);
		}

		/*
		 * 播放清單優先。
		 */
		if (
			$allow_playlist
			&& ! empty( $query['list'] )
		) {
			$playlist_id = preg_replace(
				'/[^A-Za-z0-9_-]/',
				'',
				(string) $query['list']
			);

			if ( $playlist_id !== '' ) {
				return [
					'type' => 'playlist',
					'id'   => $playlist_id,
				];
			}
		}

		$path = trim(
			(string) ( $url['path'] ?? '' ),
			'/'
		);

		if ( $host === 'youtu.be' ) {
			$path_parts = explode( '/', $path );
			$video_id   = $path_parts[0] ?? '';

			if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $video_id ) ) {
				return [
					'type' => 'video',
					'id'   => $video_id,
				];
			}

			return null;
		}

		if (
			! empty( $query['v'] )
			&& preg_match(
				'/^[A-Za-z0-9_-]{11}$/',
				(string) $query['v']
			)
		) {
			return [
				'type' => 'video',
				'id'   => (string) $query['v'],
			];
		}

		if (
			preg_match(
				'#^(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})(?:/|$)#',
				$path,
				$matches
			)
		) {
			return [
				'type' => 'video',
				'id'   => $matches[1],
			];
		}

		return null;
	};

	/**
	 * 判斷 STAFF 職位是否為作品主要導演。
	 *
	 * 這個 Helper 同時供 STAFF 排序及 JSON-LD 使用，
	 * 避免畫面與結構化資料採用不同標準。
	 */
	$is_main_director_role = static function ( $role ) {
		$role = trim(
			wp_strip_all_tags(
				(string) $role
			)
		);

		if ( $role === '' ) {
			return false;
		}

		$excluded_pattern =
			'/副導演|副导演|助理導演|助理导演|助監督|助监督|'
			. '副監督|副监督|監督補佐|监督补佐|'
			. '作畫監督|作画監督|動畫監督|动画监督|'
			. '美術監督|美术监督|攝影監督|摄影监督|'
			. '音響監督|音响监督|音樂監督|音乐监督|'
			. '3D監督|3D监督|CG監督|CG监督|'
			. 'アニメーション監督|エピソードディレクター|'
			. 'episode\s*director|assistant\s*director|'
			. 'animation\s*director|art\s*director|'
			. 'sound\s*director|music\s*director|'
			. 'director\s+of\s+photography|'
			. 'photography\s*director/iu';

		if ( preg_match( $excluded_pattern, $role ) ) {
			return false;
		}

		$director_pattern =
			'/(?:^|[\s\/／、,，;；・])'
			. '(?:總導演|总导演|導演|导演|'
			. '總監督|总监督|総監督|監督|'
			. 'director|series\s*director|chief\s*director)'
			. '(?=$|[\s\/／、,，;；・])/iu';

		return (bool) preg_match(
			$director_pattern,
			$role
		);
	};

	$json_ld_flags =
		JSON_UNESCAPED_UNICODE
		| JSON_UNESCAPED_SLASHES
		| JSON_HEX_TAG
		| JSON_HEX_AMP
		| JSON_HEX_APOS
		| JSON_HEX_QUOT;

	/* =========================================================
	 * 基本 Meta
	 * ======================================================= */

	$anilist_id = (int) $get_meta( 'anime_anilist_id', 0 );
	$mal_id     = (int) $get_meta( 'anime_mal_id', 0 );
	$bangumi_id = (int) $get_meta( 'anime_bangumi_id', 0 );

	if ( ! $bangumi_id ) {
		$bangumi_id = (int) $get_meta( 'bangumi_id', 0 );
	}

	$title_chinese    = trim( (string) $get_meta( 'anime_title_chinese' ) );
	$title_simplified = trim( (string) $get_meta( 'anime_title_simplified' ) );
	$title_native     = trim( (string) $get_meta( 'anime_title_native' ) );
	$title_romaji     = trim( (string) $get_meta( 'anime_title_romaji' ) );
	$title_english    = trim( (string) $get_meta( 'anime_title_english' ) );
	$display_title    = $title_chinese ?: get_the_title();

	$format      = trim( (string) $get_meta( 'anime_format' ) );
	$status      = trim( (string) $get_meta( 'anime_status' ) );
	$season      = trim( (string) $get_meta( 'anime_season' ) );
	$season_year = (int) $get_meta( 'anime_season_year', 0 );
	$episodes    = (int) $get_meta( 'anime_episodes', 0 );
	$ep_aired    = (int) $get_meta( 'anime_episodes_aired', 0 );
	$duration    = (int) $get_meta( 'anime_duration', 0 );
	$source      = trim( (string) $get_meta( 'anime_source' ) );
	$studio      = trim( (string) $get_meta( 'anime_studios' ) );
	$popularity  = (int) $get_meta( 'anime_popularity', 0 );

	$is_not_aired = $status === 'NOT_YET_RELEASED';

	/* =========================================================
	 * 台灣串流、代理與配音
	 * ======================================================= */

	$tw_streaming_raw    = $get_meta( 'anime_tw_streaming' );
	$tw_streaming_other  = $get_meta( 'anime_tw_streaming_other' );
	$tw_distributor      = trim( (string) $get_meta( 'anime_tw_distributor' ) );
	$tw_dist_custom      = trim( (string) $get_meta( 'anime_tw_distributor_custom' ) );
	$tw_broadcast        = trim( (string) $get_meta( 'anime_tw_broadcast' ) );
	$tw_no_stream_google = trim( (string) $get_meta( 'anime_no_streaming_google' ) );

	$dub_raw = $get_meta( 'anime_dub_language' );

	$dub_arr = is_array( $dub_raw )
		? $dub_raw
		: ( $dub_raw ? [ $dub_raw ] : [] );

	$dub_arr = array_values(
		array_unique(
			array_filter(
				array_map(
					static function ( $value ) {
						return trim( (string) $value );
					},
					$dub_arr
				)
			)
		)
	);

	$dub_labels = [
		'mandarin' => '國語配音',
		'taigi'    => '台語配音',
	];

	$dub_display = [];

	foreach ( $dub_arr as $dub_language ) {
		if ( isset( $dub_labels[ $dub_language ] ) ) {
			$dub_display[] = $dub_labels[ $dub_language ];
		}
	}

	$dub_url_taigi = trim(
		(string) $get_meta( 'anime_dub_url_taigi' )
	);

	$dub_url_mandarin = trim(
		(string) $get_meta( 'anime_dub_url_mandarin' )
	);

	$has_streaming_registry = class_exists(
		'Anime_Sync_Streaming_Registry'
	);

	$tw_stream_url_map = [];

	if ( $has_streaming_registry ) {
		$registry_platforms = Anime_Sync_Streaming_Registry::all();

		if ( is_array( $registry_platforms ) ) {
			foreach ( $registry_platforms as $platform ) {
				if ( ! is_array( $platform ) ) {
					continue;
				}

				$platform_key = trim(
					(string) ( $platform['key'] ?? '' )
				);

				if ( $platform_key === '' ) {
					continue;
				}

				$tw_stream_url_map[ $platform_key ] = trim(
					(string) $get_meta(
						'anime_tw_streaming_url_' . $platform_key
					)
				);
			}
		}
	}

	/*
	 * 代理商顯示名稱。清單的唯一來源是 class-distributor-registry.php——
	 * 這裡原本是複製的第三份，新增代理商時漏改就會顯示成英文代碼
	 * （下方有 `?? $tw_distributor` 的 fallback，不會報錯，很難察覺）。
	 */
	$tw_dist_labels = class_exists( 'Anime_Sync_Distributor_Registry' )
		? Anime_Sync_Distributor_Registry::get_labels()
		: [];

	$tw_dist_display = '';

	if ( $tw_distributor === 'other' ) {
		$tw_dist_display = $tw_dist_custom;
	} elseif ( $tw_distributor !== '' ) {
		$tw_dist_display =
			$tw_dist_labels[ $tw_distributor ]
			?? $tw_distributor;
	}

	$provider_icon_base = trailingslashit(
		ANIME_SYNC_PRO_URL . 'public/assets/img/providers'
	);

	$provider_icon_map = $has_streaming_registry
		? Anime_Sync_Streaming_Registry::get_icon_map_flat()
		: [];

	$provider_icon_map = is_array( $provider_icon_map )
		? $provider_icon_map
		: [];

	$tw_stream_labels = $has_streaming_registry
		? Anime_Sync_Streaming_Registry::get_acf_choices()
		: [];

	$tw_stream_labels = is_array( $tw_stream_labels )
		? $tw_stream_labels
		: [];

	$tw_stream_legacy_aliases = [
		'ani-one'  => 'ani_one',
		'myVideo'  => 'myvideo',
		'my_video' => 'myvideo',
		'line_tv'  => 'linetv',
	];

	$streaming_list = $decode_json(
		$get_meta( 'anime_streaming' )
	);

	$tw_streaming_items = [];
	$tw_streaming_keys  = [];

	if ( ! empty( $tw_streaming_raw ) ) {
		$raw_platforms = is_array( $tw_streaming_raw )
			? $tw_streaming_raw
			: [ $tw_streaming_raw ];

		foreach ( $raw_platforms as $platform_key ) {
			$platform_key = trim( (string) $platform_key );

			if ( isset( $tw_stream_legacy_aliases[ $platform_key ] ) ) {
				$platform_key =
					$tw_stream_legacy_aliases[ $platform_key ];
			}

			if (
				$platform_key === ''
				|| isset( $tw_streaming_keys[ $platform_key ] )
			) {
				continue;
			}

			$label = $tw_stream_labels[ $platform_key ]
				?? $platform_key;

			$tw_streaming_keys[ $platform_key ] = true;

			$stream_url = trim(
				(string) (
					$tw_stream_url_map[ $platform_key ]
					?? ''
				)
			);

			if (
				$stream_url !== ''
				&& ! wp_http_validate_url( $stream_url )
			) {
				$stream_url = '';
			}

			$tw_streaming_items[] = [
				'key'       => $platform_key,
				'label'     => $label,
				'url'       => $stream_url,
				'icon_url'  => isset( $provider_icon_map[ $platform_key ] )
					? $provider_icon_base . $provider_icon_map[ $platform_key ]
					: '',
				'icon_only' => false,
			];
		}
	}

	if ( $tw_streaming_other ) {
		$other_platforms = preg_split(
			'/[,，、;；\r\n]+/u',
			(string) $tw_streaming_other
		);

		$other_platforms = is_array( $other_platforms )
			? $other_platforms
			: [];

		foreach ( $other_platforms as $extra_platform ) {
			$extra_platform = trim( (string) $extra_platform );

			if ( $extra_platform === '' ) {
				continue;
			}

			$tw_streaming_items[] = [
				'key'       => '',
				'label'     => $extra_platform,
				'url'       => '',
				'icon_url'  => '',
				'icon_only' => false,
			];
		}
	}

	/* =========================================================
	 * 舊版／新版串流資料相容
	 * ======================================================= */

	$streaming_flat = [];

	if (
		isset( $streaming_list['taiwan'] )
		|| isset( $streaming_list['overseas'] )
	) {
		$streaming_flat = array_merge(
			is_array( $streaming_list['taiwan'] ?? null )
				? $streaming_list['taiwan']
				: [],
			is_array( $streaming_list['overseas'] ?? null )
				? $streaming_list['overseas']
				: []
		);
	} else {
		foreach ( $streaming_list as $streaming_item ) {
			if (
				is_array( $streaming_item )
				&& isset( $streaming_item['site'] )
			) {
				$streaming_flat[] = $streaming_item;
			}
		}
	}

	$overseas_streams = [];

	if (
		isset( $streaming_list['overseas'] )
		&& is_array( $streaming_list['overseas'] )
	) {
		$overseas_streams = $streaming_list['overseas'];
	} else {
		$overseas_sites = [
			'crunchyroll',
			'funimation',
			'hidive',
			'vrv',
			'hulu',
			'wakanim',
		];

		foreach ( $streaming_flat as $streaming_item ) {
			if ( ! is_array( $streaming_item ) ) {
				continue;
			}

			$streaming_site = strtolower(
				trim(
					(string) (
						$streaming_item['site']
							?? ''
					)
				)
			);

			if ( in_array( $streaming_site, $overseas_sites, true ) ) {
				$overseas_streams[] = $streaming_item;
			}
		}
	}

	/* =========================================================
	 * 配音觀看平台
	 * ======================================================= */

	$dub_items_taigi = in_array( 'taigi', $dub_arr, true )
		? $parse_dub_urls( $dub_url_taigi, '台語配音' )
		: [];

	$dub_items_mandarin = in_array( 'mandarin', $dub_arr, true )
		? $parse_dub_urls( $dub_url_mandarin, '國語配音' )
		: [];

	$dub_items_all = array_merge(
		$dub_items_taigi,
		$dub_items_mandarin
	);

	/* Anime_Sync_Streaming_Registry 裡 YouTube 的 match 陣列刻意留空
	 * (避免 AniList 自動同步時把一般 YouTube 連結誤收進台灣/海外平台清單)，
	 * 但配音區塊(dub_items_all)本來就是後台手動指定的 YouTube 連結，
	 * 這裡單獨判斷網域來解析圖示/標籤，不動 registry、不影響同步行為。 */
	$is_youtube_host = static function ( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return $host === 'youtube.com'
			|| $host === 'youtu.be'
			|| str_ends_with( $host, '.youtube.com' )
			|| str_ends_with( $host, '.youtu.be' );
	};

	$resolve_dub_platform = static function ( $url ) use (
		$has_streaming_registry,
		$provider_icon_map,
		$provider_icon_base,
		$is_youtube_host
	) {
		if (
			! $has_streaming_registry
			|| empty( $url )
		) {
			return [
				'label' => '',
				'icon'  => '',
			];
		}

		$matched_key = Anime_Sync_Streaming_Registry::match_site(
			'',
			$url
		);

		if ( ! $matched_key && $is_youtube_host( $url ) ) {
			$matched_key = 'youtube';
		}

		if ( ! $matched_key ) {
			return [
				'label' => '',
				'icon'  => '',
			];
		}

		$choices = Anime_Sync_Streaming_Registry::get_acf_choices();
		$choices = is_array( $choices ) ? $choices : [];

		return [
			'label' => $choices[ $matched_key ] ?? '',
			'icon'  => isset( $provider_icon_map[ $matched_key ] )
				? $provider_icon_base . $provider_icon_map[ $matched_key ]
				: '',
		];
	};

	foreach ( $dub_items_all as &$dub_item_reference ) {
		if ( empty( $dub_item_reference['has_label'] ) ) {
			$resolved_platform = $resolve_dub_platform(
				$dub_item_reference['url']
			);

			if ( $resolved_platform['label'] !== '' ) {
				$dub_item_reference['label'] =
					$resolved_platform['label'];
			}

			if ( $resolved_platform['icon'] !== '' ) {
				$dub_item_reference['icon'] =
					$resolved_platform['icon'];
			}
		}
	}
	unset( $dub_item_reference );

	$guess_dub_icon = static function ( $label, $url ) use (
		$has_streaming_registry,
		$provider_icon_map,
		$provider_icon_base,
		$strtolower_safe,
		$is_youtube_host
	) {
		if ( $has_streaming_registry && $url ) {
			$matched_key = Anime_Sync_Streaming_Registry::match_site(
				$label,
				$url
			);

			if ( ! $matched_key && $is_youtube_host( $url ) ) {
				$matched_key = 'youtube';
			}

			if (
				$matched_key
				&& isset( $provider_icon_map[ $matched_key ] )
			) {
				return $provider_icon_base
					. $provider_icon_map[ $matched_key ];
			}
		}

		$label_lower = $strtolower_safe( $label );

		if (
			strpos( $label_lower, '巴哈' ) !== false
			|| strpos( $label_lower, '動畫瘋' ) !== false
		) {
			return $provider_icon_base . 'anigamer_icon.webp';
		}

		if ( strpos( $label_lower, 'ofiii' ) !== false ) {
			return $provider_icon_base . 'ofiii_icon.webp';
		}

		if (
			strpos( $label_lower, 'linetv' ) !== false
			|| strpos( $label_lower, 'line tv' ) !== false
		) {
			return $provider_icon_base . 'linetv_icon.webp';
		}

		if ( strpos( $label_lower, '公視' ) !== false ) {
			return $provider_icon_base . 'channels4.webp';
		}

		return '';
	};

	$has_any_stream =
		! empty( $tw_streaming_items )
		|| ! empty( $overseas_streams )
		|| ! empty( $dub_items_all );

	$has_tw_stream =
		! empty( $tw_streaming_items )
		|| ! empty( $dub_items_all );

	/*
	 * 台灣查無上架平台時的 Google 搜尋連結。
	 *
	 * 人工在 ACF 填了就用填的；留空則以作品名稱自動組一組搜尋連結——
	 * 這是該欄位說明一直寫著「留空的話，前台可自動用作品名稱組出
	 * 搜尋連結」但先前並未實作的行為。
	 *
	 * 未播出作品不自動組：作品還沒開播，「線上看」搜尋只會導向盜版或
	 * 空結果，也給讀者錯誤期待。已人工填連結者不受此限，尊重人工判斷。
	 */
	$google_search_url = '';

	if ( ! $has_tw_stream ) {
		if ( $tw_no_stream_google !== '' && wp_http_validate_url( $tw_no_stream_google ) ) {
			$google_search_url = $tw_no_stream_google;
		} elseif ( ! $is_not_aired ) {
			$google_search_url = 'https://www.google.com/search?q='
				. urlencode( $display_title . ' 線上看' );
		}
	}

	$has_stream_section =
		$has_any_stream
		|| $google_search_url !== '';

	/* =========================================================
	 * 日期、分數與圖片
	 * ======================================================= */

	$start_date = $format_date(
		$get_meta( 'anime_start_date' )
	);

	$end_date = $format_date(
		$get_meta( 'anime_end_date' )
	);

	$score_anilist_raw = $get_meta( 'anime_score_anilist' );
	$score_anilist_num = is_numeric( $score_anilist_raw )
		? (float) $score_anilist_raw
		: 0;

	$score_anilist = $score_anilist_num > 0
		? number_format( $score_anilist_num / 10, 1 )
		: '';

	$score_mal_raw = $get_meta( 'anime_score_mal' );
	$score_mal_num = is_numeric( $score_mal_raw )
		? (float) $score_mal_raw
		: 0;

	$score_mal = $score_mal_num > 0
		? number_format( $score_mal_num / 10, 1 )
		: '';

	$score_bangumi_raw = $get_meta( 'anime_score_bangumi' );
	$score_bangumi_num = is_numeric( $score_bangumi_raw )
		? (float) $score_bangumi_raw
		: 0;

	$score_bangumi = $score_bangumi_num > 0
		? number_format( $score_bangumi_num / 10, 1 )
		: '';

	$cover_image = trim(
		(string) $get_meta( 'anime_cover_image' )
	);

	$banner_image = trim(
		(string) $get_meta( 'anime_banner_image' )
	);

	$trailer_url = trim(
		(string) $get_meta( 'anime_trailer_url' )
	);

	$online_watch_raw = trim(
		(string) $get_meta( 'anime_online_watch' )
	);

	/* =========================================================
	 * 解析多支 PV
	 * ======================================================= */

	$trailer_items = [];
	$trailer_seen  = [];

	if ( $trailer_url !== '' ) {
		$trailer_entries = preg_split(
			'/[,，、;；\r\n]+/u',
			$trailer_url
		);

		$trailer_entries = is_array( $trailer_entries )
			? $trailer_entries
			: [];

		foreach ( $trailer_entries as $trailer_entry ) {
			$trailer_entry = trim( (string) $trailer_entry );

			if ( $trailer_entry === '' ) {
				continue;
			}

			$custom_label = '';

			if ( strpos( $trailer_entry, '|' ) !== false ) {
				$parts = array_map(
					'trim',
					explode( '|', $trailer_entry, 2 )
				);

				$first  = $parts[0] ?? '';
				$second = $parts[1] ?? '';

				/*
				 * 同時相容 URL|標題及標題|URL。
				 */
				if (
					preg_match(
						'#^(?:https?://|(?:www\.)?(?:youtube\.com|youtu\.be)|[A-Za-z0-9_-]{11}$)#i',
						$first
					)
				) {
					$trailer_entry = $first;
					$custom_label  = $second;
				} else {
					$custom_label  = $first;
					$trailer_entry = $second;
				}
			}

			$youtube_resource = $parse_youtube_resource(
				$trailer_entry,
				false
			);

			$video_id = (
				is_array( $youtube_resource )
				&& ( $youtube_resource['type'] ?? '' ) === 'video'
			)
				? (string) ( $youtube_resource['id'] ?? '' )
				: '';

			if (
				$video_id === ''
				|| isset( $trailer_seen[ $video_id ] )
			) {
				continue;
			}

			$trailer_seen[ $video_id ] = true;
			$trailer_index             = count( $trailer_items ) + 1;

			$trailer_items[] = [
				'id'    => $video_id,
				'label' => $custom_label !== ''
					? $custom_label
					: 'PV ' . $trailer_index,
			];
		}
	}

	$youtube_id = ! empty( $trailer_items )
		? $trailer_items[0]['id']
		: '';

	$has_trailer = ! empty( $trailer_items );

	/* =========================================================
	 * 解析線上看 YouTube 影片／播放清單
	 * ======================================================= */

	$online_watch_items = [];
	$online_watch_seen  = [];

	if ( $online_watch_raw !== '' ) {
		$online_entries = preg_split(
			'/[,，、;；\r\n]+/u',
			$online_watch_raw
		);

		$online_entries = is_array( $online_entries )
			? $online_entries
			: [];

		foreach ( $online_entries as $online_entry ) {
			$online_entry = trim( (string) $online_entry );

			if ( $online_entry === '' ) {
				continue;
			}

			$online_label = '';
			$online_url   = $online_entry;

			if ( strpos( $online_entry, '|' ) !== false ) {
				$parts = array_map(
					'trim',
					explode( '|', $online_entry, 2 )
				);

				$first  = $parts[0] ?? '';
				$second = $parts[1] ?? '';

				if (
					preg_match(
						'#^(?:https?://|(?:www\.)?(?:youtube\.com|youtu\.be)|[A-Za-z0-9_-]{11}$)#i',
						$first
					)
				) {
					$online_url   = $first;
					$online_label = $second;
				} else {
					$online_label = $first;
					$online_url   = $second;
				}
			}

			$online_resource = $parse_youtube_resource(
				$online_url,
				true
			);

			$online_id = is_array( $online_resource )
				? (string) ( $online_resource['id'] ?? '' )
				: '';

			$online_type = is_array( $online_resource )
				? (string) ( $online_resource['type'] ?? 'video' )
				: 'video';

			if ( $online_id === '' ) {
				continue;
			}

			$online_unique_key =
				$online_type . ':' . $online_id;

			if ( isset( $online_watch_seen[ $online_unique_key ] ) ) {
				continue;
			}

			$online_watch_seen[ $online_unique_key ] = true;
			$online_index = count( $online_watch_items ) + 1;

			$online_watch_items[] = [
				'id'    => $online_id,
				'type'  => $online_type,
				'label' => $online_label !== ''
					? $online_label
					: '第 ' . $online_index . ' 部',
			];
		}
	}

	$has_online_watch = ! empty( $online_watch_items );
	$hero_has_stream  = $has_stream_section;
	$hero_has_watch   = $has_stream_section || $has_online_watch;

	/* =========================================================
	 * 外部連結及其他 Meta
	 * ======================================================= */

	$official_site = trim(
		(string) $get_meta( 'anime_official_site' )
	);

	$twitter_url = trim(
		(string) $get_meta( 'anime_twitter_url' )
	);

	$wikipedia_url = trim(
		(string) $get_meta( 'anime_wikipedia_url' )
	);

	$tiktok_url = trim(
		(string) $get_meta( 'anime_tiktok_url' )
	);

	$affiliate_html = $get_meta(
		'anime_affiliate_html'
	);

	/*
	 * 只保留合法 HTTP(S) 外部網址。
	 */
	foreach (
		[
			'official_site',
			'twitter_url',
			'wikipedia_url',
			'tiktok_url',
		] as $external_url_variable
	) {
		if (
			${$external_url_variable} !== ''
			&& ! wp_http_validate_url( ${$external_url_variable} )
		) {
			${$external_url_variable} = '';
		}
	}

	/* =========================================================
	 * 下一集播出資料
	 * ======================================================= */

	$next_airing_raw = $get_meta( 'anime_next_airing' );
	$airing_data     = [];

	if ( $next_airing_raw ) {
		if ( is_numeric( $next_airing_raw ) ) {
			$aired_now = (int) $get_meta(
				'anime_episodes_aired'
			);

			$airing_data = [
				'airingAt' => (int) $next_airing_raw,
				'episode'  => $aired_now > 0
					? $aired_now + 1
					: '',
			];
		} else {
			$decoded_airing = is_array( $next_airing_raw )
				? $next_airing_raw
				: json_decode(
					(string) $next_airing_raw,
					true
				);

			if ( is_array( $decoded_airing ) ) {
				$airing_data = $decoded_airing;
			}
		}
	}

	/* =========================================================
	 * 內容資料
	 * ======================================================= */

	$synopsis_raw = $get_meta(
		'anime_synopsis_chinese'
	);

	if ( empty( $synopsis_raw ) ) {
		$synopsis_raw = $get_meta( 'anime_synopsis' );
	}

	if ( empty( $synopsis_raw ) ) {
		$synopsis_raw = get_the_content();
	}

	$synopsis = trim( (string) $synopsis_raw );

	/*
	 * 人工編輯內容。
	 *
	 * 這些欄位應與自動同步欄位分開，避免同步程序覆蓋。
	 */
	// 優先讀新欄位（AI 批次工具寫入），fallback 舊欄位（共 129 筆舊資料）。
	/*
	 * 只認字串：這個欄位曾被寫入 WP_Error 物件，(string) 轉型會直接
	 * 致命錯誤讓整頁 500。寫入端已修，這裡再擋一次，最壞情況只是短評空白。
	 */
	$editorial_raw  = $get_meta( 'anime_editor_summary' );
	$editorial_note = trim( is_string( $editorial_raw ) ? $editorial_raw : '' );

	if ( '' === $editorial_note ) {
		$editorial_note = trim(
			(string) $get_meta( 'anime_editorial_note' )
		);
	}

	$editorial_author = trim(
		(string) $get_meta( 'anime_editorial_author' )
	);

	$editorial_updated = $format_date(
		$get_meta( 'anime_editorial_updated' )
	);

	$original_content_length = $strlen_safe(
		wp_strip_all_tags( $editorial_note )
	);

	$themes_list = $decode_json(
		$get_meta( 'anime_themes' )
	);

	$cast_list = $decode_json(
		$get_meta( 'anime_cast_json' )
	);

	$staff_list = $decode_json(
		$get_meta( 'anime_staff_json' )
	);

	/* =========================================================
	 * STAFF 排序：主要導演固定在前
	 * ======================================================= */

	$staff_sorted = [];

	foreach ( $staff_list as $staff_index => $staff_item ) {
		if ( ! is_array( $staff_item ) ) {
			continue;
		}

		$staff_role = trim(
			wp_strip_all_tags(
				(string) ( $staff_item['role'] ?? '' )
			)
		);

		$staff_sorted[] = [
			'priority' => $is_main_director_role( $staff_role )
				? 0
				: 1,
			'index'    => (int) $staff_index,
			'item'     => $staff_item,
		];
	}

	usort(
		$staff_sorted,
		static function ( $staff_a, $staff_b ) {
			if ( $staff_a['priority'] !== $staff_b['priority'] ) {
				return $staff_a['priority']
					<=> $staff_b['priority'];
			}

			return $staff_a['index']
				<=> $staff_b['index'];
		}
	);

	$staff_list = array_values(
		array_map(
			static function ( $staff_item ) {
				return $staff_item['item'];
			},
			$staff_sorted
		)
	);

	/* =========================================================
	 * 原作者資料
	 * ======================================================= */

	$original_author = trim(
		wp_strip_all_tags(
			(string) $get_meta( 'anime_original_author' )
		)
	);

	$original_author_links = [];

	if ( ! empty( $staff_list ) ) {
		$author_seen = [];

		foreach ( $staff_list as $staff_item ) {
			if ( ! is_array( $staff_item ) ) {
				continue;
			}

			$staff_name = trim(
				wp_strip_all_tags(
					(string) ( $staff_item['name'] ?? '' )
				)
			);

			$staff_role = trim(
				wp_strip_all_tags(
					(string) ( $staff_item['role'] ?? '' )
				)
			);

			$staff_id = (int) (
				$staff_item['id']
				?? 0
			);

			if ( $staff_name === '' || $staff_role === '' ) {
				continue;
			}

			$is_excluded_author_role = preg_match(
				'/角色|人物|キャラクター|character\s*design|'
					. '插畫|插画|illustrat|原畫|原画|協力/iu',
				$staff_role
			);

			if ( $is_excluded_author_role ) {
				continue;
			}

			$is_original_author_role = preg_match(
				'/原作者|原作|作者|原著|原著者|'
					. 'original\s*(creator|story|work|author)|'
					. 'story\s*&\s*art/iu',
				$staff_role
			);

			if ( ! $is_original_author_role ) {
				continue;
			}

			$author_key = $strtolower_safe( $staff_name );

			if ( isset( $author_seen[ $author_key ] ) ) {
				continue;
			}

			$author_seen[ $author_key ] = true;

			$original_author_links[] = [
				'name' => $staff_name,
				'id'   => $staff_id,
			];
		}

		$original_author_links = array_slice(
			$original_author_links,
			0,
			3
		);

		if (
			$original_author === ''
			&& ! empty( $original_author_links )
		) {
			$original_author = implode(
				'、',
				array_map(
					static function ( $author ) {
						return $author['name'];
					},
					$original_author_links
				)
			);
		}
	}

	$relations_list = $decode_json(
		$get_meta( 'anime_relations_json' )
	);

	/* =====================================================================
	 * 原作漫畫（供下方「看原作漫畫」區塊使用）
	 *
	 * 追完一季想知道後續，是購買漫畫意圖最強的時刻，而動畫頁正好是這些人
	 * 停留的地方。站上 1,511 部動畫中有 1,112 部（73.6%）有原作漫畫。
	 *
	 * ★ relations 只帶羅馬字標題（例:Shingeki no Kyojin），但 Renta! 台灣
	 *   是中文站。改用動畫自己的中文標題去搜——改編作品的中文名幾乎都與
	 *   原作漫畫相同。實測站上人氣前十且有原作漫畫的作品，10/10 都搜得到。
	 * =================================================================== */
	$source_manga_id    = 0;   // 原作漫畫的 AniList ID
	$source_manga_local = 0;   // 站內同一部漫畫的文章 ID（有匯入才會有）

	foreach ( $relations_list as $relation_row ) {
		if ( ! is_array( $relation_row ) ) {
			continue;
		}

		if ( 'MANGA' !== ( $relation_row['type'] ?? '' ) ) {
			continue;
		}

		if ( ! in_array( $relation_row['relation_type'] ?? '', [ 'ADAPTATION', 'SOURCE' ], true ) ) {
			continue;
		}

		$source_manga_id = (int) ( $relation_row['id'] ?? 0 );

		if ( $source_manga_id > 0 ) {
			break;   // 取第一筆即可，一部動畫的原作只會有一部
		}
	}

	if ( $source_manga_id > 0 ) {
		// 站內若已匯入這部漫畫，優先給站內連結（留住使用者，也補內部連結）
		$local_manga = get_posts( [
			'post_type'      => 'manga',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'anime_anilist_id',
			'meta_value'     => (string) $source_manga_id,
		] );

		$source_manga_local = ! empty( $local_manga ) ? (int) $local_manga[0] : 0;
	}

	/*
	 * 拿去搜尋的漫畫標題:動畫中文標題去掉季數字樣。
	 *
	 * 「進擊的巨人 第二季」直接拿去搜其實也搜得到，但帶著季號不夠準確，
	 * 續作越多的作品越容易失準。既有的 strip_season_markers() 是兩個
	 * mapper 類別的 private 方法，且不處理中文「第二季」，因此不動它們，
	 * 這裡就地處理需要的幾種寫法。
	 */
	$source_manga_query = $title_chinese !== '' ? $title_chinese : $display_title;
	$source_manga_query = preg_replace(
		[
			'/第\s*[0-9０-９一二三四五六七八九十]+\s*(?:季|期|部|篇|章)/u',
			'/\b\d+(?:st|nd|rd|th)\s+season\b/ui',
			'/\bseason\s*\d+\b/ui',
			'/\bpart\s*\d+\b/ui',
			'/\bfinal\s+season\b/ui',
		],
		' ',
		(string) $source_manga_query
	);
	$source_manga_query = trim( (string) preg_replace( '/\s+/u', ' ', $source_manga_query ) );

	// Renta! 台灣（賣漫畫，站上唯一有聯盟合作的通路）
	$source_manga_shop_url = '';

	if ( $source_manga_id > 0 && $source_manga_query !== '' && function_exists( 'anime_sync_affiliate_url' ) ) {
		$source_manga_shop_url = anime_sync_affiliate_url(
			'https://tw.myrenta.com/search2?keyword=' . $source_manga_query,
			(string) get_post_field( 'post_name', $post_id )
		);
	}

	$has_source_manga = ( $source_manga_local > 0 || $source_manga_shop_url !== '' );

	$episodes_list = $decode_json(
		$get_meta( 'anime_episodes_json' )
	);

	$news_items = $decode_json(
		$get_meta( 'anime_related_news_json' )
	);

	if ( empty( $news_items ) ) {
		$news_items = $decode_json(
			$get_meta( 'anime_news_json' )
		);
	}

	$normalized_news = [];
	$normalized_news_seen = [];

	foreach ( $news_items as $news_item ) {
		$normalized_item = $normalize_news_item( $news_item );

		if ( ! $normalized_item ) {
			continue;
		}

		$news_key = $normalized_item['url'] !== ''
			? strtolower( $normalized_item['url'] )
			: md5( $normalized_item['title'] );

		if ( isset( $normalized_news_seen[ $news_key ] ) ) {
			continue;
		}

		$normalized_news_seen[ $news_key ] = true;
		$normalized_news[] = $normalized_item;
	}

	$news_items = $normalized_news;

	/* =========================================================
	 * 自動比對站內相關新聞
	 * ======================================================= */

	$news_match_titles = array_values(
		array_unique(
			array_filter(
				array_map(
					'trim',
					[
						$display_title,
						$title_chinese,
						$title_simplified,
						$title_native,
						$title_romaji,
						$title_english,
					]
				)
			)
		)
	);

	if ( ! empty( $news_match_titles ) ) {
		$news_tag_cache_key =
			'asd_news_tags_'
			. $post_id
			. '_'
			. get_post_modified_time( 'U', true, $post_id );

		$cached_news_tags = get_transient(
			$news_tag_cache_key
		);

		if (
			is_array( $cached_news_tags )
			&& isset( $cached_news_tags['ids'] )
		) {
			$matched_tag_ids = array_map(
				'intval',
				(array) $cached_news_tags['ids']
			);
		} else {
			$matched_tag_ids = [];

			$all_tags = get_terms(
				[
					'taxonomy'   => 'post_tag',
					'hide_empty' => true,
					'fields'     => 'all',
				]
			);

			if ( ! is_wp_error( $all_tags ) ) {
				foreach ( $all_tags as $tag ) {
					$tag_name = trim(
						(string) $tag->name
					);

					if (
						$tag_name === ''
						|| $strlen_safe( $tag_name ) < 2
					) {
						continue;
					}

					foreach ( $news_match_titles as $match_title ) {
						if (
							$strpos_safe(
								$match_title,
								$tag_name
							) !== false
						) {
							$matched_tag_ids[] =
								(int) $tag->term_id;
							break;
						}
					}
				}
			}

			$matched_tag_ids = array_values(
				array_unique(
					array_filter(
						array_map(
							'intval',
							$matched_tag_ids
						)
					)
				)
			);

			set_transient(
				$news_tag_cache_key,
				[
					'ids' => $matched_tag_ids,
				],
				12 * HOUR_IN_SECONDS
			);
		}

		if ( ! empty( $matched_tag_ids ) ) {
			$news_query = new WP_Query(
				[
					'post_type'              => 'post',
					'post_status'            => 'publish',
					'posts_per_page'         => 6,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => [
						[
							'taxonomy' => 'post_tag',
							'field'    => 'term_id',
							'terms'    => $matched_tag_ids,
						],
					],
				]
			);

			if ( $news_query->have_posts() ) {
				$seen_news_urls = [];

				foreach ( $news_items as $existing_news ) {
					$existing_url = trim(
						(string) (
							$existing_news['url']
								?? ''
						)
					);

					if ( $existing_url !== '' ) {
						$seen_news_urls[ $existing_url ] = true;
					}
				}

				foreach ( $news_query->posts as $news_post ) {
					$news_post_url = get_permalink(
						$news_post
					);

					if (
						! $news_post_url
						|| isset( $seen_news_urls[ $news_post_url ] )
					) {
						continue;
					}

					$seen_news_urls[ $news_post_url ] = true;

					$news_items[] = [
						'title' => get_the_title( $news_post ),
						'url'   => $news_post_url,
					];
				}
			}

			wp_reset_postdata();
		}
	}

	/* =========================================================
	 * OP／ED
	 * ======================================================= */

	$theme_seen = [];
	$openings   = [];
	$endings    = [];

	foreach ( $themes_list as $theme_item ) {
		if ( ! is_array( $theme_item ) ) {
			continue;
		}

		$theme_type = strtoupper(
			trim(
				(string) (
					$theme_item['type']
						?? ''
				)
			)
		);

		$theme_slug = trim(
			(string) (
				$theme_item['slug']
					?? ''
			)
		);

		$theme_title = trim(
			(string) (
				$theme_item['title']
					?? ''
			)
		);

		$theme_key = $theme_slug !== ''
			? $theme_slug
			: $theme_type . '||' . $theme_title;

		if ( isset( $theme_seen[ $theme_key ] ) ) {
			continue;
		}

		$theme_seen[ $theme_key ] = true;

		if ( $starts_with( $theme_type, 'OP' ) ) {
			$openings[] = $theme_item;
		} elseif ( $starts_with( $theme_type, 'ED' ) ) {
			$endings[] = $theme_item;
		}
	}

	/* =========================================================
	 * 顯示標籤
	 * ======================================================= */

	$season_labels = [
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	];

	$format_labels = [
		'TV'       => 'TV',
		'TV_SHORT' => 'TV 短篇',
		'MOVIE'    => '劇場版',
		'OVA'      => 'OVA',
		'ONA'      => 'ONA',
		'SPECIAL'  => '特別篇',
		'MUSIC'    => 'MV',
	];

	$status_labels = [
		'FINISHED'         => '已完結',
		'RELEASING'        => '連載中',
		'NOT_YET_RELEASED' => '尚未播出',
		'CANCELLED'        => '已取消',
		'HIATUS'           => '暫停中',
	];

	$status_classes = [
		'FINISHED'         => 's-fin',
		'RELEASING'        => 's-rel',
		'NOT_YET_RELEASED' => 's-pre',
		'CANCELLED'        => 's-can',
		'HIATUS'           => 's-hia',
	];

	$source_labels = [
		'ORIGINAL'           => '原創',
		'MANGA'              => '漫畫改編',
		'LIGHT_NOVEL'        => '輕小說改編',
		'NOVEL'              => '小說改編',
		'VISUAL_NOVEL'       => '視覺小說改編',
		'VIDEO_GAME'         => '電玩改編',
		'WEB_MANGA'          => '網路漫畫改編',
		'BOOK'               => '書籍改編',
		'MUSIC'              => '音樂改編',
		'GAME'               => '遊戲改編',
		'LIVE_ACTION'        => '真人改編',
		'MULTIMEDIA_PROJECT' => '跨媒體企劃',
		'OTHER'              => '其他',
	];

	$season_label = $season_labels[ $season ] ?? $season;
	$format_label = $format_labels[ $format ] ?? $format;
	$status_label = $status_labels[ $status ] ?? $status;
	$status_class = $status_classes[ $status ] ?? '';
	/*
	 * AniList 的 source 沒有「韓國漫畫／webtoon」「中國漫畫」這類選項，
	 * 遇到就一律填 OTHER（或籠統歸為 MANGA），光看 source 分不出來。
	 * 例：《伊蓮娜．埃沃的觀察日誌》source=OTHER、countryOfOrigin=KR。
	 * 因此非日本作品改以國別補足，其餘維持原對照。
	 */
	$country              = strtoupper( trim( (string) $get_meta( 'anime_source_country' ) ) );
	$country_source_labels = [
		'KR' => '韓國漫畫改編',
		'CN' => '中國漫畫改編',
		'TW' => '台灣漫畫改編',
	];

	$source_label = isset( $country_source_labels[ $country ] )
		&& in_array( $source, [ 'OTHER', 'MANGA', 'COMIC', '' ], true )
			? $country_source_labels[ $country ]
			: ( $source_labels[ $source ] ?? $source );

	$ep_str = '';

	if ( $episodes > 0 ) {
		$ep_str =
			$ep_aired > 0 && $ep_aired < $episodes
				? $ep_aired . ' / ' . $episodes . ' 集'
				: $episodes . ' 集';
	} elseif ( $ep_aired > 0 ) {
		$ep_str = '已播 ' . $ep_aired . ' 集';
	}

	$season_str = '';

	if ( $season_year && $season_label ) {
		$season_str =
			$season_year . ' ' . $season_label;
	} elseif ( $season_year ) {
		$season_str = (string) $season_year;
	} elseif ( $season_label ) {
		$season_str = $season_label;
	}

	$genre_terms = get_the_terms(
		$post_id,
		'genre'
	);

	$season_terms = get_the_terms(
		$post_id,
		'anime_season_tax'
	);

	$genre_terms = is_array( $genre_terms )
		? $genre_terms
		: [];

	$season_terms = is_array( $season_terms )
		? $season_terms
		: [];

	$season_child_terms = [];

	foreach ( $season_terms as $season_term ) {
		if ( ! empty( $season_term->parent ) ) {
			$season_child_terms[] = $season_term;
		}
	}

	/* =========================================================
	 * Hero 標籤列的連結目標
	 * ---------------------------------------------------------
	 * 格式與季度各自對應一個分類法，取得 term link 後 Hero 的標籤
	 * 就能點進歸檔頁（與側欄標籤區同一套 get_term_link 做法）。
	 * 取不到時留空字串，模板會自動退回不可點的 <span>，不會產生壞連結。
	 *
	 * 狀態沒有對應的分類法，但歸檔頁支援 /anime/?anime_status={slug}
	 * 篩選（見 archive-anime.php 與 anime_sync_get_status_filter_map()），
	 * 因此改走 query string。集數仍無對應頁面，維持純文字。
	 * ======================================================= */

	/* 變數刻意加 hero_ 前綴：側欄標籤區的迴圈也用 $season_term_url /
	   $genre_term_url，同名會在日後調動區塊順序時默默取到殘值。 */

	$hero_format_url  = '';
	$format_tax_terms = get_the_terms( $post_id, 'anime_format_tax' );

	if ( is_array( $format_tax_terms ) && ! empty( $format_tax_terms ) ) {
		$resolved_format_url = get_term_link( $format_tax_terms[0] );

		if ( ! is_wp_error( $resolved_format_url ) ) {
			$hero_format_url = $resolved_format_url;
		}
	}

	$hero_season_url = '';

	if ( ! empty( $season_child_terms ) ) {
		$resolved_season_url = get_term_link( $season_child_terms[0] );

		if ( ! is_wp_error( $resolved_season_url ) ) {
			$hero_season_url = $resolved_season_url;
		}
	}

	/* 狀態走歸檔頁的 query string 篩選，slug 對應由外掛統一提供 */
	$hero_status_url = '';

	if ( $status !== '' && function_exists( 'anime_sync_get_status_filter_map' ) ) {
		foreach ( anime_sync_get_status_filter_map() as $status_slug => $status_info ) {
			if ( ( $status_info['code'] ?? '' ) === $status ) {
				$hero_status_url = add_query_arg(
					'anime_status',
					$status_slug,
					home_url( '/anime/' )
				);
				break;
			}
		}
	}

	/* =========================================================
	 * 相關作品：單次 IN 查詢
	 * ======================================================= */

	$relation_labels = [
		'PREQUEL'     => '前作',
		'SEQUEL'      => '續作',
		'PARENT'      => '本篇',
		'SIDE_STORY'  => '外傳',
		'CHARACTER'   => '角色',
		'SUMMARY'     => '總集篇',
		'ALTERNATIVE' => '其他版本',
		'SPIN_OFF'    => '衍生作',
		'OTHER'       => '相關',
		'SOURCE'      => '原作',
		'COMPILATION' => '編輯版',
		'CONTAINS'    => '收錄',
		'ANIME'       => '動畫',
	];

	$site_relations  = [];
	$relation_ids    = [];
	$relation_id_set = [];

	foreach ( $relations_list as $relation_item ) {
		if ( ! is_array( $relation_item ) ) {
			continue;
		}

		$relation_id = (int) (
			$relation_item['anilist_id']
				?? $relation_item['id']
				?? 0
		);

		if (
			$relation_id > 0
			&& ! isset( $relation_id_set[ $relation_id ] )
		) {
			$relation_id_set[ $relation_id ] = true;
			$relation_ids[] = $relation_id;
		}
	}

	if ( ! empty( $relation_ids ) ) {
		$relation_posts = get_posts(
			[
				'post_type'              => 'anime',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'     => 'anime_anilist_id',
						'value'   => $relation_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					],
				],
			]
		);

		$relation_post_map = [];

		foreach ( $relation_posts as $relation_post ) {
			$relation_post_anilist_id = (int) get_post_meta(
				$relation_post->ID,
				'anime_anilist_id',
				true
			);

			if (
				$relation_post_anilist_id > 0
				&& ! isset(
					$relation_post_map[ $relation_post_anilist_id ]
				)
			) {
				$relation_post_map[ $relation_post_anilist_id ] =
					$relation_post;
			}
		}

		foreach ( $relations_list as $relation_item ) {
			if ( ! is_array( $relation_item ) ) {
				continue;
			}

			$relation_anilist_id = (int) (
				$relation_item['anilist_id']
					?? $relation_item['id']
					?? 0
			);

			if (
				! $relation_anilist_id
				|| ! isset(
					$relation_post_map[ $relation_anilist_id ]
				)
			) {
				continue;
			}

			$relation_post =
				$relation_post_map[ $relation_anilist_id ];

			$raw_relation_label =
				$relation_item['relation_type']
					?? $relation_item['relation_label']
					?? $relation_item['type']
					?? '';

			$relation_title = get_post_meta(
				$relation_post->ID,
				'anime_title_chinese',
				true
			);

			if ( ! $relation_title ) {
				$relation_title =
					$relation_item['title_zh']
						?? $relation_item['title']
						?? get_the_title( $relation_post );
			}

			$relation_cover = get_post_meta(
				$relation_post->ID,
				'anime_cover_image',
				true
			);

			if ( ! $relation_cover ) {
				$relation_cover =
					$relation_item['cover_image']
						?? '';
			}

			$site_relations[] = [
				'title_zh'       => trim( (string) $relation_title ),
				'title_native'   => trim(
					(string) (
						$relation_item['title_native']
							?? $relation_item['native']
							?? ''
					)
				),
				'relation_label' => $relation_labels[ $raw_relation_label ]
					?? $raw_relation_label,
				'format'         => trim(
					(string) (
						$relation_item['format']
							?? ''
					)
				),
				'cover_image'    => trim(
					(string) $relation_cover
				),
				'url'            => get_permalink(
					$relation_post->ID
				),
			];
		}
	}

	/*
	 * 補反向的前作／續作關聯：AniList 社群資料常常只有一邊填（例如季2
	 * 標了「前作是季1」，季1自己卻沒有「續作是季2」這筆），造成單向
	 * 關聯、這邊看不到對方。既然雙方都已經匯進站內，就反查一次：
	 * 有沒有別部動畫的 anime_relations_json 把「我」標成 PREQUEL／
	 * SEQUEL，但我自己這邊沒有這筆，有的話補上（關係方向要對調）。
	 * 只處理 PREQUEL/SEQUEL，其他關聯類型語意不對稱、不安全反推。
	 */
	if ( $anilist_id > 0 ) {
		$reverse_relation_map = [
			'PREQUEL' => 'SEQUEL',
			'SEQUEL'  => 'PREQUEL',
		];

		global $wpdb;

		$reverse_candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = 'anime_relations_json'
				   AND meta_value LIKE %s
				   AND post_id != %d",
				'%"id":' . $anilist_id . '%',
				$post_id
			)
		);

		$already_linked_ids = array_map(
			static function ( $item ) {
				return (int) (
					$item['anilist_id']
						?? $item['id']
						?? 0
				);
			},
			array_filter( $relations_list, 'is_array' )
		);

		foreach ( $reverse_candidate_ids as $candidate_post_id ) {
			$candidate_post_id = (int) $candidate_post_id;

			$candidate_anilist_id = (int) get_post_meta(
				$candidate_post_id,
				'anime_anilist_id',
				true
			);

			if (
				! $candidate_anilist_id
				|| in_array( $candidate_anilist_id, $already_linked_ids, true )
				|| get_post_status( $candidate_post_id ) !== 'publish'
			) {
				continue;
			}

			$candidate_relations = $decode_json(
				get_post_meta( $candidate_post_id, 'anime_relations_json', true )
			);

			foreach ( $candidate_relations as $candidate_relation_item ) {
				if ( ! is_array( $candidate_relation_item ) ) {
					continue;
				}

				$candidate_points_to_id = (int) (
					$candidate_relation_item['anilist_id']
						?? $candidate_relation_item['id']
						?? 0
				);

				if ( $candidate_points_to_id !== $anilist_id ) {
					continue;
				}

				$candidate_raw_type = $candidate_relation_item['relation_type']
					?? $candidate_relation_item['relation_label']
					?? $candidate_relation_item['type']
					?? '';

				if ( ! isset( $reverse_relation_map[ $candidate_raw_type ] ) ) {
					continue;
				}

				$reversed_type = $reverse_relation_map[ $candidate_raw_type ];

				$candidate_title = get_post_meta(
					$candidate_post_id,
					'anime_title_chinese',
					true
				);

				if ( ! $candidate_title ) {
					$candidate_title = get_the_title( $candidate_post_id );
				}

				$candidate_cover = get_post_meta(
					$candidate_post_id,
					'anime_cover_image',
					true
				);

				$site_relations[] = [
					'title_zh'       => trim( (string) $candidate_title ),
					'title_native'   => '',
					'relation_label' => $relation_labels[ $reversed_type ]
						?? $reversed_type,
					'format'         => '',
					'cover_image'    => trim( (string) $candidate_cover ),
					'url'            => get_permalink( $candidate_post_id ),
				];

				break;
			}
		}
	}

	/* =========================================================
	 * 站內平均評分
	 * ======================================================= */

	$site_score     = 0.0;
	$site_story     = 0.0;
	$site_music     = 0.0;
	$site_animation = 0.0;
	$site_voice     = 0.0;
	$site_count     = 0;

	if ( class_exists( 'Anime_Sync_Rating_Manager' ) ) {
		$rating_manager = new Anime_Sync_Rating_Manager();
		$site_stats     = $rating_manager->get_stats( $post_id );

		if ( is_array( $site_stats ) ) {
			$site_score = (float) (
				$site_stats['score']
					?? 0
			);

			$site_story = (float) (
				$site_stats['avg_story']
					?? 0
			);

			$site_music = (float) (
				$site_stats['avg_music']
					?? 0
			);

			$site_animation = (float) (
				$site_stats['avg_animation']
					?? 0
			);

			$site_voice = (float) (
				$site_stats['avg_voice']
					?? 0
			);

			$site_count = (int) (
				$site_stats['vote_count']
					?? 0
			);
		}
	}

	if ( $site_score <= 0 ) {
		$site_score = (float) get_post_meta(
			$post_id,
			'anime_score_site',
			true
		);
	}

	if ( $site_count <= 0 ) {
		$site_count = (int) get_post_meta(
			$post_id,
			'anime_score_site_count',
			true
		);
	}

	if ( $site_score <= 0 ) {
		$site_score = (float) get_post_meta(
			$post_id,
			'smacg_site_score',
			true
		);

		$site_story = (float) get_post_meta(
			$post_id,
			'smacg_site_score_story',
			true
		);

		$site_music = (float) get_post_meta(
			$post_id,
			'smacg_site_score_music',
			true
		);

		$site_animation = (float) get_post_meta(
			$post_id,
			'smacg_site_score_animation',
			true
		);

		$site_voice = (float) get_post_meta(
			$post_id,
			'smacg_site_score_voice',
			true
		);

		if ( $site_count <= 0 ) {
			$site_count = (int) get_post_meta(
				$post_id,
				'smacg_site_score_count',
				true
			);
		}
	}

	/* =========================================================
	 * CAST 排序：主角優先
	 * ======================================================= */

	$cast_to_display = [];
	$cast_seen       = [];

	foreach ( $cast_list as $cast_item ) {
		if ( ! is_array( $cast_item ) ) {
			continue;
		}

		$cast_name = trim(
			(string) (
				$cast_item['name']
					?? ''
			)
		);

		$cast_role = trim(
			(string) (
				$cast_item['role']
					?? ''
			)
		);

		$cast_key = md5(
			wp_json_encode( $cast_item )
		);

		if (
			$cast_name === ''
			|| isset( $cast_seen[ $cast_key ] )
		) {
			continue;
		}

		if (
			$cast_role === '主角'
			|| strtoupper( $cast_role ) === 'MAIN'
		) {
			$cast_to_display[]       = $cast_item;
			$cast_seen[ $cast_key ] = true;
		}
	}

	foreach ( $cast_list as $cast_item ) {
		if ( ! is_array( $cast_item ) ) {
			continue;
		}

		$cast_name = trim(
			(string) (
				$cast_item['name']
					?? ''
			)
		);

		$cast_key = md5(
			wp_json_encode( $cast_item )
		);

		if (
			$cast_name === ''
			|| isset( $cast_seen[ $cast_key ] )
		) {
			continue;
		}

		$cast_to_display[]       = $cast_item;
		$cast_seen[ $cast_key ] = true;
	}

	/* =========================================================
	 * JSON-LD Schema
	 * ======================================================= */

	$schema_type = 'TVSeries';

	if ( $format === 'MOVIE' ) {
		$schema_type = 'Movie';
	} elseif ( $format === 'MUSIC' ) {
		$schema_type = 'MusicVideoObject';
	}

	$schema_genres = array_values(
		array_filter(
			array_map(
				static function ( $term ) {
					return isset( $term->name )
						? trim( (string) $term->name )
						: '';
				},
				$genre_terms
			)
		)
	);

	$alternate_names = array_values(
		array_unique(
			array_filter(
				[
					$title_chinese,
					$title_simplified,
					$title_native,
					$title_romaji,
					$title_english,
				]
			)
		)
	);

	$alternate_names = array_values(
		array_filter(
			$alternate_names,
			static function ( $alternate_name ) use ( $display_title ) {
				return $alternate_name !== $display_title;
			}
		)
	);

	$schema_description = trim(
		$substr_safe(
			wp_strip_all_tags( $synopsis ),
			0,
			300
		)
	);

	$schema_image = $cover_image
		?: get_the_post_thumbnail_url( $post_id, 'large' );

	$canonical_url = get_permalink( $post_id );
	$modified_iso  = get_post_modified_time(
		'c',
		true,
		$post_id
	);

	$website_url = trailingslashit(
		home_url( '/' )
	);

	$schema = [
		'@context'         => 'https://schema.org',
		'@type'            => $schema_type,
		'@id'              => $canonical_url . '#anime',
		'name'             => $display_title,
		'url'              => $canonical_url,
		'mainEntityOfPage' => [
			'@type' => 'WebPage',
			'@id'   => $canonical_url,
		],
		'isPartOf'          => [
			'@type' => 'WebSite',
			'@id'   => $website_url . '#website',
			'name'  => get_bloginfo( 'name' ),
			'url'   => $website_url,
		],
	];

	if ( $schema_description !== '' ) {
		$schema['description'] = $schema_description;
	}

	if ( $schema_image ) {
		$schema['image'] = esc_url_raw( $schema_image );
	}

	if ( ! empty( $schema_genres ) ) {
		$schema['genre'] = $schema_genres;
	}

	if ( $start_date ) {
		$schema['datePublished'] = $start_date;
	}

	if ( $modified_iso ) {
		$schema['dateModified'] = $modified_iso;
	}

	if ( ! empty( $alternate_names ) ) {
		$schema['alternateName'] = $alternate_names;
	}

	if ( $episodes > 0 ) {
		$schema['numberOfEpisodes'] = $episodes;
	}

	$schema_same_as = [];

	if ( $official_site ) {
		$schema_same_as[] = $official_site;
	}

	if ( $wikipedia_url ) {
		$schema_same_as[] = $wikipedia_url;
	}

	if ( $twitter_url ) {
		$schema_same_as[] = $twitter_url;
	}

	if ( $tiktok_url ) {
		$schema_same_as[] = $tiktok_url;
	}

	if ( $anilist_id > 0 ) {
		$schema_same_as[] =
			'https://anilist.co/anime/' . $anilist_id;
	}

	if ( $mal_id > 0 ) {
		$schema_same_as[] =
			'https://myanimelist.net/anime/' . $mal_id;
	}

	if ( $bangumi_id > 0 ) {
		$schema_same_as[] =
			'https://bgm.tv/subject/' . $bangumi_id;
	}

	$schema_same_as = array_values(
		array_unique(
			array_filter(
				array_map(
					'esc_url_raw',
					$schema_same_as
				)
			)
		)
	);

	if ( ! empty( $schema_same_as ) ) {
		$schema['sameAs'] = $schema_same_as;
	}

	$schema_languages = [ 'ja' ];

	$dub_lang_codes = [
		'mandarin' => 'zh-TW',
		'taigi'    => 'nan-TW',
	];

	foreach ( $dub_arr as $dub_language ) {
		if ( isset( $dub_lang_codes[ $dub_language ] ) ) {
			$schema_languages[] =
				$dub_lang_codes[ $dub_language ];
		}
	}

	$schema_languages = array_values(
		array_unique( $schema_languages )
	);

	$schema['inLanguage'] = count( $schema_languages ) === 1
		? $schema_languages[0]
		: $schema_languages;

	$schema['countryOfOrigin'] = [
		'@type' => 'Country',
		'name'  => 'Japan',
	];

	if ( $end_date && $status === 'FINISHED' ) {
		$schema['endDate'] = $end_date;
	}

	if ( $studio !== '' ) {
		$schema['productionCompany'] = [
			'@type' => 'Organization',
			'name'  => $studio,
		];
	}

	if ( ! empty( $original_author_links ) ) {
		$schema_creators = [];

		foreach ( $original_author_links as $author ) {
			$author_name = trim(
				(string) (
					$author['name']
						?? ''
				)
			);

			if ( $author_name === '' ) {
				continue;
			}

			$creator = [
				'@type' => 'Person',
				'name'  => $author_name,
			];

			$author_id = (int) (
				$author['id']
					?? 0
			);

			if ( $author_id > 0 ) {
				$creator['url'] = $entity_url(
					'person',
					$author_id,
					$author_name
				);
			}

			$schema_creators[] = $creator;
		}

		if ( ! empty( $schema_creators ) ) {
			$schema['creator'] = $schema_creators;
		}
	} elseif ( $original_author !== '' ) {
		$schema['creator'] = [
			'@type' => 'Person',
			'name'  => $original_author,
		];
	}

	$schema_directors     = [];
	$schema_director_seen = [];

	foreach ( $staff_list as $staff_item ) {
		if ( ! is_array( $staff_item ) ) {
			continue;
		}

		$staff_name = trim(
			(string) (
				$staff_item['name']
					?? ''
			)
		);

		$staff_role = trim(
			(string) (
				$staff_item['role']
					?? ''
			)
		);

		if (
			$staff_name === ''
			|| ! $is_main_director_role( $staff_role )
			|| isset( $schema_director_seen[ $staff_name ] )
		) {
			continue;
		}

		$schema_director_seen[ $staff_name ] = true;

		$director = [
			'@type' => 'Person',
			'name'  => $staff_name,
		];

		$staff_id = (int) (
			$staff_item['id']
				?? 0
		);

		if ( $staff_id > 0 ) {
			$director['url'] = $entity_url(
				'person',
				$staff_id,
				$staff_name
			);
		}

		$schema_directors[] = $director;
	}

	if ( ! empty( $schema_directors ) ) {
		$schema['director'] = $schema_directors;
	}

	$schema_actors     = [];
	$schema_actor_seen = [];

	foreach ( array_slice( $cast_to_display, 0, 10 ) as $cast_item ) {
		$voice_actors = (
			! empty( $cast_item['voice_actors'] )
			&& is_array( $cast_item['voice_actors'] )
		)
			? $cast_item['voice_actors']
			: [];

		$voice_actor = $voice_actors[0] ?? [];
		$voice_actor = is_array( $voice_actor )
			? $voice_actor
			: [];

		$voice_name = trim(
			(string) (
				$voice_actor['name']
					?? ''
			)
		);

		if (
			$voice_name === ''
			|| isset( $schema_actor_seen[ $voice_name ] )
		) {
			continue;
		}

		$schema_actor_seen[ $voice_name ] = true;

		$actor = [
			'@type' => 'Person',
			'name'  => $voice_name,
		];

		$voice_id = (int) (
			$voice_actor['id']
				?? 0
		);

		if ( $voice_id > 0 ) {
			$actor['url'] = $entity_url(
				'person',
				$voice_id,
				$voice_name
			);
		}

		$schema_actors[] = $actor;
	}

	if ( ! empty( $schema_actors ) ) {
		$schema['actor'] = $schema_actors;
	}

	/*
	 * [v15.6] 不再輸出 trailer VideoObject。
	 *
	 * Google 對 VideoObject 要求 uploadDate（影片首次發佈日），而我們沒有
	 * YouTube 真實上傳日的可靠來源（v15.5 已移除以動畫首播日充當的錯誤寫法）。
	 * 缺 uploadDate 的 VideoObject 會被 Search Console 判「無效」且拿不到任何
	 * 複合式搜尋結果加分，只是徒增結構化資料錯誤。移除後，預告片仍以嵌入的
	 * YouTube iframe 呈現，Google 會直接從 iframe 偵測影片（含 YouTube 提供的
	 * uploadDate），比我們自輸出不完整 schema 更正確。
	 * 未來若接 YouTube Data API 取得真實 uploadDate，可在此還原並補上該欄位。
	 */

	if ( $site_score > 0 && $site_count > 0 ) {
		$schema['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => round( $site_score, 1 ),
			'bestRating'  => 10,
			'worstRating' => 1,
			'ratingCount' => $site_count,
		];
	}

	/* =========================================================
	 * FAQ Schema
	 * ======================================================= */

	$faq_items = $decode_json(
		$get_meta( 'anime_faq_json' )
	);

	$faq_schema        = null;
	$faq_main          = [];
	$faq_display_items = [];
	$faq_seen          = [];

	foreach ( $faq_items as $faq_item ) {
		if ( ! is_array( $faq_item ) ) {
			continue;
		}

		$faq_question = trim(
			(string) (
				$faq_item['q']
					?? ''
			)
		);

		$faq_answer = trim(
			(string) (
				$faq_item['a']
					?? ''
			)
		);

		if ( $faq_question === '' || $faq_answer === '' ) {
			continue;
		}

		$faq_key = md5(
			$strtolower_safe(
				wp_strip_all_tags( $faq_question )
			)
		);

		if ( isset( $faq_seen[ $faq_key ] ) ) {
			continue;
		}

		$faq_seen[ $faq_key ] = true;

		$faq_display_items[] = [
			'q' => $faq_question,
			'a' => $faq_answer,
		];

		$faq_main[] = [
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( $faq_question ),
			'acceptedAnswer' => [
				'@type' => 'Answer',
				'text'  => wp_strip_all_tags( $faq_answer ),
			],
		];
	}

	if ( ! empty( $faq_main ) ) {
		$faq_schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => $canonical_url . '#faq',
			'url'        => $canonical_url . '#asd-sec-faq',
			'mainEntity' => $faq_main,
		];
	}

	/*
	 * Template 只顯示管理員提示。
	 * robots 與 Sitemap 必須在 functions.php 內統一處理。
	 */
	$is_thin_content = function_exists(
		'wxacg_is_thin_anime_page'
	)
		? (bool) wxacg_is_thin_anime_page( $post_id )
		: $original_content_length < 150;

	/* =========================================================
	 * Poster、追蹤資料與分享網址
	 * ======================================================= */

	$poster_fallback = $fallback_text(
		$display_title,
		2
	);

	$user_id = get_current_user_id();

	$user_anime_entry = [
		'status'      => null,
		'progress'    => 0,
		'favorited'   => false,
		'fullcleared' => false,
	];

	if (
		$user_id
		&& class_exists( 'Anime_Sync_User_Status_Manager' )
	) {
		$status_manager = new Anime_Sync_User_Status_Manager();

		$user_entry = $status_manager->get_entry(
			(int) $user_id,
			(int) $post_id
		);

		if ( is_array( $user_entry ) ) {
			$user_anime_entry = [
				'status'      => $user_entry['status'] ?? null,
				'progress'    => (int) (
					$user_entry['progress']
						?? 0
				),
				'favorited'   => ! empty(
					$user_entry['favorited']
				),
				'fullcleared' => ! empty(
					$user_entry['fullcleared']
				),
			];
		}
	}

	/*
	 * 全站追蹤統計（想看／追番中／已看完的人數）。
	 *
	 * 這是彙總數字、與觀看者是誰無關，
	 * 因此不受登入狀態影響，訪客也看得到。
	 */
	$track_stats = [
		'want'      => 0,
		'watching'  => 0,
		'completed' => 0,
		'dropped'   => 0,
		'favorited' => 0,
		'total'     => 0,
	];

	if ( class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
		$stats_manager = isset( $status_manager )
			? $status_manager
			: new Anime_Sync_User_Status_Manager();

		if ( method_exists( $stats_manager, 'get_stats_for_anime' ) ) {
			$track_stats = $stats_manager->get_stats_for_anime(
				(int) $post_id
			);
		}
	}

	/*
	 * 推薦區塊，兩種來源（見 class-user-status-manager.php 的 get_recommendations）：
	 *
	 *   watchlist — 依使用者自己的追蹤紀錄推算偏好類型，找出同類型、
	 *               但他還沒追過的作品。屬個人化內容。
	 *   similar   — 追番清單為空或未登入時的退路，改推「與當前作品類型相近」
	 *               的其他作品。依作品而非依人，內容對所有訪客都相同。
	 *
	 * 快取安全性：
	 *   登入者在本站有獨立的 LSCache 分區，個人化那份不會與訪客或其他
	 *   使用者共用。similar 那份本來就不含任何使用者資料，訪客之間共用
	 *   同一份輸出是正確的，也讓這段內容可以正常被快取。
	 */
	$reco_items  = [];
	$reco_source = '';

	if (
		isset( $stats_manager )
		&& method_exists( $stats_manager, 'get_recommendations' )
	) {
		$reco_ids = $stats_manager->get_recommendations(
			(int) $user_id,
			(int) $post_id,
			6,
			$reco_source
		);

		foreach ( $reco_ids as $reco_id ) {
			$reco_id = (int) $reco_id;

			$reco_title = trim(
				(string) get_post_meta(
					$reco_id,
					'anime_title_chinese',
					true
				)
			);

			if ( $reco_title === '' ) {
				$reco_title = get_the_title( $reco_id );
			}

			$reco_format = trim(
				(string) get_post_meta(
					$reco_id,
					'anime_format',
					true
				)
			);

			$reco_items[] = [
				'url'    => get_permalink( $reco_id ),
				'title'  => $reco_title,
				'cover'  => trim(
					(string) get_post_meta(
						$reco_id,
						'anime_cover_image',
						true
					)
				),
				'format' => $format_labels[ $reco_format ]
					?? $reco_format,
			];
		}
	}

	$user_rating = [
		'story'     => 5.0,
		'music'     => 5.0,
		'animation' => 5.0,
		'voice'     => 5.0,
	];

	$share_permalink = $canonical_url;

	$share_text_x =
		$display_title
		. ' | '
		. get_bloginfo( 'name' )
		. ' '
		. $share_permalink;

	$share_url_x =
		'https://twitter.com/intent/tweet?text='
		. rawurlencode( $share_text_x );

	$share_url_fb =
		'https://www.facebook.com/sharer/sharer.php?u='
		. rawurlencode( $share_permalink );

	/* =========================================================
	 * 輸出 JSON-LD
	 * ======================================================= */
	?>
	<script type="application/ld+json"><?php
		echo wp_json_encode(
			$schema,
			$json_ld_flags
		);
	?></script>

	<?php if ( $faq_schema ) : ?>
		<script type="application/ld+json"><?php
			echo wp_json_encode(
				$faq_schema,
				$json_ld_flags
			);
		?></script>
	<?php endif; ?>

	<script>
	window.SmacgUserRating = <?php
		echo wp_json_encode(
			$user_rating,
			$json_ld_flags
		);
	?>;
	</script>

	<div class="asd-wrap">

		<?php if ( $is_thin_content && current_user_can( 'manage_options' ) ) : ?>
			<?php
			// 與 functions.php wxacg_is_thin_anime_page() 一致：短評需有審核者才算數。
			$asd_reviewer_id  = (int) $get_meta( 'anime_editorial_author_id' );
			$asd_has_reviewer = ( $asd_reviewer_id > 0 ) || ( $editorial_author !== '' );
			?>
			<div
				class="asd-admin-only-notice"
				role="status"
			>
				<?php if ( $editorial_note !== '' && ! $asd_has_reviewer ) : ?>
					<strong>⚠️ 僅管理員可見：</strong>
					本頁已有編輯短評約
					<?php echo esc_html( number_format_i18n( $original_content_length ) ); ?>
					字，但<strong>尚未指定人工審核者</strong>，因此仍被視為 AI 草稿並暫時設為 noindex。
					請於編輯畫面複核短評內容後填寫「審核者」，即可解除此標記並恢復索引。
				<?php else : ?>
					<strong>⚠️ 僅管理員可見：</strong>
					本頁目前可能缺乏足夠的人工編輯內容。
					現有編輯短評約
					<?php echo esc_html( number_format_i18n( $original_content_length ) ); ?>
					字，建議補充查證來源、作品特色、適合族群與人工複核資訊。
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $banner_image ) : ?>
			<div
				class="asd-banner"
				style="background-image:url('<?php echo esc_url( $banner_image ); ?>');"
			>
				<div class="asd-banner-fade"></div>
			</div>
		<?php else : ?>
			<div class="asd-banner asd-banner--fallback">
				<div class="asd-banner-fade"></div>
			</div>
		<?php endif; ?>

		<?php
		/*
		 * 消息更新事件。在此先取一次，頁首的視覺圖切換器與下方的「消息更新」
		 * 區塊共用同一份結果，不重複查詢。
		 */
		$asd_events = class_exists( 'Anime_Sync_Anime_Events' )
			? Anime_Sync_Anime_Events::get_for_anime( $post_id )
			: [];

		/*
		 * 視覺圖切換器的圖片清單：現有封面排第一，之後接上歷次公開的視覺圖。
		 * 只有 2 張以上才會顯示切換列——絕大多數作品在累積初期只有 1 張，
		 * 單格縮圖列沒有意義。
		 */
		$asd_visuals = [];

		foreach ( $asd_events as $asd_ev ) {
			if ( 'visual' !== $asd_ev->event_type || ! $asd_ev->attachment_id ) {
				continue;
			}

			$asd_vis_full = wp_get_attachment_image_url( (int) $asd_ev->attachment_id, 'large' );

			if ( ! $asd_vis_full ) {
				continue;
			}

			$asd_visuals[] = [
				'full'  => $asd_vis_full,
				'thumb' => wp_get_attachment_image_url( (int) $asd_ev->attachment_id, 'thumbnail' ) ?: $asd_vis_full,
				'label' => $asd_ev->summary,
			];
		}

		if ( ! empty( $asd_visuals ) ) {
			$asd_current_cover = $cover_image ?: get_the_post_thumbnail_url( $post_id, 'large' );

			if ( $asd_current_cover ) {
				array_unshift( $asd_visuals, [
					'full'  => $asd_current_cover,
					'thumb' => get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ?: $asd_current_cover,
					'label' => '主視覺圖',
				] );
			}
		}

		$asd_has_switcher = count( $asd_visuals ) >= 2;
		?>

		<div class="asd-hero-new">

			<div class="asd-hero-poster-wrap<?php echo $asd_has_switcher ? ' has-switcher' : ''; ?>">
				<div class="asd-hero-poster">
					<?php if ( $cover_image ) : ?>
						<img
							src="<?php echo esc_url( $cover_image ); ?>"
							alt="<?php echo esc_attr( $display_title ); ?> 封面"
							class="asd-poster-img"
							loading="eager"
							fetchpriority="high"
							decoding="async"
						>
						<div
							class="asd-poster-fallback asd-poster-fallback--backup"
							hidden
						>
							<span><?php echo esc_html( $poster_fallback ); ?></span>
						</div>
					<?php elseif ( has_post_thumbnail() ) : ?>
						<?php
						echo get_the_post_thumbnail(
							$post_id,
							'large',
							[
								'class'         => 'asd-poster-img',
								'loading'       => 'eager',
								'fetchpriority' => 'high',
								'decoding'      => 'async',
								'alt'           => $display_title . ' 封面',
							]
						);
						?>
					<?php else : ?>
						<div class="asd-poster-fallback">
							<span><?php echo esc_html( $poster_fallback ); ?></span>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $asd_has_switcher ) : ?>
					<div class="asd-visual-switcher" role="group" aria-label="視覺圖">
						<?php foreach ( $asd_visuals as $asd_vi => $asd_visual ) : ?>
							<button
								type="button"
								class="asd-visual-thumb<?php echo 0 === $asd_vi ? ' is-active' : ''; ?>"
								data-full="<?php echo esc_url( $asd_visual['full'] ); ?>"
								aria-label="<?php echo esc_attr( $asd_visual['label'] ); ?>"
								title="<?php echo esc_attr( $asd_visual['label'] ); ?>"
							>
								<img
									src="<?php echo esc_url( $asd_visual['thumb'] ); ?>"
									alt="<?php echo esc_attr( $asd_visual['label'] ); ?>"
									loading="lazy"
									decoding="async"
								>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="asd-hero-body">

				<div class="asd-hero-breadcrumb">
					<a href="<?php echo esc_url( get_post_type_archive_link( 'anime' ) ?: home_url( '/anime/' ) ); ?>">
						動畫
					</a>

					<?php if ( $season_str ) : ?>
						<span class="asd-hbc-sep" aria-hidden="true">›</span>
						<span><?php echo esc_html( $season_str ); ?></span>
					<?php endif; ?>

					<?php if ( ! empty( $genre_terms ) ) : ?>
						<span class="asd-hbc-sep" aria-hidden="true">›</span>
						<span><?php echo esc_html( $genre_terms[0]->name ); ?></span>
					<?php endif; ?>
				</div>

				<h1 class="asd-hero-title">
					<?php echo esc_html( $display_title ); ?>
				</h1>

				<?php if ( $title_native ) : ?>
					<p class="asd-hero-native">
						<?php echo esc_html( $title_native ); ?>
					</p>
				<?php endif; ?>

				<?php
				if (
					$title_simplified
					&& $title_simplified !== $display_title
					&& $title_simplified !== $title_native
				) :
					?>
					<p class="asd-hero-native asd-hero-simplified">
						<?php echo esc_html( $title_simplified ); ?>
					</p>
				<?php endif; ?>

				<?php
				$title_en_or_romaji =
					$title_english ?: $title_romaji;

				if (
					$title_en_or_romaji
					&& $title_en_or_romaji !== $title_native
					&& $title_en_or_romaji !== $display_title
				) :
					?>
					<p class="asd-hero-native asd-hero-romaji">
						<?php echo esc_html( $title_en_or_romaji ); ?>
					</p>
				<?php endif; ?>

				<?php
				$series_tax_terms = get_the_terms(
					$post_id,
					'anime_series_tax'
				);

				if (
					is_array( $series_tax_terms )
					&& ! empty( $series_tax_terms )
				) :
					$series_tax = $series_tax_terms[0];
					$series_tax_url = get_term_link( $series_tax );

					if (
						(int) $series_tax->count >= 2
						&& ! is_wp_error( $series_tax_url )
					) :
						?>
						<a
							href="<?php echo esc_url( $series_tax_url ); ?>"
							class="asd-series-entry-badge asd-series-entry-badge--hero"
						>
							<span class="asd-series-badge-icon" aria-hidden="true">📺</span>
							<span class="asd-series-badge-text">
								<span class="asd-series-badge-label">系列作品</span>
								<span class="asd-series-badge-name">
									<?php echo esc_html( $series_tax->name ); ?>
								</span>
							</span>
							<span class="asd-series-badge-count">
								<?php echo esc_html( number_format_i18n( $series_tax->count ) ); ?> 部
							</span>
							<span class="asd-series-badge-arrow" aria-hidden="true">→</span>
						</a>
					<?php endif; ?>
				<?php endif; ?>

				<div class="asd-hero-badges">
					<?php
					/*
					 * 播映狀態：連到 /anime/?anime_status={slug} 的篩選檢視。
					 * 狀態會隨時間改變，所以不做成分類法，直接查 meta（見
					 * anime-sync-pro.php 的 anime_sync_get_status_filter_map()）。
					 */
					$hero_status_url  = '';
					$status_slug_map  = function_exists( 'anime_sync_get_status_filter_map' )
						? anime_sync_get_status_filter_map()
						: [];

					foreach ( $status_slug_map as $status_slug => $status_info ) {
						if ( $status_info['code'] === $status ) {
							$hero_status_url = add_query_arg(
								'anime_status',
								$status_slug,
								get_post_type_archive_link( 'anime' ) ?: home_url( '/anime/' )
							);
							break;
						}
					}

					$status_badge_class = 'asd-hbadge'
						. ( $status_class ? ' asd-hbadge--' . $status_class : '' );
					?>
					<?php if ( $status_label ) : ?>
						<?php if ( $hero_status_url ) : ?>
							<a
								href="<?php echo esc_url( $hero_status_url ); ?>"
								class="<?php echo esc_attr( $status_badge_class ); ?> asd-hbadge--link"
								title="<?php echo esc_attr( '查看所有' . $status_label . '的動畫' ); ?>"
							>
								<?php echo esc_html( $status_label ); ?>
							</a>
						<?php else : ?>
							<span class="<?php echo esc_attr( $status_badge_class ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						<?php endif; ?>
					<?php endif; ?>

					<?php
					/* 以下格式／季度／類型三種標籤有對應的歸檔頁，改為可點；
					   取不到 term link 時退回 <span>，維持原本的純文字顯示。 */
					?>
					<?php if ( $format_label ) : ?>
						<?php if ( $hero_format_url ) : ?>
							<a
								href="<?php echo esc_url( $hero_format_url ); ?>"
								class="asd-hbadge asd-hbadge--link"
								title="<?php echo esc_attr( '查看更多 ' . $format_label . ' 作品' ); ?>"
							>
								<?php echo esc_html( $format_label ); ?>
							</a>
						<?php else : ?>
							<span class="asd-hbadge">
								<?php echo esc_html( $format_label ); ?>
							</span>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $season_str ) : ?>
						<?php if ( $hero_season_url ) : ?>
							<a
								href="<?php echo esc_url( $hero_season_url ); ?>"
								class="asd-hbadge asd-hbadge--link"
								title="<?php echo esc_attr( '查看 ' . $season_str . ' 的所有作品' ); ?>"
							>
								<?php echo esc_html( $season_str ); ?>
							</a>
						<?php else : ?>
							<span class="asd-hbadge">
								<?php echo esc_html( $season_str ); ?>
							</span>
						<?php endif; ?>
					<?php endif; ?>

					<?php /* 集數不是分類維度，沒有歸檔頁可連 */ ?>
					<?php if ( $ep_str ) : ?>
						<span class="asd-hbadge">
							<?php echo esc_html( $ep_str ); ?>
						</span>
					<?php endif; ?>

					<?php
					foreach ( array_slice( $genre_terms, 0, 3 ) as $genre_term ) :
						$resolved_genre_url = get_term_link( $genre_term );
						$hero_genre_url     = is_wp_error( $resolved_genre_url )
							? ''
							: $resolved_genre_url;
						?>
						<?php if ( $hero_genre_url ) : ?>
							<a
								href="<?php echo esc_url( $hero_genre_url ); ?>"
								class="asd-hbadge asd-hbadge--genre asd-hbadge--link"
								title="<?php echo esc_attr( '查看更多 ' . $genre_term->name . ' 作品' ); ?>"
							>
								<?php echo esc_html( $genre_term->name ); ?>
							</a>
						<?php else : ?>
							<span class="asd-hbadge asd-hbadge--genre">
								<?php echo esc_html( $genre_term->name ); ?>
							</span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>

				<div class="asd-hero-scores-new">
					<?php if ( $score_anilist ) : ?>
						<div class="asd-score-pill asd-score-pill--al" title="AniList－國際動漫評分資料庫">
							<span class="asd-sp-dot" aria-hidden="true"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_anilist ); ?></span>
							<span class="asd-sp-label">AniList</span>
						</div>
					<?php endif; ?>

					<?php if ( $score_mal ) : ?>
						<div class="asd-score-pill asd-score-pill--mal" title="MyAnimeList（MAL）－國際動漫評分資料庫">
							<span class="asd-sp-dot" aria-hidden="true"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_mal ); ?></span>
							<span class="asd-sp-label">MAL</span>
						</div>
					<?php endif; ?>

					<?php if ( $score_bangumi ) : ?>
						<div class="asd-score-pill asd-score-pill--bgm" title="Bangumi（bgm.tv）－大陸地區動漫社群評分">
							<span class="asd-sp-dot" aria-hidden="true"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_bangumi ); ?></span>
							<span class="asd-sp-label">Bangumi</span>
						</div>
					<?php endif; ?>

					<div class="asd-score-pill asd-score-pill--site" title="本站會員評分">
						<span class="asd-sp-dot" aria-hidden="true"></span>
						<span class="asd-sp-val wacg-hero-score">
							<?php
							echo $site_score > 0
								? esc_html( number_format( $site_score, 1 ) )
								: '—';
							?>
						</span>
						<span class="asd-sp-label">本站</span>
					</div>
				</div>

				<div class="asd-hero-actions">
					<?php if ( $hero_has_stream ) : ?>
						<a
							href="#asd-sec-stream"
							class="asd-action-btn asd-action-btn--primary"
							title="<?php echo esc_attr( $display_title ); ?> 合法串流平台"
						>
							📺 串流平台
						</a>
					<?php elseif ( $has_online_watch ) : ?>
						<a
							href="#asd-sec-online"
							class="asd-action-btn asd-action-btn--primary"
							title="<?php echo esc_attr( $display_title ); ?> 線上觀看"
						>
							▶ 線上觀看
						</a>
					<?php endif; ?>

					<?php if ( $has_trailer ) : ?>
						<a
							href="#asd-sec-trailer"
							class="asd-action-btn asd-action-btn--ghost"
						>
							▶ 觀看預告
						</a>
					<?php endif; ?>

					<?php if ( is_user_logged_in() ) : ?>
						<a
							href="#asd-sec-corrections"
							class="asd-action-btn asd-action-btn--ghost"
							id="asd-hero-corr-btn"
						>
							✏ 糾錯回報
						</a>
					<?php else : ?>
						<a
							href="<?php echo esc_url( wp_login_url( $canonical_url ) ); ?>"
							class="asd-action-btn asd-action-btn--ghost"
						>
							✏ 糾錯回報
						</a>
					<?php endif; ?>

					<?php if ( $official_site ) : ?>
						<a
							href="<?php echo esc_url( $official_site ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="asd-action-btn asd-action-btn--ghost"
						>
							🌐 官方網站
						</a>
					<?php endif; ?>

					<?php if ( $bangumi_id > 0 ) : ?>
						<a
							href="<?php echo esc_url( 'https://www.anitabi.cn/map?bangumiId=' . $bangumi_id ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							data-go-confirm="1"
							class="asd-action-btn asd-action-btn--ghost"
							title="<?php echo esc_attr( $display_title ); ?> 動漫巡禮地圖"
						>
							🗺 巡禮地圖
						</a>
					<?php endif; ?>

					<?php
					if ( function_exists( 'smacg_get_anime_articles_count' ) ) :
						$feature_count = smacg_get_anime_articles_count(
							$post_id,
							'feature'
						);

						$review_count = smacg_get_anime_articles_count(
							$post_id,
							'review'
						);

						$feature_url = add_query_arg(
							'related_anime',
							$post_id,
							home_url( '/feature/anime/' )
						);

						$review_url = add_query_arg(
							'related_anime',
							$post_id,
							home_url( '/review/anime/' )
						);
						?>

						<?php if ( $feature_count > 0 ) : ?>
							<a
								href="<?php echo esc_url( $feature_url ); ?>"
								class="asd-action-btn asd-action-btn--ghost"
								title="<?php echo esc_attr( $display_title ); ?> 無雷前導文章"
							>
								🎬 無雷前導
							</a>
						<?php endif; ?>

						<?php if ( $review_count > 0 ) : ?>
							<a
								href="<?php echo esc_url( $review_url ); ?>"
								class="asd-action-btn asd-action-btn--ghost"
								title="<?php echo esc_attr( $display_title ); ?> 有雷影評文章"
							>
								📝 有雷影評
							</a>
						<?php endif; ?>
					<?php endif; ?>
				</div>

			</div><!-- /.asd-hero-body -->

			<div
				class="asd-hside-block"
				id="wacg-rating-block"
				aria-label="作品評分與摘要"
			>
				<div class="asd-hside-title">評分</div>

				<?php if ( $score_anilist ) : ?>
					<div class="asd-hside-row" title="AniList－國際動漫評分資料庫">
						<span class="asd-hside-dot asd-hside-dot--al" aria-hidden="true"></span>
						<span class="asd-hside-key">AniList</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_anilist ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $score_mal ) : ?>
					<div class="asd-hside-row" title="MyAnimeList（MAL）－國際動漫評分資料庫">
						<span class="asd-hside-dot asd-hside-dot--mal" aria-hidden="true"></span>
						<span class="asd-hside-key">MAL</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_mal ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $score_bangumi ) : ?>
					<div class="asd-hside-row" title="Bangumi（bgm.tv）－大陸地區動漫社群評分">
						<span class="asd-hside-dot asd-hside-dot--bgm" aria-hidden="true"></span>
						<span class="asd-hside-key">Bangumi</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_bangumi ); ?></span>
					</div>
				<?php endif; ?>

				<div class="wacg-rating-divider"></div>

				<div id="wacg-rating-stats" class="wacg-rating-stats">
					<div class="wacg-score-row" title="本站會員評分">
						<span class="asd-hside-dot wacg-dot-site" aria-hidden="true"></span>
						<span class="asd-hside-key">本站</span>
						<span class="asd-hside-val wacg-score-main">
							<?php
							echo $site_score > 0
								? esc_html( number_format( $site_score, 1 ) )
								: '—';
							?>
						</span>
					</div>

					<div class="wacg-vote-count">
						<?php
						echo $site_count > 0
							? esc_html(
								number_format_i18n( $site_count )
								. ' 人評分'
							)
							: '';
						?>
					</div>

					<?php if ( $site_count > 0 ) : ?>
						<div class="wacg-cats">
							<?php
							$rating_categories = [
								'story'     => [
									'label' => '劇情',
									'value' => $site_story,
								],
								'music'     => [
									'label' => '音樂',
									'value' => $site_music,
								],
								'animation' => [
									'label' => '作畫',
									'value' => $site_animation,
								],
								'voice'     => [
									'label' => '聲優',
									'value' => $site_voice,
								],
							];

							foreach ( $rating_categories as $rating_key => $rating_category ) :
								?>
								<div class="wacg-cat-row">
									<span class="wacg-cat-label">
										<?php echo esc_html( $rating_category['label'] ); ?>
									</span>
									<span class="wacg-cat-val wacg-cat-<?php echo esc_attr( $rating_key ); ?>">
										<?php
										echo $rating_category['value'] > 0
											? esc_html(
												number_format(
													(float) $rating_category['value'],
													1
												)
											)
											: '—';
										?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $site_count <= 0 ) : ?>
					<p class="wacg-be-first">
						✨ 尚無評分，歡迎分享你的看法：
					</p>
				<?php endif; ?>

				<?php if ( is_user_logged_in() ) : ?>
					<form id="wacg-rating-form" class="wacg-rating-form">
						<input
							type="hidden"
							name="anime_post_id"
							value="<?php echo esc_attr( $post_id ); ?>"
						>

						<?php
						$rating_sliders = [
							'story'     => '劇情',
							'music'     => '音樂',
							'animation' => '作畫',
							'voice'     => '聲優',
						];

						foreach ( $rating_sliders as $rating_key => $rating_label ) :
							$init_value = $user_rating[ $rating_key ];
							?>
							<div class="wacg-slider-row">
								<label
									class="wacg-slider-label"
									for="slider-<?php echo esc_attr( $rating_key ); ?>"
								>
									<?php echo esc_html( $rating_label ); ?>
								</label>

								<input
									type="range"
									id="slider-<?php echo esc_attr( $rating_key ); ?>"
									class="wacg-slider"
									min="1"
									max="10"
									step="0.1"
									value="<?php echo esc_attr( $init_value ); ?>"
								>

								<span
									id="slider-<?php echo esc_attr( $rating_key ); ?>-val"
									class="wacg-slider-val"
								>
									<?php echo esc_html( number_format( $init_value, 1 ) ); ?>
								</span>
							</div>
						<?php endforeach; ?>

						<button
							type="submit"
							id="wacg-submit-btn"
							class="wacg-submit-btn"
						>
							送出評分
						</button>

						<div
							id="wacg-rated-actions"
							class="wacg-rated-actions"
							hidden
						>
							<span class="wacg-rated-badge">✓ 你已評分</span>
							<button
								type="button"
								id="wacg-delete-btn"
								class="wacg-delete-btn"
							>
								🗑 刪除評分
							</button>
						</div>
					</form>
				<?php else : ?>
					<button
						type="button"
						class="wacg-login-prompt"
						data-action="smacg-login-prompt"
					>
						登入後即可評分
					</button>
				<?php endif; ?>

				<div class="wacg-rating-divider"></div>

				<?php
				$studio_link_id = 0;

				if ( $studio !== '' && ! empty( $staff_list ) ) {
					foreach ( $staff_list as $staff_item ) {
						if ( ! is_array( $staff_item ) ) {
							continue;
						}

						$staff_name = trim(
							wp_strip_all_tags(
								(string) (
									$staff_item['name']
										?? ''
								)
							)
						);

						$staff_role = trim(
							wp_strip_all_tags(
								(string) (
									$staff_item['role']
										?? ''
								)
							)
						);

						$staff_id = (int) (
							$staff_item['id']
								?? 0
						);

						if ( $staff_name === '' || $staff_id <= 0 ) {
							continue;
						}

						$is_studio_role =
							$strpos_safe( $staff_role, '製作' ) !== false
							|| $strpos_safe( $staff_role, '制作' ) !== false
							|| stripos( $staff_role, 'studio' ) !== false
							|| stripos( $staff_role, 'production' ) !== false;

						if (
							$is_studio_role
							&& $staff_name === $studio
						) {
							$studio_link_id = $staff_id;
							break;
						}
					}
				}

				$author_html = '';

				if ( ! empty( $original_author_links ) ) {
					$author_parts = [];

					foreach ( $original_author_links as $author ) {
						$author_name = $author['name'];
						$author_id   = (int) $author['id'];

						$author_url = $author_id > 0
							? $entity_url(
								'person',
								$author_id,
								$author_name
							)
							: '';

						$author_parts[] = $author_url !== ''
							? '<a href="' . esc_url( $author_url ) . '">'
								. esc_html( $author_name )
								. '</a>'
							: esc_html( $author_name );
					}

					$author_html = implode( '、', $author_parts );
				} elseif ( $original_author !== '' ) {
					$author_html = esc_html( $original_author );
				}

				$studio_html = '';

				if ( $studio !== '' ) {
					$studio_url = $studio_link_id > 0
						? $entity_url(
							'person',
							$studio_link_id,
							$studio
						)
						: '';

					/*
					 * 人物頁的配對條件很嚴（STAFF 需同時職位含製作類字樣且名稱完全相同），
					 * 多數作品配不到就變純文字。退回 anime_studio_tax 的歸檔頁，
					 * 與側欄標籤區連的是同一個地方。
					 */
					if ( $studio_url === '' ) {
						$studio_tax_terms = get_the_terms( $post_id, 'anime_studio_tax' );

						if ( is_array( $studio_tax_terms ) ) {
							foreach ( $studio_tax_terms as $studio_tax_term ) {
								// 只認名稱相同者：連錯製作公司比不能點更糟。
								if ( trim( $studio_tax_term->name ) !== trim( $studio ) ) {
									continue;
								}

								$resolved_studio_url = get_term_link( $studio_tax_term );

								if ( ! is_wp_error( $resolved_studio_url ) ) {
									$studio_url = $resolved_studio_url;
								}

								break;
							}
						}
					}

					$studio_html = $studio_url !== ''
						? '<a href="' . esc_url( $studio_url ) . '">'
							. esc_html( $studio )
							. '</a>'
						: esc_html( $studio );
				}

				/* 原作類型 → anime_source_tax 歸檔頁 */
				$source_html = '';

				if ( $source_label !== '' ) {
					$source_url       = '';
					$source_tax_terms = get_the_terms( $post_id, 'anime_source_tax' );

					if ( is_array( $source_tax_terms ) && ! empty( $source_tax_terms ) ) {
						$resolved_source_url = get_term_link( $source_tax_terms[0] );

						if ( ! is_wp_error( $resolved_source_url ) ) {
							$source_url = $resolved_source_url;
						}
					}

					$source_html = $source_url !== ''
						? '<a href="' . esc_url( $source_url ) . '">'
							. esc_html( $source_label )
							. '</a>'
						: esc_html( $source_label );
				}

				$hero_meta_rows = [
					[
						'key'  => '原作者',
						'val'  => $author_html,
						'html' => true,
					],
					[
						'key'  => '原作類型',
						'val'  => $source_html,
						'html' => true,
					],
					[
						'key'  => '製作公司',
						'val'  => $studio_html,
						'html' => true,
					],
					[
						'key'  => '集數',
						'val'  => $ep_str,
						'html' => false,
					],
					[
						'key'  => '時長',
						'val'  => $duration > 0
							? $duration . ' 分鐘'
							: '',
						'html' => false,
					],
				];

				$allowed_link_html = [
					'a' => [
						'href'  => [],
						'title' => [],
					],
				];

				$has_hero_meta = false;

				foreach ( $hero_meta_rows as $row ) :
					$val = trim( (string) $row['val'] );

					if ( $val === '' ) {
						continue;
					}

					$has_hero_meta = true;
					?>
					<div class="asd-hside-info-row">
						<span class="asd-hside-info-key">
							<?php echo esc_html( $row['key'] ); ?>
						</span>
						<span class="asd-hside-info-val">
							<?php
							if ( ! empty( $row['html'] ) ) {
								echo wp_kses(
									$val,
									$allowed_link_html
								);
							} else {
								echo esc_html( $val );
							}
							?>
						</span>
					</div>
				<?php endforeach; ?>

				<?php if ( ! $has_hero_meta ) : ?>
					<p class="asd-hside-empty">暫無資料</p>
				<?php endif; ?>

			</div><!-- /.asd-hside-block -->

		</div><!-- /.asd-hero-new -->

		<?php
		$progress_value  = (int) (
			$user_anime_entry['progress']
				?? 0
		);

		$is_full_cleared = ! empty(
			$user_anime_entry['fullcleared']
		);

		$has_total = $episodes > 0;

		if ( $has_total ) {
			$progress_value = max(
				0,
				min( $episodes, $progress_value )
			);
		} else {
			$progress_value = max(
				0,
				$progress_value
			);
		}

		$progress_percent = $has_total
			? min(
				100,
				(int) round(
					$progress_value
					/ max( 1, $episodes )
					* 100
				)
			)
			: 0;

		$display_total = $has_total
			? $episodes
			: ( $ep_aired > 0 ? $ep_aired : '?' );
		?>

		<div
			class="smacg-track-bar"
			data-post-id="<?php echo esc_attr( $post_id ); ?>"
			data-episodes="<?php echo esc_attr( $episodes ); ?>"
			data-status="<?php echo esc_attr( $user_anime_entry['status'] ?? '' ); ?>"
			data-progress="<?php echo esc_attr( $progress_value ); ?>"
			data-favorited="<?php echo ! empty( $user_anime_entry['favorited'] ) ? '1' : '0'; ?>"
			data-fullcleared="<?php echo $is_full_cleared ? '1' : '0'; ?>"
		>
			<div class="smacg-track-main">
				<div class="smacg-status-group">
					<?php
					$tracking_statuses = [
						'want' => [
							'icon'     => '🔖',
							'label'    => '想看',
							'disabled' => false,
						],
						'watching' => [
							'icon'     => '▶',
							'label'    => '追番中',
							'disabled' => $is_not_aired,
						],
						'completed' => [
							'icon'     => '✓',
							'label'    => '已看完',
							'disabled' => $is_not_aired,
						],
						/* 暫停：看到一半停著、之後還想回來（等下一季、沒空追）。
						   與棄坑的差別是「還想繼續」，偏好推算仍算喜歡。 */
						'paused' => [
							'icon'     => '⏸',
							'label'    => '暫停',
							'disabled' => $is_not_aired,
						],
						'dropped' => [
							'icon'     => '✕',
							'label'    => '棄坑',
							'disabled' => false,
						],
					];

					foreach ( $tracking_statuses as $tracking_key => $tracking_status ) :
						$is_active =
							( $user_anime_entry['status'] ?? '' )
							=== $tracking_key;
						?>
						<button
							type="button"
							class="smacg-status-btn<?php echo $is_active ? ' is-active' : ''; ?>"
							data-action="status"
							data-value="<?php echo esc_attr( $tracking_key ); ?>"
							<?php if ( $tracking_status['disabled'] ) : ?>
								disabled
								aria-disabled="true"
								title="尚未播出，暫時無法使用此狀態"
							<?php else : ?>
								title="<?php echo esc_attr( $tracking_status['label'] ); ?>"
							<?php endif; ?>
						>
							<span class="smacg-ico" aria-hidden="true">
								<?php echo esc_html( $tracking_status['icon'] ); ?>
							</span>
							<span><?php echo esc_html( $tracking_status['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="smacg-track-sep"></div>

				<div class="smacg-progress-group">
					<div class="smacg-prog-top">
						<span class="smacg-prog-label">
							<?php
							if ( $is_full_cleared ) {
								echo '🎉 已看完！';
							} elseif ( ! $has_total && $ep_aired > 0 ) {
								echo '📡 連載中（已播 '
									. esc_html( $ep_aired )
									. ' 集）';
							} elseif ( ! $has_total ) {
								echo '📡 連載中';
							} elseif ( $progress_value > 0 ) {
								echo '📺 觀看中';
							} else {
								echo '&nbsp;';
							}
							?>
						</span>

						<span class="smacg-prog-pct">
							<?php
							echo $has_total
								? esc_html( $progress_percent . '%' )
								: '—';
							?>
						</span>
					</div>

					<?php if ( $has_total ) : ?>
						<div
							class="smacg-prog-bar-wrap"
							role="progressbar"
							aria-label="觀看進度"
							aria-valuemin="0"
							aria-valuemax="<?php echo esc_attr( $episodes ); ?>"
							aria-valuenow="<?php echo esc_attr( $progress_value ); ?>"
						>
							<div
								class="smacg-prog-bar"
								style="width:<?php echo esc_attr( $progress_percent ); ?>%;"
							></div>
						</div>
					<?php endif; ?>

					<div class="smacg-prog-controls">
						<button
							type="button"
							class="smacg-prog-btn"
							data-action="progress"
							data-value="-1"
							aria-label="觀看進度減一集"
						>−</button>

						<span class="smacg-prog-display">
							<span class="smacg-prog-current">
								<?php echo esc_html( $progress_value ); ?>
							</span>
							<span class="smacg-prog-sep"> / </span>
							<span class="smacg-prog-total">
								<?php echo esc_html( $display_total ); ?>
							</span>
							<span class="smacg-prog-unit"> 集</span>
						</span>

						<button
							type="button"
							class="smacg-prog-btn"
							data-action="progress"
							data-value="1"
							aria-label="觀看進度加一集"
						>＋</button>
					</div>
				</div>

				<div class="smacg-track-sep"></div>

				<div class="smacg-action-group">
					<button
						type="button"
						class="smacg-icon-btn smacg-fav-btn<?php echo ! empty( $user_anime_entry['favorited'] ) ? ' is-active' : ''; ?>"
						data-action="favorite"
						title="收藏"
						aria-pressed="<?php echo ! empty( $user_anime_entry['favorited'] ) ? 'true' : 'false'; ?>"
					>
						<span class="smacg-ico" aria-hidden="true">
							<?php echo ! empty( $user_anime_entry['favorited'] ) ? '⭐' : '☆'; ?>
						</span>
						<span class="smacg-icon-label">收藏</span>
					</button>

					<button
						type="button"
						class="smacg-icon-btn smacg-share-btn"
						data-action="share"
						data-title="<?php echo esc_attr( $display_title ); ?>"
						data-url="<?php echo esc_url( $share_permalink ); ?>"
						title="分享"
					>
						<span class="smacg-ico" aria-hidden="true">🔗</span>
						<span class="smacg-icon-label">分享</span>
					</button>
				</div>
			</div>

			<div
				class="smacg-point-toast"
				aria-live="polite"
			></div>

			<?php
			/*
			 * 追蹤人數（全站彙總）。
			 * 完全沒有人追蹤時整列不輸出，避免新作品頁出現一排 0。
			 * v1.2：搬進 .smacg-track-bar 卡片內，顯示在動作按鈕下方，
			 *       不再是卡片外面獨立浮著的一排文字。
			 */
			$track_stat_items = [];

			if ( $track_stats['want'] > 0 ) {
				$track_stat_items[] = [ '🔖', '想看', $track_stats['want'], 'want' ];
			}

			if ( $track_stats['watching'] > 0 ) {
				$track_stat_items[] = [ '▶', '追番中', $track_stats['watching'], 'watching' ];
			}

			if ( $track_stats['completed'] > 0 ) {
				$track_stat_items[] = [ '✓', '已看完', $track_stats['completed'], 'completed' ];
			}

			if ( $track_stats['favorited'] > 0 ) {
				$track_stat_items[] = [ '⭐', '收藏', $track_stats['favorited'], 'favorited' ];
			}
			?>

			<?php if ( ! empty( $track_stat_items ) ) : ?>
				<div class="smacg-track-stats">
					<?php foreach ( $track_stat_items as $track_stat_item ) : ?>
						<span class="smacg-track-stat smacg-track-stat--<?php echo esc_attr( $track_stat_item[3] ); ?>">
							<span
								class="smacg-track-stat-ico"
								aria-hidden="true"
							><?php echo esc_html( $track_stat_item[0] ); ?></span>

							<span class="smacg-track-stat-label">
								<?php echo esc_html( $track_stat_item[1] ); ?>
							</span>

							<b class="smacg-track-stat-num">
								<?php echo esc_html(
									number_format_i18n( $track_stat_item[2] )
								); ?>
							</b>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div><!-- /.smacg-track-bar -->

		<div
			class="smacg-share-modal"
			id="smacg-share-modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="smacg-share-title"
			hidden
		>
			<div class="smacg-share-inner">
				<p
					class="smacg-share-title"
					id="smacg-share-title"
				>
					分享《<?php echo esc_html( $display_title ); ?>》
				</p>

				<div class="smacg-share-btns">
					<a
						class="smacg-share-link smacg-share-x"
						href="<?php echo esc_url( $share_url_x ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						𝕏 / Twitter
					</a>

					<a
						class="smacg-share-link smacg-share-fb"
						href="<?php echo esc_url( $share_url_fb ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						Facebook
					</a>

					<button
						type="button"
						class="smacg-share-link smacg-share-copy"
						id="smacg-copy-link"
					>
						📋 複製連結
					</button>
				</div>

				<button
					type="button"
					class="smacg-share-close"
					id="smacg-share-close"
					aria-label="關閉分享視窗"
				>
					✕
				</button>
			</div>
		</div>

	<?php
	/* MV 燈箱：所有主題曲卡片共用同一個，點縮圖時由 JS 換 src 再播放，
	   同頁顯示原始尺寸影片，不開新分頁。 */
	?>
	<div class="asd-mv-modal" id="asd-mv-modal" role="dialog" aria-modal="true" aria-label="MV 播放" hidden>
		<div class="asd-mv-modal-inner">
			<button type="button" class="asd-mv-modal-close" id="asd-mv-modal-close" aria-label="關閉 MV">✕</button>
			<video class="asd-mv-modal-video" id="asd-mv-modal-video" controls playsinline></video>
			<p class="asd-mv-modal-error" id="asd-mv-modal-error" hidden>影片載入失敗，請重新嘗試再點擊播放一次</p>
		</div>
	</div>

		<div class="asd-tabs-wrap">
			<nav
				class="asd-tabs"
				id="asd-tabs"
				aria-label="本頁內容導航"
			>
				<a class="asd-tab" href="#asd-sec-info">📋 基本資訊</a>

				<?php if ( $editorial_note ) : ?>
					<a class="asd-tab" href="#asd-sec-editorial">✍️ 編輯短評</a>
				<?php endif; ?>

				<?php if ( $synopsis ) : ?>
					<a class="asd-tab" href="#asd-sec-synopsis">📝 劇情簡介</a>
				<?php endif; ?>

				<?php if ( $has_trailer ) : ?>
					<a class="asd-tab" href="#asd-sec-trailer">🎞 預告片</a>
				<?php endif; ?>

				<?php if ( ! empty( $episodes_list ) ) : ?>
					<a class="asd-tab" href="#asd-sec-episodes">📺 集數列表</a>
				<?php endif; ?>

				<?php if ( ! empty( $staff_list ) ) : ?>
					<a class="asd-tab" href="#asd-sec-staff">🎬 STAFF</a>
				<?php endif; ?>

				<?php if ( ! empty( $cast_to_display ) ) : ?>
					<a class="asd-tab" href="#asd-sec-cast">🎭 CAST</a>
				<?php endif; ?>

				<?php if ( ! empty( $openings ) || ! empty( $endings ) ) : ?>
					<a class="asd-tab" href="#asd-sec-music">🎵 主題曲</a>
				<?php endif; ?>

				<?php if ( $has_stream_section ) : ?>
					<a class="asd-tab" href="#asd-sec-stream">📺 串流</a>
				<?php endif; ?>

				<?php if ( $has_online_watch ) : ?>
					<a class="asd-tab" href="#asd-sec-online">▶ 線上看</a>
				<?php endif; ?>
				<?php if ( $has_source_manga ) : ?>
					<a class="asd-tab" href="#asd-sec-manga">📖 原作漫畫</a>
				<?php endif; ?>

				<?php if ( ! empty( $faq_display_items ) ) : ?>
					<a class="asd-tab" href="#asd-sec-faq">❓ 常見問題</a>
				<?php endif; ?>

				<?php
				$has_external_links =
					$official_site
					|| $twitter_url
					|| $wikipedia_url
					|| $tiktok_url
					|| $anilist_id
					|| $mal_id
					|| $bangumi_id;
				?>

				<?php if ( $has_external_links ) : ?>
					<a class="asd-tab" href="#asd-sec-links">🔗 資料來源</a>
				<?php endif; ?>

				<a class="asd-tab" href="#asd-sec-reviews">📝 評論</a>
			</nav>

			<div class="asd-container asd-container--has-sidebar">
				<main class="asd-main" id="asd-main">

					<section class="asd-section" id="asd-sec-info">
						<h2 class="asd-section-title">📋 基本資訊</h2>

						<div class="asd-info-grid">
							<?php
							/*
							 * 首播日欄位的標籤依狀態切換。
							 *
							 * AniList 的 startDate 對未播出作品是「預定首播日」，沿用
							 * 「開始日期」會讓一個還沒到的日期讀起來像已經發生。
							 */
							$start_date_label = ( $status === 'NOT_YET_RELEASED' )
								? '預定首播'
								: '首播日期';

							$info_rows = [
								'類型'       => [ 'text' => $format_label, 'url' => $hero_format_url ],
								'集數'       => $ep_str,
								'狀態'       => [ 'text' => $status_label, 'url' => $hero_status_url ],
								'播出季度'   => [ 'text' => $season_str,   'url' => $hero_season_url ],
								'每集時長'   => $duration > 0
									? $duration . ' 分鐘'
									: '',
								$start_date_label => $start_date,
								'完結日期'   =>
									$end_date && $status === 'FINISHED'
										? $end_date
										: '',
								'原作來源'   => [ 'text' => $source_label, 'url' => $source_url ?? '' ],
								'製作公司'   => [ 'text' => $studio,       'url' => $studio_url ?? '' ],
								'台灣代理'   => $tw_dist_display,
								'播出頻道'   => $tw_broadcast,
								'配音版本'   => ! empty( $dub_display )
									? implode( '、', $dub_display )
									: '',
								'資料更新日' => get_the_modified_date( 'Y-m-d' ),
							];

							foreach ( $info_rows as $info_label => $info_value ) :
								/*
								 * 值可以是純字串，或 [ 'text' => …, 'url' => … ]。
								 * 後者在有網址時輸出連結；取不到網址就自動退回純文字，
								 * 不會產生空的 href。
								 */
								$info_url = '';

								if ( is_array( $info_value ) ) {
									$info_url   = trim( (string) ( $info_value['url']  ?? '' ) );
									$info_value = (string) ( $info_value['text'] ?? '' );
								}

								$info_value = trim( (string) $info_value );

								if ( $info_value === '' ) {
									continue;
								}
								?>
								<div class="asd-info-row">
									<span class="asd-info-label">
										<?php echo esc_html( $info_label ); ?>
									</span>
									<span class="asd-info-val">
										<?php if ( $info_url !== '' ) : ?>
											<a href="<?php echo esc_url( $info_url ); ?>">
												<?php echo esc_html( $info_value ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $info_value ); ?>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>

						<?php
						$countdown_timestamp = is_numeric( $next_airing_raw )
							? (int) $next_airing_raw
							: (int) (
								$airing_data['airingAt']
									?? 0
							);

						$countdown_episode = (int) (
							$airing_data['episode']
								?? (
									$ep_aired > 0
										? $ep_aired + 1
										: 0
								)
						);
						?>

						<?php if (
							$status === 'RELEASING'
							&& $countdown_timestamp > time()
						) : ?>
							<div class="asd-airing-bar">
								<span>
									<?php if ( $countdown_episode > 0 ) : ?>
										第 <?php echo esc_html( $countdown_episode ); ?> 集播出倒數
									<?php else : ?>
										下一集播出倒數
									<?php endif; ?>
								</span>

								<strong
									class="asd-countdown"
									data-ts="<?php echo esc_attr( $countdown_timestamp ); ?>"
									aria-live="polite"
								></strong>
							</div>
						<?php endif; ?>
					</section>

					<?php if ( $editorial_note ) : ?>
						<section
							class="asd-section asd-section--editorial"
							id="asd-sec-editorial"
						>
							<h2 class="asd-section-title">✍️ 編輯短評</h2>

							<div class="asd-editorial-note">
								<?php
								echo wp_kses_post(
									wpautop( $editorial_note )
								);
								?>
							</div>

							<?php if ( $editorial_author || $editorial_updated ) : ?>
								<div class="asd-editorial-byline">
									<?php if ( $editorial_author ) : ?>
										<span class="asd-editorial-author">
											撰寫／複核：
											<?php echo esc_html( $editorial_author ); ?>
										</span>
									<?php endif; ?>

									<?php if ( $editorial_updated ) : ?>
										<span class="asd-editorial-date">
											最後人工複核：
											<time datetime="<?php echo esc_attr( $editorial_updated ); ?>">
												<?php echo esc_html( $editorial_updated ); ?>
											</time>
										</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( $synopsis ) : ?>
						<section class="asd-section" id="asd-sec-synopsis">
							<h2 class="asd-section-title">📝 劇情簡介</h2>
							<div class="asd-synopsis">
								<?php
								echo wp_kses_post(
									wpautop( $synopsis )
								);
								?>
							</div>
						</section>
					<?php endif; ?>

					<?php
					/*
					 * 消息更新：上游偵測到的資料異動，經後台人工補寫說明並發布後才會出現。
					 * 沒有已發布事件時整個區塊不輸出——絕大多數作品在累積初期都是 0 筆。
					 *
					 * $asd_events 已在頁首（視覺圖切換器）取過，此處沿用不重複查詢。
					 */
					?>

					<?php if ( ! empty( $asd_events ) ) : ?>
						<section class="asd-section" id="asd-sec-events">
							<h2 class="asd-section-title">📰 消息更新</h2>

							<ol class="asd-events">
								<?php foreach ( $asd_events as $asd_event ) : ?>
									<li class="asd-event">
										<time class="asd-event-date" datetime="<?php echo esc_attr( $asd_event->event_date ); ?>">
											<?php echo esc_html( $asd_event->event_date ); ?>
										</time>

										<div class="asd-event-body">
											<p class="asd-event-summary"><?php echo esc_html( $asd_event->summary ); ?></p>

											<?php
											/*
											 * 刻意不放圖：視覺圖統一在頁首的切換器呈現。
											 * 消息列表插大圖會把時間軸拉得很長，一則消息就佔掉一個螢幕，
											 * 反而看不出「這部作品最近發生了哪些事」。
											 */
											?>
										</div>
									</li>
								<?php endforeach; ?>
							</ol>
						</section>
					<?php endif; ?>

					<?php if ( $has_trailer ) : ?>
						<section class="asd-section" id="asd-sec-trailer">
							<h2 class="asd-section-title">
								🎞 預告片
								<?php if ( count( $trailer_items ) > 1 ) : ?>
									<span class="asd-pv-count">
										（<?php echo esc_html( count( $trailer_items ) ); ?>）
									</span>
								<?php endif; ?>
							</h2>

							<div
								class="asd-pv-box"
								data-pv-count="<?php echo esc_attr( count( $trailer_items ) ); ?>"
							>
								<?php if ( count( $trailer_items ) > 1 ) : ?>
									<div
										class="asd-pv-tabs"
										role="tablist"
										aria-label="預告片切換"
									>
										<?php foreach ( $trailer_items as $trailer_index => $trailer_item ) : ?>
											<button
												type="button"
												id="asd-pv-tab-<?php echo (int) $trailer_index; ?>"
												class="asd-pv-tab<?php echo $trailer_index === 0 ? ' is-active' : ''; ?>"
												role="tab"
												aria-selected="<?php echo $trailer_index === 0 ? 'true' : 'false'; ?>"
												aria-controls="asd-pv-panel-<?php echo (int) $trailer_index; ?>"
												tabindex="<?php echo $trailer_index === 0 ? '0' : '-1'; ?>"
												data-pv-index="<?php echo (int) $trailer_index; ?>"
												data-pv-id="<?php echo esc_attr( $trailer_item['id'] ); ?>"
											>
												<span class="asd-pv-tab-icon" aria-hidden="true">▶</span>
												<span class="asd-pv-tab-label">
													<?php echo esc_html( $trailer_item['label'] ); ?>
												</span>
											</button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<div class="asd-pv-panels">
									<?php foreach ( $trailer_items as $trailer_index => $trailer_item ) : ?>
										<div
											class="asd-pv-panel<?php echo $trailer_index === 0 ? ' is-active' : ''; ?>"
											id="asd-pv-panel-<?php echo (int) $trailer_index; ?>"
											role="tabpanel"
											aria-labelledby="asd-pv-tab-<?php echo (int) $trailer_index; ?>"
											<?php echo $trailer_index === 0 ? '' : 'hidden'; ?>
											data-pv-index="<?php echo (int) $trailer_index; ?>"
											data-pv-id="<?php echo esc_attr( $trailer_item['id'] ); ?>"
										>
											<div class="asd-trailer-wrap">
												<iframe
													src="<?php echo esc_url( 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $trailer_item['id'] ) ); ?>"
													title="<?php echo esc_attr( $display_title . ' ' . $trailer_item['label'] ); ?>"
													allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
													referrerpolicy="strict-origin-when-cross-origin"
													allowfullscreen
													loading="lazy"
												></iframe>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $episodes_list ) ) : ?>
						<section class="asd-section" id="asd-sec-episodes">
							<h2 class="asd-section-title">📺 集數列表</h2>

							<div class="asd-ep-list" id="asd-ep-list">
								<?php
								$episode_output_index = 0;

								/*
								 * SP 沒有正規集數（Bangumi 的 ep 欄位為 0），不能沿用
								 * 「第 N 集」的 index fallback，否則會被顯示成某一集。
								 * 只有一個 SP 時標「SP」，多個才加編號。
								 */
								$sp_total = 0;
								foreach ( $episodes_list as $sp_probe ) {
									if ( is_array( $sp_probe ) && (int) ( $sp_probe['type'] ?? 0 ) === 1 ) {
										$sp_total++;
									}
								}
								$sp_output_index = 0;

								foreach ( $episodes_list as $episode_index => $episode_item ) :
									if ( ! is_array( $episode_item ) ) {
										continue;
									}

									$episode_number = (float) (
										$episode_item['ep']
											?? 0
									);

									$episode_name_cn = trim(
										(string) (
											$episode_item['name_cn']
												?? ''
										)
									);

									$episode_name_ja = trim(
										(string) (
											$episode_item['name']
												?? ''
										)
									);

									$episode_airdate = trim(
										(string) (
											$episode_item['airdate']
												?? ''
										)
									);

									if (
										$episode_name_cn !== ''
										&& class_exists( 'Anime_Sync_CN_Converter' )
									) {
										$episode_name_cn =
											Anime_Sync_CN_Converter::static_convert(
												$episode_name_cn
											);
									}

									$episode_name =
										$episode_name_cn
											?: $episode_name_ja;

									$episode_number_display =
										floor( $episode_number ) === $episode_number
											? (int) $episode_number
											: $episode_number;

									$episode_type = (int) (
										$episode_item['type']
											?? 0
									);

									if ( $episode_type === 1 ) {
										$sp_output_index++;
										$episode_display = $sp_total > 1
											? 'SP' . $sp_output_index
											: 'SP';
									} else {
										$episode_display = $episode_number > 0
											? '第' . $episode_number_display . '集'
											: '第' . ( $episode_index + 1 ) . '集';
									}
									?>
									<div class="asd-ep-row<?php echo $episode_output_index >= 3 ? ' asd-ep-hidden' : ''; ?>">
										<span class="asd-ep-num">
											<?php echo esc_html( $episode_display ); ?>
										</span>

										<div class="asd-ep-body">
											<?php if ( $episode_name ) : ?>
												<span class="asd-ep-title">
													<?php echo esc_html( $episode_name ); ?>
												</span>
											<?php endif; ?>

											<?php if (
												$episode_name_ja
												&& $episode_name_cn
												&& $episode_name_ja !== $episode_name_cn
											) : ?>
												<span class="asd-ep-title-ja" lang="ja">
													<?php echo esc_html( $episode_name_ja ); ?>
												</span>
											<?php endif; ?>
										</div>

										<?php if ( $episode_airdate ) : ?>
											<time
												class="asd-ep-date"
												datetime="<?php echo esc_attr( $format_date( $episode_airdate ) ); ?>"
											>
												<?php echo esc_html( $episode_airdate ); ?>
											</time>
										<?php endif; ?>
									</div>
									<?php
									$episode_output_index++;
								endforeach;
								?>
							</div>

							<?php if ( $episode_output_index > 3 ) : ?>
								<div class="asd-toggle-wrap">
									<button
										class="asd-ep-toggle"
										type="button"
										aria-expanded="false"
										aria-controls="asd-ep-list"
									>
										顯示全部 <?php echo esc_html( $episode_output_index ); ?> 集 ▼
									</button>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $staff_list ) ) : ?>
						<section class="asd-section" id="asd-sec-staff">
							<h2 class="asd-section-title">🎬 STAFF</h2>

							<div class="asd-staff-grid-v2" id="asd-staff-grid">
								<?php
								$staff_output_index = 0;

								foreach ( $staff_list as $staff_item ) :
									if ( ! is_array( $staff_item ) ) {
										continue;
									}

									$staff_id = (int) (
										$staff_item['id']
											?? 0
									);

									$staff_name = trim(
										(string) (
											$staff_item['name']
												?? ''
										)
									);

									$staff_native = trim(
										(string) (
											$staff_item['native']
												?? ''
										)
									);

									$staff_role_raw = trim(
										(string) (
											$staff_item['role']
												?? ''
										)
									);

									if ( $staff_name === '' ) {
										continue;
									}

									$staff_role = function_exists( 'wxacg_staff_role' )
										? wxacg_staff_role( $staff_role_raw )
										: $staff_role_raw;

									$staff_role = trim( (string) $staff_role );

									$staff_url = $staff_id > 0
										? $entity_url(
											'person',
											$staff_id,
											$staff_name
										)
										: '';
									?>
									<div class="asd-staff-card-v2<?php echo $staff_output_index >= 10 ? ' asd-staff-hidden' : ''; ?>">
										<div class="asd-staff-info">
											<?php if ( $staff_role ) : ?>
												<span class="asd-staff-role">
													<?php echo esc_html( $staff_role ); ?>
												</span>
											<?php endif; ?>

											<span class="asd-staff-name">
												<?php if ( $staff_url ) : ?>
													<a href="<?php echo esc_url( $staff_url ); ?>">
														<?php echo esc_html( $staff_name ); ?>
													</a>
												<?php else : ?>
													<?php echo esc_html( $staff_name ); ?>
												<?php endif; ?>
											</span>

											<?php if (
												$staff_native
												&& $staff_native !== $staff_name
											) : ?>
												<span class="asd-staff-native" lang="ja">
													<?php echo esc_html( $staff_native ); ?>
												</span>
											<?php endif; ?>
										</div>
									</div>
									<?php
									$staff_output_index++;
								endforeach;
								?>
							</div>

							<?php if ( $staff_output_index > 10 ) : ?>
								<div class="asd-toggle-wrap">
									<button
										class="asd-staff-toggle"
										id="asd-staff-toggle"
										type="button"
										aria-expanded="false"
										aria-controls="asd-staff-grid"
									>
										顯示全部 <?php echo esc_html( $staff_output_index ); ?> 人 ▼
									</button>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $cast_to_display ) ) : ?>
						<section class="asd-section" id="asd-sec-cast">
							<h2 class="asd-section-title">🎭 CAST</h2>

							<div class="asd-cast-grid" id="asd-cast-grid">
								<?php
								$cast_output_index = 0;

								foreach ( $cast_to_display as $cast_item ) :
									if ( ! is_array( $cast_item ) ) {
										continue;
									}

									$character_id = (int) (
										$cast_item['id']
											?? 0
									);

									$character_name = trim(
										(string) (
											$cast_item['name']
												?? ''
										)
									);

									$character_native = trim(
										(string) (
											$cast_item['native']
												?? ''
										)
									);

									$character_image = trim(
										(string) (
											$cast_item['image']
												?? ''
										)
									);

									if ( $character_name === '' ) {
										continue;
									}

									$voice_actors = (
										! empty( $cast_item['voice_actors'] )
										&& is_array( $cast_item['voice_actors'] )
									)
										? $cast_item['voice_actors']
										: [];

									$voice_actor = $voice_actors[0] ?? [];
									$voice_actor = is_array( $voice_actor )
										? $voice_actor
										: [];

									$voice_id = (int) (
										$voice_actor['id']
											?? 0
									);

									$voice_name = trim(
										(string) (
											$voice_actor['name']
												?? ''
										)
									);

									$voice_native = trim(
										(string) (
											$voice_actor['native']
												?? ''
										)
									);

									$character_fallback = $fallback_text(
										$character_name,
										2
									);

									$character_is_bangumi =
										( $cast_item['source'] ?? '' )
										=== 'bangumi';

									$character_url = (
										$character_is_bangumi
										&& $character_id > 0
									)
										? $entity_url(
											'character',
											$character_id,
											$character_name
										)
										: '';

									$voice_url = $voice_id > 0
										? $entity_url(
											'person',
											$voice_id,
											$voice_name
										)
										: '';

									$other_voice_actors = [];
									foreach ( array_slice( $voice_actors, 1 ) as $other_va ) {
										if ( ! is_array( $other_va ) ) {
											continue;
										}
										$other_name = trim( (string) ( $other_va['name'] ?? '' ) );
										if ( $other_name === '' ) {
											continue;
										}
										$other_id  = (int) ( $other_va['id'] ?? 0 );
										$other_url = $other_id > 0
											? $entity_url( 'person', $other_id, $other_name )
											: '';
										$other_voice_actors[] = [
											'name' => $other_name,
											'url'  => $other_url,
										];
									}
									?>
									<div class="asd-cast-card<?php echo $cast_output_index >= 6 ? ' asd-cast-hidden' : ''; ?>">
										<?php if ( $character_url ) : ?>
											<a
												href="<?php echo esc_url( $character_url ); ?>"
												class="asd-cast-avatar-wrap asd-cast-avatar-wrap--link"
												aria-label="<?php echo esc_attr( $character_name ); ?>"
											>
										<?php else : ?>
											<div class="asd-cast-avatar-wrap">
										<?php endif; ?>

										<?php if ( $character_image ) : ?>
											<img
												src="<?php echo esc_url( $character_image ); ?>"
												alt="<?php echo esc_attr( $character_name ); ?>"
												loading="lazy"
												decoding="async"
											>
											<div
												class="asd-cast-avatar-fb asd-cast-avatar-fb--backup"
												hidden
											>
												<span><?php echo esc_html( $character_fallback ); ?></span>
											</div>
										<?php else : ?>
											<div class="asd-cast-avatar-fb">
												<span><?php echo esc_html( $character_fallback ); ?></span>
											</div>
										<?php endif; ?>

										<?php if ( $character_url ) : ?>
											</a>
										<?php else : ?>
											</div>
										<?php endif; ?>

										<div class="asd-cast-info">
											<span class="asd-cast-char">
												<?php if ( $character_url ) : ?>
													<a href="<?php echo esc_url( $character_url ); ?>">
														<?php echo esc_html( $character_name ); ?>
													</a>
												<?php else : ?>
													<?php echo esc_html( $character_name ); ?>
												<?php endif; ?>
											</span>

											<?php if (
												$character_native
												&& $character_native !== $character_name
											) : ?>
												<span class="asd-cast-char-native" lang="ja">
													<?php echo esc_html( $character_native ); ?>
												</span>
											<?php endif; ?>

											<?php if ( $voice_name ) : ?>
												<div class="asd-cast-va">
													<div class="asd-cast-va-info">
														<span class="asd-cast-va-name">
															CV.
															<?php if ( $voice_url ) : ?>
																<a href="<?php echo esc_url( $voice_url ); ?>">
																	<?php echo esc_html( $voice_name ); ?>
																</a>
															<?php else : ?>
																<?php echo esc_html( $voice_name ); ?>
															<?php endif; ?>
														</span>

														<?php if (
															$voice_native
															&& $voice_native !== $voice_name
														) : ?>
															<span class="asd-cast-va-native" lang="ja">
																<?php echo esc_html( $voice_native ); ?>
															</span>
														<?php endif; ?>
													</div>

													<?php if ( ! empty( $other_voice_actors ) ) : ?>
														<details class="asd-cast-va-other">
															<summary>其他配音 (<?php echo count( $other_voice_actors ); ?>)</summary>
															<ul>
																<?php foreach ( $other_voice_actors as $other_va ) : ?>
																	<li>
																		<?php if ( $other_va['url'] ) : ?>
																			<a href="<?php echo esc_url( $other_va['url'] ); ?>">
																				<?php echo esc_html( $other_va['name'] ); ?>
																			</a>
																		<?php else : ?>
																			<?php echo esc_html( $other_va['name'] ); ?>
																		<?php endif; ?>
																	</li>
																<?php endforeach; ?>
															</ul>
														</details>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									</div>
									<?php
									$cast_output_index++;
								endforeach;
								?>
							</div>

							<?php if ( $cast_output_index > 6 ) : ?>
								<div class="asd-toggle-wrap">
									<button
										class="asd-cast-toggle"
										id="asd-cast-toggle"
										type="button"
										aria-expanded="false"
										aria-controls="asd-cast-grid"
									>
										顯示全部 <?php echo esc_html( $cast_output_index ); ?> 人 ▼
									</button>
								</div>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $openings ) || ! empty( $endings ) ) : ?>
						<section class="asd-section" id="asd-sec-music">
							<h2 class="asd-section-title">🎵 主題曲</h2>

							<?php
							$music_groups = [
								'OP' => $openings,
								'ED' => $endings,
							];

							foreach ( $music_groups as $music_type => $music_list ) :
								if ( empty( $music_list ) ) {
									continue;
								}
								?>
								<div class="asd-music-group">
									<h3 class="asd-music-group-title">
										<?php echo $music_type === 'OP' ? '片頭曲 OP' : '片尾曲 ED'; ?>
									</h3>

									<?php foreach ( $music_list as $theme_item ) :
										if ( ! is_array( $theme_item ) ) {
											continue;
										}

										$theme_type = strtoupper(
											trim(
												(string) (
													$theme_item['type']
														?? ''
												)
											)
										);

										$theme_title = trim(
											(string) (
												$theme_item['title']
													?? ''
											)
										);

										$theme_native = trim(
											(string) (
												$theme_item['title_native']
													?? ''
											)
										);

										$theme_artists = is_array(
											$theme_item['artists']
												?? null
										)
											? $theme_item['artists']
											: [];

										$artist_names        = [];
										$artist_romaji_names = [];

										foreach ( $theme_artists as $artist_item ) {
											if ( ! is_array( $artist_item ) ) {
												continue;
											}

											$artist_display = trim(
												(string) (
													$artist_item['name_native']
														?? $artist_item['name']
														?? ''
												)
											);

											$artist_romaji = trim(
												(string) (
													$artist_item['name']
														?? ''
												)
											);

											if ( $artist_display !== '' ) {
												$artist_names[] = $artist_display;
											}

											if ( $artist_romaji !== '' ) {
												$artist_romaji_names[] = $artist_romaji;
											}
										}

										$artist_display = implode(
											'、',
											array_unique( $artist_names )
										);

										$artist_romaji = implode(
											', ',
											array_unique( $artist_romaji_names )
										);

										$audio_url = trim(
											(string) (
												$theme_item['audio_url']
													?? ''
											)
										);

										$video_url = trim(
											(string) (
												$theme_item['video_url']
													?? ''
											)
										);

										if (
											$audio_url !== ''
											&& ! wp_http_validate_url( $audio_url )
										) {
											$audio_url = '';
										}

										if (
											$video_url !== ''
											&& ! wp_http_validate_url( $video_url )
										) {
											$video_url = '';
										}

										$theme_episodes = trim(
											(string) (
												$theme_item['episodes']
													?? ''
											)
										);

										$open_media_url =
											$video_url ?: $audio_url;

										$music_main = $theme_native !== ''
											? $theme_native
											: $theme_title;

										$music_sub = (
											$theme_native !== ''
											&& $theme_title !== ''
											&& $theme_title !== $theme_native
										)
											? $theme_title
											: '';

										$music_badge_class =
											strpos( $theme_type, 'OP' ) === 0
												? 'asd-music-type-badge--op'
												: 'asd-music-type-badge--ed';
										?>
										<div class="asd-music-card-v2">
											<span class="asd-music-type-badge <?php echo esc_attr( $music_badge_class ); ?>">
												<?php echo esc_html( $theme_type ); ?>
											</span>

											<div class="asd-music-body">
												<?php if ( $music_main ) : ?>
													<span class="asd-music-title">
														<?php echo esc_html( $music_main ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $music_sub ) : ?>
													<span class="asd-music-native">
														<?php echo esc_html( $music_sub ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $artist_display ) : ?>
													<span class="asd-music-artist">
														by <?php echo esc_html( $artist_display ); ?>
														<?php if (
															$artist_romaji
															&& $artist_romaji !== $artist_display
														) : ?>
															<span class="asd-music-artist-romaji">
																(<?php echo esc_html( $artist_romaji ); ?>)
															</span>
														<?php endif; ?>
													</span>
												<?php elseif ( $artist_romaji ) : ?>
													<span class="asd-music-artist">
														by <?php echo esc_html( $artist_romaji ); ?>
													</span>
												<?php endif; ?>

												<?php if ( $theme_episodes ) : ?>
													<span class="asd-music-episodes">
														<?php echo esc_html( $theme_episodes ); ?>
													</span>
												<?php endif; ?>
											</div>

											<?php if ( $video_url ) : ?>
												<div
													class="asd-music-thumb-slot"
													role="button"
													tabindex="0"
													aria-label="播放 MV"
												>
													<video
														class="asd-music-thumb-video"
														data-src="<?php echo esc_url( $video_url ); ?>"
														preload="none"
														muted
														playsinline
													></video>
													<span class="asd-music-thumb-play" aria-hidden="true">▶</span>
												</div>
											<?php endif; ?>

											<?php if ( $audio_url || $video_url ) : ?>
												<div
													class="asd-music-player-wrap"
													data-audio-src="<?php echo esc_url( $audio_url ); ?>"
													data-video-src="<?php echo esc_url( $video_url ); ?>"
												>
													<audio class="asd-music-audio" preload="none"></audio>
													<video
														class="asd-music-video"
														preload="none"
														playsinline
														hidden
													></video>

													<button
														class="asd-music-play-btn"
														type="button"
														aria-label="播放"
													></button>

													<div
														class="asd-music-progress-wrap"
														role="slider"
														aria-label="播放進度"
														aria-valuemin="0"
														aria-valuemax="100"
														aria-valuenow="0"
														tabindex="0"
													>
														<div class="asd-music-progress-bar"></div>
													</div>

													<span class="asd-music-time">0:00</span>

													<?php if ( ! $video_url && $open_media_url ) : ?>
														<a
															class="asd-music-open-link"
															href="<?php echo esc_url( $open_media_url ); ?>"
															target="_blank"
															rel="noopener noreferrer"
														>
															MV
														</a>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>

							<p class="asd-stream-disclaimer" style="margin-top:20px !important;">
								主題曲影音由外部資料庫即時提供，載入速度依網路狀況而定，若遇到緩衝、讀取較慢或播放失敗，請耐心等候或重新點擊播放一次。
							</p>
						</section>
					<?php endif; ?>

					<?php if ( $has_stream_section ) : ?>
						<section class="asd-section" id="asd-sec-stream">
							<h2 class="asd-section-title">📺 合法串流平台</h2>

							<?php if ( ! $has_tw_stream && $google_search_url ) : ?>
								<div class="asd-stream-region asd-stream-region--google">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--google" aria-hidden="true"></span>
										<span>台灣暫無確認的上架平台</span>
									</div>

									<p class="asd-stream-description">
										目前尚未確認本作在台灣的合法串流資訊，可透過搜尋引擎查詢最新授權狀態。
									</p>

									<div class="asd-stream-list">
										<a
											href="<?php echo esc_url( $google_search_url ); ?>"
											target="_blank"
											rel="noopener noreferrer nofollow"
											class="asd-stream-btn"
											title="<?php echo esc_attr( $display_title ); ?> Google 搜尋"
										>
											<span class="asd-stream-label">🔍 搜尋合法觀看管道</span>
										</a>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $tw_streaming_items ) ) : ?>
								<div class="asd-stream-region asd-stream-region--tw">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--tw" aria-hidden="true"></span>
										<span>台港澳地區</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $tw_streaming_items as $stream_item ) :
											$stream_label = trim(
												(string) (
													$stream_item['label']
														?? ''
												)
											);

											$stream_url = trim(
												(string) (
													$stream_item['url']
														?? ''
												)
											);

											$stream_icon = trim(
												(string) (
													$stream_item['icon_url']
														?? ''
												)
											);

											$stream_icon_only =
												! empty( $stream_item['icon_only'] );

											if ( $stream_label === '' ) {
												continue;
											}

											$stream_class =
												'asd-stream-btn'
												. (
													$stream_icon_only
														? ' asd-stream-btn--icon-only'
														: ''
												)
												. (
													$stream_url
														? ''
														: ' asd-stream-btn--no-link'
												);
											?>

											<?php if ( $stream_url ) : ?>
												<a
													href="<?php echo esc_url( $stream_url ); ?>"
													target="_blank"
													rel="noopener noreferrer"
													class="<?php echo esc_attr( $stream_class ); ?>"
													title="<?php echo esc_attr( $stream_label ); ?>"
												>
											<?php else : ?>
												<span
													class="<?php echo esc_attr( $stream_class ); ?>"
													title="<?php echo esc_attr( $stream_label ); ?>"
												>
											<?php endif; ?>

											<?php if ( $stream_icon ) : ?>
												<img
													src="<?php echo esc_url( $stream_icon ); ?>"
													alt=""
													class="asd-stream-icon"
													loading="lazy"
													decoding="async"
												>
											<?php endif; ?>

											<span class="asd-stream-label">
												<?php echo esc_html( $stream_label ); ?>
											</span>

											<?php if ( $stream_url ) : ?>
												</a>
											<?php else : ?>
												</span>
											<?php endif; ?>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $dub_items_all ) ) : ?>
								<div class="asd-stream-region asd-stream-region--dub">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--dub" aria-hidden="true"></span>
										<span>中文／台語配音</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $dub_items_all as $dub_item ) :
											$dub_label = trim(
												(string) (
													$dub_item['label']
														?? ''
												)
											);

											$dub_url = trim(
												(string) (
													$dub_item['url']
														?? ''
												)
											);

											$dub_icon = trim(
												(string) (
													$dub_item['icon']
														?? ''
												)
											);

											if ( ! $dub_icon ) {
												$dub_icon = $guess_dub_icon(
													$dub_label,
													$dub_url
												);
											}

											if ( ! $dub_url ) {
												continue;
											}
											?>
											<a
												href="<?php echo esc_url( $dub_url ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												class="asd-stream-btn asd-stream-btn--dub"
												title="<?php echo esc_attr( $dub_label ); ?>"
											>
												<?php if ( $dub_icon ) : ?>
													<img
														src="<?php echo esc_url( $dub_icon ); ?>"
														alt=""
														class="asd-stream-icon"
														loading="lazy"
														decoding="async"
													>
												<?php endif; ?>

												<span class="asd-stream-label">
													<?php echo esc_html( $dub_label ); ?>
												</span>
											</a>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $overseas_streams ) ) : ?>
								<div class="asd-stream-region asd-stream-region--os">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--os" aria-hidden="true"></span>
										<span>海外平台</span>
										<span class="asd-stream-region-note">台港澳地區可能無法觀看</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $overseas_streams as $overseas_item ) :
											if ( ! is_array( $overseas_item ) ) {
												continue;
											}

											$overseas_site = trim(
												(string) (
													$overseas_item['site']
														?? ''
												)
											);

											$overseas_url = trim(
												(string) (
													$overseas_item['url']
														?? ''
												)
											);

											if (
												$overseas_site === ''
												|| $overseas_url === ''
												|| ! wp_http_validate_url( $overseas_url )
											) {
												continue;
											}

											/* 優先用登錄表的 match_site() 模糊比對(跟 AniList 自動同步
											 * 用的是同一套邏輯)，涵蓋後台手動輸入跟正式 label 不完全一致
											 * 的寫法(例如「Disney Plus」對正式 label「Disney+」)；
											 * 比對不到才退回直接用 label 小寫查表。 */
											$overseas_platform_key = $has_streaming_registry
												? Anime_Sync_Streaming_Registry::match_site( $overseas_site, $overseas_url )
												: null;

											$overseas_key = $overseas_platform_key ?? strtolower( $overseas_site );

											$overseas_icon =
												isset( $provider_icon_map[ $overseas_key ] )
													? $provider_icon_base
														. $provider_icon_map[ $overseas_key ]
													: '';
											?>
											<a
												href="<?php echo esc_url( $overseas_url ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												class="asd-stream-btn<?php echo $overseas_icon ? ' asd-stream-btn--icon-only' : ''; ?> asd-stream-btn--os"
												title="<?php echo esc_attr( $overseas_site ); ?>"
											>
												<?php if ( $overseas_icon ) : ?>
													<img
														src="<?php echo esc_url( $overseas_icon ); ?>"
														alt=""
														class="asd-stream-icon"
														loading="lazy"
														decoding="async"
													>
												<?php endif; ?>

												<span class="asd-stream-label">
													<?php echo esc_html( $overseas_site ); ?>
												</span>
											</a>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<p class="asd-stream-disclaimer" style="margin-top:20px !important;">
								串流授權、方案及地區限制可能隨時異動，實際供應狀況請以平台與代理商公告為準。
							</p>
						</section>
					<?php endif; ?>

					<?php if ( $has_online_watch ) : ?>
						<section class="asd-section" id="asd-sec-online">
							<h2 class="asd-section-title">
								▶ 官方線上看
								<?php if ( count( $online_watch_items ) > 1 ) : ?>
									<span class="asd-pv-count">
										（<?php echo esc_html( count( $online_watch_items ) ); ?>）
									</span>
								<?php endif; ?>
							</h2>

							<div
								class="asd-pv-box asd-ow-box"
								data-ow-count="<?php echo esc_attr( count( $online_watch_items ) ); ?>"
							>
								<?php if ( count( $online_watch_items ) > 1 ) : ?>
									<div
										class="asd-pv-tabs asd-ow-tabs"
										role="tablist"
										aria-label="線上看切換"
									>
										<?php foreach ( $online_watch_items as $online_index => $online_item ) : ?>
											<button
												type="button"
												id="asd-ow-tab-<?php echo (int) $online_index; ?>"
												class="asd-pv-tab asd-ow-tab<?php echo $online_index === 0 ? ' is-active' : ''; ?>"
												role="tab"
												aria-selected="<?php echo $online_index === 0 ? 'true' : 'false'; ?>"
												aria-controls="asd-ow-panel-<?php echo (int) $online_index; ?>"
												tabindex="<?php echo $online_index === 0 ? '0' : '-1'; ?>"
												data-ow-index="<?php echo (int) $online_index; ?>"
												data-ow-id="<?php echo esc_attr( $online_item['id'] ); ?>"
											>
												<span class="asd-pv-tab-icon" aria-hidden="true">▶</span>
												<span class="asd-pv-tab-label">
													<?php echo esc_html( $online_item['label'] ); ?>
												</span>
											</button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<div class="asd-pv-panels asd-ow-panels">
									<?php foreach ( $online_watch_items as $online_index => $online_item ) :
										$online_embed_url =
											( $online_item['type'] ?? 'video' ) === 'playlist'
												? 'https://www.youtube-nocookie.com/embed/videoseries?list='
													. rawurlencode( $online_item['id'] )
												: 'https://www.youtube-nocookie.com/embed/'
													. rawurlencode( $online_item['id'] );
										?>
										<div
											class="asd-pv-panel asd-ow-panel<?php echo $online_index === 0 ? ' is-active' : ''; ?>"
											id="asd-ow-panel-<?php echo (int) $online_index; ?>"
											role="tabpanel"
											aria-labelledby="asd-ow-tab-<?php echo (int) $online_index; ?>"
											<?php echo $online_index === 0 ? '' : 'hidden'; ?>
											data-ow-index="<?php echo (int) $online_index; ?>"
											data-ow-id="<?php echo esc_attr( $online_item['id'] ); ?>"
											data-ow-type="<?php echo esc_attr( $online_item['type'] ?? 'video' ); ?>"
										>
											<div class="asd-trailer-wrap">
												<iframe
													src="<?php echo esc_url( $online_embed_url ); ?>"
													title="<?php echo esc_attr( $display_title . ' ' . $online_item['label'] ); ?>"
													allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
													referrerpolicy="strict-origin-when-cross-origin"
													allowfullscreen
													loading="lazy"
												></iframe>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>

							<p class="asd-stream-disclaimer" style="margin-top:20px !important;">
								影片由 YouTube 頻道提供。若無法播放，可能是影片已下架、限制嵌入、設有地區限制或需要頻道會員資格。
							</p>
						</section>
					<?php endif; ?>

					<?php /* 看原作漫畫（接在「線上看」之後——同樣是「去哪裡看」的延伸） */ ?>
					<?php if ( $has_source_manga ) : ?>
						<section class="asd-section" id="asd-sec-manga">
							<h2 class="asd-section-title">
								📖 《<?php echo esc_html( $display_title ); ?>》原作漫畫哪裡看?
							</h2>

							<div class="asd-read-channels">
								<?php if ( $source_manga_local > 0 ) : ?>
									<?php /* 站內已有這部漫畫:優先導向自家頁面 */ ?>
									<a href="<?php echo esc_url( get_permalink( $source_manga_local ) ); ?>"
									   class="asd-read-channel asd-read-channel--primary">
										<span class="asd-read-channel-name">本站漫畫資料</span>
										<span class="asd-read-channel-badge">卷數・台版資訊</span>
									</a>
								<?php endif; ?>

								<?php if ( $source_manga_shop_url !== '' ) : ?>
									<a href="<?php echo esc_url( $source_manga_shop_url ); ?>"
									   target="_blank"
									   <?php /* 聯盟連結必須同時標 sponsored 與 nofollow */ ?>
									   rel="noopener noreferrer nofollow sponsored"
									   class="asd-read-channel">
										<span class="asd-read-channel-name">Renta! 台灣</span>
										<span class="asd-read-channel-badge">看漫畫・繁中</span>
									</a>
								<?php endif; ?>
							</div>

							<p class="asd-read-channels-tip">
								動畫進度追完了想看後續，可以從原作漫畫接下去。以上為正版電子書平台連結。
							</p>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $faq_display_items ) ) : ?>
						<section class="asd-section" id="asd-sec-faq">
							<h2 class="asd-section-title">❓ 常見問題</h2>

							<div class="asd-faq-list">
								<?php foreach ( $faq_display_items as $faq_item ) : ?>
									<div class="asd-faq-item">
										<div class="asd-faq-q">
											<span class="asd-faq-q-label" aria-hidden="true">Q.</span>
											<span class="asd-faq-q-text">
												<?php echo esc_html( $faq_item['q'] ); ?>
											</span>
										</div>

										<div class="asd-faq-a">
											<span class="asd-faq-a-label" aria-hidden="true">A.</span>
											<div class="asd-faq-a-text">
												<?php
												echo wp_kses_post(
													wpautop( $faq_item['a'] )
												);
												?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php
					$clean_anilist = $anilist_id > 0
						? (string) $anilist_id
						: '';

					$clean_mal = $mal_id > 0
						? (string) $mal_id
						: '';

					$clean_bangumi = $bangumi_id > 0
						? (string) $bangumi_id
						: '';
					?>

					<?php if ( $has_external_links ) : ?>
						<section class="asd-section" id="asd-sec-links">
							<h2 class="asd-section-title">🔗 資料來源與外部連結</h2>

							<p class="asd-stream-description">
								作品資料可能參考官方網站及公開資料庫，播出與授權資訊仍應以官方公告為準。
							</p>

							<div class="asd-ext-links-grid">
								<?php if ( $official_site ) : ?>
									<a
										href="<?php echo esc_url( $official_site ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card"
									>
										<span class="asd-ext-site">🌐 官方網站</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $twitter_url ) : ?>
									<a
										href="<?php echo esc_url( $twitter_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card"
									>
										<span class="asd-ext-site">𝕏 Twitter / X</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $wikipedia_url ) : ?>
									<a
										href="<?php echo esc_url( $wikipedia_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card"
									>
										<span class="asd-ext-site">📖 Wikipedia</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $tiktok_url ) : ?>
									<a
										href="<?php echo esc_url( $tiktok_url ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card"
									>
										<span class="asd-ext-site">🎵 TikTok</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_anilist ) : ?>
									<a
										href="<?php echo esc_url( 'https://anilist.co/anime/' . $clean_anilist ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card asd-ext--al"
									>
										<span class="asd-ext-site">🔵 AniList</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_mal ) : ?>
									<a
										href="<?php echo esc_url( 'https://myanimelist.net/anime/' . $clean_mal ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card asd-ext--mal"
									>
										<span class="asd-ext-site">🔵 MyAnimeList</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_bangumi ) : ?>
									<a
										href="<?php echo esc_url( 'https://bgm.tv/subject/' . $clean_bangumi ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										class="asd-ext-link-card asd-ext--bgm"
									>
										<span class="asd-ext-site">🍡 Bangumi</span>
										<span class="asd-ext-arrow" aria-hidden="true">→</span>
									</a>
								<?php endif; ?>
							</div>
						</section>
					<?php endif; ?>

					<section class="asd-section" id="asd-sec-reviews">
						<h2 class="asd-section-title">📝 評論</h2>

						<?php
						/*
						 * 集數選單只給短評（吐槽）用，長評固定是整部作品層級。
						 * 直接沿用上面集數列表已經算好的 ep 數字，不重新解析一次。
						 *
						 * 只列出「已經播出」的集數，未播的不該讓人選（沒播就不
						 * 可能有心得）。判斷雙重保險：這一集自己的 airdate 已經
						 * 過了，或退而求其次比對 anime_episodes_aired 這個彙總
						 * 計數——後者是同步排程定期更新的，可能會比實際播出進度
						 * 慢半拍，所以優先看單集自己的 airdate。
						 */
						$review_episode_options = [];
						if ( ! empty( $episodes_list ) ) {
							$review_now_ts = current_time( 'timestamp' );
							foreach ( $episodes_list as $ep_probe ) {
								if ( ! is_array( $ep_probe ) ) {
									continue;
								}
								$ep_num = (int) ( $ep_probe['ep'] ?? 0 );
								if ( $ep_num <= 0 ) {
									continue;
								}

								$ep_airdate_raw = trim( (string) ( $ep_probe['airdate'] ?? '' ) );
								$ep_airdate_ts  = $ep_airdate_raw !== '' ? strtotime( $ep_airdate_raw ) : false;

								$has_aired = $ep_airdate_ts !== false
									? $ep_airdate_ts <= $review_now_ts
									: $ep_num <= $ep_aired; // 沒有 airdate 資料時退而求其次比對彙總計數

								if ( $has_aired ) {
									$review_episode_options[ $ep_num ] = $ep_num;
								}
							}
							ksort( $review_episode_options );
						}
						?>

						<div
							class="asd-review-root"
							id="asd-review-root"
							data-anime-id="<?php echo (int) $post_id; ?>"
							data-episodes="<?php echo esc_attr( wp_json_encode( array_values( $review_episode_options ) ) ); ?>"
						>
							<p class="asd-review-loading">評論載入中…</p>
						</div>
					</section>

					<?php
					/*
					 * wpDiscuz 留言區已於 2026-08-18 移除，改由上方「📝 評論」
					 * 統一承接（自建系統支援分集數、劇透標記、樓中樓、@提及、
					 * 追蹤討論串）。原有留言已遷移，wp_comments 資料保留未動，
					 * 需要時重新啟用外掛並還原本區塊即可切回。
					 */
					?>

					<?php if ( shortcode_exists( 'wxacg_correction_form' ) ) : ?>
						<section
							class="asd-section asd-corrections"
							id="asd-sec-corrections"
						>
							<?php
							echo do_shortcode(
								'[wxacg_correction_form]'
							);
							?>
						</section>
					<?php endif; ?>

				</main><!-- /.asd-main -->

				<aside class="asd-sidebar" aria-label="作品補充資訊">
					<?php
					$has_tags_section =
						$studio !== ''
							|| ! empty( $season_child_terms )
							|| ! empty( $genre_terms );
					?>

					<?php if ( $has_tags_section ) : ?>
						<div class="asd-side-section">
							<div class="asd-side-section__head">
								<h3>🏷️ 作品標籤</h3>
							</div>

							<div class="asd-tags-wrap">
								<?php if ( $studio ) :
									$studio_terms = get_terms(
										[
											'taxonomy'   => 'anime_studio_tax',
											'name'       => $studio,
											'hide_empty' => false,
											'number'     => 1,
										]
									);

									$studio_term_url = '';

									if (
										! is_wp_error( $studio_terms )
										&& ! empty( $studio_terms )
									) {
										$resolved_studio_url = get_term_link(
											$studio_terms[0]
										);

										if ( ! is_wp_error( $resolved_studio_url ) ) {
											$studio_term_url = $resolved_studio_url;
										}
									}
									?>

									<?php if ( $studio_term_url ) : ?>
										<a
											href="<?php echo esc_url( $studio_term_url ); ?>"
											class="asd-tag-item asd-tag-item--studio"
										>
											🎬 <?php echo esc_html( $studio ); ?>
										</a>
									<?php else : ?>
										<span class="asd-tag-item asd-tag-item--studio">
											🎬 <?php echo esc_html( $studio ); ?>
										</span>
									<?php endif; ?>
								<?php endif; ?>

								<?php foreach ( $season_child_terms as $season_term ) :
									$season_term_url = get_term_link( $season_term );

									if ( is_wp_error( $season_term_url ) ) {
										continue;
									}
									?>
									<a
										href="<?php echo esc_url( $season_term_url ); ?>"
										class="asd-tag-item asd-tag-item--season"
									>
										<?php echo esc_html( $season_term->name ); ?>
									</a>
								<?php endforeach; ?>

								<?php foreach ( $genre_terms as $genre_term ) :
									$genre_term_url = get_term_link( $genre_term );

									if ( is_wp_error( $genre_term_url ) ) {
										continue;
									}
									?>
									<a
										href="<?php echo esc_url( $genre_term_url ); ?>"
										class="asd-tag-item"
									>
										<?php echo esc_html( $genre_term->name ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $news_items ) ) : ?>
						<div class="asd-side-section">
							<div class="asd-side-section__head">
								<h3>📰 相關新聞</h3>
							</div>

							<div class="asd-side-news">
								<?php foreach ( array_slice( $news_items, 0, 6 ) as $news_item ) : ?>
									<?php if ( ! empty( $news_item['url'] ) ) : ?>
										<a
											href="<?php echo esc_url( $news_item['url'] ); ?>"
											class="asd-news-card"
										>
											<span class="asd-news-card__title">
												<?php echo esc_html( $news_item['title'] ); ?>
											</span>
											<span class="asd-news-arrow" aria-hidden="true">→</span>
										</a>
									<?php else : ?>
										<div class="asd-news-card">
											<span class="asd-news-card__title">
												<?php echo esc_html( $news_item['title'] ); ?>
											</span>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $site_relations ) ) : ?>
						<div class="asd-side-section">
							<div class="asd-side-section__head">
								<h3>🔗 相關作品</h3>
							</div>

							<div class="asd-side-cards">
								<?php foreach ( $site_relations as $relation_item ) : ?>
									<a
										href="<?php echo esc_url( $relation_item['url'] ); ?>"
										class="asd-mini-card"
									>
										<div class="asd-mini-card__thumb">
											<?php if ( ! empty( $relation_item['cover_image'] ) ) : ?>
												<img
													src="<?php echo esc_url( $relation_item['cover_image'] ); ?>"
													alt="<?php echo esc_attr( $relation_item['title_zh'] ); ?> 封面"
													loading="lazy"
													decoding="async"
												>
											<?php else : ?>
												<div class="asd-mini-card__thumb-fb">
													<span>
														<?php
														echo esc_html(
															$fallback_text(
																$relation_item['title_zh'],
																2
															)
														);
														?>
													</span>
												</div>
											<?php endif; ?>
										</div>

										<div class="asd-mini-card__body">
											<span class="asd-mini-card__title">
												<?php echo esc_html( $relation_item['title_zh'] ); ?>
											</span>

											<span class="asd-mini-card__meta">
												<?php echo esc_html( $relation_item['relation_label'] ); ?>
												<?php if ( $relation_item['format'] ) : ?>
													· <?php echo esc_html( $relation_item['format'] ); ?>
												<?php endif; ?>
											</span>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $reco_items ) ) : ?>
						<div class="asd-side-section">
							<div class="asd-side-section__head">
								<h3><?php echo $reco_source === 'watchlist' ? '✨ 為你推薦' : '✨ 類似作品'; ?></h3>
							</div>

							<p class="asd-side-note">
								<?php
								echo $reco_source === 'watchlist'
									? '依你追過的作品類型推算，只有你看得到。'
									: '與這部作品類型相近的其他作品。';
								?>
							</p>

							<div class="asd-side-cards">
								<?php foreach ( $reco_items as $reco_item ) : ?>
									<a
										href="<?php echo esc_url( $reco_item['url'] ); ?>"
										class="asd-mini-card"
									>
										<div class="asd-mini-card__thumb">
											<?php if ( ! empty( $reco_item['cover'] ) ) : ?>
												<img
													src="<?php echo esc_url( $reco_item['cover'] ); ?>"
													alt="<?php echo esc_attr( $reco_item['title'] ); ?> 封面"
													loading="lazy"
													decoding="async"
												>
											<?php else : ?>
												<div class="asd-mini-card__thumb-fb">
													<span>
														<?php
														echo esc_html(
															$fallback_text(
																$reco_item['title'],
																2
															)
														);
														?>
													</span>
												</div>
											<?php endif; ?>
										</div>

										<div class="asd-mini-card__body">
											<span class="asd-mini-card__title">
												<?php echo esc_html( $reco_item['title'] ); ?>
											</span>

											<?php if ( $reco_item['format'] ) : ?>
												<span class="asd-mini-card__meta">
													<?php echo esc_html( $reco_item['format'] ); ?>
												</span>
											<?php endif; ?>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $affiliate_html ) : ?>
						<div class="asd-side-section">
							<div class="asd-side-section__head">
								<h3>🛒 購買連結</h3>
							</div>

							<div class="asd-affiliate-box">
								<?php echo wp_kses_post( $affiliate_html ); ?>
							</div>
						</div>
					<?php endif; ?>

					<div class="asd-side-section asd-sponsor-block">
						<div class="asd-sponsor-title">支持微笑動漫</div>
						<div class="asd-sponsor-desc">
							如果本站整理的資料對你有幫助，可自願支持伺服器與內容維護。
						</div>
						<a
							href="<?php echo esc_url( home_url( '/sponsor/' ) ); ?>"
							class="asd-sponsor-btn"
						>
							贊助微笑動漫
						</a>
						<div class="asd-sponsor-note">
							贊助不影響本站評分、內容或編輯立場
						</div>
					</div>

					<?php if ( ! $is_thin_content ) : ?>
						<div class="asd-ad-placeholder" aria-label="廣告">
							<div class="asd-ad-inner"></div>
						</div>
					<?php endif; ?>
				</aside>
			</div><!-- /.asd-container -->
		</div><!-- /.asd-tabs-wrap -->
	</div><!-- /.asd-wrap -->

<script>
document.addEventListener('DOMContentLoaded', function() {
	var tabLists = document.querySelectorAll('.asd-pv-tabs, .asd-online-tabs');
	
	tabLists.forEach(function(tabList) {
		var tabs = tabList.querySelectorAll('[role="tab"]');
		
		tabs.forEach(function(tab) {
			tab.addEventListener('click', function() {
				var targetId = this.getAttribute('aria-controls');
				var targetPanel = document.getElementById(targetId);
				if (!targetPanel) return;

				// 移除同一個 tablist 內所有 tab 的 active 狀態
				tabs.forEach(function(t) {
					t.classList.remove('is-active');
					t.setAttribute('aria-selected', 'false');
					t.setAttribute('tabindex', '-1');
				});

				// 設定當前點擊的 tab 為 active
				this.classList.add('is-active');
				this.setAttribute('aria-selected', 'true');
				this.setAttribute('tabindex', '0');

				// 尋找對應的 box 容器來取得 panels
				var box = this.closest('.asd-pv-box') || this.closest('.asd-online-box');
				if (box) {
					var panels = box.querySelectorAll('[role="tabpanel"]');
					panels.forEach(function(p) {
						p.classList.remove('is-active');
						p.setAttribute('hidden', '');
					});
				}

				// 顯示目標 panel
				targetPanel.classList.add('is-active');
				targetPanel.removeAttribute('hidden');
			});
		});
	});
});
</script>

<?php
endwhile;

get_footer();
