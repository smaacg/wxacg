<?php
/**
 * Staff Roles — 製作職位的分組與排序規則。
 *
 * 2026-08-31 拿掉匯入端的職位白名單之後（class-api-handler.php 的
 * get_bgm_staff），一部作品的 STAFF 從 6-7 筆變成平均 121 筆、最多 475 筆，
 * 職位種類最多 57 種。平鋪 475 張卡片沒人看得完，其中 137 張還都標著「原画」。
 *
 * 所以前台改成依職位分組，這個類別集中管理「哪些職位重要、怎麼排」。
 *
 * PRIMARY 這份名單就是原本的白名單，一個字沒改——那份名單被用了很久，
 * 代表判斷過。只是用途從「過濾掉其他職位」變成「排在最前面」，
 * 既有判斷保留，但不再擋資料。
 *
 * Changelog:
 *   1.0.0 (2026-08-31) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Staff_Roles {

	/**
	 * 主要職位。陣列順序就是顯示順序。
	 *
	 * 同時列簡繁兩種寫法：Bangumi 回的是簡體（导演／音响监督），但站上
	 * 既有資料經過繁簡轉換的那批是繁體（監督／音響監督），兩種都要認得。
	 * 實測 wp_anime_relations 裡「腳本 2,268 筆」與「脚本 847 筆」並存，
	 * 只認一種會漏掉一半。
	 */
	const PRIMARY = [
		'导演', '監督', '监督', '導演',
		'原作',
		'系列构成', '系列構成',
		'脚本', '腳本', '劇本',
		'人物原案',
		'角色设计', '角色設計',
		'人物设定', '人物設定',
		'音乐', '音樂',
		'音响监督', '音響監督',
		'动画制作', '動畫製作',
		'主题歌演出', '主題歌演出',
		'主题歌作词', '主題歌作詞',
		'主题歌作曲', '主題歌作曲',
	];

	/**
	 * 職位顯示名稱：簡體 → 繁體。
	 *
	 * Bangumi 回的職位是簡體，站上是繁體站，不轉的話畫面會出現
	 *「导演」「音响监督」。不丟給 CN_Converter 是因為職位名稱是固定的
	 * 有限集合，逐條列出可控，也不會誤轉（例如「制作」在日文語境是
	 *「製作」，但「动画制作」的正確譯法是「動畫製作」）。
	 *
	 * 沒列到的職位原樣輸出，不硬猜。
	 */
	const LABELS = [
		'导演'         => '監督',
		'副导演'       => '副監督',
		'原作'         => '原作',
		'原作协力'     => '原作協力',
		'系列构成'     => '系列構成',
		'脚本'         => '腳本',
		'人物原案'     => '人物原案',
		'角色设计'     => '角色設計',
		'人物设定'     => '人物設定',
		'副人物设定'   => '副人物設定',
		'音乐'         => '音樂',
		'音乐制作'     => '音樂製作',
		'音乐制作人'   => '音樂製作人',
		'音乐助理'     => '音樂助理',
		'音响监督'     => '音響監督',
		'音响'         => '音響',
		'音响制作担当' => '音響製作擔當',
		'音效'         => '音效',
		'录音'         => '錄音',
		'动画制作'     => '動畫製作',
		'制作'         => '製作',
		'制片人'       => '製片人',
		'副制片人'     => '副製片人',
		'动画制片人'   => '動畫製片人',
		'宣传制片人'   => '宣傳製片人',
		'助理制片人'   => '助理製片人',
		'执行制片人'   => '執行製片人',
		'制作进行'     => '製作進行',
		'制作进行协力' => '製作進行協力',
		'制作管理'     => '製作管理',
		'制作协力'     => '製作協力',
		'设定制作'     => '設定製作',
		'企画'         => '企劃',
		'监制'         => '監製',
		'监修'         => '監修',
		'原画'         => '原畫',
		'第二原画'     => '第二原畫',
		'补间动画'     => '補間動畫',
		'动画检查'     => '動畫檢查',
		'作画监督'     => '作畫監督',
		'总作画监督'   => '總作畫監督',
		'作画监督助理' => '作畫監督助理',
		'动作作画监督' => '動作作畫監督',
		'演出'         => '演出',
		'主演出'       => '主演出',
		'演出助理'     => '演出助理',
		'助理导演'     => '助理導演',
		'分镜'         => '分鏡',
		'美术监督'     => '美術監督',
		'美术监督助理' => '美術監督助理',
		'美术设计'     => '美術設計',
		'美术板'       => '美術板',
		'背景美术'     => '背景美術',
		'色彩设计'     => '色彩設計',
		'色彩指定'     => '色彩指定',
		'摄影监督'     => '攝影監督',
		'摄影'         => '攝影',
		'剪辑'         => '剪輯',
		'在线剪辑'     => '線上剪輯',
		'机械设定'     => '機械設定',
		'道具设计'     => '道具設計',
		'设定协力'     => '設定協力',
		'特效'         => '特效',
		'视觉效果'     => '視覺效果',
		'协力'         => '協力',
		'特别鸣谢'     => '特別鳴謝',
		'主题歌演出'   => '主題歌演出',
		'主题歌作词'   => '主題歌作詞',
		'主题歌作曲'   => '主題歌作曲',
		'主题歌编曲'   => '主題歌編曲',
	];

	/**
	 * 這個職位是不是主要職位。
	 */
	public static function is_primary( string $role ): bool {
		return in_array( trim( $role ), self::PRIMARY, true );
	}

	/**
	 * 主要職位的排序位置；不是主要職位回 PHP_INT_MAX。
	 */
	public static function primary_order( string $role ): int {
		$i = array_search( trim( $role ), self::PRIMARY, true );

		return false === $i ? PHP_INT_MAX : (int) $i;
	}

	/**
	 * 顯示用的職位名稱。對不到就原樣回傳，不硬猜。
	 *
	 * 純查表，故意不做簡繁轉換——這支會在前台每個職位群組跑一次，實測
	 * 一部作品可以有 69 個群組，靠 CN_Converter 補會多花 27ms，佔頁面
	 * TTFB 的 18%。轉換改在匯入端做一次（見 normalize()）。
	 */
	public static function label( string $role ): string {
		$role = trim( $role );

		return self::LABELS[ $role ] ?? $role;
	}

	/**
	 * 匯入端用的職位名稱正規化。存進 anime_staff_json 之前跑一次。
	 *
	 * 拿掉白名單之後職位種類從 14 種變成站上實測 280 種，LABELS 這份
	 * 手寫對照表只蓋到 66 種，其餘 214 種原樣存下去，前台就會出現
	 * 「3DCG 导演」「企画协力」「总制片人」這種簡體職位名。
	 *
	 * 兩段式：LABELS 優先（手寫的是策展結果，像「导演」→「監督」這種
	 * 用語差異不是簡繁轉換做得到的），對不到才交給 CN_Converter。實測
	 * 214 種對不到的裡面，轉換器能修好 61 種（企画协力→企畫協力、
	 * 总制片人→總製片人、CG 导演→CG 監督），其餘 153 種本來就是繁體
	 * 或純英數。
	 *
	 * 放在匯入端而不是前台，是因為每次轉換 0.39ms——匯入時一部作品
	 * 幾百筆只跑一次，前台則是每次瀏覽都要重跑。
	 */
	public static function normalize( string $role ): string {
		$role = trim( $role );

		if ( '' === $role ) {
			return $role;
		}

		if ( isset( self::LABELS[ $role ] ) ) {
			return self::LABELS[ $role ];
		}

		if ( class_exists( 'Anime_Sync_CN_Converter' ) ) {
			return Anime_Sync_CN_Converter::static_convert( $role );
		}

		return $role;
	}

	/**
	 * 把 staff 清單依職位分組。
	 *
	 * 主要職位在前（依 PRIMARY 的宣告順序），其餘依原始出現順序墊後——
	 * Bangumi 的排列本身就大致由重要到次要，沿用它比自己再定一套規則可靠。
	 *
	 * @param array $staff anime_staff_json 解出來的清單
	 * @return array[] [ [ 'label'=>'監督', 'primary'=>true, 'items'=>[...] ], ... ]
	 */
	public static function group( array $staff ): array {
		$buckets = [];
		$seen    = [];
		$order   = 0;

		foreach ( $staff as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$role = trim( (string) ( $item['role'] ?? '' ) );

			if ( '' === $role ) {
				$role = '其他';
			}

			if ( ! isset( $buckets[ $role ] ) ) {
				$buckets[ $role ] = [
					'label'   => self::label( $role ),
					'primary' => self::is_primary( $role ),
					'sort'    => self::primary_order( $role ),
					'seen'    => $order++,
					'items'   => [],
				];
			}

			$buckets[ $role ]['items'][] = $item;
		}

		/*
		 * 主要職位依 PRIMARY 的順序，其餘依第一次出現的順序。
		 * 兩者之間用 sort 的 PHP_INT_MAX 天然分開，不必額外標記。
		 */
		uasort(
			$buckets,
			static function ( array $a, array $b ): int {
				if ( $a['sort'] !== $b['sort'] ) {
					return $a['sort'] <=> $b['sort'];
				}

				return $a['seen'] <=> $b['seen'];
			}
		);

		return array_values( $buckets );
	}
}
