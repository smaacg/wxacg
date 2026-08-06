<?php
/**
 * Single Anime Template
 *
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-anime.php
 *
 * @version 15.4 — 2026-08-03
 *
 * 15.4:
 * - 修正 Hero 評分欄與 CSS 結構不一致
 * - 移除強制拉長海報的 inline CSS
 * - 補齊串流、配音、Google 搜尋與線上看 Tab
 * - wxacg_staff_role() 與 mbstring 安全防呆
 * - 強化 YouTube 影片／播放清單解析及去重
 * - 保留 CAST／STAFF 實體連結、評分、追蹤、FAQ 與 JSON-LD
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
			: $raw;
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

		$title = $item['title'] ?? $item['name'] ?? $item['headline'] ?? '';
		$url   = $item['url'] ?? $item['link'] ?? '';

		$title = trim( (string) $title );
		$url   = trim( (string) $url );

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

		$type = $type === 'character' ? 'character' : 'person';
		$name = trim( (string) $name );
		$slug = $name !== ''
			? rawurlencode( str_replace( ' ', '-', $name ) )
			: '';

		return home_url(
			'/' . $type . '/' . $id . '/' . $slug
		);
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

			if ( $url === '' ) {
				continue;
			}

			$items[] = [
				'label'     => $label !== '' ? $label : $default_label,
				'url'       => $url,
				'has_label' => $label !== '',
			];
		}

		return $items;
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

	$dub_url_taigi    = trim( (string) $get_meta( 'anime_dub_url_taigi' ) );
	$dub_url_mandarin = trim( (string) $get_meta( 'anime_dub_url_mandarin' ) );

	$has_streaming_registry = class_exists(
		'Anime_Sync_Streaming_Registry'
	);

	$tw_stream_url_map = [];

	if ( $has_streaming_registry ) {
		$registry_platforms = Anime_Sync_Streaming_Registry::all();

		if ( is_array( $registry_platforms ) ) {
			foreach ( $registry_platforms as $platform ) {
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

	$tw_dist_labels = [
		'muse'      => '木棉花',
		'medialink' => '曼迪傳播',
		'linbang'   => '羚邦',
		'tropic'    => '回歸線娛樂',
		'proware'   => '普威爾',
		'kadokawa'  => '台灣角川',
		'gungho'    => '群英社',
		'tien'      => '提恩傳媒',
		'garage'    => '車庫娛樂',
		'carsun'    => '采昌國際',
		'jbf'       => '日本橋文化（JBF）',
		'righttime' => '利得時代',
		'aniplus'   => 'ANIPLUS Asia',
		'tongli'    => '東立出版社',
		'remow'     => 'REMOW',
		'gaga'      => 'GaGa OOLala',
		'other'     => '',
	];

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

			$tw_streaming_items[] = [
				'key'       => $platform_key,
				'label'     => $label,
				'url'       => $tw_stream_url_map[ $platform_key ] ?? '',
				'icon_url'  => isset( $provider_icon_map[ $platform_key ] )
					? $provider_icon_base . $provider_icon_map[ $platform_key ]
					: '',
				'icon_only' => false,
			];
		}
	}

	if ( $tw_streaming_other ) {
		$other_platforms = array_map(
			'trim',
			explode( ',', (string) $tw_streaming_other )
		);

		foreach ( $other_platforms as $extra_platform ) {
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
			$streaming_site = strtolower(
				trim( (string) ( $streaming_item['site'] ?? '' ) )
			);

			if ( in_array( $streaming_site, $overseas_sites, true ) ) {
				$overseas_streams[] = $streaming_item;
			}
		}
	}

	/* =========================================================
	 * 配音觀看平台
	 * 必須提前解析，讓 Hero 和 Tabs 能正確判斷。
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

	$resolve_dub_platform = static function ( $url ) use (
		$has_streaming_registry,
		$provider_icon_map,
		$provider_icon_base
	) {
		if ( ! $has_streaming_registry || empty( $url ) ) {
			return [
				'label' => '',
				'icon'  => '',
			];
		}

		$matched_key = Anime_Sync_Streaming_Registry::match_site(
			'',
			$url
		);

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
		$strtolower_safe
	) {
		if ( $has_streaming_registry && $url ) {
			$matched_key = Anime_Sync_Streaming_Registry::match_site(
				$label,
				$url
			);

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

	$google_search_url =
		! $has_tw_stream && $tw_no_stream_google !== ''
			? $tw_no_stream_google
			: '';

	$has_stream_section =
		$has_any_stream
		|| $google_search_url !== '';

	$hero_has_watch = $has_stream_section;

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

				$trailer_entry = $parts[0] ?? '';
				$custom_label  = $parts[1] ?? '';

				/*
				 * 同時相容：
				 * URL|標題
				 * 標題|URL
				 */
				if (
					! preg_match( '#^(?:https?://|[A-Za-z0-9_-]{11}$)#', $trailer_entry )
					&& preg_match( '#^(?:https?://|[A-Za-z0-9_-]{11}$)#', $custom_label )
				) {
					$temp          = $trailer_entry;
					$trailer_entry = $custom_label;
					$custom_label  = $temp;
				}
			}

			$video_id = '';

			if (
				preg_match(
					'/(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/|shorts\/))([A-Za-z0-9_-]{11})/',
					$trailer_entry,
					$matches
				)
			) {
				$video_id = $matches[1];
			} elseif (
				preg_match(
					'/^[A-Za-z0-9_-]{11}$/',
					$trailer_entry
				)
			) {
				$video_id = $trailer_entry;
			}

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

				/*
				 * 預設格式：標題|網址。
				 * 同時容忍網址|標題。
				 */
				if (
					preg_match(
						'#^(?:https?://|[A-Za-z0-9_-]{11}$)#',
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

			$online_id   = '';
			$online_type = 'video';

			if (
				preg_match(
					'/(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/|shorts\/))([A-Za-z0-9_-]{11})/',
					$online_url,
					$video_matches
				)
			) {
				$online_id = $video_matches[1];
			} elseif (
				preg_match(
					'/^[A-Za-z0-9_-]{11}$/',
					$online_url
				)
			) {
				$online_id = $online_url;
			} elseif (
				preg_match(
					'/[?&]list=([A-Za-z0-9_-]+)/',
					$online_url,
					$playlist_matches
				)
			) {
				$online_id   = $playlist_matches[1];
				$online_type = 'playlist';
			}

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
				: json_decode( $next_airing_raw, true );

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

	$themes_list = $decode_json(
		$get_meta( 'anime_themes' )
	);

	$cast_list = $decode_json(
		$get_meta( 'anime_cast_json' )
	);
	$staff_list = $decode_json(
		$get_meta( 'anime_staff_json' )
	);

    /*
 * STAFF 排序：主要導演固定顯示在最前面
 * 其他 STAFF 保持原本資料順序。
 */
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

	/*
	 * 排除不是主要導演的職位，
	 * 避免作畫監督、美術監督、副導演等被排到最前面。
	 */
	$is_excluded_director = preg_match(
		'/副導演|助理導演|助監督|副監督|監督補佐|作畫監督|作画監督|動畫導演|美術監督|攝影監督|音響監督|音樂監督|3D監督|CG監督|episode\s*director|assistant\s*director|animation\s*director|art\s*director|sound\s*director|photography\s*director/i',
		$staff_role
	);

	/*
	 * 支援中文、日文及英文主要導演職位。
	 */
	$is_main_director = ! $is_excluded_director
		&& preg_match(
			'/總導演|总导演|導演|导演|總監督|総監督|^監督$|^director$|series\s*director|chief\s*director/i',
			$staff_role
		);

	$staff_sorted[] = [
		'priority' => $is_main_director ? 0 : 1,
		'index'    => (int) $staff_index,
		'item'     => $staff_item,
	];
}

/* 導演優先，其他項目維持原本順序 */
usort(
	$staff_sorted,
	static function ( $staff_a, $staff_b ) {
		if ( $staff_a['priority'] !== $staff_b['priority'] ) {
			return $staff_a['priority'] <=> $staff_b['priority'];
		}

		return $staff_a['index'] <=> $staff_b['index'];
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

	/*
	 * 原作者資料
	 *
	 * 讀取優先順序：
	 * 1. anime_original_author 獨立 Meta 欄位
	 * 2. 從 anime_staff_json 自動尋找原作／作者職位
	 *
	 * 自動排除：
	 * 角色原案、角色設計、插畫、原畫及原作協力等職位，
	 * 避免將非原作者誤判為原作者。
	 */
	$original_author = trim(
		wp_strip_all_tags(
			(string) $get_meta( 'anime_original_author' )
		)
	);

	if ( $original_author === '' && ! empty( $staff_list ) ) {
		$original_author_names = [];

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

			if ( $staff_name === '' || $staff_role === '' ) {
				continue;
			}

			/*
			 * 排除容易被誤判成原作者的職位。
			 */
			$is_excluded_author_role = preg_match(
				'/角色|人物|キャラクター|character\s*design|插畫|插画|illustrat|原畫|原画|協力/i',
				$staff_role
			);

			if ( $is_excluded_author_role ) {
				continue;
			}

			/*
			 * 支援繁中、簡中、日文及英文常見職位名稱。
			 */
			$is_original_author_role = preg_match(
				'/原作者|原作|作者|原著|原著者|original\s*(creator|story|work|author)|story\s*&\s*art/i',
				$staff_role
			);

			if ( ! $is_original_author_role ) {
				continue;
			}

			$original_author_names[] = $staff_name;
		}

		/*
		 * 清除重複作者，最多顯示三位，
		 * 避免 Hero 右側評分卡過度拉長。
		 */
		$original_author_names = array_values(
			array_unique(
				array_filter( $original_author_names )
			)
		);

		$original_author = implode(
			'、',
			array_slice( $original_author_names, 0, 3 )
		);
	}

	$relations_list = $decode_json(
		$get_meta( 'anime_relations_json' )
	);

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

	foreach ( $news_items as $news_item ) {
		$normalized_item = $normalize_news_item( $news_item );

		if ( $normalized_item ) {
			$normalized_news[] = $normalized_item;
		}
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
		$all_tags = get_terms(
			[
				'taxonomy'   => 'post_tag',
				'hide_empty' => true,
			]
		);

		$matched_tag_ids = [];

		if ( ! is_wp_error( $all_tags ) ) {
			foreach ( $all_tags as $tag ) {
				$tag_name = trim( (string) $tag->name );

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
			array_unique( $matched_tag_ids )
		);

		if ( ! empty( $matched_tag_ids ) ) {
			$news_query = new WP_Query(
				[
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'tax_query'      => [
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
					if ( ! empty( $existing_news['url'] ) ) {
						$seen_news_urls[ $existing_news['url'] ] = true;
					}
				}

				foreach ( $news_query->posts as $news_post ) {
					$news_post_url = get_permalink(
						$news_post
					);

					if ( isset( $seen_news_urls[ $news_post_url ] ) ) {
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

		$theme_type  = strtoupper(
			trim( (string) ( $theme_item['type'] ?? '' ) )
		);
		$theme_slug  = trim(
			(string) ( $theme_item['slug'] ?? '' )
		);
		$theme_title = trim(
			(string) ( $theme_item['title'] ?? '' )
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
		'TV_SHORT' => 'TV',
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
	$source_label = $source_labels[ $source ] ?? $source;

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
	 * 相關作品：一次 IN 查詢
	 * ======================================================= */

	$relation_labels = [
		'PREQUEL'     => '前作',
		'SEQUEL'      => '續作',
		'PARENT'      => '本篇',
		'SIDE_STORY'  => '外傳',
		'CHARACTER'   => '角色',
		'SUMMARY'     => '總集篇',
		'ALTERNATIVE' => '替代版本',
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
			$relation_ids[]                  = $relation_id;
		}
	}

	if ( ! empty( $relation_ids ) ) {
		$relation_posts = get_posts(
			[
				'post_type'      => 'anime',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [
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
					(string) ( $relation_item['format'] ?? '' )
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
				$site_stats['score'] ?? 0
			);

			$site_story = (float) (
				$site_stats['avg_story'] ?? 0
			);

			$site_music = (float) (
				$site_stats['avg_music'] ?? 0
			);

			$site_animation = (float) (
				$site_stats['avg_animation'] ?? 0
			);

			$site_voice = (float) (
				$site_stats['avg_voice'] ?? 0
			);

			$site_count = (int) (
				$site_stats['vote_count'] ?? 0
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
			(string) ( $cast_item['name'] ?? '' )
		);

		$cast_role = trim(
			(string) ( $cast_item['role'] ?? '' )
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
			(string) ( $cast_item['name'] ?? '' )
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
			200
		)
	);

	$schema_image = $cover_image
		?: get_the_post_thumbnail_url( $post_id, 'large' );

	$schema = [
		'@context' => 'https://schema.org',
		'@type'    => $schema_type,
		'name'     => $display_title,
		'url'      => get_permalink( $post_id ),
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
	$dub_lang_codes   = [
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

	$schema_directors     = [];
	$schema_director_seen = [];

	foreach ( $staff_list as $staff_item ) {
		if ( ! is_array( $staff_item ) ) {
			continue;
		}

		$staff_name = trim(
			(string) ( $staff_item['name'] ?? '' )
		);

		$staff_role = trim(
			(string) ( $staff_item['role'] ?? '' )
		);

		if ( $staff_name === '' ) {
			continue;
		}

		$is_director =
			$strpos_safe( $staff_role, '導演' ) !== false
			|| $strpos_safe( $staff_role, '監督' ) !== false
			|| stripos( $staff_role, 'director' ) !== false;

		if (
			$is_director
			&& ! isset( $schema_director_seen[ $staff_name ] )
		) {
			$schema_director_seen[ $staff_name ] = true;

			$schema_directors[] = [
				'@type' => 'Person',
				'name'  => $staff_name,
			];
		}
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
		$voice_name  = is_array( $voice_actor )
			? trim( (string) ( $voice_actor['name'] ?? '' ) )
			: '';

		if (
			$voice_name === ''
			|| isset( $schema_actor_seen[ $voice_name ] )
		) {
			continue;
		}

		$schema_actor_seen[ $voice_name ] = true;

		$schema_actors[] = [
			'@type' => 'Person',
			'name'  => $voice_name,
		];
	}

	if ( ! empty( $schema_actors ) ) {
		$schema['actor'] = $schema_actors;
	}

	if ( $youtube_id ) {
		$schema_trailer = [
			'@type'        => 'VideoObject',
			'name'         => $display_title . ' 預告片',
			'description'  => $schema_description !== ''
				? $schema_description
				: $display_title . ' 預告片',
			'thumbnailUrl' =>
				'https://i.ytimg.com/vi/'
				. $youtube_id
				. '/hqdefault.jpg',
			'embedUrl'     =>
				'https://www.youtube.com/embed/'
				. $youtube_id,
		];

		if ( $start_date ) {
			$schema_trailer['uploadDate'] =
				$start_date . 'T00:00:00+09:00';
		}

		$schema['trailer'] = $schema_trailer;
	}

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

	$faq_schema = null;
	$faq_main   = [];

	foreach ( $faq_items as $faq_item ) {
		if ( ! is_array( $faq_item ) ) {
			continue;
		}

		$faq_question = trim(
			(string) ( $faq_item['q'] ?? '' )
		);

		$faq_answer = trim(
			(string) ( $faq_item['a'] ?? '' )
		);

		if ( $faq_question === '' || $faq_answer === '' ) {
			continue;
		}

		$faq_main[] = [
			'@type'          => 'Question',
			'name'           => $faq_question,
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
			'mainEntity' => $faq_main,
		];
	}

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
				'progress'    => (int) ( $user_entry['progress'] ?? 0 ),
				'favorited'   => ! empty( $user_entry['favorited'] ),
				'fullcleared' => ! empty( $user_entry['fullcleared'] ),
			];
		}
	}

	$user_rating = [
		'story'     => 5.0,
		'music'     => 5.0,
		'animation' => 5.0,
		'voice'     => 5.0,
	];

	$share_permalink = get_permalink( $post_id );
	$share_text_x    =
		$display_title
		. ' | 微笑動漫 '
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
		echo wp_json_encode( $schema, $json_ld_flags );
	?></script>

	<?php if ( $faq_schema ) : ?>
		<script type="application/ld+json"><?php
			echo wp_json_encode( $faq_schema, $json_ld_flags );
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

		<div class="asd-hero-new">

			<div class="asd-hero-poster-wrap">
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
			</div>

			<div class="asd-hero-body">

				<div class="asd-hero-breadcrumb">
					<span>動畫</span>

					<?php if ( $season_str ) : ?>
						<span class="asd-hbc-sep">›</span>
						<span><?php echo esc_html( $season_str ); ?></span>
					<?php endif; ?>

					<?php if ( ! empty( $genre_terms ) ) : ?>
						<span class="asd-hbc-sep">›</span>
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

					$series_tax_url = get_term_link(
						$series_tax
					);

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
								<?php echo (int) $series_tax->count; ?> 部
							</span>

							<span class="asd-series-badge-arrow" aria-hidden="true">→</span>
						</a>
					<?php endif; ?>
				<?php endif; ?>

				<div class="asd-hero-badges">
					<?php if ( $status_label ) : ?>
						<span class="asd-hbadge<?php
							echo $status_class
								? ' asd-hbadge--' . esc_attr( $status_class )
								: '';
						?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
					<?php endif; ?>

					<?php if ( $format_label ) : ?>
						<span class="asd-hbadge">
							<?php echo esc_html( $format_label ); ?>
						</span>
					<?php endif; ?>

					<?php if ( $season_str ) : ?>
						<span class="asd-hbadge">
							<?php echo esc_html( $season_str ); ?>
						</span>
					<?php endif; ?>

					<?php if ( $ep_str ) : ?>
						<span class="asd-hbadge">
							<?php echo esc_html( $ep_str ); ?>
						</span>
					<?php endif; ?>

					<?php foreach ( array_slice( $genre_terms, 0, 3 ) as $genre_term ) : ?>
						<span class="asd-hbadge asd-hbadge--genre">
							<?php echo esc_html( $genre_term->name ); ?>
						</span>
					<?php endforeach; ?>
				</div>

				<div class="asd-hero-scores-new">
					<?php if ( $score_anilist ) : ?>
						<div class="asd-score-pill asd-score-pill--al">
							<span class="asd-sp-dot"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_anilist ); ?></span>
							<span class="asd-sp-label">AniList</span>
						</div>
					<?php endif; ?>

					<?php if ( $score_mal ) : ?>
						<div class="asd-score-pill asd-score-pill--mal">
							<span class="asd-sp-dot"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_mal ); ?></span>
							<span class="asd-sp-label">MAL</span>
						</div>
					<?php endif; ?>

					<?php if ( $score_bangumi ) : ?>
						<div class="asd-score-pill asd-score-pill--bgm">
							<span class="asd-sp-dot"></span>
							<span class="asd-sp-val"><?php echo esc_html( $score_bangumi ); ?></span>
							<span class="asd-sp-label">Bangumi</span>
						</div>
					<?php endif; ?>

					<div class="asd-score-pill asd-score-pill--site">
						<span class="asd-sp-dot"></span>

						<span class="asd-sp-val wacg-hero-score">
							<?php
							echo $site_score > 0
								? esc_html( number_format( $site_score, 1 ) )
								: '—';
							?>
						</span>

						<span class="asd-sp-label">WeixiaoAcg</span>
					</div>
				</div>

				<div class="asd-hero-actions">
					<?php if ( $hero_has_watch ) : ?>
						<a
							href="#asd-sec-stream"
							class="asd-action-btn asd-action-btn--primary"
							title="<?php echo esc_attr( $display_title ); ?> 線上觀看"
						>
							📺 線上觀看
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
							href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
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
					<div class="asd-hside-row">
						<span class="asd-hside-dot asd-hside-dot--al"></span>
						<span class="asd-hside-key">AniList</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_anilist ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $score_mal ) : ?>
					<div class="asd-hside-row">
						<span class="asd-hside-dot asd-hside-dot--mal"></span>
						<span class="asd-hside-key">MAL</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_mal ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $score_bangumi ) : ?>
					<div class="asd-hside-row">
						<span class="asd-hside-dot asd-hside-dot--bgm"></span>
						<span class="asd-hside-key">Bangumi</span>
						<span class="asd-hside-val"><?php echo esc_html( $score_bangumi ); ?></span>
					</div>
				<?php endif; ?>

				<div class="wacg-rating-divider"></div>

				<div id="wacg-rating-stats" class="wacg-rating-stats">
					<div class="wacg-score-row">
						<span class="asd-hside-dot wacg-dot-site"></span>
						<span class="asd-hside-key">WeixiaoAcg</span>

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
							? esc_html( $site_count . ' 人評分' )
							: '';
						?>
					</div>

					<div class="wacg-cats">
						<div class="wacg-cat-row">
							<span class="wacg-cat-label">劇情</span>
							<span class="wacg-cat-val wacg-cat-story"><?php
								echo $site_story > 0
									? esc_html( number_format( $site_story, 1 ) )
									: '—';
							?></span>
						</div>

						<div class="wacg-cat-row">
							<span class="wacg-cat-label">音樂</span>
							<span class="wacg-cat-val wacg-cat-music"><?php
								echo $site_music > 0
									? esc_html( number_format( $site_music, 1 ) )
									: '—';
							?></span>
						</div>

						<div class="wacg-cat-row">
							<span class="wacg-cat-label">作畫</span>
							<span class="wacg-cat-val wacg-cat-animation"><?php
								echo $site_animation > 0
									? esc_html( number_format( $site_animation, 1 ) )
									: '—';
							?></span>
						</div>

						<div class="wacg-cat-row">
							<span class="wacg-cat-label">聲優</span>
							<span class="wacg-cat-val wacg-cat-voice"><?php
								echo $site_voice > 0
									? esc_html( number_format( $site_voice, 1 ) )
									: '—';
							?></span>
						</div>
					</div>
				</div>

				<?php if ( $site_count <= 0 ) : ?>
					<p class="wacg-be-first">
						✨ 尚無評分，快來搶頭香！
					</p>
				<?php endif; ?>

				<?php if ( is_user_logged_in() ) : ?>
					<form id="wacg-rating-form" class="wacg-rating-form">
						<?php
						$rating_sliders = [
							[
								'key'   => 'story',
								'label' => '劇情',
							],
							[
								'key'   => 'music',
								'label' => '音樂',
							],
							[
								'key'   => 'animation',
								'label' => '作畫',
							],
							[
								'key'   => 'voice',
								'label' => '聲優',
							],
						];

						foreach ( $rating_sliders as $rating_slider ) :
							$rating_key = $rating_slider['key'];
							$init_value = $user_rating[ $rating_key ];
							?>
							<div class="wacg-slider-row">
								<label
									class="wacg-slider-label"
									for="slider-<?php echo esc_attr( $rating_key ); ?>"
								>
									<?php echo esc_html( $rating_slider['label'] ); ?>
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
				$hero_meta_rows = [
					'集數'     => $ep_str,
					'時長'     => $duration > 0
						? $duration . ' 分鐘'
						: '',
					'原作類型' => $source_label,
					'原作者'   => $original_author,
					'製作公司' => $studio,
				];


				$has_hero_meta = false;

				foreach ( $hero_meta_rows as $meta_key => $meta_value ) :
					$meta_value = trim( (string) $meta_value );

					if ( $meta_value === '' ) {
						continue;
					}

					$has_hero_meta = true;
					?>
					<div class="asd-hside-info-row">
						<span class="asd-hside-info-key">
							<?php echo esc_html( $meta_key ); ?>
						</span>

						<span class="asd-hside-info-val">
							<?php echo esc_html( $meta_value ); ?>
						</span>
					</div>
				<?php endforeach; ?>

				<?php if ( ! $has_hero_meta ) : ?>
					<p class="asd-hside-empty">暫無資料</p>
				<?php endif; ?>
			</div><!-- /.asd-hside-block -->

		</div><!-- /.asd-hero-new -->

		<?php
		$progress_value  = (int) ( $user_anime_entry['progress'] ?? 0 );
		$is_full_cleared = ! empty( $user_anime_entry['fullcleared'] );
		$has_total       = $episodes > 0;

		if ( $has_total ) {
			$progress_value = max(
				0,
				min( $episodes, $progress_value )
			);
		} else {
			$progress_value = max( 0, $progress_value );
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
					<button
						type="button"
						class="smacg-status-btn<?php echo ( $user_anime_entry['status'] ?? '' ) === 'want' ? ' is-active' : ''; ?>"
						data-action="status"
						data-value="want"
						title="想看"
					>
						<span class="smacg-ico">🔖</span>
						<span>想看</span>
					</button>

					<button
						type="button"
						class="smacg-status-btn<?php echo ( $user_anime_entry['status'] ?? '' ) === 'watching' ? ' is-active' : ''; ?>"
						data-action="status"
						data-value="watching"
						<?php if ( $is_not_aired ) : ?>
							disabled
							aria-disabled="true"
							title="尚未播出，無法追番"
						<?php else : ?>
							title="追番中"
						<?php endif; ?>
					>
						<span class="smacg-ico">▶</span>
						<span>追番中</span>
					</button>

					<button
						type="button"
						class="smacg-status-btn<?php echo ( $user_anime_entry['status'] ?? '' ) === 'completed' ? ' is-active' : ''; ?>"
						data-action="status"
						data-value="completed"
						<?php if ( $is_not_aired ) : ?>
							disabled
							aria-disabled="true"
							title="尚未播出，無法標記已看完"
						<?php else : ?>
							title="已看完"
						<?php endif; ?>
					>
						<span class="smacg-ico">✓</span>
						<span>已看完</span>
					</button>

					<button
						type="button"
						class="smacg-status-btn<?php echo ( $user_anime_entry['status'] ?? '' ) === 'dropped' ? ' is-active' : ''; ?>"
						data-action="status"
						data-value="dropped"
						title="棄坑"
					>
						<span class="smacg-ico">✕</span>
						<span>棄坑</span>
					</button>
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
						<div class="smacg-prog-bar-wrap">
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
					>
						<span class="smacg-ico">
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
						<span class="smacg-ico">🔗</span>
						<span class="smacg-icon-label">分享</span>
					</button>
				</div>
			</div>

			<div
				class="smacg-point-toast"
				aria-live="polite"
			></div>
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

		<div class="asd-tabs-wrap">
			<nav class="asd-tabs" id="asd-tabs" aria-label="頁面導航">
				<a class="asd-tab" href="#asd-sec-info">📋 基本資訊</a>

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

				<?php if ( ! empty( $faq_main ) ) : ?>
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
					<a class="asd-tab" href="#asd-sec-links">🔗 外部連結</a>
				<?php endif; ?>

				<a class="asd-tab" href="#asd-sec-comments">💬 留言</a>
			</nav>

			<div class="asd-container asd-container--has-sidebar">
				<main class="asd-main" id="asd-main">

					<section class="asd-section" id="asd-sec-info">
						<h2 class="asd-section-title">📋 基本資訊</h2>

						<div class="asd-info-grid">
							<?php
							$info_rows = [
								'類型'     => $format_label,
								'集數'     => $ep_str,
								'狀態'     => $status_label,
								'播出季度' => $season_str,
								'每集時長' => $duration > 0 ? $duration . ' 分鐘' : '',
								'開始日期' => $start_date,
								'結束日期' => $end_date && $status === 'FINISHED' ? $end_date : '',
								'原作來源' => $source_label,
								'製作公司' => $studio,
								'台灣代理' => $tw_dist_display,
								'播出頻道' => $tw_broadcast,
								'配音版本' => ! empty( $dub_display ) ? implode( '、', $dub_display ) : '',
								'最後更新' => get_the_modified_date( 'Y-m-d' ),
							];

							foreach ( $info_rows as $info_label => $info_value ) :
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
										<?php echo esc_html( $info_value ); ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>

						<?php
						$countdown_timestamp = is_numeric( $next_airing_raw )
							? (int) $next_airing_raw
							: (int) ( $airing_data['airingAt'] ?? 0 );

						$countdown_episode = (int) (
							$airing_data['episode']
							?? ( $ep_aired > 0 ? $ep_aired + 1 : 0 )
						);
						?>

						<?php if ( $status === 'RELEASING' && $countdown_timestamp > time() ) : ?>
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
								></strong>
							</div>
						<?php endif; ?>
					</section>

					<?php if ( $synopsis ) : ?>
						<section class="asd-section" id="asd-sec-synopsis">
							<h2 class="asd-section-title">📝 劇情簡介</h2>
							<div class="asd-synopsis">
								<?php echo wp_kses_post( wpautop( $synopsis ) ); ?>
							</div>
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
									<div class="asd-pv-tabs" role="tablist" aria-label="預告片切換">
										<?php foreach ( $trailer_items as $trailer_index => $trailer_item ) : ?>
											<button
												type="button"
												class="asd-pv-tab<?php echo $trailer_index === 0 ? ' is-active' : ''; ?>"
												role="tab"
												aria-selected="<?php echo $trailer_index === 0 ? 'true' : 'false'; ?>"
												aria-controls="asd-pv-panel-<?php echo (int) $trailer_index; ?>"
												data-pv-index="<?php echo (int) $trailer_index; ?>"
												data-pv-id="<?php echo esc_attr( $trailer_item['id'] ); ?>"
											>
												<span class="asd-pv-tab-icon">▶</span>
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
											data-pv-index="<?php echo (int) $trailer_index; ?>"
											data-pv-id="<?php echo esc_attr( $trailer_item['id'] ); ?>"
										>
											<div class="asd-trailer-wrap">
												<iframe
													src="<?php echo esc_url( 'https://www.youtube.com/embed/' . $trailer_item['id'] ); ?>"
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
								<?php foreach ( $episodes_list as $episode_index => $episode_item ) :
									if ( ! is_array( $episode_item ) ) {
										continue;
									}

									$episode_number = (float) ( $episode_item['ep'] ?? 0 );
									$episode_name_cn = trim( (string) ( $episode_item['name_cn'] ?? '' ) );
									$episode_name_ja = trim( (string) ( $episode_item['name'] ?? '' ) );
									$episode_airdate = trim( (string) ( $episode_item['airdate'] ?? '' ) );

									if (
										$episode_name_cn !== ''
										&& class_exists( 'Anime_Sync_CN_Converter' )
									) {
										$episode_name_cn = Anime_Sync_CN_Converter::static_convert(
											$episode_name_cn
										);
									}

									$episode_name = $episode_name_cn ?: $episode_name_ja;

									$episode_number_display = floor( $episode_number ) === $episode_number
										? (int) $episode_number
										: $episode_number;

									$episode_display = $episode_number > 0
										? '第' . $episode_number_display . '集'
										: '第' . ( $episode_index + 1 ) . '集';
									?>
									<div class="asd-ep-row<?php echo $episode_index >= 3 ? ' asd-ep-hidden' : ''; ?>">
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
												<span class="asd-ep-title-ja">
													<?php echo esc_html( $episode_name_ja ); ?>
												</span>
											<?php endif; ?>
										</div>

										<?php if ( $episode_airdate ) : ?>
											<span class="asd-ep-date">
												<?php echo esc_html( $episode_airdate ); ?>
											</span>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>

							<?php if ( count( $episodes_list ) > 3 ) : ?>
								<div class="asd-toggle-wrap">
									<button class="asd-ep-toggle" type="button">
										顯示全部 <?php echo esc_html( count( $episodes_list ) ); ?> 集 ▼
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

									$staff_id = (int) ( $staff_item['id'] ?? 0 );
									$staff_name = trim( (string) ( $staff_item['name'] ?? '' ) );
									$staff_native = trim( (string) ( $staff_item['native'] ?? '' ) );
									$staff_role_raw = trim( (string) ( $staff_item['role'] ?? '' ) );

									if ( $staff_name === '' ) {
										continue;
									}

									$staff_role = function_exists( 'wxacg_staff_role' )
										? wxacg_staff_role( $staff_role_raw )
										: $staff_role_raw;

									$staff_role = trim( (string) $staff_role );
									$staff_url  = $staff_id > 0
										? $entity_url( 'person', $staff_id, $staff_name )
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

											<?php if ( $staff_native && $staff_native !== $staff_name ) : ?>
												<span class="asd-staff-native">
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

									$character_id = (int) ( $cast_item['id'] ?? 0 );
									$character_name = trim( (string) ( $cast_item['name'] ?? '' ) );
									$character_native = trim( (string) ( $cast_item['native'] ?? '' ) );
									$character_image = trim( (string) ( $cast_item['image'] ?? '' ) );

									if ( $character_name === '' ) {
										continue;
									}

									$voice_actors = (
										! empty( $cast_item['voice_actors'] )
										&& is_array( $cast_item['voice_actors'] )
									) ? $cast_item['voice_actors'] : [];

									$voice_actor = $voice_actors[0] ?? [];
									$voice_actor = is_array( $voice_actor ) ? $voice_actor : [];

									$voice_id = (int) ( $voice_actor['id'] ?? 0 );
									$voice_name = trim( (string) ( $voice_actor['name'] ?? '' ) );
									$voice_native = trim( (string) ( $voice_actor['native'] ?? '' ) );

									$character_fallback = $fallback_text( $character_name, 2 );
									$character_is_bangumi = ( $cast_item['source'] ?? '' ) === 'bangumi';

									$character_url = $character_is_bangumi && $character_id > 0
										? $entity_url( 'character', $character_id, $character_name )
										: '';

									$voice_url = $voice_id > 0
										? $entity_url( 'person', $voice_id, $voice_name )
										: '';
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
											<div class="asd-cast-avatar-fb asd-cast-avatar-fb--backup" hidden>
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
												<span class="asd-cast-char-native">
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
															<span class="asd-cast-va-native">
																<?php echo esc_html( $voice_native ); ?>
															</span>
														<?php endif; ?>
													</div>
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

										$theme_type = strtoupper( trim( (string) ( $theme_item['type'] ?? '' ) ) );
										$theme_title = trim( (string) ( $theme_item['title'] ?? '' ) );
										$theme_native = trim( (string) ( $theme_item['title_native'] ?? '' ) );
										$theme_artists = is_array( $theme_item['artists'] ?? null )
											? $theme_item['artists']
											: [];

										$artist_names = [];
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
												(string) ( $artist_item['name'] ?? '' )
											);

											if ( $artist_display !== '' ) {
												$artist_names[] = $artist_display;
											}

											if ( $artist_romaji !== '' ) {
												$artist_romaji_names[] = $artist_romaji;
											}
										}

										$artist_display = implode( '、', array_unique( $artist_names ) );
										$artist_romaji = implode( ', ', array_unique( $artist_romaji_names ) );

										$audio_url = trim( (string) ( $theme_item['audio_url'] ?? '' ) );
										$video_url = trim( (string) ( $theme_item['video_url'] ?? '' ) );
										$theme_episodes = trim( (string) ( $theme_item['episodes'] ?? '' ) );
										$open_media_url = $video_url ?: $audio_url;

										$music_main = $theme_native !== '' ? $theme_native : $theme_title;
										$music_sub = (
											$theme_native !== ''
											&& $theme_title !== ''
											&& $theme_title !== $theme_native
										) ? $theme_title : '';

										$music_badge_class = strpos( $theme_type, 'OP' ) === 0
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

											<?php if ( $audio_url || $video_url ) : ?>
												<div
													class="asd-music-player-wrap"
													data-audio-src="<?php echo esc_url( $audio_url ); ?>"
													data-video-src="<?php echo esc_url( $video_url ); ?>"
												>
													<audio class="asd-music-audio" preload="none"></audio>
													<video class="asd-music-video" preload="none" playsinline hidden></video>

													<button
														class="asd-music-play-btn"
														type="button"
														aria-label="播放"
													></button>

													<div class="asd-music-progress-wrap">
														<div class="asd-music-progress-bar"></div>
													</div>

													<span class="asd-music-time">0:00</span>

													<?php if ( $open_media_url ) : ?>
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
						</section>
					<?php endif; ?>

					<?php if ( $has_stream_section ) : ?>
						<section class="asd-section" id="asd-sec-stream">
							<h2 class="asd-section-title">📺 串流平台</h2>

							<?php if ( ! $has_tw_stream && $google_search_url ) : ?>
								<div class="asd-stream-region asd-stream-region--google">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--google"></span>
										<span>台灣暫無上架平台</span>
									</div>

									<p class="asd-stream-description">
										本作在台灣目前尚無串流平台資訊，可透過 Google 搜尋是否有其他合法觀看管道。
									</p>

									<div class="asd-stream-list">
										<a
											href="<?php echo esc_url( $google_search_url ); ?>"
											target="_blank"
											rel="noopener noreferrer nofollow"
											class="asd-stream-btn"
											title="<?php echo esc_attr( $display_title ); ?> Google 搜尋"
										>
											<span class="asd-stream-label">🔍 Google 搜尋哪裡看</span>
										</a>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $tw_streaming_items ) ) : ?>
								<div class="asd-stream-region asd-stream-region--tw">
									<div class="asd-stream-region-head">
										<span class="asd-stream-dot asd-stream-dot--tw"></span>
										<span>台灣地區</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $tw_streaming_items as $stream_item ) :
											$stream_label = trim( (string) ( $stream_item['label'] ?? '' ) );
											$stream_url = trim( (string) ( $stream_item['url'] ?? '' ) );
											$stream_icon = trim( (string) ( $stream_item['icon_url'] ?? '' ) );
											$stream_icon_only = ! empty( $stream_item['icon_only'] );

											if ( $stream_label === '' ) {
												continue;
											}

											$stream_class = 'asd-stream-btn'
												. ( $stream_icon_only ? ' asd-stream-btn--icon-only' : '' )
												. ( $stream_url ? '' : ' asd-stream-btn--no-link' );
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

											<?php if ( ! $stream_icon_only ) : ?>
												<span class="asd-stream-label">
													<?php echo esc_html( $stream_label ); ?>
												</span>
											<?php endif; ?>

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
										<span class="asd-stream-dot asd-stream-dot--dub"></span>
										<span>中文／台語／其他配音</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $dub_items_all as $dub_item ) :
											$dub_label = trim( (string) ( $dub_item['label'] ?? '' ) );
											$dub_url = trim( (string) ( $dub_item['url'] ?? '' ) );
											$dub_icon = trim( (string) ( $dub_item['icon'] ?? '' ) );

											if ( ! $dub_icon ) {
												$dub_icon = $guess_dub_icon( $dub_label, $dub_url );
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
										<span class="asd-stream-dot asd-stream-dot--os"></span>
										<span>海外平台</span>
										<span class="asd-stream-region-note">（台灣可能無法觀看）</span>
									</div>

									<div class="asd-stream-list">
										<?php foreach ( $overseas_streams as $overseas_item ) :
											if ( ! is_array( $overseas_item ) ) {
												continue;
											}

											$overseas_site = trim( (string) ( $overseas_item['site'] ?? '' ) );
											$overseas_url = trim( (string) ( $overseas_item['url'] ?? '' ) );

											if ( $overseas_site === '' || $overseas_url === '' ) {
												continue;
											}

											$overseas_key = strtolower( $overseas_site );
											$overseas_icon = isset( $provider_icon_map[ $overseas_key ] )
												? $provider_icon_base . $provider_icon_map[ $overseas_key ]
												: '';
											?>
											<a
												href="<?php echo esc_url( $overseas_url ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												class="asd-stream-btn<?php echo $overseas_icon ? ' asd-stream-btn--icon-only' : ''; ?> asd-stream-btn--os"
												title="<?php echo esc_attr( $overseas_site ); ?>"
												data-fallback-label="<?php echo esc_attr( $overseas_site ); ?>"
											>
												<?php if ( $overseas_icon ) : ?>
													<img
														src="<?php echo esc_url( $overseas_icon ); ?>"
														alt=""
														class="asd-stream-icon"
														loading="lazy"
														decoding="async"
													>
													<span class="asd-stream-label asd-stream-label--fallback">
														<?php echo esc_html( $overseas_site ); ?>
													</span>
												<?php else : ?>
													<span class="asd-stream-label">
														<?php echo esc_html( $overseas_site ); ?>
													</span>
												<?php endif; ?>
											</a>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<p class="asd-stream-disclaimer">
								⚠️ 串流連結可能因平台授權異動而失效，建議以官方平台公告為準。
							</p>
						</section>
					<?php endif; ?>

					<?php if ( $has_online_watch ) : ?>
						<section class="asd-section" id="asd-sec-online">
							<h2 class="asd-section-title">
								▶ 線上看
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
									<div class="asd-pv-tabs asd-ow-tabs" role="tablist" aria-label="線上看切換">
										<?php foreach ( $online_watch_items as $online_index => $online_item ) : ?>
											<button
												type="button"
												class="asd-pv-tab asd-ow-tab<?php echo $online_index === 0 ? ' is-active' : ''; ?>"
												role="tab"
												aria-selected="<?php echo $online_index === 0 ? 'true' : 'false'; ?>"
												aria-controls="asd-ow-panel-<?php echo (int) $online_index; ?>"
												data-ow-index="<?php echo (int) $online_index; ?>"
												data-ow-id="<?php echo esc_attr( $online_item['id'] ); ?>"
											>
												<span class="asd-pv-tab-icon">▶</span>
												<span class="asd-pv-tab-label">
													<?php echo esc_html( $online_item['label'] ); ?>
												</span>
											</button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>

								<div class="asd-pv-panels asd-ow-panels">
									<?php foreach ( $online_watch_items as $online_index => $online_item ) :
										$online_embed_url = ( $online_item['type'] ?? 'video' ) === 'playlist'
											? 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode( $online_item['id'] )
											: 'https://www.youtube.com/embed/' . rawurlencode( $online_item['id'] );
										?>
										<div
											class="asd-pv-panel asd-ow-panel<?php echo $online_index === 0 ? ' is-active' : ''; ?>"
											id="asd-ow-panel-<?php echo (int) $online_index; ?>"
											role="tabpanel"
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

							<p class="asd-stream-disclaimer">
								⚠️ 影片由 YouTube 提供；若無法播放，可能需加入頻道會員，或影片已下架、限制嵌入。
							</p>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $faq_main ) ) : ?>
						<section class="asd-section" id="asd-sec-faq">
							<h2 class="asd-section-title">❓ 常見問題</h2>

							<div class="asd-faq-list">
								<?php foreach ( $faq_items as $faq_item ) :
									if ( ! is_array( $faq_item ) ) {
										continue;
									}

									$faq_question = trim( (string) ( $faq_item['q'] ?? '' ) );
									$faq_answer = trim( (string) ( $faq_item['a'] ?? '' ) );

									if ( $faq_question === '' || $faq_answer === '' ) {
										continue;
									}
									?>
									<div class="asd-faq-item">
										<div class="asd-faq-q">
											<span class="asd-faq-q-label">Q.</span>
											<span class="asd-faq-q-text">
												<?php echo esc_html( $faq_question ); ?>
											</span>
										</div>

										<div class="asd-faq-a">
											<span class="asd-faq-a-label">A.</span>
											<div class="asd-faq-a-text">
												<?php echo wp_kses_post( wpautop( $faq_answer ) ); ?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>

					<?php
					$clean_anilist = $anilist_id > 0 ? (string) $anilist_id : '';
					$clean_mal = $mal_id > 0 ? (string) $mal_id : '';
					$clean_bangumi = $bangumi_id > 0 ? (string) $bangumi_id : '';
					?>

					<?php if ( $has_external_links ) : ?>
						<section class="asd-section" id="asd-sec-links">
							<h2 class="asd-section-title">🔗 外部連結</h2>

							<div class="asd-ext-links-grid">
								<?php if ( $official_site ) : ?>
									<a href="<?php echo esc_url( $official_site ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card">
										<span class="asd-ext-site">🌐 官方網站</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $twitter_url ) : ?>
									<a href="<?php echo esc_url( $twitter_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card">
										<span class="asd-ext-site">𝕏 Twitter / X</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $wikipedia_url ) : ?>
									<a href="<?php echo esc_url( $wikipedia_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card">
										<span class="asd-ext-site">📖 Wikipedia</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $tiktok_url ) : ?>
									<a href="<?php echo esc_url( $tiktok_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card">
										<span class="asd-ext-site">🎵 TikTok</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_anilist ) : ?>
									<a href="<?php echo esc_url( 'https://anilist.co/anime/' . $clean_anilist ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--al">
										<span class="asd-ext-site">🔵 AniList</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_mal ) : ?>
									<a href="<?php echo esc_url( 'https://myanimelist.net/anime/' . $clean_mal ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--mal">
										<span class="asd-ext-site">🔵 MyAnimeList</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>

								<?php if ( $clean_bangumi ) : ?>
									<a href="<?php echo esc_url( 'https://bgm.tv/subject/' . $clean_bangumi ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--bgm">
										<span class="asd-ext-site">🍡 Bangumi</span>
										<span class="asd-ext-arrow">→</span>
									</a>
								<?php endif; ?>
							</div>
						</section>
					<?php endif; ?>

					<section class="asd-section asd-comments" id="asd-sec-comments">
						<h2 class="asd-section-title">💬 留言</h2>
						<div class="asd-comments-inner">
							<?php comments_template(); ?>
						</div>
					</section>

					<?php if ( shortcode_exists( 'wxacg_correction_form' ) ) : ?>
						<section class="asd-section asd-corrections" id="asd-sec-corrections">
							<?php echo do_shortcode( '[wxacg_correction_form]' ); ?>
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

									$studio_url = home_url( '/anime/' );

									if (
										! is_wp_error( $studio_terms )
										&& ! empty( $studio_terms )
									) {
										$studio_term_url = get_term_link( $studio_terms[0] );

										if ( ! is_wp_error( $studio_term_url ) ) {
											$studio_url = $studio_term_url;
										}
									}
									?>
									<a href="<?php echo esc_url( $studio_url ); ?>" class="asd-tag-item asd-tag-item--studio">
										🎬 <?php echo esc_html( $studio ); ?>
									</a>
								<?php endif; ?>

								<?php foreach ( $season_child_terms as $season_term ) :
									$season_term_url = get_term_link( $season_term );

									if ( is_wp_error( $season_term_url ) ) {
										continue;
									}
									?>
									<a href="<?php echo esc_url( $season_term_url ); ?>" class="asd-tag-item asd-tag-item--season">
										<?php echo esc_html( $season_term->name ); ?>
									</a>
								<?php endforeach; ?>

								<?php foreach ( $genre_terms as $genre_term ) :
									$genre_term_url = get_term_link( $genre_term );

									if ( is_wp_error( $genre_term_url ) ) {
										continue;
									}
									?>
									<a href="<?php echo esc_url( $genre_term_url ); ?>" class="asd-tag-item">
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
											<span class="asd-news-arrow">→</span>
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
									<a href="<?php echo esc_url( $relation_item['url'] ); ?>" class="asd-mini-card">
										<div class="asd-mini-card__thumb">
											<?php if ( ! empty( $relation_item['cover_image'] ) ) : ?>
												<img
													src="<?php echo esc_url( $relation_item['cover_image'] ); ?>"
													alt="<?php echo esc_attr( $relation_item['title_zh'] ); ?>"
													loading="lazy"
													decoding="async"
												>
											<?php else : ?>
												<div class="asd-mini-card__thumb-fb">
													<span>
														<?php echo esc_html( $fallback_text( $relation_item['title_zh'], 2 ) ); ?>
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
							喜歡這部作品的資訊嗎？微笑動漫每天整合全球動漫情報，你的咖啡讓我們繼續走下去 ☕
						</div>
						<a
							href="<?php echo esc_url( home_url( '/sponsor/' ) ); ?>"
							class="asd-sponsor-btn"
						>
							贊助微笑動漫
						</a>
						<div class="asd-sponsor-note">
							贊助費用於伺服器維護，感謝每一位支持者
						</div>
					</div>

					<div class="asd-ad-placeholder" aria-label="廣告版位">
						<div class="asd-ad-inner"></div>
					</div>
				</aside>
			</div><!-- /.asd-container -->
		</div><!-- /.asd-tabs-wrap -->
	</div><!-- /.asd-wrap -->

<?php
endwhile;

get_footer();
