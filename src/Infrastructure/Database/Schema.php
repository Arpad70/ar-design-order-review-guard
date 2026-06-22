<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Infrastructure\Database;

defined('ABSPATH') || exit;

final class Schema
{
	/**
	 * @return string[]
	 */
	public function getStatements(): array
	{
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$bin = $wpdb->prefix . 'ardrg_secure_bin_orders';
		$audit = $wpdb->prefix . 'ardrg_secure_bin_audit';

		return array(
			"CREATE TABLE {$bin} (
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
			) {$charset};",
			"CREATE TABLE {$audit} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(64) NOT NULL,
				order_id BIGINT UNSIGNED NULL,
				actor_user_id BIGINT UNSIGNED NULL,
				context_json LONGTEXT NULL,
				created_at_gmt DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY event_type (event_type),
				KEY created_at_gmt (created_at_gmt)
			) {$charset};",
		);
	}
}
