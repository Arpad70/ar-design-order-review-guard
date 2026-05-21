<?php
/**
 * Plugin Name: AR Design Order Review Guard
 * Description: Nahrádza auto-rušenie nezaplatených objednávok bezpečným mezistavom pre manuálnu kontrolu bez rezervácie a odpočtu skladu.
 * Version: 0.2.19
 * Author: AR Design
 * Update URI: https://github.com/Arpad70/ar-design-order-review-guard
 * Text Domain: ar-design-order-review-guard
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.0
 */
if (! defined('ABSPATH')) {
	exit;
}

define('ARDRG_VERSION', '0.2.19');
define('ARDRG_DB_VERSION', '0.2.0');
define('ARDRG_FILE', __FILE__);
define('ARDRG_BASENAME', plugin_basename(__FILE__));
define('ARDRG_PATH', plugin_dir_path(__FILE__));
define('ARDRG_GITHUB_REPOSITORY', 'Arpad70/ar-design-order-review-guard');
define('ARDRG_TEXT_DOMAIN', 'ar-design-order-review-guard');

require_once ARDRG_PATH . 'bootstrap/autoload.php';
ArDesign\OrderReviewGuard\Support\Autoloader::register();
require_once ARDRG_PATH . 'src/Support/Updates/GitHubUpdater.php';

final class ArDesignOrderReviewGuard
{
	public const CRON_HOOK_NAME = 'ar_design_move_unpaid_to_manual_review';
	public const CRON_RECURRENCE_NAME = 'ar_design_every_ten_minutes';
	private const STATUS_SLUG = ARD_WORKFLOW_STATUS_MANUAL_REVIEW;
	private const DELETE_BLOCKED_TRANSIENT_PREFIX = 'ard_delete_blocked_';
	private const STALE_MINUTES = 45;
	private const DEFAULT_RISK_THRESHOLD = 4;
	private const NIGHT_RISK_THRESHOLD = 3;
	private const NIGHT_HIGH_TOTAL_LIMIT = 80.0;
	private const OPTION_MANAGER_EMAIL = 'ardrg_manager_email';
	private const OPTION_SECRET_HASH = 'ardrg_secret_hash';
	private const OPTION_SECRET_CHANGED_AT = 'ardrg_secret_changed_at';
	private const OPTION_LAST_CRON_COMPLETED_AT_GMT = 'ardrg_last_cron_completed_at_gmt';
	private const SECURE_BIN_TABLE = 'ardrg_secure_bin_orders';
	private const AUDIT_TABLE = 'ardrg_secure_bin_audit';
	private const SECURE_DELETE_TOKEN_PREFIX = 'ardrg_secure_delete_';
	private const META_MANUAL_REVIEW_SEEN = '_ardrg_manual_review_seen';
	private const META_MANUAL_REVIEW_RETURNED = '_ardrg_manual_review_returned';

	public static function bootstrap(): void
	{
		add_action('init', array(__CLASS__, 'registerStatus'));
		add_action('admin_menu', array(__CLASS__, 'registerAdminReportPage'), 99);
		add_action('admin_notices', array(__CLASS__, 'renderGlobalBulkNotices'));
		add_action('add_meta_boxes', array(__CLASS__, 'registerOrderMetaBox'));
		add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'renderOrderEditInlinePanel'));
		add_action('admin_post_ardrg_generate_secret', array(__CLASS__, 'handleGenerateSecret'));
		add_action('admin_post_ardrg_secure_bin_order', array(__CLASS__, 'handleSecureBinOrder'));
		add_action('admin_post_ardrg_bulk_secure_bin_orders', array(__CLASS__, 'handleBulkSecureBinOrders'));

		add_filter('wc_order_statuses', array(__CLASS__, 'registerStatusInLists'));
		add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'registerBulkAction'));
		add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'registerBulkAction'));
		add_filter('handle_bulk_actions-edit-shop_order', array(__CLASS__, 'handleBulkAction'), 10, 3);
		add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'handleBulkAction'), 10, 3);
		add_filter('woocommerce_admin_order_actions', array(__CLASS__, 'registerAdminActionButton'), 20, 2);

		add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'blockStockReductionForManualReview'), 10, 2);
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'releaseStockOnManualReviewTransition'), 20, 4);
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'trackManualReviewLifecycleFlags'), 30, 4);
		add_action('woocommerce_loaded', array(__CLASS__, 'disableWooAutoCancelUnpaid'), 30);

		add_filter('cron_schedules', array(__CLASS__, 'registerTenMinuteCron'));
		add_action(self::CRON_HOOK_NAME, array(__CLASS__, 'moveStaleUnpaidOrdersToManualReview'));

		add_filter('pre_delete_post', array(__CLASS__, 'preventPermanentDelete'), 10, 2);
		add_filter('pre_trash_post', array(__CLASS__, 'preventTrashOrder'), 10, 3);
		add_filter('woocommerce_pre_delete_order', array(__CLASS__, 'preventWooOrderDelete'), 10, 3);

		// High-priority override for AR Design Reporting deletion blockers.
		add_filter('pre_delete_post', array(__CLASS__, 'allowAuthorizedSecureDeletePreDeletePost'), 999, 2);
		add_filter('pre_trash_post', array(__CLASS__, 'allowAuthorizedSecureDeletePreTrashPost'), 999, 3);
		add_filter('woocommerce_pre_delete_order', array(__CLASS__, 'allowAuthorizedSecureDeleteWoo'), 999, 3);
	}

	public static function registerStatus(): void
	{
		ard_workflow_register_post_statuses(array(self::STATUS_SLUG), 'ar-design-order-review-guard');
	}

	public static function registerStatusInLists(array $statuses): array
	{
		return ard_workflow_insert_statuses_after($statuses, array(self::STATUS_SLUG), 'ar-design-order-review-guard', 'wc-pending');
	}

	public static function preventPermanentDelete(mixed $delete, \WP_Post $post): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}

		if (self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return $delete;
		}

		self::blockDeleteAttempt((int) $post->ID, 'delete', 'pre_delete_post');

		return false;
	}

	public static function preventTrashOrder(mixed $trash, \WP_Post $post, mixed $previous_status): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}

		if (self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return $trash;
		}

		self::blockDeleteAttempt((int) $post->ID, 'trash', 'pre_trash_post');

		return false;
	}

	public static function preventWooOrderDelete(mixed $check, mixed $order, bool $force_delete): mixed
	{
		if (! $order instanceof \WC_Order) {
			return $check;
		}

		$order_id = (int) $order->get_id();
		if (self::hasSecureDeleteAuthorization($order_id)) {
			return $check;
		}

		self::blockDeleteAttempt($order_id, $force_delete ? 'delete' : 'trash', 'woocommerce_pre_delete_order');

		return false;
	}

	private static function blockDeleteAttempt(int $order_id, string $attempt, string $source): void
	{
		if ($order_id <= 0) {
			return;
		}

		$actor_user_id = get_current_user_id() ?: null;
		$attempt = sanitize_key($attempt);
		if (! in_array($attempt, array('delete', 'trash'), true)) {
			$attempt = 'delete';
		}

		self::storeDeleteBlockedNotice($order_id, $attempt, $actor_user_id);
		self::audit('delete_attempt_blocked', array('attempt' => $attempt, 'source' => $source), $actor_user_id, $order_id);
		self::logReportingDeleteBlock($order_id, $attempt, $source, $actor_user_id);
	}

	private static function storeDeleteBlockedNotice(int $order_id, string $attempt, ?int $actor_user_id): void
	{
		if (null === $actor_user_id || $actor_user_id <= 0) {
			return;
		}

		set_transient(
			self::DELETE_BLOCKED_TRANSIENT_PREFIX . $actor_user_id,
			array(
				'order_id' => $order_id,
				'attempt' => $attempt,
			),
			300
		);
	}

	private static function logReportingDeleteBlock(int $order_id, string $attempt, string $source, ?int $actor_user_id): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'ard_audit_log';
		$existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
		if (! is_string($existing) || '' === $existing) {
			return;
		}

		$created_at = gmdate('Y-m-d H:i:s');
		$context_json = wp_json_encode(array('source' => $source));

		$wpdb->insert(
			$table,
			array(
				'event_type' => 'order_delete_attempt_blocked',
				'entity_type' => 'order',
				'entity_id' => $order_id,
				'order_id' => $order_id,
				'actor_user_id' => $actor_user_id,
				'old_value_json' => wp_json_encode(array()),
				'new_value_json' => wp_json_encode(array('attempt' => $attempt)),
				'context_json' => $context_json,
				'created_at_gmt' => $created_at,
			),
			array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
		);

		if ('delete' !== $attempt) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'event_type' => 'order_permanent_delete_blocked',
				'entity_type' => 'order',
				'entity_id' => $order_id,
				'order_id' => $order_id,
				'actor_user_id' => $actor_user_id,
				'old_value_json' => wp_json_encode(array()),
				'new_value_json' => wp_json_encode(array()),
				'context_json' => $context_json,
				'created_at_gmt' => $created_at,
			),
			array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
		);
	}

	public static function registerBulkAction(array $actions): array
	{
		$actions['mark_' . self::STATUS_SLUG] = __('Změnit na Manuální kontrola', 'ar-design-order-review-guard');
		$actions['ardrg_bulk_secure_bin'] = __('Secure Bin: Vymazat označené (tajné heslo)', 'ar-design-order-review-guard');
		return $actions;
	}

	public static function handleBulkAction(string $redirect_to, string $action, array $ids): string
	{
		if ('ardrg_bulk_secure_bin' === $action) {
			$order_ids = array_values(array_unique(array_map('absint', $ids)));
			$order_ids = array_values(array_filter($order_ids));
			if (empty($order_ids)) {
				return add_query_arg('ardrg_bulk_marked', '0', $redirect_to);
			}

			return add_query_arg(
				array(
					'page' => 'ar-order-review-guard',
					'ardrg_bulk_secure_bin' => '1',
					'ardrg_order_ids' => implode(',', $order_ids),
					'ardrg_return_to' => rawurlencode($redirect_to),
				),
				admin_url('admin.php')
			);
		}

		if ('mark_' . self::STATUS_SLUG !== $action) {
			return $redirect_to;
		}

		$updated = 0;
		foreach ($ids as $id) {
			$order_id = absint($id);
			if ($order_id <= 0) {
				continue;
			}

			$order = wc_get_order($order_id);
			if (! $order instanceof \WC_Order) {
				continue;
			}

			if (self::STATUS_SLUG === $order->get_status()) {
				continue;
			}

			$order->update_status(self::STATUS_SLUG, __('Hromadná akce: přesun do Manuální kontroly.', 'ar-design-order-review-guard'), true);
			$updated++;
		}

		return add_query_arg('ardrg_bulk_marked', (string) $updated, $redirect_to);
	}

	public static function registerAdminActionButton(array $actions, mixed $order): array
	{
		if (! $order instanceof \WC_Order) {
			return $actions;
		}
		if (self::STATUS_SLUG !== $order->get_status()) {
			$actions[self::STATUS_SLUG] = array(
				'url' => wp_nonce_url(admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=' . self::STATUS_SLUG . '&order_id=' . $order->get_id()), 'woocommerce-mark-order-status'),
				'name' => __('Manuální kontrola', 'ar-design-order-review-guard'),
				'action' => self::STATUS_SLUG,
			);
			return $actions;
		}

		$actions['ardrg_secure_bin'] = array(
			'url' => self::getOrderSecureBinUrl((int) $order->get_id()),
			'name' => __('Secure Bin', 'ar-design-order-review-guard'),
			'action' => 'trash',
		);

		return $actions;
	}

	public static function registerAdminReportPage(): void
	{
		add_submenu_page('woocommerce', __('AR Order Review Guard', 'ar-design-order-review-guard'), __('AR Review Guard', 'ar-design-order-review-guard'), 'manage_woocommerce', 'ar-order-review-guard', array(__CLASS__, 'renderAdminReportPage'));
	}

	public static function renderAdminReportPage(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Nedostatečná oprávnění.', 'ar-design-order-review-guard'));
		}

		$manager_email = (string) get_option(self::OPTION_MANAGER_EMAIL, get_option('admin_email'));
		$secret_changed_at = (int) get_option(self::OPTION_SECRET_CHANGED_AT, 0);
		$stats = self::collectManualReviewStats();
		$recent_ops = self::getRecentSecureBinOperations(30);

		echo '<div class="wrap"><h1>AR Order Review Guard</h1>';
		self::renderAdminNotices();
		self::renderSecureBinFormIfRequested();
		self::renderBulkSecureBinFormIfRequested();

		echo '<h2>Secure Bin nastavení</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;background:#fff;padding:16px;border:1px solid #ccd0d4;">';
		echo '<input type="hidden" name="action" value="ardrg_generate_secret" />';
		wp_nonce_field('ardrg_generate_secret');
		echo '<p><label><strong>Manažerský e-mail</strong></label><br /><input type="email" class="regular-text" name="manager_email" value="' . esc_attr($manager_email) . '" required /></p>';
		if ($secret_changed_at > 0) {
			echo '<p><em>Poslední změna hesla: ' . esc_html(self::formatLocalDateTimeFromTimestamp($secret_changed_at)) . '</em></p>';
		}
		submit_button('Vygenerovat nové tajné heslo', 'primary', 'submit', false);
		echo '</form>';

		echo '<h2 style="margin-top:24px;display:flex;align-items:baseline;gap:10px;">' . esc_html__('Prehľad manuálnej kontroly', 'ar-design-order-review-guard') . ' <span style="font-size:13px;font-weight:400;color:#50575e;">' . esc_html(self::getManualReviewCronSummary()) . '</span></h2>';
		echo '<table class="widefat striped" style="max-width:920px;"><tbody>';
		echo '<tr><td>Manuální kontrola celkem</td><td><strong>' . esc_html((string) $stats['totals']['all']) . '</strong></td></tr>';
		echo '<tr><td>Noční (22:00-06:00)</td><td>' . esc_html((string) $stats['totals']['night']) . '</td></tr>';
		echo '<tr><td>Denní (06:00-22:00)</td><td>' . esc_html((string) $stats['totals']['day']) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px;">Poslední Secure Bin operace</h2>';
		if (empty($recent_ops)) {
			echo '<p>Žádné záznamy zatím nejsou k dispozici.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>';
			echo '<th>Čas</th><th>Událost</th><th>Objednávka</th><th>Uživatel</th><th>Detail</th>';
			echo '</tr></thead><tbody>';
			foreach ($recent_ops as $row) {
				$created_at = isset($row['created_at_gmt']) ? (string) $row['created_at_gmt'] : '';
				$event_type = isset($row['event_type']) ? (string) $row['event_type'] : '';
				$order_id = isset($row['order_id']) ? (int) $row['order_id'] : 0;
				$actor_user_id = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : 0;
				$context_json = isset($row['context_json']) ? (string) $row['context_json'] : '';
				$local_time = '' !== $created_at ? self::formatLocalDateTimeFromGmtString($created_at) : '';
				$user_label = $actor_user_id > 0 ? ('#' . $actor_user_id) : '—';
				$user = $actor_user_id > 0 ? get_user_by('id', $actor_user_id) : false;
				if ($user instanceof \WP_User) {
					$user_label = '#' . $actor_user_id . ' (' . $user->user_login . ')';
				}

				$detail = '';
				if ('' !== $context_json) {
					$context = json_decode($context_json, true);
					if (is_array($context)) {
						if (isset($context['reason'])) {
							$detail = 'reason: ' . (string) $context['reason'];
						} elseif (isset($context['status_before_delete'])) {
							$detail = 'status_before_delete: ' . (string) $context['status_before_delete'];
						}
					}
				}

				echo '<tr>';
				echo '<td>' . esc_html($local_time) . '</td>';
				echo '<td><code>' . esc_html($event_type) . '</code></td>';
				echo '<td>' . ($order_id > 0 ? ('#' . esc_html((string) $order_id)) : '—') . '</td>';
				echo '<td>' . esc_html($user_label) . '</td>';
				echo '<td>' . esc_html($detail) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function getRecentSecureBinOperations(int $limit = 30): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::AUDIT_TABLE;
		$limit = max(1, min(200, $limit));

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, order_id, actor_user_id, context_json, created_at_gmt
				FROM {$table}
				WHERE event_type IN (%s, %s, %s)
				ORDER BY id DESC
				LIMIT %d",
				'secure_bin_success',
				'secure_bin_failed',
				'secret_generated',
				$limit
			),
			ARRAY_A
		);

		return is_array($rows) ? $rows : array();
	}

	private static function renderAdminNotices(): void
	{
		if (! isset($_GET['ardrg_notice'])) {
			return;
		}
		$notice = sanitize_key((string) wp_unslash($_GET['ardrg_notice']));
		$map = array(
			'secret_generated' => array('success', 'Nové heslo bylo vygenerováno a odesláno.'),
			'secret_mail_failed' => array('error', 'Heslo bylo uloženo, ale e-mail se nepodařilo odeslat.'),
			'secure_bin_done' => array('success', 'Objednávka byla přesunuta do Secure Bin a trvale smazána.'),
			'secure_bin_wrong_secret' => array('error', 'Neplatné tajné heslo. Zkontrolujte heslo a zkuste to znovu.'),
			'secure_bin_error' => array('error', 'Secure Bin operaci se nepodařilo dokončit.'),
		);
		if (! isset($map[$notice])) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr($map[$notice][0]) . '"><p>' . esc_html($map[$notice][1]) . '</p></div>';
	}

	public static function renderGlobalBulkNotices(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		if (isset($_GET['ardrg_bulk_marked'])) {
			$count = absint((string) wp_unslash($_GET['ardrg_bulk_marked']));
			echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Hromadně přesunuto do Manuální kontroly: %d objednávek.', $count)) . '</p></div>';
		}

		if (isset($_GET['ardrg_bulk_secure_processed']) || isset($_GET['ardrg_bulk_secure_deleted']) || isset($_GET['ardrg_bulk_secure_failed'])) {
			$processed = absint((string) wp_unslash($_GET['ardrg_bulk_secure_processed'] ?? '0'));
			$deleted = absint((string) wp_unslash($_GET['ardrg_bulk_secure_deleted'] ?? '0'));
			$failed = absint((string) wp_unslash($_GET['ardrg_bulk_secure_failed'] ?? '0'));
			$message = sprintf(
				'Hromadné Secure Bin: zpracováno %d, smazáno %d, selhalo %d.',
				$processed,
				$deleted,
				$failed
			);
			$class = $failed > 0 ? 'warning' : 'success';
			echo '<div class="notice notice-' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
		}
	}

	public static function handleGenerateSecret(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}
		check_admin_referer('ardrg_generate_secret');
		$user_id = get_current_user_id();
		$old_email = (string) get_option(self::OPTION_MANAGER_EMAIL, '');
		$new_email = sanitize_email((string) wp_unslash($_POST['manager_email'] ?? ''));
		if ('' === $new_email || ! is_email($new_email)) {
			wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secret_mail_failed'));
			exit;
		}

		update_option(self::OPTION_MANAGER_EMAIL, $new_email, false);
		if ($old_email !== $new_email) {
			self::audit('manager_email_changed', array('old_email' => $old_email, 'new_email' => $new_email), $user_id, null);
		}

		$secret = wp_generate_password(16, false, false);
		update_option(self::OPTION_SECRET_HASH, wp_hash_password($secret), false);
		update_option(self::OPTION_SECRET_CHANGED_AT, time(), false);
		self::audit('secret_generated', array('target_email' => $new_email), $user_id, null);

		$mail_ok = wp_mail($new_email, 'AR Review Guard - nové tajné heslo', "Nové heslo: {$secret}\nVygenerováno: " . self::formatLocalDateTimeFromTimestamp(time()));
		wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=' . ($mail_ok ? 'secret_generated' : 'secret_mail_failed')));
		exit;
	}

	private static function renderSecureBinFormIfRequested(): void
	{
		$order_id = absint($_GET['secure_bin_order_id'] ?? 0);
		if ($order_id <= 0) {
			return;
		}
		if (! wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'ardrg_secure_bin_form_' . $order_id)) {
			echo '<div class="notice notice-error"><p>Neplatný token.</p></div>';
			return;
		}
		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order || self::STATUS_SLUG !== $order->get_status()) {
			echo '<div class="notice notice-error"><p>Secure Bin lze použít pouze pro stav Manuální kontrola.</p></div>';
			return;
		}
		echo '<div class="notice notice-warning"><p>Objednávka #' . esc_html((string) $order_id) . ' bude přesunuta do speciální tabulky a trvale smazána.</p></div>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;background:#fff;padding:16px;border:1px solid #ccd0d4;margin-bottom:16px;">';
		echo '<input type="hidden" name="action" value="ardrg_secure_bin_order" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '" />';
		wp_nonce_field('ardrg_secure_bin_order_' . $order_id);
		echo '<p><label><strong>Tajné heslo</strong></label><br /><input type="password" class="regular-text" name="manager_secret" required autocomplete="off" /></p>';
		submit_button('Potvrdit Secure Bin', 'delete');
		echo '</form>';
	}

	private static function renderBulkSecureBinFormIfRequested(): void
	{
		if (! isset($_GET['ardrg_bulk_secure_bin']) || '1' !== (string) wp_unslash($_GET['ardrg_bulk_secure_bin'])) {
			return;
		}

		$order_ids_raw = isset($_GET['ardrg_order_ids']) ? (string) wp_unslash($_GET['ardrg_order_ids']) : '';
		$order_ids = array_values(array_filter(array_map('absint', explode(',', $order_ids_raw))));
		if (empty($order_ids)) {
			echo '<div class="notice notice-error"><p>Nebyly vybrány žádné objednávky.</p></div>';
			return;
		}

		$return_to = isset($_GET['ardrg_return_to']) ? rawurldecode((string) wp_unslash($_GET['ardrg_return_to'])) : '';
		$return_to = self::sanitizeOrdersListReturnUrl($return_to);

		echo '<div class="notice notice-warning"><p>';
		echo 'Hromadné Secure Bin vymazání pro ' . esc_html((string) count($order_ids)) . ' objednávek. ';
		echo 'Budou vymazány jen objednávky ve stavu Manuální kontrola a jen při správném tajném hesle.';
		echo '</p></div>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;background:#fff;padding:16px;border:1px solid #ccd0d4;margin-bottom:16px;">';
		echo '<input type="hidden" name="action" value="ardrg_bulk_secure_bin_orders" />';
		echo '<input type="hidden" name="order_ids" value="' . esc_attr(implode(',', $order_ids)) . '" />';
		echo '<input type="hidden" name="return_to" value="' . esc_attr($return_to) . '" />';
		wp_nonce_field('ardrg_bulk_secure_bin_orders');
		echo '<p><label><strong>Tajné heslo</strong></label><br /><input type="password" class="regular-text" name="manager_secret" required autocomplete="off" /></p>';
		submit_button('Potvrdit hromadné Secure Bin vymazání', 'delete');
		echo '</form>';
	}

	public static function registerOrderMetaBox(): void
	{
		add_meta_box(
			'ardrg-secure-bin',
			__('AR Review Guard: Secure Bin', 'ar-design-order-review-guard'),
			array(__CLASS__, 'renderOrderMetaBox'),
			'shop_order',
			'side',
			'high'
		);
	}

	public static function renderOrderMetaBox(\WP_Post $post): void
	{
		$order_id = (int) $post->ID;
		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order) {
			echo '<p>' . esc_html__('Objednávka nebyla načtena.', 'ar-design-order-review-guard') . '</p>';
			return;
		}

		if (self::STATUS_SLUG !== $order->get_status()) {
			echo '<p>' . esc_html__('Tlačítko je dostupné jen pro stav Manuální kontrola.', 'ar-design-order-review-guard') . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__('Bezpečné vymazání objednávky', 'ar-design-order-review-guard') . '</strong></p>';
		echo '<p>' . esc_html__('Objednávka bude archivována do Secure Bin tabulky a trvale smazána z WooCommerce.', 'ar-design-order-review-guard') . '</p>';
		self::renderSecureBinInlineForm($order_id);
	}

	private static function getOrderSecureBinUrl(int $order_id): string
	{
		$order_id = absint($order_id);
		$nonce = wp_create_nonce('ardrg_secure_bin_form_' . $order_id);
		$page_url = admin_url('admin.php?page=ar-order-review-guard&secure_bin_order_id=' . $order_id . '&_wpnonce=' . $nonce);
		$return_to = self::resolveOrdersListReturnUrl();
		if ('' !== $return_to) {
			$page_url = add_query_arg('ardrg_return_to', rawurlencode($return_to), $page_url);
		}

		// HPOS order edit url fallback.
		$hpos_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&ardrg_secure_bin_order_id=' . $order_id . '&ardrg_secure_bin_nonce=' . $nonce);
		if ('' !== $return_to) {
			$hpos_url = add_query_arg('ardrg_return_to', rawurlencode($return_to), $hpos_url);
		}

		if (isset($_GET['page']) && 'wc-orders' === (string) $_GET['page']) {
			return $hpos_url;
		}

		return $page_url;
	}

	public static function renderOrderEditInlinePanel(mixed $order): void
	{
		if (! $order instanceof \WC_Order) {
			return;
		}
		if (self::STATUS_SLUG !== $order->get_status()) {
			return;
		}
		$order_id = (int) $order->get_id();
		echo '<div class="order_data_column" style="width:100%;padding-top:8px;">';
		echo '<h4>' . esc_html__('AR Review Guard: Secure Bin', 'ar-design-order-review-guard') . '</h4>';
		echo '<p>' . esc_html__('Objednávku lze trvale vymazat jen přes tajné heslo.', 'ar-design-order-review-guard') . '</p>';
		self::renderSecureBinInlineForm($order_id);
		echo '</div>';
	}

	private static function renderSecureBinInlineForm(int $order_id): void
	{
		$return_to = self::resolveOrdersListReturnUrl();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
		echo '<input type="hidden" name="action" value="ardrg_secure_bin_order" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '" />';
		echo '<input type="hidden" name="return_to" value="' . esc_attr($return_to) . '" />';
		wp_nonce_field('ardrg_secure_bin_order_' . $order_id);
		echo '<p><label><strong>' . esc_html__('Tajné heslo', 'ar-design-order-review-guard') . '</strong></label><br />';
		echo '<input type="password" class="regular-text" name="manager_secret" required autocomplete="off" /></p>';
		echo '<p><button type="submit" class="button button-primary" onclick="return confirm(\'Potvrdit trvalé vymazání objednávky?\');">' . esc_html__('Vymazat objednávku (Secure Bin)', 'ar-design-order-review-guard') . '</button></p>';
		echo '</form>';
	}

	public static function handleSecureBinOrder(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}
		$order_id = absint($_POST['order_id'] ?? 0);
		check_admin_referer('ardrg_secure_bin_order_' . $order_id);
		$order = wc_get_order($order_id);
		$user_id = get_current_user_id();
		$return_to = (string) wp_unslash($_POST['return_to'] ?? '');

		$secret = trim((string) wp_unslash($_POST['manager_secret'] ?? ''));
		$hash = (string) get_option(self::OPTION_SECRET_HASH, '');
		if (! $order instanceof \WC_Order || self::STATUS_SLUG !== $order->get_status() || '' === $hash || ! wp_check_password($secret, $hash)) {
			self::audit('secure_bin_failed', array('reason' => 'validation_failed'), $user_id, $order_id);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_wrong_secret', $return_to));
			exit;
		}

		$product_summary = self::buildOrderProductsSummary($order);

		if (! self::archiveOrderToSecureBin($order, $user_id)) {
			self::audit('secure_bin_failed', array('reason' => 'archive_failed'), $user_id, $order_id);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_error', $return_to));
			exit;
		}

		self::setSecureDeleteAuthorization($order_id, $user_id);
		$deleted = self::forceDeleteOrder($order_id, $order);
		self::clearSecureDeleteAuthorization($order_id, $user_id);
		if (! $deleted) {
			self::audit('secure_bin_failed', array('reason' => 'delete_failed'), $user_id, $order_id);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_error', $return_to));
			exit;
		}

		self::purgeArDesignReportingTrailAndLogDelete(
			$order_id,
			(float) $order->get_total(),
			(string) $order->get_currency(),
			$product_summary,
			$user_id
		);

		self::audit('secure_bin_success', array('status_before_delete' => self::STATUS_SLUG), $user_id, $order_id);
		wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_done', $return_to));
		exit;
	}

	public static function handleBulkSecureBinOrders(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}
		check_admin_referer('ardrg_bulk_secure_bin_orders');

		$user_id = get_current_user_id();
		$return_to = (string) wp_unslash($_POST['return_to'] ?? '');
		$order_ids_raw = (string) wp_unslash($_POST['order_ids'] ?? '');
		$order_ids = array_values(array_filter(array_map('absint', explode(',', $order_ids_raw))));

		$secret = trim((string) wp_unslash($_POST['manager_secret'] ?? ''));
		$hash = (string) get_option(self::OPTION_SECRET_HASH, '');
		if ('' === $hash || ! wp_check_password($secret, $hash)) {
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_wrong_secret', $return_to));
			exit;
		}

		$processed = 0;
		$deleted = 0;
		$failed = 0;

		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);
			if (! $order instanceof \WC_Order || self::STATUS_SLUG !== $order->get_status()) {
				continue;
			}

			$processed++;
			$product_summary = self::buildOrderProductsSummary($order);
			if (! self::archiveOrderToSecureBin($order, $user_id)) {
				self::audit('secure_bin_failed', array('reason' => 'archive_failed_bulk'), $user_id, $order_id);
				$failed++;
				continue;
			}

			self::setSecureDeleteAuthorization($order_id, $user_id);
			$did_delete = self::forceDeleteOrder($order_id, $order);
			self::clearSecureDeleteAuthorization($order_id, $user_id);

			if (! $did_delete) {
				self::audit('secure_bin_failed', array('reason' => 'delete_failed_bulk'), $user_id, $order_id);
				$failed++;
				continue;
			}

			self::purgeArDesignReportingTrailAndLogDelete(
				$order_id,
				(float) $order->get_total(),
				(string) $order->get_currency(),
				$product_summary,
				$user_id
			);
			self::audit('secure_bin_success', array('status_before_delete' => self::STATUS_SLUG, 'bulk' => true), $user_id, $order_id);
			$deleted++;
		}

		$target = self::sanitizeOrdersListReturnUrl($return_to);
		$target = add_query_arg(
			array(
				'ardrg_bulk_secure_processed' => (string) $processed,
				'ardrg_bulk_secure_deleted' => (string) $deleted,
				'ardrg_bulk_secure_failed' => (string) $failed,
			),
			$target
		);
		wp_safe_redirect($target);
		exit;
	}

	private static function buildPostSecureBinRedirectUrl(string $notice, string $return_to_raw): string
	{
		$base_url = self::sanitizeOrdersListReturnUrl($return_to_raw);
		return add_query_arg('ardrg_notice', sanitize_key($notice), $base_url);
	}

	private static function resolveOrdersListReturnUrl(): string
	{
		$from_query = isset($_GET['ardrg_return_to']) ? (string) wp_unslash($_GET['ardrg_return_to']) : '';
		$from_query = rawurldecode($from_query);
		if ('' !== $from_query) {
			return self::sanitizeOrdersListReturnUrl($from_query);
		}

		$referer = wp_get_referer();
		if (is_string($referer) && '' !== $referer) {
			return self::sanitizeOrdersListReturnUrl($referer);
		}

		return self::defaultOrdersListUrl();
	}

	private static function sanitizeOrdersListReturnUrl(string $candidate_url): string
	{
		$url = wp_validate_redirect($candidate_url, '');
		if ('' === $url) {
			return self::defaultOrdersListUrl();
		}

		$parts = wp_parse_url($url);
		if (! is_array($parts)) {
			return self::defaultOrdersListUrl();
		}

		$query = array();
		if (isset($parts['query']) && is_string($parts['query'])) {
			parse_str($parts['query'], $query);
		}

		$page = isset($query['page']) ? (string) $query['page'] : '';
		$post_type = isset($query['post_type']) ? (string) $query['post_type'] : '';
		$action = isset($query['action']) ? (string) $query['action'] : '';
		$id = isset($query['id']) ? (int) $query['id'] : 0;

		$is_hpos_orders_list = ('wc-orders' === $page) && ('edit' !== $action) && ($id <= 0);
		$is_legacy_orders_list = ('shop_order' === $post_type);

		if ($is_hpos_orders_list || $is_legacy_orders_list) {
			return $url;
		}

		return self::defaultOrdersListUrl();
	}

	private static function defaultOrdersListUrl(): string
	{
		$manual_status = ard_workflow_wc_status_key(self::STATUS_SLUG);
		if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
			&& method_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url('admin.php?page=wc-orders&status=' . $manual_status);
		}

		return admin_url('edit.php?post_type=shop_order&post_status=' . $manual_status);
	}

	private static function forceDeleteOrder(int $order_id, \WC_Order $order): bool
	{
		if (function_exists('wc_delete_order')) {
			$result = wc_delete_order($order_id, true);
			return ! empty($result);
		}

		if (method_exists($order, 'delete')) {
			$order->delete(true);
			$check = wc_get_order($order_id);
			return ! $check instanceof \WC_Order;
		}

		$result = wp_delete_post($order_id, true);
		return ! empty($result);
	}

	private static function purgeArDesignReportingTrailAndLogDelete(int $order_id, float $total, string $currency, array $product_summary, int $actor_user_id): void
	{
		global $wpdb;

		if ($order_id <= 0) {
			return;
		}

		$prefix = (string) $wpdb->prefix;
		$audit_table = $prefix . 'ard_audit_log';
		$processing_table = $prefix . 'ard_order_processing';
		$archive_table = $prefix . 'ard_order_archive';
		$flags_table = $prefix . 'ard_order_flags';

		// Zmazanie predchádzajúcich audit/workflow stôp objednávky.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$audit_table}
				WHERE order_id = %d
				OR (entity_type = %s AND entity_id = %d)",
				$order_id,
				'order',
				$order_id
			)
		);
		$wpdb->delete($processing_table, array('order_id' => $order_id), array('%d'));
		$wpdb->delete($archive_table, array('order_id' => $order_id), array('%d'));
		$wpdb->delete($flags_table, array('order_id' => $order_id), array('%d'));

		$wpdb->insert(
			$audit_table,
			array(
				'event_type' => 'order_deleted_secure_bin',
				'entity_type' => 'order',
				'entity_id' => $order_id,
				'order_id' => $order_id,
				'actor_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
				'old_value_json' => wp_json_encode(array()),
				'new_value_json' => wp_json_encode(
					array(
						'total' => round($total, 2),
						'currency' => $currency,
						'product_ids' => $product_summary['product_ids'],
					)
				),
				'context_json' => wp_json_encode(
					array(
						'source' => 'secure_bin_delete',
						'products_summary' => $product_summary,
					)
				),
				'created_at_gmt' => gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
		);
	}

	private static function buildOrderProductsSummary(\WC_Order $order): array
	{
		$product_ids = array();
		$items = array();

		foreach ($order->get_items() as $item) {
			if (! $item instanceof \WC_Order_Item_Product) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			if ($product_id > 0) {
				$product_ids[] = $product_id;
			}

			$items[] = array(
				'product_id' => $product_id,
				'variation_id' => $variation_id > 0 ? $variation_id : null,
				'qty' => (int) $item->get_quantity(),
			);
		}

		$product_ids = array_values(array_unique(array_filter($product_ids)));

		return array(
			'product_ids' => $product_ids,
			'items' => $items,
		);
	}

	private static function setSecureDeleteAuthorization(int $order_id, int $user_id): void
	{
		set_transient(self::SECURE_DELETE_TOKEN_PREFIX . $user_id . '_' . $order_id, '1', 120);
	}

	private static function clearSecureDeleteAuthorization(int $order_id, int $user_id): void
	{
		delete_transient(self::SECURE_DELETE_TOKEN_PREFIX . $user_id . '_' . $order_id);
	}

	private static function hasSecureDeleteAuthorization(int $order_id): bool
	{
		$user_id = get_current_user_id();
		if ($user_id <= 0 || $order_id <= 0) {
			return false;
		}
		return '1' === (string) get_transient(self::SECURE_DELETE_TOKEN_PREFIX . $user_id . '_' . $order_id);
	}

	public static function allowAuthorizedSecureDeletePreDeletePost(mixed $delete, \WP_Post $post): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}
		$order = wc_get_order((int) $post->ID);
		if ($order instanceof \WC_Order && self::STATUS_SLUG === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return null;
		}
		return $delete;
	}

	public static function allowAuthorizedSecureDeletePreTrashPost(mixed $trash, \WP_Post $post, mixed $previous_status): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}
		$order = wc_get_order((int) $post->ID);
		if ($order instanceof \WC_Order && self::STATUS_SLUG === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return null;
		}
		return $trash;
	}

	public static function allowAuthorizedSecureDeleteWoo(mixed $check, mixed $order, bool $force_delete): mixed
	{
		if (! $order instanceof \WC_Order) {
			return $check;
		}
		$order_id = (int) $order->get_id();
		if (self::STATUS_SLUG === $order->get_status() && self::hasSecureDeleteAuthorization($order_id)) {
			return null;
		}
		return $check;
	}

	private static function createTables(): void
	{
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$bin = $wpdb->prefix . self::SECURE_BIN_TABLE;
		$audit = $wpdb->prefix . self::AUDIT_TABLE;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta("CREATE TABLE {$bin} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_number VARCHAR(100) NOT NULL,
			status_before_delete VARCHAR(64) NOT NULL,
			total DECIMAL(24,8) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			customer_email VARCHAR(190) NOT NULL DEFAULT '',
			created_gmt DATETIME NULL,
			archived_at_gmt DATETIME NOT NULL,
			archived_by_user_id BIGINT UNSIGNED NULL,
			snapshot_json LONGTEXT NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY order_id (order_id)
		) {$charset};");
		dbDelta("CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(64) NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			context_json LONGTEXT NULL,
			created_at_gmt DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY created_at_gmt (created_at_gmt)
		) {$charset};");
	}

	private static function archiveOrderToSecureBin(\WC_Order $order, int $actor_user_id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . self::SECURE_BIN_TABLE;
		$order_id = (int) $order->get_id();
		$items = array();
		foreach ($order->get_items() as $item_id => $item) {
			$items[] = array(
				'item_id' => (int) $item_id,
				'name' => $item->get_name(),
				'type' => $item->get_type(),
				'qty' => method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : null,
				'total' => (float) $item->get_total(),
			);
		}
		$snapshot = array(
			'order_data' => $order->get_data(),
			'post' => (array) get_post($order_id),
			'post_meta' => get_post_meta($order_id),
			'items' => $items,
		);
		$new_payload = array(
			'order_id' => $order_id,
			'order_number' => (string) $order->get_order_number(),
			'status_before_delete' => (string) $order->get_status(),
			'total' => (float) $order->get_total(),
			'currency' => (string) $order->get_currency(),
			'customer_email' => (string) $order->get_billing_email(),
			'created_gmt' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
			'archived_at_gmt' => gmdate('Y-m-d H:i:s'),
			'archived_by_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
			'snapshot_json' => wp_json_encode($snapshot),
		);

		$existing = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id),
			ARRAY_A
		);

		// Už archivované: porovnat starý/new snapshot a případně updatovat.
		if (is_array($existing) && ! empty($existing)) {
			if (! self::shouldUpdateSecureBinSnapshot($existing, $new_payload)) {
				return true;
			}

			$updated = $wpdb->update(
				$table,
				array(
					'order_number' => $new_payload['order_number'],
					'status_before_delete' => $new_payload['status_before_delete'],
					'total' => $new_payload['total'],
					'currency' => $new_payload['currency'],
					'customer_email' => $new_payload['customer_email'],
					'created_gmt' => $new_payload['created_gmt'],
					'archived_at_gmt' => $new_payload['archived_at_gmt'],
					'archived_by_user_id' => $new_payload['archived_by_user_id'],
					'snapshot_json' => $new_payload['snapshot_json'],
				),
				array('order_id' => $order_id),
				array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s'),
				array('%d')
			);

			return false !== $updated;
		}

		$inserted = $wpdb->insert(
			$table,
			$new_payload,
			array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s')
		);

		return false !== $inserted;
	}

	private static function shouldUpdateSecureBinSnapshot(array $existing, array $new_payload): bool
	{
		$existing_snapshot_raw = isset($existing['snapshot_json']) ? (string) $existing['snapshot_json'] : '';
		$new_snapshot_raw = isset($new_payload['snapshot_json']) ? (string) $new_payload['snapshot_json'] : '';

		if ('' === $existing_snapshot_raw && '' !== $new_snapshot_raw) {
			return true;
		}

		$existing_snapshot = json_decode($existing_snapshot_raw, true);
		$new_snapshot = json_decode($new_snapshot_raw, true);

		$existing_items = is_array($existing_snapshot) && isset($existing_snapshot['items']) && is_array($existing_snapshot['items']) ? count($existing_snapshot['items']) : 0;
		$new_items = is_array($new_snapshot) && isset($new_snapshot['items']) && is_array($new_snapshot['items']) ? count($new_snapshot['items']) : 0;
		if ($new_items > $existing_items) {
			return true;
		}

		$existing_meta = is_array($existing_snapshot) && isset($existing_snapshot['post_meta']) && is_array($existing_snapshot['post_meta']) ? count($existing_snapshot['post_meta']) : 0;
		$new_meta = is_array($new_snapshot) && isset($new_snapshot['post_meta']) && is_array($new_snapshot['post_meta']) ? count($new_snapshot['post_meta']) : 0;
		if ($new_meta > $existing_meta) {
			return true;
		}

		$existing_size = strlen($existing_snapshot_raw);
		$new_size = strlen($new_snapshot_raw);
		if ($new_size > $existing_size) {
			return true;
		}

		$critical_keys = array('order_number', 'status_before_delete', 'total', 'currency', 'customer_email', 'created_gmt');
		foreach ($critical_keys as $key) {
			$old = isset($existing[$key]) ? (string) $existing[$key] : '';
			$new = isset($new_payload[$key]) ? (string) $new_payload[$key] : '';
			if ($old !== $new) {
				return true;
			}
		}

		return false;
	}

	private static function audit(string $event_type, array $context, ?int $user_id, ?int $order_id): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::AUDIT_TABLE;
		$wpdb->insert(
			$table,
			array(
				'event_type' => sanitize_key($event_type),
				'order_id' => $order_id ?: null,
				'actor_user_id' => $user_id ?: null,
				'context_json' => wp_json_encode($context),
				'created_at_gmt' => gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%d', '%d', '%s', '%s')
		);
	}

	public static function blockStockReductionForManualReview(bool $can_reduce, mixed $order): bool
	{
		if ($order instanceof \WC_Order && self::STATUS_SLUG === $order->get_status()) {
			return false;
		}
		return $can_reduce;
	}

	public static function releaseStockOnManualReviewTransition(int $order_id, string $from, string $to, mixed $order): void
	{
		if (self::STATUS_SLUG !== $to) {
			return;
		}
		if (! $order instanceof \WC_Order) {
			$order = wc_get_order($order_id);
		}
		if ($order instanceof \WC_Order && function_exists('wc_release_stock_for_order')) {
			wc_release_stock_for_order($order);
		}
	}

	public static function disableWooAutoCancelUnpaid(): void
	{
		remove_action('woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders');
	}

	public static function registerTenMinuteCron(array $schedules): array
	{
		if (! isset($schedules[self::CRON_RECURRENCE_NAME])) {
			$schedules[self::CRON_RECURRENCE_NAME] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => __('Every 10 Minutes', 'ar-design-order-review-guard'));
		}
		return $schedules;
	}

	public static function moveStaleUnpaidOrdersToManualReview(): void
	{
		if (! function_exists('wc_get_orders')) {
			return;
		}
		$before = gmdate('Y-m-d H:i:s', time() - (self::STALE_MINUTES * MINUTE_IN_SECONDS));
		$orders = wc_get_orders(array('type' => 'shop_order', 'status' => array('pending', 'on-hold', 'processing'), 'limit' => 100, 'return' => 'objects', 'date_created' => '<' . $before));
		foreach ($orders as $order) {
			if (! $order instanceof \WC_Order || $order->is_paid() || self::STATUS_SLUG === $order->get_status()) {
				continue;
			}
			if (self::isManualReviewReturnProtected($order)) {
				continue;
			}
			$risk = self::evaluateOrderRisk($order);
			$threshold = self::resolveRiskThreshold($order);
			if ($risk['score'] < $threshold) {
				continue;
			}
			$order->update_status(self::STATUS_SLUG, self::buildManualReviewReason($risk, $threshold, false), true);
			if (function_exists('wc_release_stock_for_order')) {
				wc_release_stock_for_order($order);
			}
		}

		update_option(self::OPTION_LAST_CRON_COMPLETED_AT_GMT, gmdate('Y-m-d H:i:s'), false);
	}

	public static function trackManualReviewLifecycleFlags(int $order_id, string $from, string $to, mixed $order): void
	{
		if (! $order instanceof \WC_Order) {
			$order = wc_get_order($order_id);
		}
		if (! $order instanceof \WC_Order) {
			return;
		}

		if (self::STATUS_SLUG === $to) {
			$order->update_meta_data(self::META_MANUAL_REVIEW_SEEN, '1');
			$order->save_meta_data();
			return;
		}

		if (self::STATUS_SLUG === $from && self::STATUS_SLUG !== $to) {
			$order->update_meta_data(self::META_MANUAL_REVIEW_SEEN, '1');
			$order->update_meta_data(self::META_MANUAL_REVIEW_RETURNED, '1');
			$order->update_meta_data(self::META_MANUAL_REVIEW_RETURNED . '_at_gmt', gmdate('Y-m-d H:i:s'));
			$order->save_meta_data();
		}
	}

	private static function isManualReviewReturnProtected(\WC_Order $order): bool
	{
		$seen = (string) $order->get_meta(self::META_MANUAL_REVIEW_SEEN, true);
		$returned = (string) $order->get_meta(self::META_MANUAL_REVIEW_RETURNED, true);
		return ('1' === $seen) && ('1' === $returned);
	}

	private static function evaluateOrderRisk(\WC_Order $order): array
	{
		$score = 0;
		$reasons = array();
		$total = (float) $order->get_total();
		$limit = self::resolveHighTotalLimit($order);
		if ($total >= $limit) {
			$score += 2;
			$reasons[] = 'vyšší hodnota objednávky';
		}
		if (0 === (int) $order->get_customer_id()) {
			$score += 1;
			$reasons[] = 'host objednávka';
		}
		$payment_method = (string) $order->get_payment_method();
		if (in_array($payment_method, array('cod', 'bacs', 'cheque'), true)) {
			$score += 2;
			$reasons[] = 'riziková platební metoda';
		}
		if ('' === (string) $order->get_customer_ip_address()) {
			$score += 1;
			$reasons[] = 'chybějící IP';
		}
		if (self::isNightWindow()) {
			$score += 1;
			$reasons[] = 'noční režim';
		}
		return array('score' => $score, 'reasons' => $reasons);
	}

	private static function buildManualReviewReason(array $risk, int $threshold, bool $force): string
	{
		return sprintf('Automaticky přesunuto do manuální kontroly. Risk score: %d (threshold: %d).', (int) $risk['score'], $threshold);
	}

	private static function resolveRiskThreshold(\WC_Order $order): int
	{
		return self::isNightWindow() ? self::NIGHT_RISK_THRESHOLD : self::DEFAULT_RISK_THRESHOLD;
	}

	private static function resolveHighTotalLimit(\WC_Order $order): float
	{
		return self::isNightWindow() ? self::NIGHT_HIGH_TOTAL_LIMIT : 120.0;
	}

	private static function isNightWindow(): bool
	{
		$hour = (int) wp_date('G');
		return $hour >= 22 || $hour < 6;
	}

	private static function collectManualReviewStats(): array
	{
		if (! function_exists('wc_get_orders')) {
			return array('totals' => array('all' => 0, 'night' => 0, 'day' => 0), 'daily' => array());
		}
		$orders = wc_get_orders(array('type' => 'shop_order', 'status' => array(self::STATUS_SLUG), 'limit' => 1000, 'return' => 'objects'));
		$totals = array('all' => 0, 'night' => 0, 'day' => 0);
		foreach ($orders as $order) {
			if (! $order instanceof \WC_Order || ! $order->get_date_created()) {
				continue;
			}
			$totals['all']++;
			$hour = (int) wp_date('G', $order->get_date_created()->getTimestamp());
			if ($hour >= 22 || $hour < 6) {
				$totals['night']++;
			} else {
				$totals['day']++;
			}
		}
		return array('totals' => $totals, 'daily' => array());
	}

	private static function formatLocalDateTimeFromTimestamp(int $timestamp): string
	{
		$format = (string) (get_option('date_format') . ' ' . get_option('time_format'));
		return wp_date($format, $timestamp);
	}

	private static function formatLocalDateTimeFromGmtString(string $gmt_datetime): string
	{
		$timestamp = strtotime($gmt_datetime . ' UTC');
		if (false === $timestamp) {
			return $gmt_datetime;
		}
		return self::formatLocalDateTimeFromTimestamp((int) $timestamp);
	}

	private static function getManualReviewCronSummary(): string
	{
		$last_completed_gmt = (string) get_option(self::OPTION_LAST_CRON_COMPLETED_AT_GMT, '');
		$next_scheduled = wp_next_scheduled(self::CRON_HOOK_NAME);

		$last_label = '' !== $last_completed_gmt
			? self::formatLocalDateTimeFromGmtString($last_completed_gmt)
			: __('zatím neproběhlo', 'ar-design-order-review-guard');
		$next_label = false !== $next_scheduled
			? self::formatLocalDateTimeFromTimestamp((int) $next_scheduled)
			: __('nenaplánováno', 'ar-design-order-review-guard');

		return sprintf(
			/* translators: 1: last completed cron datetime or fallback, 2: next scheduled cron datetime or fallback. */
			__('Posledný dokončený WP CRON: %1$s (ďalšie plánované spustenie: %2$s)', 'ar-design-order-review-guard'),
			$last_label,
			$next_label
		);
	}
}

ArDesign\OrderReviewGuard\Application\Bootstrap::boot()->run();
register_activation_hook(ARDRG_FILE, array('ArDesign\\OrderReviewGuard\\Application\\Bootstrap', 'activate'));
register_deactivation_hook(ARDRG_FILE, array('ArDesign\\OrderReviewGuard\\Application\\Bootstrap', 'deactivate'));
