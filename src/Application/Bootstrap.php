<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application;

use ArDesign\OrderReviewGuard\Infrastructure\Database\Migrator;
use ArDesign\OrderReviewGuard\Infrastructure\Database\Schema;
use ArDesign\OrderReviewGuard\Support\Updates\GitHubUpdater;

final class Bootstrap
{
	private static ?self $instance = null;

	private Requirements $requirements;
	private Migrator $migrator;
	private GitHubUpdater $updater;
	/** @var object */
	private $rollbackManager;

	private function __construct()
	{
		$rollbackManagerClass = '\\ArDesign\\OrderReviewGuard\\Support\\Updates\\RollbackManager';

		$this->requirements = new Requirements();
		$this->migrator = new Migrator(new Schema());
		$this->updater = new GitHubUpdater(ARDRG_GITHUB_REPOSITORY, ARDRG_BASENAME, ARDRG_VERSION);
		$this->rollbackManager = new $rollbackManagerClass(ARDRG_BASENAME, ARDRG_PATH);
	}

	public static function boot(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run(): void
	{
		add_action('init', array($this, 'loadTextDomain'));
		$this->rollbackManager->register();
		add_action('plugins_loaded', array($this, 'bootstrapRuntime'), 20);
	}

	public function loadTextDomain(): void
	{
		load_plugin_textdomain(ARDRG_TEXT_DOMAIN, false, dirname(ARDRG_BASENAME) . '/languages');
	}

	public function bootstrapRuntime(): void
	{
		add_action('admin_notices', array($this, 'renderBootstrapNotice'));
		$this->ensureSchemaIsCurrent();

		if (! $this->requirements->canBoot()) {
			return;
		}

		$this->updater->register();
		\ArDesignOrderReviewGuard::bootstrap();
		$this->ensureManualReviewCronIsScheduled();
	}

	public static function activate(): void
	{
		$bootstrap = self::boot();
		$bootstrap->migrator->migrate();
		update_option('ardrg_version', ARDRG_VERSION);

		if (! wp_next_scheduled(\ArDesignOrderReviewGuard::CRON_HOOK_NAME)) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, \ArDesignOrderReviewGuard::CRON_RECURRENCE_NAME, \ArDesignOrderReviewGuard::CRON_HOOK_NAME);
		}
	}

	public static function deactivate(): void
	{
		$timestamp = wp_next_scheduled(\ArDesignOrderReviewGuard::CRON_HOOK_NAME);
		while (false !== $timestamp) {
			wp_unschedule_event($timestamp, \ArDesignOrderReviewGuard::CRON_HOOK_NAME);
			$timestamp = wp_next_scheduled(\ArDesignOrderReviewGuard::CRON_HOOK_NAME);
		}
	}

	private function ensureSchemaIsCurrent(): void
	{
		$current_db_version = (string) get_option('ardrg_db_version', '0.0.0');
		$current_plugin_version = (string) get_option('ardrg_version', '0.0.0');
		$needs_db_migration = version_compare($current_db_version, ARDRG_DB_VERSION, '<');
		if (! $needs_db_migration) {
			$needs_db_migration = ! empty($this->migrator->getMissingTables());
		}

		if ($needs_db_migration) {
			$this->migrator->migrate();
		}

		if ($current_plugin_version !== ARDRG_VERSION) {
			update_option('ardrg_version', ARDRG_VERSION);
		}
	}

	private function ensureManualReviewCronIsScheduled(): void
	{
		if (wp_next_scheduled(\ArDesignOrderReviewGuard::CRON_HOOK_NAME)) {
			return;
		}

		wp_schedule_event(
			time() + MINUTE_IN_SECONDS,
			\ArDesignOrderReviewGuard::CRON_RECURRENCE_NAME,
			\ArDesignOrderReviewGuard::CRON_HOOK_NAME
		);
	}

	public function renderBootstrapNotice(): void
	{
		if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
			return;
		}

		if (! $this->requirements->canBoot()) {
			echo '<div class="notice notice-warning"><p>' . esc_html($this->requirements->getFailureMessage()) . '</p></div>';
		}
	}
}
