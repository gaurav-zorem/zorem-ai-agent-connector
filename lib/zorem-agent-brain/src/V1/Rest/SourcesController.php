<?php
/**
 * Configuring and running the Brain's sources.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Rest;

use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Curated\CuratedSync;
use ZoremAgentBrain\V1\Ingestion\ExtractionQueue;
use ZoremAgentBrain\V1\Ingestion\FullRebuild;
use ZoremAgentBrain\V1\Ingestion\IngestionOrchestrator;
use ZoremAgentBrain\V1\Settings;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Sources\WpContentSource;

defined( 'ABSPATH' ) || exit;

/**
 * Routes for each source: what is configured, change it, run it now.
 *
 * Running a source here is the same work the extraction tick does, done on
 * request.
 */
final class SourcesController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/sources', \WP_REST_Server::READABLE, array( $this, 'sources' ) );
		$this->route( '/sources/pages/candidates', \WP_REST_Server::READABLE, array( $this, 'page_candidates' ), array( 'search' => self::optional_string( 100 ) ) );
		$this->route(
			'/sources/pages',
			\WP_REST_Server::CREATABLE,
			array( $this, 'select_pages' ),
			array(
				'post_ids'  => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'maxItems' => 500,
					'default'  => array(),
				),
				'all_posts' => array(
					'type' => 'boolean',
				),
			)
		);
		$this->route(
			'/sources/curated/local',
			\WP_REST_Server::CREATABLE,
			array( $this, 'curated_local' ),
			array(
				'plugin'    => self::required_string( 191 ),
				'use_local' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);
		$this->route( '/sources/(?P<id>[a-z0-9:_-]{1,191})/run', \WP_REST_Server::CREATABLE, array( $this, 'run' ) );
		$this->route( '/sources/rebuild', \WP_REST_Server::CREATABLE, array( $this, 'rebuild' ) );
		$this->route(
			'/sources/woocommerce/policy',
			\WP_REST_Server::EDITABLE,
			array( $this, 'set_policy' ),
			array(
				'purpose' => array(
					'type'     => 'string',
					'enum'     => array_keys( WooCommerceSource::policy_options() ),
					'required' => true,
				),
				'page_id' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
			)
		);
	}

	/**
	 * Read everything again.
	 *
	 * @return \WP_REST_Response
	 */
	public function rebuild() {
		return rest_ensure_response( Brain::get( FullRebuild::class )->run() );
	}

	/**
	 * Choose the page for one store policy, in WooCommerce's settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_policy( \WP_REST_Request $request ) {
		return $this->respond( Brain::get( WooCommerceSource::class )->set_policy_page( (string) $request['purpose'], (int) $request['page_id'] ) );
	}

	/**
	 * Every source's configuration and state.
	 *
	 * @return \WP_REST_Response
	 */
	public function sources() {
		$pages = Brain::get( WpContentSource::class );
		$store = Brain::get( WooCommerceSource::class );

		return rest_ensure_response(
			array(
				'pages'       => array(
					'selected'  => array_map( array( self::class, 'page' ), $pages->selected() ),
					'all_posts' => $pages->all_posts(),
					'published' => $pages->published_post_count(),
				),
				'woocommerce' => array(
					'available' => $store->is_available(),
					'pages'     => $store->policy_page_status(),
				),
				'extraction'  => array(
					'enabled' => Settings::bool( 'knowledge_enabled' ),
					'queue'   => Brain::get( ExtractionQueue::class )->summary(),
				),
				'curated'     => array(
					'enabled' => Settings::bool( 'knowledge_curated_kb' ),
					'plugins' => Brain::get( CuratedSync::class )->covered_plugins(),
				),
			)
		);
	}

	/**
	 * Published pages and posts that could be selected.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function page_candidates( \WP_REST_Request $request ) {
		$query = new \WP_Query(
			array(
				'post_type'      => WpContentSource::selectable_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				's'              => (string) $request['search'],
				'orderby'        => 'title',
				'order'          => 'ASC',
				'has_password'   => false,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return rest_ensure_response( array( 'items' => array_map( array( self::class, 'page' ), array_map( 'intval', $query->posts ) ) ) );
	}

	/**
	 * Replace the selected pages, or turn "every post" on or off.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function select_pages( \WP_REST_Request $request ) {
		$pages = Brain::get( WpContentSource::class );

		if ( null !== $request['all_posts'] ) {
			return rest_ensure_response( $pages->set_all_posts( (bool) $request['all_posts'] ) );
		}

		return rest_ensure_response( $pages->select( array_map( 'absint', (array) $request['post_ids'] ) ) );
	}

	/**
	 * Keep one covered plugin on this site's own knowledge, or give it back to the catalogue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function curated_local( \WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request['plugin'] );

		if ( ! in_array( $slug, wp_list_pluck( Brain::get( CuratedSync::class )->covered_plugins(), 'slug' ), true ) ) {
			return new \WP_Error( 'zorem_agent_brain_not_covered', __( 'The bundled documentation does not cover that plugin.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( Brain::get( CuratedSync::class )->set_local( $slug, (bool) $request['use_local'] ) );
	}

	/**
	 * Run a source now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$id = (string) $request['id'];

		switch ( $id ) {
			case 'woocommerce':
				return rest_ensure_response( Brain::get( WooCommerceSource::class )->sync() );

			case 'curated':
				return rest_ensure_response( Brain::get( CuratedSync::class )->sync() );

			case 'extraction':
				$orchestrator = Brain::get( IngestionOrchestrator::class );
				$queued       = $orchestrator->refresh_queue();

				return rest_ensure_response(
					array(
						'queue' => $queued,
						'tick'  => $orchestrator->run_tick( 'manual' ),
					)
				);
		}

		return new \WP_Error( 'zorem_agent_brain_not_found', __( 'No such source.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
	}

	/**
	 * A page as listed.
	 *
	 * @param int $post_id Post id.
	 * @return array{id: int, title: string, type: string, url: string}
	 */
	private static function page( int $post_id ): array {
		return array(
			'id'    => $post_id,
			'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'type'  => (string) get_post_type( $post_id ),
			'url'   => (string) get_permalink( $post_id ),
		);
	}
}
