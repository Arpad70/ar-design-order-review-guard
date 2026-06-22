<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

final class SecureBinArchiver
{
	public static function forceDeleteOrder(int $order_id, \WC_Order $order): bool
	{
		if (function_exists('wc_delete_order')) {
			$result = \wc_delete_order($order_id, true);

			return ! empty($result);
		}

		if (method_exists($order, 'delete')) {
			$order->delete(true);
			$check = \wc_get_order($order_id);

			return ! $check instanceof \WC_Order;
		}

		$result = \wp_delete_post($order_id, true);

		return ! empty($result);
	}

	public static function purgeArDesignReportingTrailAndLogDelete(int $order_id, float $total, string $currency, array $product_summary, int $actor_user_id): void
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
				'old_value_json' => \wp_json_encode(array()),
				'new_value_json' => \wp_json_encode(
					array(
						'total' => round($total, 2),
						'currency' => $currency,
						'product_ids' => $product_summary['product_ids'],
					)
				),
				'context_json' => \wp_json_encode(
					array(
						'source' => 'secure_bin_delete',
						'products_summary' => $product_summary,
					)
				),
				'created_at_gmt' => \gmdate('Y-m-d H:i:s'),
			),
			array('%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
		);
	}

	public static function archiveOrderToSecureBin(\WC_Order $order, int $actor_user_id, string $secureBinTable): bool
	{
		global $wpdb;

		$table = $wpdb->prefix . $secureBinTable;
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
			'post' => (array) \get_post($order_id),
			'post_meta' => \get_post_meta($order_id),
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
			'archived_at_gmt' => \gmdate('Y-m-d H:i:s'),
			'archived_by_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
			'snapshot_json' => \wp_json_encode($snapshot),
		);

		$existing = $wpdb->get_row(
			$wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id),
			ARRAY_A
		);

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

	/**
	 * @param array<string, mixed> $existing
	 * @param array<string, mixed> $new_payload
	 */
	public static function shouldUpdateSecureBinSnapshot(array $existing, array $new_payload): bool
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
}