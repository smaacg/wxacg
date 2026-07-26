<?php
/**
 * 外部連結處理：自動加 target=_blank 與 rel="noopener noreferrer"
 *
 * 行為與 blocksy-child v2.7.3 inc/external-links.php 完全一致。
 *
 * @package WxacgApi
 */

defined( 'ABSPATH' ) || exit;

class Wxacg_Api_External_Links {

    /**
     * @var string|null  本站 host（lazy init）
     */
    private $site_host = null;

    public function register_hooks(): void {
        add_filter( 'the_content', [ $this, 'filter_the_content' ] );
    }

    public function filter_the_content( $content ) {
        if ( is_admin() || $content === '' || $content === null ) {
            return $content;
        }

        if ( $this->site_host === null ) {
            $this->site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        return preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/i',
            [ $this, 'replace_anchor' ],
            $content
        );
    }

    private function replace_anchor( array $m ): string {
        $before = $m[1];
        $url    = $m[2];
        $after  = $m[3];

        // 錨點 / mailto / tel / javascript: 不處理
        if ( strpos( $url, '#' ) === 0 ) return $m[0];
        if ( preg_match( '/^(mailto:|tel:|javascript:)/i', $url ) ) return $m[0];

        $link_host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $link_host || $link_host === $this->site_host ) {
            return $m[0];
        }

        // 外部連結
        $attrs = $before . $after;

        if ( ! preg_match( '/target=/i', $attrs ) ) {
            $attrs .= ' target="_blank"';
        }

        if ( preg_match( '/rel=["\']([^"\']*)["\']/i', $attrs, $rel_m ) ) {
            if ( strpos( $rel_m[1], 'noopener' ) === false ) {
                $attrs = str_replace(
                    $rel_m[0],
                    'rel="' . $rel_m[1] . ' noopener"',
                    $attrs
                );
            }
        } else {
            $attrs .= ' rel="noopener noreferrer"';
        }

        return '<a ' . trim( $attrs ) . ' href="' . esc_url( $url ) . '">';
    }
}
