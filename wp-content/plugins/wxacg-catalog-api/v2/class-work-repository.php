<?php

defined( 'ABSPATH' ) || exit;

final class Wxacg_Catalog_Api_V2_Work_Repository {

	public function __construct(
		private Wxacg_Catalog_Api_Catalog_Query $query,
		private Wxacg_Catalog_Api_Schema $schema
	) {}

	public function collection( array $params ): array {
		$params['type'] = 'anime';

		unset( $params['lang'], $params['q'] );

		$query = $this->query->query( $params );
		$items = [];

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->schema->summary( $post );
			}
		}

		return [
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		];
	}

	public function search( string $term, array $params ): array {
		$params['type']   = 'anime';
		$params['search'] = mb_substr( trim( $term ), 0, 100 );

		unset( $params['lang'], $params['q'] );

		return $this->collection( $params );
	}

	public function find( int $id ): ?array {
		$post = $this->query->find_published( $id );

		if ( ! $post instanceof WP_Post || 'anime' !== $post->post_type ) {
			return null;
		}

		return $this->schema->detail( $post );
	}
}
