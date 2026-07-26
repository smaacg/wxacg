<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_V2_Search_Controller {

	public function __construct(
		private Wxacg_Catalog_Api_V2_Work_Repository $repository,
		private Wxacg_Catalog_Api_V2_Anime_Serializer $serializer,
		private Wxacg_Catalog_Api_Response $response,
		private Wxacg_Catalog_Api_Language $language
	) {}

	public function search(
		WP_REST_Request $request
	): WP_REST_Response {
		$lang   = $this->language->resolve( $request->get_param( 'lang' ) );
		$query  = trim( (string) $request->get_param( 'q' ) );
		$params = $request->get_params();
		$result = $this->repository->search( $query, $params );
		$data   = [];

		foreach ( $result['items'] as $item ) {
			$data[] = $this->serializer->summary( $item, $lang );
		}

		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min(
			50,
			max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) )
		);

		$pagination = [
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $result['total'],
			'total_pages' => $result['total_pages'],
		];

		return $this->response->success(
			array_values( $data ),
			$lang,
			$pagination,
			[
				'X-WP-Total'      => $result['total'],
				'X-WP-TotalPages' => $result['total_pages'],
			]
		);
	}
}
