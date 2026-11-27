<?php
/**
 * Removing a host, and the Brain with the last one.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Host;

use ZoremAgentBrain\V1\Schema\Installer;
use ZoremAgentBrain\V1\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D-11: Brain data goes only with the last host, and only if chosen.
 *
 * A host's uninstaller calls uninstall_host(). The host leaves the record
 * either way. The shared tables and options are removed only when no other
 * Agent OS plugin is still installed **and** the Brain's own "delete data on
 * uninstall" setting is on — so uninstalling one plugin never deletes Brain
 * data the other plugin is still using.
 */
final class Uninstaller {

	/** Outcomes, for the caller and for tests. */
	public const KEPT_OTHER_HOST = 'kept_other_host';
	public const KEPT_BY_SETTING = 'kept_by_setting';
	public const REMOVED         = 'removed';

	/**
	 * Host registry.
	 *
	 * @var HostRegistry
	 */
	private HostRegistry $hosts;

	/**
	 * Constructor.
	 *
	 * @param HostRegistry $hosts Host registry.
	 */
	public function __construct( HostRegistry $hosts ) {
		$this->hosts = $hosts;
	}

	/**
	 * Remove a host, and the Brain if it was the last and deletion was chosen.
	 *
	 * @param string $slug Slug of the host being uninstalled.
	 * @return string One of the outcome constants.
	 */
	public function uninstall_host( string $slug ): string {
		$others = $this->hosts->other_installed_hosts( $slug );

		$this->hosts->leave( $slug );

		if ( array() !== $others ) {
			return self::KEPT_OTHER_HOST;
		}

		/*
		 * The last host is going. Its scheduled work goes with it, and so do
		 * the secrets it held — the AI provider key and any source token — even
		 * when the knowledge itself is kept: nothing left would use them, and
		 * a reinstall asks for them again.
		 */
		\ZoremAgentBrain\V1\Ingestion\IngestionScheduler::unschedule();
		$this->forget_secrets();

		if ( ! Settings::get( 'delete_data_on_uninstall', false ) ) {
			return self::KEPT_BY_SETTING;
		}

		$this->remove_everything();

		return self::REMOVED;
	}

	/**
	 * Delete the stored AI key and every source's stored token.
	 */
	private function forget_secrets(): void {
		delete_option( \ZoremAgentBrain\V1\Ai\CredentialStore::OPTION );

		/*
		 * AI Agent OS (legacy)'s own copy, which the import read and left alone so the
		 * old plugin keeps working. Once that plugin is gone too, nothing can
		 * use it, and it is not left behind.
		 */
		if ( ! is_dir( WP_PLUGIN_DIR . '/zorem-agent-os' ) ) {
			delete_option( 'zorem_agentos_ai_credential' );
		}

		if ( array() !== Installer::missing_tables() ) {
			return;
		}

		$sources = new \ZoremAgentBrain\V1\Sources\SourceRepository();

		foreach ( $sources->all() as $source ) {
			$config = (array) ( $source['config'] ?? array() );

			if ( '' === (string) ( $config['token'] ?? '' ) ) {
				continue;
			}

			unset( $config['token'], $config['token_last4'], $config['token_fp'] );
			$sources->save( (string) $source['key'], (string) $source['type'], (string) $source['label'], $config );
		}
	}

	/**
	 * Drop the tables, then every option and transient under the Brain's prefix.
	 */
	private function remove_everything(): void {
		global $wpdb;

		\ZoremAgentBrain\V1\Ingestion\IngestionScheduler::unschedule();
		Installer::drop_tables();

		foreach ( array( 'zorem_agent_brain_', '_transient_zorem_agent_brain_', '_transient_timeout_zorem_agent_brain_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall; no API deletes options by prefix.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		wp_cache_flush();
	}
}
