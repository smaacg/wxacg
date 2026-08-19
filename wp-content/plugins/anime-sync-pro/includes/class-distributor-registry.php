<?php
/**
 * 檔案名稱: includes/class-distributor-registry.php
 * 台灣代理商／發行商統一登錄表 — Single Source of Truth
 *
 * ✅ 新增代理商只需在此檔案的 $DISTRIBUTORS 加一筆，以下全部自動生效：
 *    - class-acf-fields.php  真欄位   field_anime_tw_distributor
 *    - class-acf-fields.php  捷徑欄位 field_shortcut_anime_tw_distributor
 *    - 前台模板 single-anime.php      $tw_dist_labels
 *
 * ★ 為什麼要有這支檔案
 *   在此之前同一份清單被複製在上述三處。新增「壹壹喜喜」時只改了前兩處，
 *   漏掉前台那份——而前台有 `?? $tw_distributor` 的 fallback，
 *   結果不會報錯，只會在頁面上顯示英文代碼「yiyixixi」。
 *   這種漏改不會有任何警告，只能靠人記得三個地方都要動。
 *
 *   作法比照 class-streaming-registry.php，同一個專案裡維持一致的模式。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Distributor_Registry {

	/**
	 * ★ 新增代理商只需在這裡加一筆 ★
	 *
	 * key   → 存進 anime_tw_distributor 的機器識別碼，一旦上線就不要再改，
	 *         已存的文章是靠它對應顯示名稱的
	 * label → 顯示名稱
	 *
	 * 順序即下拉選單的顯示順序。'other' 由 get_acf_choices() 自動補在最後，
	 * 不要寫進這個陣列。
	 *
	 * @var array<string,string>
	 */
	private static array $DISTRIBUTORS = [
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
		'righttime' => '利得時代（Right Time）',
		'aniplus'   => 'ANIPLUS Asia',
		'tongli'    => '東立出版社',
		'remow'     => 'REMOW',
		'gaga'      => 'GaGa OOLala',
		'liying'    => '麗嬰國際',
		'yiyixixi'  => '壹壹喜喜',
	];

	/**
	 * 原始清單（不含「請選擇」與「其他」）。
	 *
	 * @return array<string,string> key => label
	 */
	public static function all(): array {
		return self::$DISTRIBUTORS;
	}

	/**
	 * ACF select 欄位用的 choices。
	 *
	 * 前後自動補上「請選擇」與「其他(自訂)」——這兩個是 UI 需要的項目，
	 * 不屬於代理商本身，因此不放進 $DISTRIBUTORS。
	 *
	 * @return array<string,string>
	 */
	public static function get_acf_choices(): array {
		return array_merge(
			[ '' => '── 請選擇 ──' ],
			self::$DISTRIBUTORS,
			[ 'other' => '其他(自訂)' ]
		);
	}

	/**
	 * 前台顯示用的對照表。
	 *
	 * 'other' 對應空字串——選了「其他」時要顯示的是
	 * anime_tw_distributor_custom 的內容，由模板自行處理。
	 *
	 * @return array<string,string>
	 */
	public static function get_labels(): array {
		return self::$DISTRIBUTORS + [ 'other' => '' ];
	}

	/**
	 * 單一 key 的顯示名稱。
	 *
	 * @param string $key 代理商識別碼。
	 * @return string 找不到時回傳空字串（呼叫端自行決定要不要顯示原始 key）。
	 */
	public static function get_label( string $key ): string {
		return self::$DISTRIBUTORS[ $key ] ?? '';
	}
}
