<?php
/**
 * Class Anime_Sync_CN_Converter
 *
 * 簡繁轉換類別。
 * 流程：OpenCC（S2TWP）→ 自訂站內字典覆寫。
 * 若 OpenCC 不可用，則 fallback 至靜態字典。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_CN_Converter {

    private static ?bool  $opencc_available = null;
    private static ?array $dict_cache       = null;

    // =========================================================================
    // 公開介面
    // =========================================================================

    public static function static_convert( string $text ): string {
        if ( $text === '' ) {
            return '';
        }

        return self::convert_document( $text );
    }

    public function convert( string $text ): string {
        return self::static_convert( $text );
    }

    /**
     * 遞迴轉換字串 / 陣列 / 物件。
     * 保留 array key，不直接動 key 名稱。
     *
     * @param mixed $value
     * @return mixed
     */
    public function convert_mixed( $value ) {
        return self::convert_mixed_value( $value );
    }

    /**
     * 若是 JSON 字串則只轉換值，不動 key；否則當一般字串處理。
     */
    public function convert_json_string( string $json ): string {
        return self::convert_possible_json_string( $json );
    }

    public function get_stats(): array {
        $dict_file = self::get_dict_path();
        $dict      = self::load_dict();

        return [
            'dict_path'          => $dict_file,
            'dict_file_exists'   => file_exists( $dict_file ),
            'dict_file_size'     => file_exists( $dict_file ) ? (int) filesize( $dict_file ) : 0,
            'dict_entry_count'   => count( $dict ),
            'opencc_available'   => self::is_opencc_available(),
            'opencc_vendor_path' => self::get_vendor_path(),
            'mode'               => self::is_opencc_available() ? 'opencc_then_dict' : 'dict_only',
        ];
    }

    // =========================================================================
    // 路徑輔助
    // =========================================================================

    private static function get_plugin_root(): string {
        return dirname( __FILE__, 2 ) . DIRECTORY_SEPARATOR;
    }

    private static function get_autoload_path(): string {
        return self::get_plugin_root() . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    }

    private static function get_vendor_path(): string {
        return self::get_plugin_root() . 'vendor' . DIRECTORY_SEPARATOR . 'overtrue' . DIRECTORY_SEPARATOR . 'php-opencc';
    }

    private static function get_dict_path(): string {
        return self::get_plugin_root() . 'data' . DIRECTORY_SEPARATOR . 'cn-tw-dict.json';
    }

    // =========================================================================
    // OpenCC / 字典主流程
    // =========================================================================

    private static function convert_document( string $text ): string {
        if ( $text === '' ) {
            return '';
        }

        if ( ! self::contains_cjk( $text ) ) {
            return $text;
        }

        $tokens = [];
        $text   = self::protect_segments( $text, $tokens );

        if ( self::looks_like_json( $text ) ) {
            $text = self::convert_possible_json_string( $text );
        } elseif ( self::looks_like_html( $text ) ) {
            $text = self::convert_html_text_nodes( $text );
        } else {
            $text = self::convert_plain_text( $text );
        }

        return self::restore_segments( $text, $tokens );
    }

    private static function convert_plain_text( string $text ): string {
        $converted = $text;

        if ( self::is_opencc_available() ) {
            $converted = self::convert_with_opencc( $converted );
        }

        return self::convert_with_dict( $converted );
    }

    private static function convert_with_opencc( string $text ): string {
        try {
            return \Overtrue\PHPOpenCC\OpenCC::convert( $text, 'S2TWP' );
        } catch ( \Throwable $e ) {
            return $text;
        }
    }

    private static function is_opencc_available(): bool {
        if ( self::$opencc_available !== null ) {
            return self::$opencc_available;
        }

        $autoload = self::get_autoload_path();

        if ( ! file_exists( $autoload ) ) {
            self::$opencc_available = false;
            return false;
        }

        if ( ! class_exists( 'Overtrue\PHPOpenCC\OpenCC', false ) ) {
            require_once $autoload;
        }

        // ★ 修正：false → true，允許 autoload 觸發類別偵測
        self::$opencc_available = class_exists( 'Overtrue\PHPOpenCC\OpenCC', true );
        return self::$opencc_available;
    }

    // =========================================================================
    // HTML / JSON / Mixed 處理
    // =========================================================================

    private static function convert_html_text_nodes( string $html ): string {
        $parts = preg_split( '/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return self::convert_plain_text( $html );
        }

        foreach ( $parts as $index => $part ) {
            if ( $part === '' ) {
                continue;
            }

            if ( preg_match( '/^<[^>]+>$/u', $part ) ) {
                continue;
            }

            $parts[ $index ] = self::convert_plain_text( $part );
        }

        return implode( '', $parts );
    }

    private static function convert_possible_json_string( string $json ): string {
        $trimmed = trim( $json );
        if ( $trimmed === '' ) {
            return $json;
        }

        $decoded = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return self::looks_like_html( $json )
                ? self::convert_html_text_nodes( $json )
                : self::convert_plain_text( $json );
        }

        $converted = self::convert_mixed_value( $decoded );

        $encoded = wp_json_encode( $converted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $encoded ) ? $encoded : $json;
    }

    private static function convert_mixed_value( $value ) {
        if ( is_string( $value ) ) {
            return self::convert_document( $value );
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::convert_mixed_value( $item );
            }
            return $value;
        }

        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value->{$key} = self::convert_mixed_value( $item );
            }
            return $value;
        }

        return $value;
    }

    // =========================================================================
    // 保護片段（避免破壞 URL / shortcode / code）
    // =========================================================================

    private static function protect_segments( string $text, array &$tokens ): string {
        $patterns = [
            '/https?:\/\/[^\s"\'<>]+/u',
            '/\[[^\]]+\]/u',
            '/<code\b[^>]*>.*?<\/code>/uis',
            '/<pre\b[^>]*>.*?<\/pre>/uis',
        ];

        foreach ( $patterns as $pattern ) {
            $text = preg_replace_callback(
                $pattern,
                static function ( array $matches ) use ( &$tokens ): string {
                    $token            = '__ASCNPROTECT_' . count( $tokens ) . '__';
                    $tokens[ $token ] = $matches[0];
                    return $token;
                },
                $text
            );
        }

        return $text;
    }

    private static function restore_segments( string $text, array $tokens ): string {
        if ( empty( $tokens ) ) {
            return $text;
        }

        return strtr( $text, $tokens );
    }

    // =========================================================================
    // 靜態字典
    // =========================================================================

    private static function load_dict(): array {
        if ( self::$dict_cache !== null ) {
            return self::$dict_cache;
        }

        $dict_file = self::get_dict_path();

        if ( ! file_exists( $dict_file ) ) {
            self::$dict_cache = [];
            return [];
        }

        $json = file_get_contents( $dict_file );
        if ( $json === false ) {
            self::$dict_cache = [];
            return [];
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            self::$dict_cache = [];
            return [];
        }

        $dict = [];
        foreach ( $decoded as $from => $to ) {
            if ( is_string( $from ) && is_string( $to ) && $from !== '' ) {
                $dict[ $from ] = $to;
            }
        }

        uksort(
            $dict,
            static function ( string $a, string $b ): int {
                return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
            }
        );

        self::$dict_cache = $dict;
        return self::$dict_cache;
    }

    private static function convert_with_dict( string $text ): string {
        $dict = self::load_dict();
        if ( $text === '' || empty( $dict ) ) {
            return $text;
        }

        return str_replace( array_keys( $dict ), array_values( $dict ), $text );
    }

    // =========================================================================
    // 判斷輔助
    // =========================================================================

    private static function looks_like_json( string $text ): bool {
        $trimmed = trim( $text );
        if ( $trimmed === '' ) {
            return false;
        }

        $first = substr( $trimmed, 0, 1 );
        $last  = substr( $trimmed, -1 );

        return ( $first === '{' && $last === '}' ) || ( $first === '[' && $last === ']' );
    }

    private static function looks_like_html( string $text ): bool {
        return (bool) preg_match( '/<[^>]+>/u', $text );
    }

    private static function contains_cjk( string $text ): bool {
        return (bool) preg_match( '/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text );
    }
}
