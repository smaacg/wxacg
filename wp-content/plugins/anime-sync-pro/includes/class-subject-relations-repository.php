<?php
/**
 * Subject Relations Repository — Bangumi 跨媒體關聯的唯讀查詢層。
 *
 * 資料來源 wp_wxacg_subject_relations（由 scratchpad/bgm/relations.php 匯入）,
 * 記錄「這部動畫關聯到哪些音樂 / 遊戲 / 真人版 / 書籍 / 其他動畫」。
 *
 * 設計取捨：
 *
 *   1. 不為每張專輯建 CPT 文章。5,837 首歌各開一篇會是大量薄內容，
 *      而且搜尋意圖本來就落在動畫頁上。詳見 anime-sync-pro.php 註解。
 *
 *   2. 分組欄位依類型而異——音樂用 relation_type（原聲集/角色歌/片頭曲），
 *      遊戲和三次元用 platform（桌遊/電影/舞台劇）。實測 2026-08-29：
 *      三次元的 relation_type 有 207/357 是 11「衍生」，區分度太低，
 *      platform 才講得出「這是電影還是舞台劇」。
 *
 *   3. 標題不外連 Bangumi。站內有對應文章才給連結（local_post_id），
 *      否則純文字——整頁看完是這個區塊的目的，不是把人送出站。
 *
 * Changelog:
 *   1.0.0 (2026-08-29) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Subject_Relations_Repository {

	private $table;

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const CACHE_VER = 'v3';

	/**
	 * 總數不超過這個值就攤平成單一面封面牆，不分組。
	 *
	 * 分組在數量少時只會製造破洞：實測正式站分布，80% 的作品專輯總數
	 * 不到 6 張，再切成原聲集／片頭曲／片尾曲／插入歌四組，每組永遠
	 * 是 1-2 張，每一排右邊都空一大截，看起來是四排零星小圖而不是一面牆。
	 *
	 * 12 這個門檻讓 93% 的作品（1-12 張）走單一面牆；13 張以上一組通常
	 * 已經填得滿一行，分組才真的在整理東西。
	 * 攤平時類型改由每張自己的 'group' 標示，資訊不會少。
	 */
	const FLAT_MAX = 12;

	/* Bangumi subject_type */
	const TYPE_BOOK   = 1;
	const TYPE_ANIME  = 2;
	const TYPE_MUSIC  = 3;
	const TYPE_GAME   = 4;
	const TYPE_REAL   = 6;

	/**
	 * 音樂關聯類型。陣列順序就是顯示順序。
	 *
	 * ★ 原聲集排第一、片頭曲片尾曲排後面,是刻意的：
	 *   動畫頁的音樂區塊上半部已經有 AnimeThemes 的 OP/ED 播放器,
	 *   Bangumi 的 3003/3004 是同幾首歌的 CD 單曲,擺前面會讓使用者
	 *   在同一個區塊裡看到兩次「Blue Bird」。實測火影收合狀態下
	 *   6 筆全是重複的片頭曲,整塊等於沒有新資訊。
	 *   把上面沒有的(原聲集、角色歌、廣播劇)排前面才有增益。
	 */
	const MUSIC_LABELS = [
		3001 => '原聲集',
		3002 => '角色歌',
		3007 => '廣播劇',
		3006 => '印象曲',
		3005 => '插入歌',
		3003 => '片頭曲',
		3004 => '片尾曲',
		3099 => '其他',
	];

	/** 遊戲平台。實測只出現 4001 / 4005 兩種。 */
	const GAME_PLATFORMS = [
		4001 => '遊戲',
		4005 => '桌遊',
	];

	/**
	 * 三次元平台。0 代表 Bangumi 沒填，歸「其他」。
	 * 1/2/3 是劇集地區，6001-6004 是形式，兩套編碼混用是 Bangumi 的原始設計。
	 */
	const REAL_PLATFORMS = [
		6002 => '電影',
		6003 => '舞台劇',
		6001 => '電視劇',
		1    => '日劇',
		2    => '歐美劇',
		3    => '華語劇',
		6004 => '綜藝',
		0    => '其他',
	];

	/**
	 * 通用關聯類型，當作次要標籤（badge）用。
	 * 只列實測有出現且語意明確的；沒列到的回傳空字串不顯示 badge，
	 * 不硬猜——寧可少一個標籤，也不要標錯。
	 */
	const REL_BADGES = [
		1  => '改編',
		2  => '前傳',
		3  => '續集',
		6  => '番外篇',
		7  => '角色出演',
		8  => '相同世界觀',
		10 => '不同演繹',
		11 => '衍生',
		12 => '主線故事',
		14 => '聯動',
	];

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'wxacg_subject_relations';
	}

	/**
	 * 取某作品某類型的關聯，已依顯示順序分組。
	 *
	 * @return array[] [ [ 'label' => '原聲集', 'items' => [ ... ] ], ... ]
	 *                 沒資料回空陣列。
	 */
	public function get_grouped( int $post_id, int $subject_type ): array {
		$rows = $this->get_rows( $post_id, $subject_type );

		if ( empty( $rows ) ) {
			return [];
		}

		/* 分組依據與標籤表依類型切換,理由見檔頭註解 2 */
		if ( self::TYPE_MUSIC === $subject_type ) {
			$key_field = 'relation_type';
			$labels    = self::MUSIC_LABELS;
		} elseif ( self::TYPE_GAME === $subject_type ) {
			$key_field = 'platform';
			$labels    = self::GAME_PLATFORMS;
		} elseif ( self::TYPE_REAL === $subject_type ) {
			$key_field = 'platform';
			$labels    = self::REAL_PLATFORMS;
		} else {
			$key_field = 'relation_type';
			$labels    = self::REL_BADGES;
		}

		$buckets = [];

		foreach ( $rows as $row ) {
			$key = (int) $row[ $key_field ];

			/* 標籤表沒有的代碼歸「其他」,不丟棄資料也不猜名稱 */
			$bucket = isset( $labels[ $key ] ) ? $key : '__other';

			$buckets[ $bucket ][] = $this->format_item( $row, $subject_type );
		}

		/*
		 * 依 $labels 宣告順序輸出,「其他」永遠墊底。
		 *
		 * 每個項目順便帶上自己所屬的組名（'group'）。前台在數量少的時候
		 * 會攤平成單一面封面牆不分組（見 FLAT_MAX），那時類型要由項目
		 * 自己標示——沒有這個欄位，攤平後就看不出哪張是片頭曲。
		 */
		$out = [];

		$with_group = static function ( array $items, string $label ): array {
			foreach ( $items as &$item ) {
				$item['group'] = $label;
			}

			unset( $item );

			return $items;
		};

		foreach ( array_keys( $labels ) as $key ) {
			if ( ! empty( $buckets[ $key ] ) ) {
				$out[] = [
					'label' => $labels[ $key ],
					'count' => count( $buckets[ $key ] ),
					'items' => $with_group( $buckets[ $key ], $labels[ $key ] ),
				];
			}
		}

		if ( ! empty( $buckets['__other'] ) ) {
			$out[] = [
				'label' => '其他',
				'count' => count( $buckets['__other'] ),
				'items' => $with_group( $buckets['__other'], '其他' ),
			];
		}

		return $out;
	}

	/**
	 * 該作品某類型共有幾筆。給導覽列判斷要不要顯示 tab 用。
	 */
	public function count_by_type( int $post_id, int $subject_type ): int {
		return count( $this->get_rows( $post_id, $subject_type ) );
	}

	/* =====================================================================
	 * 內部
	 * ===================================================================== */

	/**
	 * 單次查詢 + 快取。同一頁會問音樂/遊戲/三次元三次,
	 * 但快取以 post_id + type 為單位,不會重複打資料庫。
	 */
	private function get_rows( int $post_id, int $subject_type ): array {
		global $wpdb;

		if ( $post_id <= 0 || $subject_type <= 0 ) {
			return [];
		}

		$cache_key = sprintf(
			'asp_subjrel_%s_%d_%d',
			self::CACHE_VER,
			$post_id,
			$subject_type
		);

		$cached = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bgm_id, relation_type, platform, name, name_cn, local_post_id, cover_url
				 FROM {$this->table}
				 WHERE post_id = %d AND subject_type = %d
				 ORDER BY relation_type ASC, id ASC",
				$post_id,
				$subject_type
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			/* 查詢失敗與「沒資料」要分得出來,不能一律當空陣列快取起來 */
			if ( '' !== $wpdb->last_error ) {
				error_log(
					sprintf(
						'[anime-sync-pro] subject_relations 查詢失敗 post_id=%d type=%d: %s',
						$post_id,
						$subject_type,
						$wpdb->last_error
					)
				);
			}

			return [];
		}

		/*
		 * Bangumi 的 name_cn 是簡體(「火影忍者疾风传 羁绊驱动」),
		 * 本站是繁體站,必須轉換。在寫快取前轉,轉一次管 6 小時,
		 * 不要放到 format_item() 每次算。
		 */
		if ( class_exists( 'Anime_Sync_CN_Converter' ) ) {
			foreach ( $rows as &$row ) {
				if ( '' !== (string) $row['name_cn'] ) {
					$row['name_cn'] = Anime_Sync_CN_Converter::static_convert(
						(string) $row['name_cn']
					);
				}
			}

			unset( $row );
		}

		set_transient( $cache_key, $rows, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * 一列 → 顯示用陣列。
	 */
	private function format_item( array $row, int $subject_type ): array {
		$name_cn = trim( (string) $row['name_cn'] );
		$name    = trim( (string) $row['name'] );

		/* 中文名優先,沒有才退回原文;兩個都有才顯示副標題 */
		$title = '' !== $name_cn ? $name_cn : $name;
		$sub   = ( '' !== $name_cn && '' !== $name && $name_cn !== $name ) ? $name : '';

		$rel  = (int) $row['relation_type'];
		$post = (int) $row['local_post_id'];

		/*
		 * badge 只在「不是分組依據」時才有意義。
		 * 音樂已經用 relation_type 分組了,再標一次「片頭曲」是廢話。
		 */
		$badge = ( self::TYPE_MUSIC === $subject_type )
			? ''
			: ( self::REL_BADGES[ $rel ] ?? '' );

		return [
			'title'   => $title,
			'sub'     => $sub,
			'badge'   => $badge,
			'bgm_id'  => (int) $row['bgm_id'],
			/*
			 * 封面網址（Bangumi CDN，不是本站檔案）。
			 * NULL 代表回補還沒跑到，'' 代表對方沒有這張圖——前台兩者
			 * 都當成「沒封面」顯示佔位，差別只對回補程式有意義。
			 */
			'cover'   => (string) ( $row['cover_url'] ?? '' ),
			/* 站內有文章才給連結,理由見檔頭註解 3 */
			'url'     => ( $post > 0 && 'publish' === get_post_status( $post ) )
				? get_permalink( $post )
				: '',
		];
	}
}
