<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Language {

	public const DEFAULT_LANGUAGE = 'zh-TW';

	public const SUPPORTED = [
		'zh-TW',
		'zh-HK',
		'zh-CN',
		'ja-JP',
		'ja-Latn',
		'en',
	];

	public function normalize( $language ): ?string {
		$language = trim( str_replace( '_', '-', (string) $language ) );

		$aliases = [
			'zh-tw'   => 'zh-TW',
			'zh-hant' => 'zh-TW',
			'zh-hk'   => 'zh-HK',
			'zh-cn'   => 'zh-CN',
			'zh-hans' => 'zh-CN',
			'ja'      => 'ja-JP',
			'ja-jp'   => 'ja-JP',
			'ja-latn' => 'ja-Latn',
			'en'      => 'en',
			'en-us'   => 'en',
			'en-gb'   => 'en',
		];

		$key = strtolower( $language );

		return $aliases[ $key ] ?? null;
	}

	public function is_supported( $language ): bool {
		return null !== $this->normalize( $language );
	}

	public function resolve( $language ): string {
		return $this->normalize( $language ) ?? self::DEFAULT_LANGUAGE;
	}

	public function title( array $titles, string $requested ): array {
		$requested = $this->resolve( $requested );

		$official = [
			'zh-TW'   => $this->string_or_null(
				$titles['chinese'] ?? $titles['display'] ?? null
			),
			/*
			 * 現有資料尚未區分香港及中國官方譯名。
			 * 不得直接冒充官方譯名，因此暫時輸出 null。
			 */
			'zh-HK'   => null,
			'zh-CN'   => null,
			'ja-JP'   => $this->string_or_null( $titles['native'] ?? null ),
			'ja-Latn' => $this->string_or_null( $titles['romaji'] ?? null ),
			'en'      => $this->string_or_null( $titles['english'] ?? null ),
		];

		$fallbacks = [
			'zh-TW'   => [ 'zh-TW', 'ja-JP', 'ja-Latn', 'en' ],
			'zh-HK'   => [ 'zh-HK', 'zh-TW', 'ja-JP', 'en' ],
			'zh-CN'   => [ 'zh-CN', 'zh-TW', 'ja-JP', 'en' ],
			'ja-JP'   => [ 'ja-JP', 'ja-Latn', 'en', 'zh-TW' ],
			'ja-Latn' => [ 'ja-Latn', 'ja-JP', 'en', 'zh-TW' ],
			'en'      => [ 'en', 'ja-Latn', 'ja-JP', 'zh-TW' ],
		];

		$display         = '';
		$resolved_locale = $requested;

		foreach ( $fallbacks[ $requested ] as $locale ) {
			if ( ! empty( $official[ $locale ] ) ) {
				$display         = $official[ $locale ];
				$resolved_locale = $locale;
				break;
			}
		}

		if ( '' === $display ) {
			$display = $this->string_or_null(
				$titles['display'] ?? null
			) ?? '';
		}

		$aliases = [];

		foreach ( $titles as $title ) {
			$title = $this->string_or_null( $title );

			if ( null !== $title && $title !== $display ) {
				$aliases[] = $title;
			}
		}

		return [
			'display'          => $display,
			'locale'           => $resolved_locale,
			'requested_locale' => $requested,
			'official'         => $official,
			'aliases'          => array_values( array_unique( $aliases ) ),
		];
	}

	private function string_or_null( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}