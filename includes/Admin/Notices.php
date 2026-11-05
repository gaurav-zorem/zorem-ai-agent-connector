<?php
/**
 * Admin notices.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Admin;

use AiAgentOs\Core\Requirements;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an administrator why the plugin is not running.
 *
 * Shown only to users who can do something about it (`activate_plugins`), and
 * each failure is its own sentence naming the cause and the fix.
 */
final class Notices {

	/**
	 * The failed requirements.
	 *
	 * @var Requirements
	 */
	private Requirements $requirements;

	/**
	 * Constructor.
	 *
	 * @param Requirements $requirements The failed requirements.
	 */
	public function __construct( Requirements $requirements ) {
		$this->requirements = $requirements;
	}

	/**
	 * Register the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render one notice listing every failure.
	 */
	public function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$failures = $this->requirements->failures();

		if ( array() === $failures ) {
			return;
		}

		$type = isset( $failures[ Requirements::LEGACY_ACTIVE ] ) && 1 === count( $failures ) ? 'warning' : 'error';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?>">
			<p><strong><?php echo esc_html( AI_AGENT_OS_NAME ); ?></strong></p>
			<ul>
				<?php foreach ( $failures as $slug => $message ) : ?>
					<li>
						<?php echo esc_html( $message ); ?>
						<code><?php echo esc_html( $slug ); ?></code>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
