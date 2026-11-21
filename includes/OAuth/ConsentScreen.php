<?php
/**
 * The consent screen.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Core\Settings;
use AiAgentOs\Governance\AbilityInventory;
use AiAgentOs\Governance\GrantAuthority;
use AiAgentOs\Governance\PermissionRepository;
use AiAgentOs\Governance\PermissionResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Where a human approves what a remote client may do on this site.
 *
 * The OAuth plumbing is the boring half and this screen is the product. It
 * shows who is asking — the client's own claim, attributed as such, beside
 * the redirect host it cannot fake — and the exact tools it would get.
 *
 * Approving connects the client as this site's agent: same grants, same deny
 * rules, same masking. Nothing is created and nothing is widened — the client
 * can only ever reach what the agent already reaches, and the tool list shown
 * here is the one its MCP server will register. Denying changes nothing.
 */
final class ConsentScreen {

	/**
	 * The admin-post action, also the nonce action.
	 */
	public const ACTION = 'ai_agent_os_oauth_consent';

	/**
	 * Query argument carrying a form error back to the screen.
	 */
	public const ERROR_ARG = 'ai_agent_os_error';

	/**
	 * The one form error: Approve came back with nothing active to connect as.
	 */
	public const ERROR_INACTIVE = 'agent_inactive';

	/**
	 * Agent meta: apps an administrator chose not to be asked about again, client id => user id => time.
	 */
	public const TRUST_META = 'trusted_apps';

	/**
	 * Client identification.
	 *
	 * @var ClientResolver
	 */
	private ClientResolver $clients;

	/**
	 * Credential persistence.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Grant persistence.
	 *
	 * @var PermissionRepository
	 */
	private PermissionRepository $permissions;

	/**
	 * The permission authority.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $resolver;

	/**
	 * Live ability registry.
	 *
	 * @var AbilityInventory
	 */
	private AbilityInventory $inventory;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The request validated on page load, for the render callback.
	 *
	 * @var AuthorizationRequest|\WP_Error|null
	 */
	private $current = null;

	/**
	 * This screen's admin page hook, so its assets load nowhere else.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Who may hand out which grants.
	 *
	 * @var GrantAuthority|null
	 */
	private ?GrantAuthority $authority;

	/**
	 * Constructor.
	 *
	 * @param ClientResolver       $clients     Client identification.
	 * @param OAuthStore           $store       Credential persistence.
	 * @param AgentRepository      $agents      Agent persistence.
	 * @param PermissionRepository $permissions Grant persistence.
	 * @param PermissionResolver   $resolver    The permission authority.
	 * @param AbilityInventory     $inventory   Live ability registry.
	 * @param Settings             $settings    Plugin settings.
	 * @param GrantAuthority|null  $authority   Who may hand out which grants.
	 */
	public function __construct(
		ClientResolver $clients,
		OAuthStore $store,
		AgentRepository $agents,
		PermissionRepository $permissions,
		PermissionResolver $resolver,
		AbilityInventory $inventory,
		Settings $settings,
		?GrantAuthority $authority = null
	) {
		$this->clients     = $clients;
		$this->store       = $store;
		$this->agents      = $agents;
		$this->permissions = $permissions;
		$this->resolver    = $resolver;
		$this->inventory   = $inventory;
		$this->settings    = $settings;
		$this->authority   = $authority;
	}

	/**
	 * Hook the screen and its form handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_filter( 'login_message', array( $this, 'login_message' ) );
	}

	/**
	 * Register the page, outside the menu.
	 *
	 * Registered at `read` so a logged-in non-admin who follows a client's
	 * link reaches a clear refusal (D6) instead of WordPress's generic one.
	 * The capability that matters is checked in the callbacks.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			'',
			__( 'Connect a client', 'zorem-ai-agent-connector' ),
			'',
			'read',
			OAuthEndpoints::CONSENT_PAGE,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			$this->hook_suffix = (string) $hook;

			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * This screen's own stylesheet and script, on this screen only.
	 *
	 * The consent screen renders outside the plugin's admin shell and loads
	 * none of its assets, so it carries its own small pair.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'ai-agent-os-connect-consent',
			AI_AGENT_OS_URL . 'assets/css/connect-consent.css',
			array(),
			$this->asset_version( 'assets/css/connect-consent.css' )
		);
	}

	/**
	 * An asset's version: the plugin version, plus the file's own timestamp so
	 * a changed file is never served from a cache.
	 *
	 * @param string $relative Path inside the plugin folder.
	 */
	private function asset_version( string $relative ): string {
		$time = (int) @filemtime( AI_AGENT_OS_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the plugin version.

		return AI_AGENT_OS_VERSION . ( $time > 0 ? '.' . $time : '' );
	}

	/**
	 * Validate before any output.
	 */
	public function load(): void {
		/*
		 * A page outside the menu has no title for WordPress to look up, and
		 * admin-header.php passes the null it finds to strip_tags(), which PHP
		 * 8.1+ reports as a deprecation across the top of the screen.
		 */
		global $title;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Variables.GlobalVariables -- Core reads the screen title from this global; there is no API for a menu-less page.
		$title = __( 'Connect a client', 'zorem-ai-agent-connector' );

		if ( current_user_can( \AiAgentOs\Core\Capabilities::MANAGE ) && OAuthEndpoints::is_available() && $this->settings->bool( 'mcp_enabled' ) ) {
			$this->validate();
		}

		/*
		 * The approval is its own small page, sized for the popup window an
		 * app opens: printed here, before the admin header, and ended there.
		 */
		$this->render();
	}

	/**
	 * Validate the request, and connect a trusted app at once.
	 */
	private function validate(): void {

		/*
		 * A request that fails validation is explained on this screen, never
		 * answered with a redirect: anyone can register a client and send an
		 * administrator a link, and a redirect from here would take them to
		 * whatever address that client listed. Only a decision the
		 * administrator posted (Approve or Deny) is sent back to the client.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- An inbound OAuth request carries no nonce; nothing is changed by viewing it.
		$ai_agent_os_query = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );

		$this->current = AuthorizationRequest::from_params( self::request_params( (array) $ai_agent_os_query ), $this->clients );

		if ( $this->current instanceof AuthorizationRequest ) {
			$this->maybe_connect_trusted( $this->current );
		}
	}

	/**
	 * Say why the login form is there when an app's connect window landed on it.
	 *
	 * @param string $message Login screen message.
	 */
	public function login_message( $message ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Read only: picks a fixed message.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

		if ( false === strpos( $redirect, 'page=' . OAuthEndpoints::CONSENT_PAGE ) ) {
			return (string) $message;
		}

		return (string) $message . '<p class="message">' . esc_html__( 'Log in as an administrator to connect your AI app. You’ll approve the connection on the next step.', 'zorem-ai-agent-connector' ) . '</p>';
	}

	/**
	 * Connect at once an app the administrator said not to ask about again.
	 *
	 * Only while the agent can still only look (no changes, no deletes,
	 * customer details hidden): the moment its permissions grow, the question
	 * is asked again. The code still goes only to the app's own address.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 */
	private function maybe_connect_trusted( AuthorizationRequest $request ): void {
		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent || ! $agent->is_active() || ! $this->address_is_current( $request ) ) {
			return;
		}

		if ( ! $this->is_trusted( $agent, (string) $request->client()['client_id'] ) || ! $this->only_looks( $agent ) || ! $this->may_hand_out( $agent ) ) {
			return;
		}

		$code = $this->approve( $request, $agent );

		if ( ! is_wp_error( $code ) ) {
			$this->redirect( $request->redirect_with( array( 'code' => (string) $code ) ) );
		}
	}

	/**
	 * Whether the current administrator trusts an app on the agent.
	 *
	 * @param Agent  $agent     The agent.
	 * @param string $client_id The app's client id.
	 */
	private function is_trusted( Agent $agent, string $client_id ): bool {
		$trusted = (array) $agent->meta( self::TRUST_META, array() );

		return '' !== $client_id && isset( $trusted[ $client_id ][ get_current_user_id() ] );
	}

	/**
	 * Remember that the current administrator trusts an app on the agent.
	 *
	 * @param Agent  $agent     The agent.
	 * @param string $client_id The app's client id.
	 */
	private function trust( Agent $agent, string $client_id ): void {
		$trusted = (array) $agent->meta( self::TRUST_META, array() );

		$trusted[ $client_id ]                           = (array) ( $trusted[ $client_id ] ?? array() );
		$trusted[ $client_id ][ get_current_user_id() ] = time();

		$this->agents->update_meta( array( self::TRUST_META => array_slice( $trusted, -20, null, true ) ) );
	}

	/**
	 * Whether the agent can only look: no tool that changes, no deletes, customer details hidden.
	 *
	 * @param Agent $agent The agent.
	 */
	private function only_looks( Agent $agent ): bool {
		if ( $this->resolver->destructive_allowed( $agent ) || ! $agent->masks_pii() ) {
			return false;
		}

		$descriptors = $this->inventory->descriptors();

		foreach ( $this->resolver->granted_ability_names( $agent ) as $name ) {
			// Write on a view-only ability allows only viewing, so it does not count.
			if ( PermissionRepository::LEVEL_WRITE === $this->permissions->get_grant( $agent->get_id(), $name ) && 'read' !== (string) ( $descriptors[ $name ]['risk'] ?? 'write' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		$ai_agent_os_refusal = null;
		$ai_agent_os_request = null;
		$ai_agent_os_agent   = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Display only: picks which fixed message to show.
		$ai_agent_os_form_error = isset( $_GET[ self::ERROR_ARG ] ) && self::ERROR_INACTIVE === sanitize_key( wp_unslash( $_GET[ self::ERROR_ARG ] ) );

		if ( ! current_user_can( \AiAgentOs\Core\Capabilities::MANAGE ) ) {
			$ai_agent_os_refusal = __( 'Only a site administrator can connect a client to this site. Ask an administrator to sign in and start the connection again — nothing has been changed.', 'zorem-ai-agent-connector' );
		} elseif ( ! $this->settings->bool( 'mcp_enabled' ) ) {
			$ai_agent_os_refusal = __( 'The MCP endpoint is switched off on this site, so there is nothing to connect to.', 'zorem-ai-agent-connector' );
		} elseif ( ! OAuthEndpoints::is_available() ) {
			$ai_agent_os_refusal = (string) OAuthEndpoints::unavailable_reason();
		} elseif ( is_wp_error( $this->current ) ) {
			$ai_agent_os_refusal = $this->current->get_error_message();
		} elseif ( $this->current instanceof AuthorizationRequest && ! $this->address_is_current( $this->current ) ) {
			// Signing in at an MCP address that has been replaced.
			$ai_agent_os_refusal = __( 'The client is signing in at an MCP address that is no longer this site’s — it may have been replaced. Copy the current address from the agent’s Connection panel and add it to the client again.', 'zorem-ai-agent-connector' );
		} elseif ( $this->current instanceof AuthorizationRequest ) {
			$ai_agent_os_request = $this->current;

			// Null while the agent is revoked or expired: there is nothing to approve.
			$ai_agent_os_agent = $this->agent_summary();
		} else {
			$ai_agent_os_refusal = __( 'This page is opened by a client asking to connect. There is no request to approve.', 'zorem-ai-agent-connector' );
		}

		$ai_agent_os_cards     = null === $ai_agent_os_agent ? array() : array( $ai_agent_os_agent );
		$ai_agent_os_form_text = __( 'Nothing on this site is active to connect as any more. Start the connection again from the app.', 'zorem-ai-agent-connector' );

		require AI_AGENT_OS_PATH . 'includes/Admin/Screen/partials/connect-popup.php';
	}

	/**
	 * Handle Approve or Deny.
	 */
	public function handle(): void {
		if ( ! current_user_can( \AiAgentOs\Core\Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Only a site administrator can connect a client to this site.', 'zorem-ai-agent-connector' ), 403 );
		}

		check_admin_referer( self::ACTION );

		if ( ! OAuthEndpoints::is_available() || ! $this->settings->bool( 'mcp_enabled' ) ) {
			wp_die( esc_html( (string) ( OAuthEndpoints::unavailable_reason() ?? __( 'The MCP endpoint is switched off.', 'zorem-ai-agent-connector' ) ) ), 400 );
		}

		$posted = isset( $_POST['oauth'] ) && is_array( $_POST['oauth'] )
			? map_deep( wp_unslash( $_POST['oauth'] ), 'sanitize_text_field' )
			: array();

		$request = AuthorizationRequest::from_params( self::request_params( (array) $posted ), $this->clients );

		$this->maybe_send_back( $request );

		if ( ! $request instanceof AuthorizationRequest ) {
			wp_die( esc_html( $request->get_error_message() ), 400 );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( 'approve' !== $decision ) {
			$this->redirect( $request->redirect_with( array( 'error' => 'access_denied' ) ) );
		}

		$agent = $this->agents->get();

		/*
		 * Re-checked here rather than trusted from the page that was rendered:
		 * the agent may have been revoked or expired since it loaded, or the
		 * address replaced.
		 */
		if ( ! $agent instanceof Agent || ! $agent->is_active() ) {
			$this->back_to_screen( $request, self::ERROR_INACTIVE );
		}

		if ( ! $this->address_is_current( $request ) ) {
			wp_die( esc_html__( 'This MCP address has been replaced. Add the agent’s current address to the client and connect again.', 'zorem-ai-agent-connector' ), 400 );
		}

		/*
		 * Approving hands the client a credential for every grant the agent
		 * holds. A delegated agent manager may only hand out access they could
		 * have granted themselves, the same rule as issuing a token.
		 */
		if ( ! $this->may_hand_out( $agent ) ) {
			wp_die( esc_html__( 'This agent is allowed to use tools that need WordPress capabilities you do not have, so you cannot connect a client to it. An administrator can.', 'zorem-ai-agent-connector' ), 403 );
		}

		$code = $this->approve( $request, $agent );

		if ( is_wp_error( $code ) ) {
			$this->redirect( $request->redirect_with( array( 'error' => 'server_error' ) ) );
		}

		// "Don't ask again": kept only for an agent that can only look, checked again here.
		if ( ! empty( $_POST['trust'] ) && $this->only_looks( $agent ) ) {
			$this->trust( $agent, (string) $request->client()['client_id'] );
		}

		$this->redirect( $request->redirect_with( array( 'code' => (string) $code ) ) );
	}

	/**
	 * Whether the current user could have granted everything the agent holds.
	 *
	 * An administrator always can, so this only ever narrows a delegated
	 * agent manager. Without the authority service (unit tests), only an
	 * administrator may approve.
	 *
	 * @param Agent $agent The agent.
	 */
	private function may_hand_out( Agent $agent ): bool {
		if ( null === $this->authority ) {
			return current_user_can( 'manage_options' );
		}

		list( , $refused ) = $this->authority->split( $this->permissions->get_grants( $agent->get_id() ), get_current_user_id() );

		return array() === $refused;
	}

	/**
	 * Issue an authorization code for the agent.
	 *
	 * Nothing about the agent changes except a note of the latest sign-in,
	 * which the Connection screen shows. Its grants are not touched.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 * @param Agent                $agent   The agent the client will act as.
	 * @return string|\WP_Error The code.
	 */
	private function approve( AuthorizationRequest $request, Agent $agent ) {
		$client = $request->client();

		$this->agents->update_meta(
			array(
				'oauth' => array(
					'client_id'     => (string) $client['client_id'],
					'client_name'   => (string) $client['name'],
					'client_kind'   => (string) $client['kind'],
					'redirect_host' => $request->redirect_host(),
					'approved_by'   => get_current_user_id(),
					'approved_at'   => current_time( 'mysql', true ),
				),
			)
		);

		return $this->store->issue(
			OAuthStore::TYPE_CODE,
			$agent->get_id(),
			$this->store->new_grant_id(),
			(string) $client['client_id'],
			array(
				'redirect_uri'   => $request->param( 'redirect_uri' ),
				'code_challenge' => $request->param( 'code_challenge' ),
				'resource'       => $request->resource(),
			)
		);
	}

	/**
	 * Whether the client is signing in at the agent's current MCP address.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 */
	private function address_is_current( AuthorizationRequest $request ): bool {
		return ( new \AiAgentOs\Mcp\AgentAddress( $this->agents ) )->matches( $request->agent_code() );
	}

	/**
	 * The site's agent, with the tools a client would get as it.
	 *
	 * The tool list is `PermissionResolver::granted_ability_names()` — the
	 * same call ServerBuilder makes to decide what goes on the agent's MCP
	 * server — so the screen cannot promise a tool the client will not see,
	 * or hide one it will. The "cannot" lines are derived from the agent at
	 * render time (plan §3.3.1), never stored.
	 *
	 * Null while it is revoked or expired: it could not act, so a connection
	 * to it would fail at the first call.
	 *
	 * @return array<string, mixed>|null
	 */
	private function agent_summary(): ?array {
		$descriptors = $this->inventory->descriptors();

		$agent = $this->agents->get();

		if ( $agent instanceof Agent && $agent->is_active() ) {
			$tools  = array();
			$writes = 0;

			foreach ( $this->resolver->granted_ability_names( $agent ) as $name ) {
				$level = $this->permissions->get_grant( $agent->get_id(), $name );
				$risk  = (string) ( $descriptors[ $name ]['risk'] ?? 'read' );

				// Write on a view-only ability allows exactly what read does.
				if ( 'read' === $risk && PermissionRepository::LEVEL_WRITE === $level ) {
					$level = PermissionRepository::LEVEL_READ;
				}

				$tools[] = array(
					'name'  => $name,
					'label' => (string) ( $descriptors[ $name ]['label'] ?? $name ),
					'level' => $level,
					'risk'  => $risk,
				);

				if ( PermissionRepository::LEVEL_WRITE === $level ) {
					++$writes;
				}
			}

			$hides = max( 0, count( $descriptors ) - count( $tools ) );
			$wont  = array();

			if ( 0 === $writes ) {
				$wont[] = __( 'Cannot change anything — every tool it has is read-only.', 'zorem-ai-agent-connector' );
			}

			if ( ! $this->resolver->destructive_allowed( $agent ) ) {
				$wont[] = __( 'Cannot delete, cancel or refund anything — destructive abilities stay off.', 'zorem-ai-agent-connector' );
			}

			if ( $agent->masks_pii() ) {
				$wont[] = __( 'Cannot see customer names, emails, phone numbers or addresses — they are replaced with [masked].', 'zorem-ai-agent-connector' );
			}

			if ( $hides > 0 ) {
				$wont[] = sprintf(
					/* translators: %d: number of abilities. */
					_n( 'Cannot see the %d other ability on this site — it is not on its server at all.', 'Cannot see the %d other abilities on this site — they are not on its server at all.', $hides, 'zorem-ai-agent-connector' ),
					$hides
				);
			}

			return array(
				'id'          => $agent->get_id(),
				'writes'      => $writes,
				'destructive' => $this->resolver->destructive_allowed( $agent ),
				'masks'       => $agent->masks_pii(),
				'hides'       => $hides,
				'name'        => $agent->get_name(),
				'description' => (string) $agent->meta( 'description', '' ),
				'acts_for'    => Agent::acts_for_label( $agent->get_acts_for() ),
				'tools'       => $tools,
				'wont'        => $wont,
			);
		}

		return null;
	}

	/**
	 * Return to the consent screen with the same request and a form error.
	 *
	 * The request is carried back in the URL exactly as it arrived, and is
	 * validated again when the screen loads — nothing about it is trusted
	 * because it came through here.
	 *
	 * @param AuthorizationRequest $request Validated request.
	 * @param string               $error   One of the ERROR_* constants.
	 */
	private function back_to_screen( AuthorizationRequest $request, string $error ): void {
		$args = array();

		foreach ( $request->params() as $key => $value ) {
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		$args[ self::ERROR_ARG ] = $error;

		// `add_query_arg()` encodes these; encoding them here as well would double it.
		wp_safe_redirect( add_query_arg( $args, OAuthEndpoints::consent_url() ) );
		exit;
	}

	/**
	 * Pick the authorization parameters this server acts on.
	 *
	 * One place for both directions: the client's opening `$_GET` and the
	 * consent form's `$_POST['oauth']` carry the same parameters, and a value
	 * is no more trustworthy for having been through a form of ours once
	 * already. Only the names in `AuthorizationRequest::PARAMS` are read, so
	 * anything else a client sends stops here instead of travelling further in.
	 *
	 * Both callers unslash and sanitize before this runs;
	 * `AuthorizationRequest::from_params()` then sanitizes each value by its
	 * kind and validates it.
	 *
	 * @param array<string|int, mixed> $source Cleaned request data.
	 * @return array<string, string>
	 */
	private static function request_params( array $source ): array {
		$params = array();

		foreach ( AuthorizationRequest::PARAMS as $key ) {
			$params[ $key ] = isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? (string) $source[ $key ] : '';
		}

		return $params;
	}

	/**
	 * Send a client-facing error back to the client, if it is one.
	 *
	 * @param AuthorizationRequest|\WP_Error $request Validation result.
	 */
	private function maybe_send_back( $request ): void {
		if ( ! is_wp_error( $request ) ) {
			return;
		}

		$data = $request->get_error_data();

		if ( is_array( $data ) && ! empty( $data['redirect_uri'] ) ) {
			$this->redirect( (string) $data['redirect_uri'] );
		}
	}

	/**
	 * Redirect to a client and stop.
	 *
	 * `wp_redirect`, not `wp_safe_redirect`: the destination is by definition
	 * another site. It has been matched exactly against the client's published
	 * redirect URIs before any path reaches here.
	 *
	 * @param string $url Destination.
	 */
	private function redirect( string $url ): void {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Verified client redirect URI; see above.
		wp_redirect( $url );
		exit;
	}
}
