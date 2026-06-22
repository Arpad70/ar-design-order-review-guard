<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support\Updates;

use ArDesign\Shared\Updates\GitHubPluginUpdater as BaseGitHubPluginUpdater;
use ArDesign\Shared\Updates\PluginRollbackManager as BasePluginRollbackManager;

defined('ABSPATH') || exit;

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/GitHubPluginUpdater.php';
require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/PluginRollbackManager.php';

final class GitHubUpdater extends BaseGitHubPluginUpdater
{
	public function __construct(string $repositoryFullName, string $pluginBasename, string $currentVersion)
	{
		parent::__construct(
			$repositoryFullName,
			$pluginBasename,
			$currentVersion,
			array(
				'plugin_slug' => 'ar-design-order-review-guard',
				'plugin_name' => 'AR Design Order Review Guard',
				'text_domain' => 'ar-design-order-review-guard',
				'description' => 'Order review guard with secure bin workflow for WooCommerce.',
				'author_label' => 'Arpad70',
				'user_agent_slug' => 'ar-design-order-review-guard',
				'cache_key_prefix' => 'ardrg_github_release_data_',
				'preferred_zip_names' => array('ar-design-order-review-guard.zip'),
				'preferred_zip_prefixes' => array('ar-design-order-review-guard-'),
				'include_versioned_slug_zip' => true,
				'allow_any_zip_fallback' => true,
			)
		);
	}
}

final class RollbackManager extends BasePluginRollbackManager
{
	public function __construct(string $pluginBasename, string $pluginRoot)
	{
		parent::__construct(
			$pluginBasename,
			$pluginRoot,
			array(
				'backup_dir' => 'ard-order-review-guard-backups',
				'hash_backup_by_plugin_basename' => true,
				'error_code' => 'ardrg_rollback_performed',
				'error_message' => 'Aktualizácia AR Design Order Review Guard zlyhala. Predchádzajúca verzia bola automaticky obnovená zo zálohy.',
				'text_domain' => 'ar-design-order-review-guard',
			)
		);
	}
}
