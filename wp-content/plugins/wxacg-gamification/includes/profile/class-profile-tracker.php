<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Profile Tracker — 個人資料完善 EXP
 *
 * 監聽：
 *   - smacg_avatar_uploaded   → on_avatar
 *   - smacg_bio_updated       → on_bio
 *   - smacg_birthday_updated  → on_birthday
 *
 * 上述三個 action 由 member-ajax.php / 未來新增的 UI 觸發；
 * 本類別轉發到 smacg_profile_avatar / smacg_profile_bio / smacg_profile_birthday，
 * 進而由 Exp_Events::on_profile_* 接手發 EXP（含 maybe_award_profile_complete）。
 *
 * 注意：bio 必須 ≥10 字才觸發 profile_bio
 *
 * @since 2.7.0
 * @version 2.7.1 (2026-05-22)
 *   - Fix BugF：on_birthday() 原 regex 僅檢查格式 (\d{4}-)?\d{2}-\d{2}，
 *     會放行 02-30 / 13-45 / 2026-02-31 等無效日期，
 *     使 Exp_Events::on_profile_birthday() 被觸發但後續 Anniversary_Cron
 *     永遠無法找到匹配日期，造成「設定生日得 EXP 但生日當天沒獎勵」的不一致。
 *     現於 regex 通過後再用 checkdate() 驗證日期合法性。
 *     若使用者只填 MM-DD（無年份），以閏年（取 2000）做為驗證年份避免誤判 02-29。
 */
class Profile_Tracker {

    private static $instance = null;
    const MIN_BIO_LEN = 10;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'smacg_avatar_uploaded',  [ __CLASS__, 'on_avatar' ],   10, 1 );
        add_action( 'smacg_bio_updated',      [ __CLASS__, 'on_bio' ],      10, 2 );
        add_action( 'smacg_birthday_updated', [ __CLASS__, 'on_birthday' ], 10, 2 );
    }

    /**
     * 頭像上傳成功
     *
     * @param int $uid
     */
    public static function on_avatar( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return;
        do_action( 'smacg_profile_avatar', $uid );
    }

    /**
     * 自我介紹更新（≥10 字才獎勵）
     *
     * @param int    $uid
     * @param string $bio
     */
    public static function on_bio( $uid, $bio ) {
        $uid = (int) $uid;
        $bio = trim( (string) $bio );
        if ( $uid <= 0 ) return;
        if ( mb_strlen( $bio ) < self::MIN_BIO_LEN ) return;

        do_action( 'smacg_profile_bio', $uid );
    }

    /**
     * 生日設定
     *
     * @param int    $uid
     * @param string $birthday  'YYYY-MM-DD' 或 'MM-DD'
     */
    public static function on_birthday( $uid, $birthday ) {
        $uid      = (int) $uid;
        $birthday = trim( (string) $birthday );
        if ( $uid <= 0 ) return;

        // ★ v2.7.1 BugF：兩階段驗證
        //   1) 格式驗證（regex）
        //   2) 日期合法性（checkdate）
        if ( ! self::is_valid_birthday( $birthday ) ) return;

        do_action( 'smacg_profile_birthday', $uid );
    }

    /**
     * 驗證生日字串：格式 + 日期合法性
     *
     * 接受：
     *   YYYY-MM-DD（如 1990-02-29 須在閏年）
     *   MM-DD     （以 2000 為基準年驗證，可容納 02-29）
     *
     * 拒絕：
     *   02-30 / 13-01 / 1990-02-31 / 非數字 / 空字串
     *
     * @param string $birthday
     * @return bool
     */
    public static function is_valid_birthday( $birthday ) {
        $birthday = trim( (string) $birthday );
        if ( $birthday === '' ) return false;

        // 格式檢查
        if ( ! preg_match( '/^(?:(\d{4})-)?(\d{2})-(\d{2})$/', $birthday, $m ) ) {
            return false;
        }

        $year  = isset( $m[1] ) && $m[1] !== '' ? (int) $m[1] : 2000; // 2000 是閏年，可容納 02-29
        $month = (int) $m[2];
        $day   = (int) $m[3];

        // 合理年份範圍（避免 1900 前或未來日期）
        if ( $year < 1900 || $year > (int) current_time( 'Y' ) ) {
            return false;
        }

        // ★ 核心：PHP 內建日期合法性驗證
        return checkdate( $month, $day, $year );
    }
}
