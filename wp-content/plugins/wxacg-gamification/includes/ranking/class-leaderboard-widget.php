<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Leaderboard widget + shortcode（搬自 theme/inc/leaderboard-widget.php）
 *
 * Shortcode: [smacg_leaderboard type="exp_total" limit="10" title="..."]
 * Widget:    SMACG_Leaderboard_Widget
 */
class Leaderboard_Widget {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'smacg_leaderboard', [ __CLASS__, 'shortcode' ] );
        add_action( 'widgets_init', [ __CLASS__, 'register_widget' ] );
    }

    /* ==========================================================
     * Shortcode
     * ========================================================== */
    public static function shortcode( $atts ) {
        $a = shortcode_atts( [
            'type'  => 'exp_total',
            'limit' => 10,
            'title' => '',
            'show_more' => '1',
        ], $atts, 'smacg_leaderboard' );

        return self::render( $a['type'], (int) $a['limit'], $a['title'], $a['show_more'] === '1' );
    }

    /* ==========================================================
     * 共用 render
     * ========================================================== */
    public static function render( $type, $limit, $title = '', $show_more = true ) {
        if ( ! in_array( $type, WXACG_RANKING_TYPES, true ) ) return '';

        $data = Ranking_System::get( $type, 1, $limit );
        $items = $data['items'];

        if ( ! $title ) $title = self::default_title( $type );

        ob_start();
        ?>
        <div class="smacg-leaderboard-widget" data-type="<?php echo esc_attr( $type ); ?>">
            <h3 class="smacg-leaderboard-title">
                <i class="fa-solid <?php echo esc_attr( self::default_icon( $type ) ); ?>"></i>
                <?php echo esc_html( $title ); ?>
            </h3>
            <?php if ( empty( $items ) ) : ?>
                <p class="smacg-leaderboard-empty">尚無資料</p>
            <?php else : ?>
                <ol class="smacg-leaderboard-list">
                    <?php foreach ( $items as $it ) :
                        $medal = '';
                        if ( $it['rank'] === 1 ) $medal = '🥇';
                        elseif ( $it['rank'] === 2 ) $medal = '🥈';
                        elseif ( $it['rank'] === 3 ) $medal = '🥉';
                        ?>
                        <li class="smacg-leaderboard-item smacg-rank-<?php echo (int) $it['rank']; ?>">
                            <span class="smacg-rank-num"><?php echo $medal ?: '#' . (int) $it['rank']; ?></span>
                            <a href="<?php echo esc_url( $it['profile_url'] ); ?>" class="smacg-rank-user">
                                <img src="<?php echo esc_url( $it['avatar'] ); ?>" alt="" class="smacg-rank-avatar" loading="lazy">
                                <span class="smacg-rank-name"><?php echo esc_html( $it['display_name'] ); ?></span>
                                <span class="smacg-rank-level">Lv.<?php echo (int) $it['level']; ?></span>
                            </a>
                            <span class="smacg-rank-score"><?php echo number_format_i18n( $it['score'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if ( $show_more ) : ?>
                <a href="<?php echo esc_url( home_url( '/ranking-users/?type=' . rawurlencode( $type ) ) ); ?>" class="smacg-leaderboard-more">
                    查看完整排行 →
                </a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function default_title( $type ) {
        return [
            'exp_total'   => 'EXP 總榜',
            'exp_monthly' => '本月 EXP 榜',
            'followers'   => '人氣榜',
            'badges'      => '徽章榜',
        ][ $type ] ?? '排行榜';
    }

    public static function default_icon( $type ) {
        return [
            'exp_total'   => 'fa-crown',
            'exp_monthly' => 'fa-calendar-star',
            'followers'   => 'fa-users',
            'badges'      => 'fa-trophy',
        ][ $type ] ?? 'fa-ranking-star';
    }

    /* ==========================================================
     * Widget class（小工具）
     * ========================================================== */
    public static function register_widget() {
        register_widget( '\WXACG\Gamification\Leaderboard_WP_Widget' );
    }
}

/* ============================================================
 * WP_Widget 實作
 * ============================================================ */
class Leaderboard_WP_Widget extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'smacg_leaderboard',
            'SMACG 排行榜',
            [ 'description' => '顯示用戶排行榜（EXP/人氣/徽章）' ]
        );
    }

    public function widget( $args, $instance ) {
        $type  = $instance['type']  ?? 'exp_total';
        $limit = (int) ( $instance['limit'] ?? 10 );
        $title = $instance['title'] ?? '';

        echo $args['before_widget'];
        echo Leaderboard_Widget::render( $type, $limit, $title, true );
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $type  = $instance['type']  ?? 'exp_total';
        $limit = (int) ( $instance['limit'] ?? 10 );
        $title = $instance['title'] ?? '';
        ?>
        <p>
            <label>標題：<input class="widefat" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo esc_attr( $title ); ?>"></label>
        </p>
        <p>
            <label>類型：
                <select name="<?php echo $this->get_field_name( 'type' ); ?>" class="widefat">
                    <?php foreach ( WXACG_RANKING_TYPES as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>>
                            <?php echo esc_html( Leaderboard_Widget::default_title( $t ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>顯示筆數：<input type="number" min="1" max="50" name="<?php echo $this->get_field_name( 'limit' ); ?>" value="<?php echo (int) $limit; ?>"></label>
        </p>
        <?php
    }

    public function update( $new, $old ) {
        return [
            'title' => sanitize_text_field( $new['title'] ?? '' ),
            'type'  => in_array( $new['type'] ?? '', WXACG_RANKING_TYPES, true ) ? $new['type'] : 'exp_total',
            'limit' => max( 1, min( 50, (int) ( $new['limit'] ?? 10 ) ) ),
        ];
    }
}
