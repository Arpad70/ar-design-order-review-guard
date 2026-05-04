<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Infrastructure\Database;

final class Migrator
{
	private Schema $schema;

	public function __construct(Schema $schema)
	{
		$this->schema = $schema;
	}

	public function migrate(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ($this->schema->getStatements() as $statement) {
			dbDelta($statement);
		}
		update_option('ardrg_db_version', ARDRG_DB_VERSION);
	}

	/**
	 * @return string[]
	 */
	public function getMissingTables(): array
	{
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'ardrg_secure_bin_orders',
			$wpdb->prefix . 'ardrg_secure_bin_audit',
		);
		$missing = array();
		foreach ($tables as $table) {
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			if ($found !== $table) {
				$missing[] = $table;
			}
		}
		return $missing;
	}
}
