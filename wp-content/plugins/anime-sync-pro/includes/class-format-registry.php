<?php
/**
 * 檔案名稱: includes/class-format-registry.php
 * 作品格式（AniList format）統一登錄表 — Single Source of Truth
 *
 * ✅ 改一個格式的顯示名稱只需動此檔的 $FORMATS，以下全部自動生效：
 *    - class-acf-fields.php        後台 meta 面板 ×4、anime_format 下拉 ×1
 *    - class-import-manager.php    anime_format_tax 建立 term 時的名稱
 *    - public/templates/archive-anime.php   前台列表徽章
 *    - public/templates/archive-series.php  系列頁徽章
 *    - public/templates/single-anime.php    單篇徽章
 *    - 主題 page-bangumi.php                新番表徽章
 *
 * ★ 為什麼要有這支檔案
 *   在此之前同一張對照表被複製在 7 個檔案共 11 份，於是各自漂移。
 *   2026-09-15 實測站上同時存在：
 *     TV_SHORT → 「TV 短篇」「TV短篇」「短篇 TV 動畫」「電視短篇動畫」
 *                「短篇電視動漫 (TV_SHORT)」 共 5 種
 *     ONA      → 「ONA」「ONA（網路動畫）」「網路動漫 (ONA)」
 *                「網路原創動畫（Original Net Animation）。首播平台是…」 共 5 種
 *     MUSIC    → 「音樂」「MV」「音樂MV」「音樂 MV」 共 4 種
 *   漏改不會有任何警告，只會在某個頁面顯示跟別頁不一樣的字。
 *
 *   作法比照 class-distributor-registry.php 與 class-streaming-registry.php，
 *   同一個專案裡維持一致的模式。
 *
 * ★ 為什麼分 short / long / choice 三種風格
 *   這不是漂移，是原本就存在的合理區分，收斂時刻意保留：
 *     short  前台徽章，位置窄，要短（TV、MV）
 *     long   後台 meta 面板，看得懂比短重要（電視動畫、音樂 MV）
 *     choice ACF 下拉，後台編輯時要能對上 AniList 原始代碼
 *
 * ★ 刻意不收編的一處
 *   ai-editorial-tool.php 的 wxacg_editorial_format_label() 維持獨立。
 *   它的輸出會進 AI 產文的事實清單（「形式：短篇 TV 動畫」），需要中性
 *   敘述而不是「泡麵番」這種帶戲謔的俗稱，否則模型會把它寫進正式文章。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Format_Registry {

	/**
	 * ★ 改格式顯示名稱只需改這裡 ★
	 *
	 * key       → AniList 的 format 代碼，同時是存進 anime_format 的值，
	 *             一旦上線就不要再改，已存的文章是靠它對應顯示名稱的
	 * short     → 前台徽章用（必填）
	 * long      → 後台說明用（省略時退回 short）
	 * choice    → ACF 下拉用（省略時退回 long）
	 * slug      → anime_format_tax 的 term slug，**不要改**，改了網址會斷
	 * term_name → term 名稱與 short 不同時才寫（省略時退回 short）
	 *
	 * 沒有 slug 的是非動畫格式，只有系列頁會用到（系列可包含漫畫／小說），
	 * 不會建立 anime_format_tax 的 term。
	 *
	 * @var array<string,array<string,string>>
	 */
	private static array $FORMATS = [
		'TV'       => [
			'short'  => 'TV',
			'long'   => '電視動畫',
			'choice' => '電視動畫 (TV)',
			'slug'   => 'tv',
		],
		'TV_SHORT' => [
			// 2026-09-15 由「TV短篇」改為社群慣用的「泡麵番」（一集短到只夠泡碗麵）。
			// 三種風格相同：它本身就夠短，不需要再分長短版。
			'short'  => '泡麵番',
			'choice' => '泡麵番 (TV_SHORT)',
			'slug'   => 'tv-short',
		],
		'MOVIE'    => [
			'short'  => '劇場版',
			'choice' => '劇場版 (MOVIE)',
			'slug'   => 'movie',
		],
		'OVA'      => [
			'short' => 'OVA',
			'slug'  => 'ova',
		],
		'ONA'      => [
			'short'  => 'ONA',
			'long'   => 'ONA（網路動畫）',
			// 明確寫出而不讓它退回 long，下拉才跟其他選項維持「名稱 (代碼)」的版式
			'choice' => '網路動畫 (ONA)',
			'slug'   => 'ona',
		],
		'SPECIAL'  => [
			'short'  => '特別篇',
			'choice' => '特別篇 (SPECIAL)',
			'slug'   => 'special',
		],
		'MUSIC'    => [
			'short'  => 'MV',
			'long'   => '音樂 MV',
			'choice' => '音樂 MV (MUSIC)',
			'slug'   => 'music',
			// 正式站既有 term 就叫「音樂MV」（無空格）。改它沒有好處，
			// 只會多一次正式站寫入，所以這裡保留原樣、不跟著 long 走。
			'term_name' => '音樂MV',
		],

		/* ── 非動畫格式：系列可包含漫畫／小說，只有系列頁會顯示 ── */
		'MANGA'       => [ 'short' => '漫畫' ],
		'ONE_SHOT'    => [ 'short' => '短篇' ],
		'NOVEL'       => [ 'short' => '小說' ],
		'LIGHT_NOVEL' => [ 'short' => '輕小說' ],
	];

	/**
	 * 完整登錄表。
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all(): array {
		return self::$FORMATS;
	}

	/**
	 * 單一格式的顯示名稱。
	 *
	 * @param string $code  AniList format 代碼。
	 * @param string $style short|long|choice，預設 short。
	 * @return string 找不到時回傳空字串（呼叫端自行決定要不要顯示原始代碼）。
	 */
	public static function get_label( string $code, string $style = 'short' ): string {
		$code = strtoupper( trim( $code ) );
		$row  = self::$FORMATS[ $code ] ?? null;
		if ( null === $row ) {
			return '';
		}

		// choice 退回 long、long 退回 short，省得每一筆都要寫滿三種
		if ( 'choice' === $style ) {
			return $row['choice'] ?? $row['long'] ?? $row['short'];
		}
		if ( 'long' === $style ) {
			return $row['long'] ?? $row['short'];
		}
		return $row['short'];
	}

	/**
	 * 整張 代碼 => 名稱 對照表，給原本就寫成 $format_labels 陣列的呼叫端沿用。
	 *
	 * @param string $style          short|long|choice。
	 * @param bool   $anime_only     true 時排除漫畫／小說等非動畫格式。
	 * @return array<string,string>
	 */
	public static function get_labels( string $style = 'short', bool $anime_only = false ): array {
		$out = [];
		foreach ( self::$FORMATS as $code => $row ) {
			if ( $anime_only && empty( $row['slug'] ) ) {
				continue;
			}
			$out[ $code ] = self::get_label( $code, $style );
		}
		return $out;
	}

	/**
	 * ACF select 的 choices。只收動畫格式——anime_format 欄位不會存漫畫格式。
	 *
	 * @return array<string,string>
	 */
	public static function get_acf_choices(): array {
		return self::get_labels( 'choice', true );
	}

	/**
	 * anime_format_tax 建立 term 用的 name / slug。
	 *
	 * slug 是既有網址的一部分，**不要改**；name 可以改，改了只影響分類頁標題。
	 * 注意這只在「建立新 term」時生效，既有 term 的名稱要另外用 wp term update 改。
	 *
	 * @return array<string,array{name:string,slug:string}>
	 */
	public static function get_term_map(): array {
		$out = [];
		foreach ( self::$FORMATS as $code => $row ) {
			if ( empty( $row['slug'] ) ) {
				continue;
			}
			$out[ $code ] = [
				'name' => $row['term_name'] ?? $row['short'],
				'slug' => $row['slug'],
			];
		}
		return $out;
	}
}
