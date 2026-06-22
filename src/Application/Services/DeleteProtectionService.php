<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/Security/DeleteAuthorizationManager.php';
require_once dirname(__DIR__, 2) . '/Infrastructure/Audit/AuditLogger.php';

final class DeleteProtectionService
{
	public static function preventPermanentDelete(mixed $delete, \WP_Post $post, string $secureDeleteTokenPrefix, string $deleteBlockedTransientPrefix, string $auditTable): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}

		if (\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::hasSecureDeleteAuthorization((int) $post->ID, $secureDeleteTokenPrefix)) {
			return $delete;
		}

		self::blockDeleteAttempt((int) $post->ID, 'delete', 'pre_delete_post', $deleteBlockedTransientPrefix, $auditTable);

		return false;
	}

	public static function preventTrashOrder(mixed $trash, \WP_Post $post, mixed $previous_status, string $secureDeleteTokenPrefix, string $deleteBlockedTransientPrefix, string $auditTable): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}

		if (\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::hasSecureDeleteAuthorization((int) $post->ID, $secureDeleteTokenPrefix)) {
			return $trash;
		}

		self::blockDeleteAttempt((int) $post->ID, 'trash', 'pre_trash_post', $deleteBlockedTransientPrefix, $auditTable);

		return false;
	}

	public static function preventWooOrderDelete(mixed $check, mixed $order, bool $force_delete, string $secureDeleteTokenPrefix, string $deleteBlockedTransientPrefix, string $auditTable): mixed
	{
		if (! $order instanceof \WC_Order) {
			return $check;
		}

		$order_id = (int) $order->get_id();
		if (\ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::hasSecureDeleteAuthorization($order_id, $secureDeleteTokenPrefix)) {
			return $check;
		}

		self::blockDeleteAttempt($order_id, $force_delete ? 'delete' : 'trash', 'woocommerce_pre_delete_order', $deleteBlockedTransientPrefix, $auditTable);

		return false;
	}

	private static function blockDeleteAttempt(int $order_id, string $attempt, string $source, string $deleteBlockedTransientPrefix, string $auditTable): void
	{
		if ($order_id <= 0) {
			return;
		}

		$actor_user_id = get_current_user_id() ?: null;
		$attempt = sanitize_key($attempt);
		if (! in_array($attempt, array('delete', 'trash'), true)) {
			$attempt = 'delete';
		}

		self::storeDeleteBlockedNotice($order_id, $attempt, $actor_user_id, $deleteBlockedTransientPrefix);
		\ArDesign\OrderReviewGuard\Infrastructure\Audit\AuditLogger::audit('delete_attempt_blocked', array('attempt' => $attempt, 'source' => $source), $actor_user_id, $order_id, $auditTable);
		self::logReportingDeleteBlock($order_id, $attempt, $source, $actor_user_id);
	}

	private static function storeDeleteBlockedNotice(int $order_id, string $attempt, ?int $actor_user_id, string $deleteBlockedTransientPrefix): void
	{
		if (null === $actor_user_id || $actor_user_id <= 0) {
			return;
		}

		set_transient(
			$deleteBlockedTransientPrefix . $actor_user_id,
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
}