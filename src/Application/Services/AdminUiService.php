<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

require_once __DIR__ . '/ManualReviewWorkflowService.php';
require_once __DIR__ . '/OrderStatusManager.php';
require_once dirname(__DIR__, 2) . '/Support/DateTimeFormatter.php';
require_once dirname(__DIR__, 2) . '/Support/OrdersListNavigator.php';

final class AdminUiService
{
	public static function registerBulkAction(array $actions, string $statusSlug): array
	{
		$actions['mark_' . $statusSlug] = __('Změnit na Manuální kontrola', 'ar-design-order-review-guard');
		$actions['ardrg_bulk_secure_bin'] = __('Secure Bin: Vymazat označené (tajné heslo)', 'ar-design-order-review-guard');

		return $actions;
	}

	public static function handleBulkAction(string $redirect_to, string $action, array $ids, string $statusSlug): string
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

		if ('mark_' . $statusSlug !== $action) {
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

			if ($statusSlug === $order->get_status()) {
				continue;
			}

			$order->update_status($statusSlug, __('Hromadná akce: přesun do Manuální kontroly.', 'ar-design-order-review-guard'), true);
			$updated++;
		}

		return add_query_arg('ardrg_bulk_marked', (string) $updated, $redirect_to);
	}

	public static function registerAdminActionButton(array $actions, mixed $order, string $statusSlug): array
	{
		if (! $order instanceof \WC_Order) {
			return $actions;
		}

		if ($statusSlug !== $order->get_status()) {
			$actions[$statusSlug] = array(
				'url' => wp_nonce_url(admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=' . $statusSlug . '&order_id=' . $order->get_id()), 'woocommerce-mark-order-status'),
				'name' => __('Manuální kontrola', 'ar-design-order-review-guard'),
				'action' => $statusSlug,
			);

			return $actions;
		}

		$actions['ardrg_secure_bin'] = array(
			'url' => self::getOrderSecureBinUrl((int) $order->get_id(), $statusSlug),
			'name' => __('Secure Bin', 'ar-design-order-review-guard'),
			'action' => 'trash',
		);

		return $actions;
	}

	public static function renderAdminReportPage(string $managerEmailOption, string $secretChangedAtOption, string $auditTable, string $statusSlug, string $cronHookName): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Nedostatečná oprávnění.', 'ar-design-order-review-guard'));
		}

		$manager_email = (string) get_option($managerEmailOption, get_option('admin_email'));
		$secret_changed_at = (int) get_option($secretChangedAtOption, 0);
		$stats = ManualReviewWorkflowService::collectManualReviewStats($statusSlug);
		$recent_ops = self::getRecentSecureBinOperations($auditTable, 30);

		echo '<div class="wrap"><h1>AR Order Review Guard</h1>';
		self::renderAdminNotices();
		self::renderSecureBinFormIfRequested($statusSlug);
		self::renderBulkSecureBinFormIfRequested($statusSlug);

		echo '<h2>Secure Bin nastavení</h2>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:760px;background:#fff;padding:16px;border:1px solid #ccd0d4;">';
		echo '<input type="hidden" name="action" value="ardrg_generate_secret" />';
		wp_nonce_field('ardrg_generate_secret');
		echo '<p><label><strong>Manažerský e-mail</strong></label><br /><input type="email" class="regular-text" name="manager_email" value="' . esc_attr($manager_email) . '" required /></p>';
		if ($secret_changed_at > 0) {
			echo '<p><em>Poslední změna hesla: ' . esc_html(\ArDesign\OrderReviewGuard\Support\DateTimeFormatter::formatLocalDateTimeFromTimestamp($secret_changed_at)) . '</em></p>';
		}
		submit_button('Vygenerovat nové tajné heslo', 'primary', 'submit', false);
		echo '</form>';

		echo '<h2 style="margin-top:24px;display:flex;align-items:baseline;gap:10px;">' . esc_html__('Prehľad manuálnej kontroly', 'ar-design-order-review-guard') . ' <span style="font-size:13px;font-weight:400;color:#50575e;">' . esc_html(\ArDesign\OrderReviewGuard\Support\DateTimeFormatter::getManualReviewCronSummary($cronHookName)) . '</span></h2>';
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
				$local_time = '' !== $created_at ? \ArDesign\OrderReviewGuard\Support\DateTimeFormatter::formatLocalDateTimeFromGmtString($created_at) : '';
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

	public static function renderOrderMetaBox(\WP_Post $post, string $statusSlug): void
	{
		$order_id = (int) $post->ID;
		$order = wc_get_order($order_id);
		if (! $order instanceof \WC_Order) {
			echo '<p>' . esc_html__('Objednávka nebyla načtena.', 'ar-design-order-review-guard') . '</p>';
			return;
		}

		if ($statusSlug !== $order->get_status()) {
			echo '<p>' . esc_html__('Tlačítko je dostupné jen pro stav Manuální kontrola.', 'ar-design-order-review-guard') . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__('Bezpečné vymazání objednávky', 'ar-design-order-review-guard') . '</strong></p>';
		echo '<p>' . esc_html__('Objednávka bude archivována do Secure Bin tabulky a trvale smazána z WooCommerce.', 'ar-design-order-review-guard') . '</p>';
		self::renderSecureBinInlineForm($order_id, $statusSlug);
	}

	public static function renderOrderEditInlinePanel(mixed $order, string $statusSlug): void
	{
		if (! $order instanceof \WC_Order) {
			return;
		}

		if ($statusSlug !== $order->get_status()) {
			return;
		}

		$order_id = (int) $order->get_id();
		echo '<div class="order_data_column" style="width:100%;padding-top:8px;">';
		echo '<h4>' . esc_html__('AR Review Guard: Secure Bin', 'ar-design-order-review-guard') . '</h4>';
		echo '<p>' . esc_html__('Objednávku lze trvale vymazat jen přes tajné heslo.', 'ar-design-order-review-guard') . '</p>';
		self::renderSecureBinInlineForm($order_id, $statusSlug);
		echo '</div>';
	}

	public static function getOrderSecureBinUrl(int $order_id, string $statusSlug): string
	{
		$order_id = absint($order_id);
		$nonce = wp_create_nonce('ardrg_secure_bin_form_' . $order_id);
		$page_url = admin_url('admin.php?page=ar-order-review-guard&secure_bin_order_id=' . $order_id . '&_wpnonce=' . $nonce);
		$return_to = \ArDesign\OrderReviewGuard\Support\OrdersListNavigator::resolveOrdersListReturnUrl($statusSlug);
		if ('' !== $return_to) {
			$page_url = add_query_arg('ardrg_return_to', rawurlencode($return_to), $page_url);
		}

		$hpos_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&ardrg_secure_bin_order_id=' . $order_id . '&ardrg_secure_bin_nonce=' . $nonce);
		if ('' !== $return_to) {
			$hpos_url = add_query_arg('ardrg_return_to', rawurlencode($return_to), $hpos_url);
		}

		if (isset($_GET['page']) && 'wc-orders' === (string) $_GET['page']) {
			return $hpos_url;
		}

		return $page_url;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function getRecentSecureBinOperations(string $auditTable, int $limit = 30): array
	{
		global $wpdb;
		$table = $wpdb->prefix . $auditTable;
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

	private static function renderSecureBinFormIfRequested(string $statusSlug): void
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
		if (! $order instanceof \WC_Order || $statusSlug !== $order->get_status()) {
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

	private static function renderBulkSecureBinFormIfRequested(string $statusSlug): void
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
		$return_to = \ArDesign\OrderReviewGuard\Support\OrdersListNavigator::sanitizeOrdersListReturnUrl($return_to, $statusSlug);

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

	private static function renderSecureBinInlineForm(int $order_id, string $statusSlug): void
	{
		$return_to = \ArDesign\OrderReviewGuard\Support\OrdersListNavigator::resolveOrdersListReturnUrl($statusSlug);
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
}