<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Infrastructure\Audit;

defined('ABSPATH') || exit;

final class AuditLogger
{
	public static function audit(string $event_type, array $context, ?int $user_id, ?int $order_id, string $auditTable): void
	{
		global $wpdb;

		$table = $wpdb->prefix . $auditTable;
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
}