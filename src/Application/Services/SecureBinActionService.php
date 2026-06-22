<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/Security/DeleteAuthorizationManager.php';
require_once dirname(__DIR__, 2) . '/Infrastructure/Audit/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/Support/DateTimeFormatter.php';
require_once dirname(__DIR__, 2) . '/Support/OrderProductSummaryBuilder.php';
require_once dirname(__DIR__, 2) . '/Support/OrdersListNavigator.php';
require_once __DIR__ . '/SecureBinArchiver.php';

final class SecureBinActionService
{
	public static function handleGenerateSecret(string $managerEmailOption, string $secretHashOption, string $secretChangedAtOption, string $auditTable): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}

		check_admin_referer('ardrg_generate_secret');
		$user_id = get_current_user_id();
		$old_email = (string) get_option($managerEmailOption, '');
		$new_email = sanitize_email((string) wp_unslash($_POST['manager_email'] ?? ''));
		if ('' === $new_email || ! is_email($new_email)) {
			wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=secret_mail_failed'));
			exit;
		}

		update_option($managerEmailOption, $new_email, false);
		if ($old_email !== $new_email) {
			self::audit('manager_email_changed', array('old_email' => $old_email, 'new_email' => $new_email), $user_id, null, $auditTable);
		}

		$secret = wp_generate_password(16, false, false);
		update_option($secretHashOption, wp_hash_password($secret), false);
		update_option($secretChangedAtOption, time(), false);
		self::audit('secret_generated', array('target_email' => $new_email), $user_id, null, $auditTable);

		$mail_ok = wp_mail($new_email, 'AR Review Guard - nové tajné heslo', "Nové heslo: {$secret}\nVygenerováno: " . \ArDesign\OrderReviewGuard\Support\DateTimeFormatter::formatLocalDateTimeFromTimestamp(time()));
		wp_safe_redirect(admin_url('admin.php?page=ar-order-review-guard&ardrg_notice=' . ($mail_ok ? 'secret_generated' : 'secret_mail_failed')));
		exit;
	}

	public static function handleSecureBinOrder(string $statusSlug, string $secretHashOption, string $secureBinTable, string $auditTable, string $secureDeleteTokenPrefix): void
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
		$hash = (string) get_option($secretHashOption, '');
		if (! $order instanceof \WC_Order || $statusSlug !== $order->get_status() || '' === $hash || ! wp_check_password($secret, $hash)) {
			self::audit('secure_bin_failed', array('reason' => 'validation_failed'), $user_id, $order_id, $auditTable);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_wrong_secret', $return_to, $statusSlug));
			exit;
		}

		$product_summary = \ArDesign\OrderReviewGuard\Support\OrderProductSummaryBuilder::build($order);

		if (! SecureBinArchiver::archiveOrderToSecureBin($order, $user_id, $secureBinTable)) {
			self::audit('secure_bin_failed', array('reason' => 'archive_failed'), $user_id, $order_id, $auditTable);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_error', $return_to, $statusSlug));
			exit;
		}

		\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::setSecureDeleteAuthorization($order_id, $user_id, $secureDeleteTokenPrefix);
		$deleted = SecureBinArchiver::forceDeleteOrder($order_id, $order);
		\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::clearSecureDeleteAuthorization($order_id, $user_id, $secureDeleteTokenPrefix);
		if (! $deleted) {
			self::audit('secure_bin_failed', array('reason' => 'delete_failed'), $user_id, $order_id, $auditTable);
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_error', $return_to, $statusSlug));
			exit;
		}

		SecureBinArchiver::purgeArDesignReportingTrailAndLogDelete(
			$order_id,
			(float) $order->get_total(),
			(string) $order->get_currency(),
			$product_summary,
			$user_id
		);

		self::audit('secure_bin_success', array('status_before_delete' => $statusSlug), $user_id, $order_id, $auditTable);
		wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_done', $return_to, $statusSlug));
		exit;
	}

	public static function handleBulkSecureBinOrders(string $statusSlug, string $secretHashOption, string $secureBinTable, string $auditTable, string $secureDeleteTokenPrefix): void
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
		$hash = (string) get_option($secretHashOption, '');
		if ('' === $hash || ! wp_check_password($secret, $hash)) {
			wp_safe_redirect(self::buildPostSecureBinRedirectUrl('secure_bin_wrong_secret', $return_to, $statusSlug));
			exit;
		}

		$processed = 0;
		$deleted = 0;
		$failed = 0;

		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);
			if (! $order instanceof \WC_Order || $statusSlug !== $order->get_status()) {
				continue;
			}

			$processed++;
			$product_summary = \ArDesign\OrderReviewGuard\Support\OrderProductSummaryBuilder::build($order);
			if (! SecureBinArchiver::archiveOrderToSecureBin($order, $user_id, $secureBinTable)) {
				self::audit('secure_bin_failed', array('reason' => 'archive_failed_bulk'), $user_id, $order_id, $auditTable);
				$failed++;
				continue;
			}

			\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::setSecureDeleteAuthorization($order_id, $user_id, $secureDeleteTokenPrefix);
			$did_delete = SecureBinArchiver::forceDeleteOrder($order_id, $order);
			\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::clearSecureDeleteAuthorization($order_id, $user_id, $secureDeleteTokenPrefix);

			if (! $did_delete) {
				self::audit('secure_bin_failed', array('reason' => 'delete_failed_bulk'), $user_id, $order_id, $auditTable);
				$failed++;
				continue;
			}

			SecureBinArchiver::purgeArDesignReportingTrailAndLogDelete(
				$order_id,
				(float) $order->get_total(),
				(string) $order->get_currency(),
				$product_summary,
				$user_id
			);
			self::audit('secure_bin_success', array('status_before_delete' => $statusSlug, 'bulk' => true), $user_id, $order_id, $auditTable);
			$deleted++;
		}

		$target = \ArDesign\OrderReviewGuard\Support\OrdersListNavigator::sanitizeOrdersListReturnUrl($return_to, $statusSlug);
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

	private static function buildPostSecureBinRedirectUrl(string $notice, string $returnToRaw, string $statusSlug): string
	{
		return \ArDesign\OrderReviewGuard\Support\OrdersListNavigator::buildPostSecureBinRedirectUrl($notice, $returnToRaw, $statusSlug);
	}

	private static function audit(string $event_type, array $context, ?int $user_id, ?int $order_id, string $auditTable): void
	{
		\ArDesign\OrderReviewGuard\Infrastructure\Audit\AuditLogger::audit($event_type, $context, $user_id, $order_id, $auditTable);
	}
}