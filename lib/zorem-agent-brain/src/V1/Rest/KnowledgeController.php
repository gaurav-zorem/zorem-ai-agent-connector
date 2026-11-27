<?php
/**
 * Entries, coverage, settings and scope.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Rest;

use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Ingestion\ExtractionQueue;
use ZoremAgentBrain\V1\Scope\ScopeRepository;
use ZoremAgentBrain\V1\Settings;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBridge\V1\Security\TextRedactor;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's view of the store.
 *
 * These routes see every entry, drafts included — they are how a merchant
 * inspects and corrects the Brain, not a way for a reader to read it.
 * Content is still shown with secrets redacted: extraction reads settings,
 * and a key that ended up in an entry must not be put on screen.
 */
final class KnowledgeController extends Controller {

	/** Settings this API may change. */
	private const SETTINGS = array( 'knowledge_enabled', 'knowledge_curated_kb', 'delete_data_on_uninstall' );

	/** Longest content returned for one entry. */
	private const MAX_CONTENT = 20000;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/status', \WP_REST_Server::READABLE, array( $this, 'status' ) );
		$this->route(
			'/entries',
			\WP_REST_Server::READABLE,
			array( $this, 'entries' ),
			array(
				'source'   => self::optional_string( 24 ),
				'section'  => self::optional_string( 32 ),
				'plugin'   => self::optional_string( 100 ),
				'trust'    => array(
					'type'    => 'string',
					'enum'    => array( '', KnowledgeEntry::TRUST_APPROVED, KnowledgeEntry::TRUST_DRAFT ),
					'default' => '',
				),
				'search'   => self::optional_string( 200 ),
				'inactive' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Also list entries of plugins that are switched off. Off by default, so the list shows what the assistants can use.',
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			)
		);
		$this->route( '/entries/(?P<id>\d+)', \WP_REST_Server::READABLE, array( $this, 'entry' ) );
		$this->route( '/coverage', \WP_REST_Server::READABLE, array( $this, 'coverage' ) );
		$this->route( '/settings', \WP_REST_Server::READABLE, array( $this, 'settings' ) );
		$this->route( '/settings', \WP_REST_Server::EDITABLE, array( $this, 'update_settings' ) );

		$scope_args = array(
			'type'    => array(
				'type'     => 'string',
				'enum'     => ScopeRepository::scope_types(),
				'required' => true,
			),
			'value'   => self::required_string( 191 ),
			'granted' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
		$reader     = '/scope/(?P<reader>[a-z]+:[A-Za-z0-9._-]{1,90})';

		$this->route( $reader, \WP_REST_Server::READABLE, array( $this, 'scope' ) );
		$this->route( $reader, \WP_REST_Server::CREATABLE, array( $this, 'set_scope' ), $scope_args );
		$this->route( $reader, \WP_REST_Server::DELETABLE, array( $this, 'remove_scope' ), array_intersect_key( $scope_args, array_flip( array( 'type', 'value' ) ) ) );
	}

	/**
	 * Store health and headline counts.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$coverage = Brain::repository()->coverage();
		$totals   = array(
			'entries' => 0,
			'drafts'  => 0,
			'tokens'  => 0,
		);
		$sources  = array();

		foreach ( $coverage as $row ) {
			$totals['entries']        += $row['entries'];
			$totals['tokens']         += $row['tokens'];
			$sources[ $row['source'] ] = ( $sources[ $row['source'] ] ?? 0 ) + $row['entries'];

			if ( KnowledgeEntry::TRUST_DRAFT === $row['trust'] ) {
				$totals['drafts'] += $row['entries'];
			}
		}

		return rest_ensure_response(
			array(
				'kit'        => Brain::version(),
				'store'      => Brain::store_status(),
				'hosts'      => array_keys( Brain::hosts()->all() ),
				'totals'     => $totals,
				'by_source'  => $sources,
				'extraction' => Brain::get( ExtractionQueue::class )->summary(),
			)
		);
	}

	/**
	 * A page of entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function entries( \WP_REST_Request $request ) {
		/*
		 * Switched-off plugins' entries are left out unless asked for. Listed by
		 * default, they put the names of plugins the merchant turned off — or a
		 * sibling plugin they never use — among what the assistants actually
		 * know, which reads as something they did not install.
		 */
		$args = array(
			'per_page'           => (int) $request['per_page'],
			'page'               => (int) $request['page'],
			'include_stale'      => rest_sanitize_boolean( $request['inactive'] ),
			'include_superseded' => true,
		);

		foreach ( array(
			'source'  => 'source_type',
			'section' => 'section',
			'plugin'  => 'plugin_slug',
			'trust'   => 'trust_status',
		) as $param => $arg ) {
			if ( '' !== (string) $request[ $param ] ) {
				$args[ $arg ] = (string) $request[ $param ];
			}
		}

		$search = trim( (string) $request['search'] );
		$result = '' === $search
			? Brain::repository()->query( $args )
			: Brain::repository()->search( new \ZoremAgentBrain\V1\Store\SearchQuery( $search ), $args );

		return rest_ensure_response(
			array(
				'items' => array_map( array( self::class, 'summary' ), $result['items'] ),
				'total' => (int) $result['total'],
			)
		);
	}

	/**
	 * One entry, content included (secrets redacted).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function entry( \WP_REST_Request $request ) {
		$entry = Brain::repository()->find_by_id( (int) $request['id'] );

		if ( ! $entry instanceof KnowledgeEntry ) {
			return new \WP_Error( 'zorem_agent_brain_not_found', __( 'No such entry.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array_merge(
				self::summary( $entry ),
				array(
					'content'    => TextRedactor::secrets( mb_substr( $entry->get_content(), 0, self::MAX_CONTENT ) ),
					'truncated'  => mb_strlen( $entry->get_content() ) > self::MAX_CONTENT,
					'facts'      => $entry->get_facts(),
					'provenance' => (array) $entry->meta( 'provenance', array() ),
				)
			)
		);
	}

	/**
	 * Counts by plugin, section, source and trust.
	 *
	 * @return \WP_REST_Response
	 */
	public function coverage() {
		return rest_ensure_response( array( 'rows' => Brain::repository()->coverage() ) );
	}

	/**
	 * The Brain's settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function settings() {
		return rest_ensure_response( array_intersect_key( Settings::all(), array_flip( self::SETTINGS ) ) );
	}

	/**
	 * Change settings. Unknown keys are ignored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$changes = array_intersect_key( (array) $request->get_json_params() + (array) $request->get_body_params(), array_flip( self::SETTINGS ) );

		Settings::update( $changes );

		return $this->settings();
	}

	/**
	 * A reader's explicit rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function scope( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'rules' => Brain::scopes()->for_reader( (string) $request['reader'] ) ) );
	}

	/**
	 * Set one rule for a reader.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_scope( \WP_REST_Request $request ) {
		$ok = Brain::scopes()->set( (string) $request['reader'], (string) $request['type'], (string) $request['value'], (bool) $request['granted'], get_current_user_id() );

		return $ok ? $this->scope( $request ) : new \WP_Error( 'zorem_agent_brain_scope_failed', __( 'The rule could not be saved.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Remove one rule from a reader.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function remove_scope( \WP_REST_Request $request ) {
		Brain::scopes()->remove( (string) $request['reader'], (string) $request['type'], (string) $request['value'] );

		return $this->scope( $request );
	}

	/**
	 * An entry as listed.
	 *
	 * @param KnowledgeEntry $entry Entry.
	 * @return array<string, mixed>
	 */
	public static function summary( KnowledgeEntry $entry ): array {
		return array(
			'id'         => $entry->get_id(),
			'entry_key'  => $entry->get_entry_key(),
			'title'      => $entry->get_title(),
			'summary'    => TextRedactor::secrets( $entry->get_summary() ),
			'source'     => $entry->get_source_type(),
			'plugin'     => (string) $entry->get_plugin_slug(),
			'section'    => $entry->get_section(),
			'trust'      => $entry->get_trust_status(),
			'stale'      => $entry->is_stale(),
			'superseded' => '' !== (string) $entry->meta( 'superseded_by', '' ),
			'tokens'     => $entry->get_token_estimate(),
			'updated_at' => $entry->get_updated_at(),
		);
	}
}
