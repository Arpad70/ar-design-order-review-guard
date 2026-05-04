<?php
/**
 * Plugin Name: AR Design Order Review Guard
 * Description: Nahrádza auto-rušenie nezaplatených objednávok bezpečným mezistavom pre manuálnu kontrolu bez rezervácie a odpočtu skladu.
 * Version: 0.2.0
 * Author: AR Design
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
	exit;
}

final class ArDesignOrderReviewGuard
{
	private const STATUS_SLUG = 'manual-review';
	private const CRON_HOOK = 'ar_design_move_unpaid_to_manual_review';
	private const CRON_RECURRENCE = 'ar_design_every_ten_minutes';
	private const STALE_MINUTES = 45;
	private const DEFAULT_RISK_THRESHOLD = 4;
	private const NIGHT_RISK_THRESHOLD = 3;
	private const NIGHT_HIGH_TOTAL_LIMIT = 80.0;
	private const OPTION_MANAGER_EMAIL = 'ardrg_manager_email';
	private const OPTION_SECRET_HASH = 'ardrg_secret_hash';
	private const OPTION_SECRET_CHANGED_AT = 'ardrg_secret_changed_at';
	private const SECURE_BIN_TABLE = 'ardrg_secure_bin_orders';
	private const AUDIT_TABLE = 'ardrg_secure_bin_audit';
	private const SECURE_DELETE_TOKEN_PREFIX = 'ardrg_secure_delete_';

	public static function bootstrap(): void
	{
		add_action('init', array(__CLASS__, 'registerStatus'));
		add_action('admin_menu', array(__CLASS__, 'registerAdminReportPage'), 99);
		add_action('admin_post_ardrg_generate_secret', array(__CLASS__, 'handleGenerateSecret'));
		add_action('admin_post_ardrg_secure_bin_order', array(__CLASS__, 'handleSecureBinOrder'));

		add_filter('wc_order_statuses', array(__CLASS__, 'registerStatusInLists'));
		add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'registerBulkAction'));
		add_filter('woocommerce_admin_order_actions', array(__CLASS__, 'registerAdminActionButton'), 20, 2);

		add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'blockStockReductionForManualReview'), 10, 2);
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'releaseStockOnManualReviewTransition'), 20, 4);
		add_action('woocommerce_loaded', array(__CLASS__, 'disableWooAutoCancelUnpaid'), 30);

		add_filter('cron_schedules', array(__CLASS__, 'registerTenMinuteCron'));
		add_action(self::CRON_HOOK, array(__CLASS__, 'moveStaleUnpaidOrdersToManualReview'));

		// High-priority override for AR Design Reporting deletion blockers.
		add_filter('pre_delete_post', array(__CLASS__, 'allowAuthorizedSecureDeletePreDeletePost'), 999, 2);
		add_filter('pre_trash_post', array(__CLASS__, 'allowAuthorizedSecureDeletePreTrashPost'), 999, 3);
		add_filter('woocommerce_pre_delete_order', array(__CLASS__, 'allowAuthorizedSecureDeleteWoo'), 999, 3);
	}

	public static function activate(): void
	{
		self::createTables();
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK);
		}
	}

	public static function deactivate(): void
	{
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		while (false !== $timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	public static function registerStatus(): void
	{
		register_post_status(
			'wc-' . self::STATUS_SLUG,
			array(
				'label' => _x('Manuální kontrola', 'Order status', 'ar-design-order-review-guard'),
				'public' => true,
				'exclude_from_search' => false,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				'label_count' => _n_noop('Manuální kontrola <span class="count">(%s)</span>', 'Manuální kontrola <span class="count">(%s)</span>', 'ar-design-order-review-guard'),
			)
		);
	}

	public static function registerStatusInLists(array $statuses): array
	{
		$result = array();
		$inserted = false;
		foreach ($statuses as $key => $label) {
			$result[$key] = $label;
			if ('wc-pending' === $key) {
				$result['wc-' . self::STATUS_SLUG] = __('Manuální kontrola', 'ar-design-order-review-guard');
				$inserted = true;
			}
		}
		if (! $inserted) {
			$result['wc-' . self::STATUS_SLUG] = __('Manuální kontrola', 'ar-design-order-review-guard');
		}
		return $result;
	}

	public static function registerBulkAction(array $actions): array
	{
		$actions['mark_' . self::STATUS_SLUG] = __('Změnit na Manuální kontrola', 'ar-design-order-review-guard');
		return $actions;
	}

	public static function registerAdminActionButton(array $actions, $order): array
	{
		if (! $order instanceof WC_Order) {
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
			'url' => wp_nonce_url(admin_url('admin.php?page=ar-order-review-guard&secure_bin_order_id=' . $order->get_id()), 'ardrg_secure_bin_form_' . $order->get_id()),
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

		echo '<div class="wrap"><h1>AR Order Review Guard</h1>';
		self::renderAdminNotices();
		self::renderSecureBinFormIfRequested();

		echo '<h2>Secure Bin nastavení</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;background:#fff;padding:16px;border:1px solid #ccd0d4;">';
		echo '<input type="hidden" name="action" value="ardrg_generate_secret" />';
		wp_nonce_field('ardrg_generate_secret');
		echo '<p><label><strong>Manažerský e-mail</strong></label><br /><input type="email" class="regular-text" name="manager_email" value="' . esc_attr($manager_email) . '" required /></p>';
		if ($secret_changed_at > 0) {
			echo '<p><em>Poslední změna hesla: ' . esc_html(wp_date('Y-m-d H:i:s', $secret_changed_at)) . '</em></p>';
		}
		submit_button('Vygenerovat nové tajné heslo', 'primary', 'submit', false);
		echo '</form>';

		echo '<h2 style="margin-top:24px;">Přehled manuální kontroly</h2>';
		echo '<table class="widefat striped" style="max-width:920px;"><tbody>';
		echo '<tr><td>Manuální kontrola celkem</td><td><strong>' . esc_html((string) $stats['totals']['all']) . '</strong></td></tr>';
		echo '<tr><td>Noční (22:00-06:00)</td><td>' . esc_html((string) $stats['totals']['night']) . '</td></tr>';
		echo '<tr><td>Denní (06:00-22:00)</td><td>' . esc_html((string) $stats['totals']['day']) . '</td></tr>';
		echo '</tbody></table>';

		echo '</div>';
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
			'secure_bin_error' => array('error', 'Secure Bin operaci se nepodařilo dokončit.'),
		);
		if (! isset($map[$notice])) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr($map[$notice][0]) . '"><p>' . esc_html($map[$notice][1]) . '</p></div>';
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

		$mail_ok = wp_mail($new_email, 'AR Review Guard - nové tajné heslo', "Nové heslo: {$secret}\nVygenerováno: " . wp_date('Y-m-d H:i:s'));
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
		if (! $order instanceof WC_Order || self::STATUS_SLUG !== $order->get_status()) {
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

	public static function handleSecureBinOrder(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}
		$order_id = absint($_POST['order_id'] ?? 0);
		check_admin_referer('ardrg_secure_bin_order_' . $order_id);
		$order = wc_get_order($order_id);
		$user_id = get_current_user_id();

		$secret = (string) wp_unslash($_POST['manager_secret'] ?? '');
		$hash = (string) get_option(self::OPTION_SECRET_HASH, '');
		if (! $order instanceof WC_Order || self::STATUS_SLUG !== $order->get_status() || '' === $hash || ! wp_check_password($secret, $hash)) {
			self::audit('secure_bin_failed', array('reason' => 'validation_failed'), $user_id, $order_id);
			wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secure_bin_error'));
			exit;
		}

		if (! self::archiveOrderToSecureBin($order, $user_id)) {
			self::audit('secure_bin_failed', array('reason' => 'archive_failed'), $user_id, $order_id);
			wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secure_bin_error'));
			exit;
		}

		self::setSecureDeleteAuthorization($order_id, $user_id);
		$deleted = wc_delete_order($order_id, true);
		self::clearSecureDeleteAuthorization($order_id, $user_id);
		if (! $deleted) {
			self::audit('secure_bin_failed', array('reason' => 'delete_failed'), $user_id, $order_id);
			wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secure_bin_error'));
			exit;
		}

		self::audit('secure_bin_success', array('status_before_delete' => self::STATUS_SLUG), $user_id, $order_id);
		wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secure_bin_done'));
		exit;
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

	public static function allowAuthorizedSecureDeletePreDeletePost($delete, WP_Post $post)
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}
		$order = wc_get_order((int) $post->ID);
		if ($order instanceof WC_Order && self::STATUS_SLUG === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return null;
		}
		return $delete;
	}

	public static function allowAuthorizedSecureDeletePreTrashPost($trash, WP_Post $post, $previous_status)
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}
		$order = wc_get_order((int) $post->ID);
		if ($order instanceof WC_Order && self::STATUS_SLUG === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID)) {
			return null;
		}
		return $trash;
	}

	public static function allowAuthorizedSecureDeleteWoo($check, $order, bool $force_delete)
	{
		if (! $order instanceof WC_Order) {
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

	private static function archiveOrderToSecureBin(WC_Order $order, int $actor_user_id): bool
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

		$result = $wpdb->insert(
			$table,
			array(
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
			),
			array('%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s')
		);

		return false !== $result;
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

	public static function blockStockReductionForManualReview(bool $can_reduce, $order): bool
	{
		if ($order instanceof WC_Order && self::STATUS_SLUG === $order->get_status()) {
			return false;
		}
		return $can_reduce;
	}

	public static function releaseStockOnManualReviewTransition(int $order_id, string $from, string $to, $order): void
	{
		if (self::STATUS_SLUG !== $to) {
			return;
		}
		if (! $order instanceof WC_Order) {
			$order = wc_get_order($order_id);
		}
		if ($order instanceof WC_Order && function_exists('wc_release_stock_for_order')) {
			wc_release_stock_for_order($order);
		}
	}

	public static function disableWooAutoCancelUnpaid(): void
	{
		remove_action('woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders');
	}

	public static function registerTenMinuteCron(array $schedules): array
	{
		if (! isset($schedules[self::CRON_RECURRENCE])) {
			$schedules[self::CRON_RECURRENCE] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => __('Every 10 Minutes', 'ar-design-order-review-guard'));
		}
		return $schedules;
	}

	public static function moveStaleUnpaidOrdersToManualReview(): void
	{
		if (! function_exists('wc_get_orders')) {
			return;
		}
		$before = gmdate('Y-m-d H:i:s', time() - (self::STALE_MINUTES * MINUTE_IN_SECONDS));
		$orders = wc_get_orders(array('type' => 'shop_order', 'status' => array('pending', 'on-hold', 'failed'), 'limit' => 100, 'return' => 'objects', 'date_created' => '<' . $before));
		foreach ($orders as $order) {
			if (! $order instanceof WC_Order || $order->is_paid() || self::STATUS_SLUG === $order->get_status()) {
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
	}

	private static function evaluateOrderRisk(WC_Order $order): array
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

	private static function resolveRiskThreshold(WC_Order $order): int
	{
		return self::isNightWindow() ? self::NIGHT_RISK_THRESHOLD : self::DEFAULT_RISK_THRESHOLD;
	}

	private static function resolveHighTotalLimit(WC_Order $order): float
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
			if (! $order instanceof WC_Order || ! $order->get_date_created()) {
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
}

ArDesignOrderReviewGuard::bootstrap();
register_activation_hook(__FILE__, array('ArDesignOrderReviewGuard', 'activate'));
register_deactivation_hook(__FILE__, array('ArDesignOrderReviewGuard', 'deactivate'));

