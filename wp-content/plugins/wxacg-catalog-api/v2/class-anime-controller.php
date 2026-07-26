<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_V2_Anime_Controller {

	public function __construct(
		private Wxacg_Catalog_Api_V2_Work_Repository $repository,
		private Wxacg_Catalog_Api_V2_Anime_Serializer $serializer,
		private Wxacg_Catalog_Api_Response $response,
		private Wxacg_Catalog_Api_Language $language
	) {}

	public function collection(
		WP_REST_Request $request
	): WP_REST_Response {
		$lang   = $this->language->resolve( $request->get_param( 'lang' ) );
		$params = $request->get_params();
		$result = $this->repository->collection( $params );
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

	public function item( WP_REST_Request $request ): WP_REST_Response {
		$lang = $this->language->resolve( $request->get_param( 'lang' ) );
		$id   = absint( $request->get_param( 'id' ) );
		$item = $this->repository->find( $id );

		if ( null === $item ) {
			return $this->response->error(
				'not_found',
				'找不到已發布的動畫。',
				404,
				$lang
			);
		}

		return $this->response->success(
			$this->serializer->detail( $item, $lang ),
			$lang
		);
	}
}
