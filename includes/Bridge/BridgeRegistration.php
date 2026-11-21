<?php
/**
 * What this plugin tells the Agent Runtime Bridge.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Bridge;

use ZoremAgentBridge\V1\Contracts\AgentContextProviderInterface;
use ZoremAgentBridge\V1\Contracts\AuditSinkInterface;
use ZoremAgentBridge\V1\Contracts\AgentDirectoryInterface;
use ZoremAgentBridge\V1\Contracts\PermissionAuthorityInterface;
use ZoremAgentBridge\V1\Registry\FeatureRegistry;
use ZoremAgentBridge\V1\Registry\ProviderRegistry;
use ZoremAgentBridge\V1\Vocabulary\ReasonVocabulary;
use AiAgentOs\Core\Container;
use AiAgentOs\Governance;

defined( 'ABSPATH' ) || exit;

/**
 * Announces this plugin on the bridge during registration.
 *
 * Anything built on top discovers it by asking the bridge for the
 * `agent-os.core` feature, never by probing this plugin's classes or
 * constants. It also claims the three authority contracts: who is acting, who
 * the agent is, and what it may do. A conflicting claim from another plugin
 * voids both and fails closed, with an admin notice.
 */
final class BridgeRegistration {

	/**
	 * The feature id other plugins ask for.
	 */
	public const FEATURE = 'agent-os.core';

	/**
	 * This plugin's owner name on the bridge.
	 */
	public const OWNER = 'ai-agent-os';

	/**
	 * The service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container The service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hook into bridge registration.
	 */
	public function register(): void {
		add_action( 'zorem_agent_bridge_register', array( $this, 'on_register' ), 10, 3 );
	}

	/**
	 * Register with the bridge.
	 *
	 * @param ProviderRegistry $registry   Provider registry.
	 * @param FeatureRegistry  $features   Feature registry.
	 * @param ReasonVocabulary $vocabulary Reason vocabulary.
	 */
	public function on_register( ProviderRegistry $registry, FeatureRegistry $features, ReasonVocabulary $vocabulary ): void {
		$features->register( self::FEATURE, AI_AGENT_OS_VERSION, self::OWNER );

		$registry->claim( AgentContextProviderInterface::class, $this->container->get( CoreAgentContext::class ), self::OWNER );
		$registry->claim( AgentDirectoryInterface::class, $this->container->get( CoreAgentDirectory::class ), self::OWNER );
		$registry->claim( PermissionAuthorityInterface::class, $this->container->get( CorePermissionAuthority::class ), self::OWNER );

		/*
		 * No audit sink and no skill catalogue: this plugin keeps no record of
		 * the agent's calls, so other plugins keep their own records and the
		 * bridge answers with its fallbacks.
		 */

		/*
		 * The labels are translated strings, and registration runs at
		 * plugins_loaded: translating there loads the text domain before init,
		 * which WordPress reports as "triggered too early" on every request.
		 * The vocabulary is not sealed, so the labels wait for init.
		 */
		if ( did_action( 'init' ) ) {
			self::register_labels( $vocabulary );

			return;
		}

		add_action(
			'init',
			static function () use ( $vocabulary ): void {
				self::register_labels( $vocabulary );
			},
			1
		);
	}

	/**
	 * Register this plugin's reason labels.
	 *
	 * @param ReasonVocabulary $vocabulary Reason vocabulary.
	 */
	public static function register_labels( ReasonVocabulary $vocabulary ): void {
		$labels = array_merge(
			Governance\Decision::labels(),
			array(
				CorePermissionAuthority::NOT_AN_AGENT => __( 'This identity is not the agent of this site.', 'zorem-ai-agent-connector' ),
				'ability_error'                       => __( 'The ability ran and returned an error.', 'zorem-ai-agent-connector' ),
			)
		);

		foreach ( $labels as $slug => $label ) {
			$vocabulary->register( $slug, $label, self::OWNER );
		}
	}
}
