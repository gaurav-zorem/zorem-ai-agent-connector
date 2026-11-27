<?php
/**
 * The site's AI provider.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Rest;

use ZoremAgentBrain\V1\Ai\ConsentLedger;
use ZoremAgentBrain\V1\Ai\CredentialStore;
use ZoremAgentBrain\V1\Ai\ModelChoice;
use ZoremAgentBrain\V1\Ai\ModelGateway;
use ZoremAgentBrain\V1\Ai\ProviderResolver;
use ZoremAgentBrain\V1\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Routes for the site's AI provider. (The class keeps its name from when it
 * also served the review queue and AI drafts, removed in 1.3.0.)
 *
 * The provider routes never return a key: storing one answers with its
 * description (provider, last four characters), and reading the provider
 * answers with where the key comes from. Storing a key requires the
 * acknowledgement to be on file, and recording the acknowledgement requires
 * an explicit `acknowledge: true` — a request cannot agree on someone's behalf
 * by omission.
 */
final class ReviewController extends Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/provider', \WP_REST_Server::READABLE, array( $this, 'provider' ) );
		$this->route(
			'/provider/consent',
			\WP_REST_Server::CREATABLE,
			array( $this, 'acknowledge' ),
			array(
				'acknowledge' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);
		$this->route( '/provider/consent', \WP_REST_Server::DELETABLE, array( $this, 'withdraw' ) );
		$this->route(
			'/provider/key',
			\WP_REST_Server::CREATABLE,
			array( $this, 'store_key' ),
			array(
				'provider' => array(
					'type'     => 'string',
					'enum'     => ProviderResolver::known_providers(),
					'required' => true,
				),
				'key'      => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 512,
				),
			)
		);
		$this->route( '/provider/key', \WP_REST_Server::DELETABLE, array( $this, 'forget_key' ) );
		$this->route(
			'/provider/model',
			\WP_REST_Server::CREATABLE,
			array( $this, 'choose_model' ),
			array(
				'model' => array(
					'type'      => 'string',
					'required'  => true,
					'maxLength' => 120,
				),
			)
		);
		$this->route( '/provider/models', \WP_REST_Server::CREATABLE, array( $this, 'refresh_models' ) );
	}

	/**
	 * Choose the model for the current provider; '' for its default.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function choose_model( \WP_REST_Request $request ) {
		$provider = (string) Brain::get( ProviderResolver::class )->resolve()['provider'];
		$saved    = ModelChoice::save( $provider, (string) $request['model'] );

		return $this->respond(
			is_wp_error( $saved ) ? $saved : array(
				'provider' => $provider,
				'model'    => ModelChoice::for_provider( $provider ),
			)
		);
	}

	/**
	 * Ask the provider for its model list again.
	 *
	 * @return \WP_REST_Response
	 */
	public function refresh_models() {
		$provider = (string) Brain::get( ProviderResolver::class )->resolve()['provider'];

		// The stored key is handed to the AI client by a lease; without one the provider cannot be asked.
		Brain::get( ModelGateway::class )->lease( 'enrichment', ModelGateway::AUDIENCE_ADMIN );

		return rest_ensure_response( array( 'models' => ModelChoice::models( $provider, true ) ) );
	}

	/**
	 * Where the key comes from, what is stored, what was acknowledged, and whether AI can run.
	 *
	 * @return \WP_REST_Response
	 */
	public function provider() {
		$providers = array();

		foreach ( ProviderResolver::known_providers() as $id ) {
			$providers[] = array(
				'id'           => $id,
				'label'        => ProviderResolver::label( $id ),
				'constants'    => ProviderResolver::constants_for( $id ),
				'client_ready' => ProviderResolver::client_ready( $id ),
			);
		}

		return rest_ensure_response(
			array(
				'providers'           => $providers,
				'availability'        => Brain::get( ModelGateway::class )->availability( 'enrichment' ),
				'assistants'          => Brain::get( ModelGateway::class )->lease( 'storefront', ModelGateway::AUDIENCE_ADMIN )->state(),
				'consent'             => Brain::get( ConsentLedger::class )->describe(),
				'consent_version_now' => ConsentLedger::CURRENT_VERSION,
				'stored_key'          => Brain::get( CredentialStore::class )->describe(),
			)
		);
	}

	/**
	 * Record the administrator's acknowledgement of the current text.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function acknowledge( \WP_REST_Request $request ) {
		if ( true !== $request['acknowledge'] ) {
			return new \WP_Error( 'zorem_agent_brain_ai_consent_required', __( 'Tick the acknowledgement to record it.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		Brain::get( ConsentLedger::class )->record( get_current_user_id() );

		return $this->provider();
	}

	/**
	 * Withdraw the acknowledgement.
	 *
	 * @return \WP_REST_Response
	 */
	public function withdraw() {
		Brain::get( ConsentLedger::class )->revoke();

		return $this->provider();
	}

	/**
	 * Store the site's key. Answers with its description, never the key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function store_key( \WP_REST_Request $request ) {
		$stored = Brain::get( CredentialStore::class )->store( (string) $request['provider'], (string) $request['key'], get_current_user_id() );

		return $this->respond( is_wp_error( $stored ) ? $stored : Brain::get( CredentialStore::class )->describe() );
	}

	/**
	 * Delete the stored key.
	 *
	 * @return \WP_REST_Response
	 */
	public function forget_key() {
		Brain::get( CredentialStore::class )->forget();

		return rest_ensure_response( Brain::get( CredentialStore::class )->describe() );
	}
}
