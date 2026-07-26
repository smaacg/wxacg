<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_Plugin {

	private static ?self $instance = null;

	private bool $initialized = false;

	private array $modules = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		$cache    = new Wxacg_Catalog_Api_Cache();
		$schema   = new Wxacg_Catalog_Api_Schema();
		$query    = new Wxacg_Catalog_Api_Catalog_Query();
		$language = new Wxacg_Catalog_Api_Language();
		$response = new Wxacg_Catalog_Api_Response();

		$rest = new Wxacg_Catalog_Api_Rest_Controller(
			$query,
			$schema,
			$cache
		);

		$compatibility = new Wxacg_Catalog_Api_Compatibility(
			$query,
			$schema
		);

		$v2_repository = new Wxacg_Catalog_Api_V2_Work_Repository(
			$query,
			$schema
		);

		$v2_serializer = new Wxacg_Catalog_Api_V2_Anime_Serializer(
			$language
		);

		$v2_anime = new Wxacg_Catalog_Api_V2_Anime_Controller(
			$v2_repository,
			$v2_serializer,
			$response,
			$language
		);

		$v2_search = new Wxacg_Catalog_Api_V2_Search_Controller(
			$v2_repository,
			$v2_serializer,
			$response,
			$language
		);

		$v2_router = new Wxacg_Catalog_Api_V2_Router(
			$v2_anime,
			$v2_search,
			$language
		);

		$this->modules = [
			'cache'            => $cache,
			'schema'           => $schema,
			'query'            => $query,
			'rest'             => $rest,
			'compatibility'    => $compatibility,
			'language'         => $language,
			'response'         => $response,
			'v2_repository'    => $v2_repository,
			'v2_serializer'    => $v2_serializer,
			'v2_anime'         => $v2_anime,
			'v2_search'        => $v2_search,
			'v2_router'        => $v2_router,
		];

		$cache->register_hooks();
		$rest->register_hooks();
		$compatibility->register_hooks();
		$v2_router->register_hooks();
	}

	public function get_module( string $key ): ?object {
		return $this->modules[ $key ] ?? null;
	}
}
