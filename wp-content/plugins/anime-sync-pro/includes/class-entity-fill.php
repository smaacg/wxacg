<?php
/**
 * Entity Fill — 用預先算好的資料檔補上實體缺少的欄位。
 *
 * 為什麼是「檔案」而不是「向 Bangumi 抓」：
 *
 *   正式站實測 14,325 筆實體缺 infobox／summary／name_cn，但把整份
 *   Bangumi Archive dump 撈過一遍之後，真正有資料可補的只有 219 筆。
 *   其餘 13,514 筆在 Bangumi 上本身就是空的——多半是配角、單集角色、
 *   小牌工作人員，條目只有名字。抽樣以 API 交叉驗證過，dump 與 API
 *   的結果完全一致（例：bgm person 26907 金澤慎太郎，兩邊 summary 皆 0 bytes、
 *   infobox 只有一個「简体中文名」有值）。
 *
 *   也就是說站上的資料**已經與 Bangumi 一致**，只差這 219 筆。
 *   為了 219 筆去打 14,325 次 API 不划算，而且 2026-08 的實體回補 cron
 *   正是這樣把正式站 load 打上去的——它反覆重問那些永遠是空的條目。
 *
 * 為什麼資料檔要放進 repo：
 *
 *   正式站的資料庫沒辦法用 git push 更新，dump 又有 414MB，不適合放進
 *   repo 也不適合讓正式站自己下載解析。改成在本地把重活做完（解析 32 萬行
 *   Bangumi wiki 語法），只把「真正要寫進去的欄位」送上去——42 KB。
 *
 * 資料檔的產生方式與轉換規則刻意與正常匯入路徑一致
 *（class-entity-migrator.php 的 infobox 處理 + Anime_Sync_CN_Converter），
 * 所以這裡只做單純的 UPDATE，不再做任何轉換。
 *
 * 只填空欄位，絕不覆蓋既有資料。
 *
 * Changelog:
 *   1.0.0 (2026-08-30) — 初版。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Entity_Fill {

	/**
	 * 每一份資料檔跑過就記一次，避免升級時重複執行。
	 *
	 * 用檔名當 key 而不是單一布林值：日後再產一份新的資料檔時，
	 * 舊的那筆紀錄不會擋住新的。
	 */
	const OPTION_DONE = 'anime_sync_entity_fill_done';

	const DATA_DIR = 'data/';

	/** 單次事件的 hook。跑完就結束，不會留下常駐排程。 */
	const HOOK = 'anime_sync_entity_fill_run';

	/**
	 * 目前要跑的資料檔。日後再產新的就往這裡加一行，舊的紀錄不會擋住它。
	 */
	const FILES = [
		'entity-fill-2026-08-30.jsonl',
	];

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run_pending' ] );
	}

	/**
	 * 有沒有還沒跑的資料檔。
	 */
	public static function pending(): array {
		$done = (array) get_option( self::OPTION_DONE, [] );

		return array_values( array_diff( self::FILES, array_keys( $done ) ) );
	}

	/**
	 * 排一個單次事件把待跑的資料檔處理掉。
	 *
	 * 不在 maybe_upgrade() 裡直接跑：219 筆會產生約 650 次查詢，那不該卡在
	 * 使用者的頁面載入上——版本升級的偵測有可能發生在任何一個前台請求。
	 * 沒有待跑的就不排程，所以平常完全沒有成本。
	 */
	public static function maybe_schedule(): void {
		if ( ! self::pending() ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, self::HOOK );
	}

	/**
	 * 單次事件的處理器：把還沒跑的資料檔一次跑完。
	 */
	public static function run_pending(): void {
		foreach ( self::pending() as $file ) {
			self::run( $file );
		}
	}

	/**
	 * 跑一份資料檔。
	 *
	 * @param string $file 檔名（相對於外掛的 data/ 目錄）
	 * @param bool   $force 忽略「跑過了」的紀錄，重跑一次
	 * @return array{ok:bool, msg:string, rows:int, filled:int, skipped:int}
	 */
	public static function run( string $file, bool $force = false ): array {
		$fail = [ 'ok' => false, 'msg' => '', 'rows' => 0, 'filled' => 0, 'skipped' => 0 ];

		/* 檔名只允許這個目錄下的檔案，不接受路徑 */
		if ( '' === $file || basename( $file ) !== $file ) {
			$fail['msg'] = '檔名不合法';

			return $fail;
		}

		$done = (array) get_option( self::OPTION_DONE, [] );

		if ( ! $force && isset( $done[ $file ] ) ) {
			return [
				'ok'      => true,
				'msg'     => '已於 ' . $done[ $file ] . ' 執行過，略過',
				'rows'    => 0,
				'filled'  => 0,
				'skipped' => 0,
			];
		}

		$path = ANIME_SYNC_PRO_DIR . self::DATA_DIR . $file;

		if ( ! is_readable( $path ) ) {
			$fail['msg'] = '找不到資料檔：' . $file;

			return $fail;
		}

		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			$fail['msg'] = '資料檔開不起來：' . $file;

			return $fail;
		}

		global $wpdb;

		$tables = [
			'P' => $wpdb->prefix . 'anime_persons',
			'C' => $wpdb->prefix . 'anime_characters',
		];

		/*
		 * 欄位對照依表而異：人物表沒有 name_cn（它用 name_original），
		 * 角色表才有。列成兩份而不是查一次再過濾——查詢語句要用到欄位名，
		 * 對不存在的欄位下 SELECT 會直接是 SQL 錯誤。
		 */
		$fields = [
			'P' => [
				'ib' => 'infobox_json',
				'sm' => 'summary',
			],
			'C' => [
				'ib' => 'infobox_json',
				'sm' => 'summary',
				'nc' => 'name_cn',
			],
		];

		$stat = [ 'rows' => 0, 'filled' => 0, 'skipped' => 0 ];

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$rec = json_decode( $line, true );

			if ( ! is_array( $rec ) || empty( $rec['k'] ) || empty( $rec['id'] ) ) {
				continue;
			}

			$table = $tables[ $rec['k'] ] ?? '';

			if ( '' === $table ) {
				continue;
			}

			$stat['rows']++;

			$map = $fields[ $rec['k'] ];

			/*
			 * 一次把要判斷的欄位全查回來，不要一個欄位查一次。
			 *
			 * 而且「這筆實體在不在站上」與「欄位是不是空的」必須分開判斷：
			 * NULL 既可能代表查不到這一列，也可能代表欄位本來就是 NULL，
			 * 而後者正是要補的目標。用整列的有無來判斷存在性才不會混淆。
			 */
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT `' . implode( '`, `', $map ) . "` FROM {$table} WHERE bgm_id = %d",
					(int) $rec['id']
				),
				ARRAY_A
			);

			if ( ! $row ) {
				/* 站上沒有這筆實體，略過 */
				continue;
			}

			$set = [];

			foreach ( $map as $short => $column ) {
				if ( ! isset( $rec[ $short ] ) ) {
					continue;
				}

				/*
				 * 只填空欄位。這裡重新查而不是相信產檔當下的狀態——產檔到
				 * 執行之間可能隔了幾天，中間可能已被別的途徑補上，不該覆蓋。
				 * NULL 與空字串都算空。
				 */
				if ( '' !== trim( (string) $row[ $column ] ) ) {
					$stat['skipped']++;

					continue;
				}

				$value = $rec[ $short ];

				$set[ $column ] = is_array( $value )
					? wp_json_encode( $value, JSON_UNESCAPED_UNICODE )
					: (string) $value;
			}

			if ( empty( $set ) ) {
				continue;
			}

			$wpdb->update( $table, $set, [ 'bgm_id' => (int) $rec['id'] ] );

			$stat['filled'] += count( $set );
		}

		fclose( $handle );

		$done[ $file ] = current_time( 'mysql' );

		update_option( self::OPTION_DONE, $done, false );

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info(
				sprintf(
					'實體資料補齊完成：%s（讀 %d 筆，寫入 %d 個欄位，已有值略過 %d）',
					$file,
					$stat['rows'],
					$stat['filled'],
					$stat['skipped']
				)
			);
		}

		return [
			'ok'      => true,
			'msg'     => sprintf(
				'讀 %s 筆，寫入 %s 個欄位，已有值略過 %s',
				number_format_i18n( $stat['rows'] ),
				number_format_i18n( $stat['filled'] ),
				number_format_i18n( $stat['skipped'] )
			),
			'rows'    => $stat['rows'],
			'filled'  => $stat['filled'],
			'skipped' => $stat['skipped'],
		];
	}
}
