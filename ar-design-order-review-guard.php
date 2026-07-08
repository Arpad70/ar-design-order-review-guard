<?php
/**
 * Plugin Name: AR Design Order Review Guard
 * Plugin URI: https://github.com/Arpad70/ar-design-order-review-guard
 * Description: Nahrádza auto-rušenie nezaplatených objednávok bezpečným mezistavom pre manuálnu kontrolu bez rezervácie a odpočtu skladu.
 * Version: 0.2.21
 * Author: AR Design
 * Author URI: https://arpad-horak.cz
 * Developer: Arpád Horák
 * Developer URI: https://arpad-horak.cz
 * Update URI: https://github.com/Arpad70/ar-design-order-review-guard
 * Requires Plugins: ar-design-shared-support
 * Text Domain: ar-design-order-review-guard
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 10.6.1
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 */
if (! defined('ABSPATH')) {
	exit;
}

define('ARDRG_VERSION', '0.2.21');
define('ARDRG_DB_VERSION', '0.2.21');
define('ARDRG_FILE', __FILE__);
define('ARDRG_BASENAME', plugin_basename(__FILE__));
define('ARDRG_PATH', plugin_dir_path(__FILE__));
define('ARDRG_GITHUB_REPOSITORY', 'Arpad70/ar-design-order-review-guard');
define('ARDRG_TEXT_DOMAIN', 'ar-design-order-review-guard');

add_action('before_woocommerce_init', static function (): void {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ARDRG_FILE, true);
	}
});

require_once ARDRG_PATH . 'bootstrap/autoload.php';
ArDesign\OrderReviewGuard\Support\Autoloader::register();
require_once ARDRG_PATH . 'src/Application/HookRegistrar.php';
require_once ARDRG_PATH . 'src/Support/Updates/GitHubUpdater.php';
require_once ARDRG_PATH . 'src/Infrastructure/Audit/AuditLogger.php';
require_once ARDRG_PATH . 'src/Application/Security/DeleteAuthorizationManager.php';
require_once ARDRG_PATH . 'src/Application/Services/AdminUiService.php';
require_once ARDRG_PATH . 'src/Application/Services/DeleteProtectionService.php';
require_once ARDRG_PATH . 'src/Application/Services/OrderRiskEvaluator.php';
require_once ARDRG_PATH . 'src/Application/Services/OrderStatusManager.php';
require_once ARDRG_PATH . 'src/Application/Services/OrderStockManager.php';
require_once ARDRG_PATH . 'src/Application/Services/SecureBinArchiver.php';
require_once ARDRG_PATH . 'src/Application/Services/SecureBinActionService.php';
require_once ARDRG_PATH . 'src/Application/Services/ManualReviewWorkflowService.php';
require_once ARDRG_PATH . 'src/Support/DateTimeFormatter.php';
require_once ARDRG_PATH . 'src/Support/OrdersListNavigator.php';
require_once ARDRG_PATH . 'src/Support/OrderProductSummaryBuilder.php';

final class ArDesignOrderReviewGuard
{
	public const CRON_HOOK_NAME = 'ar_design_move_unpaid_to_manual_review';
	public const CRON_RECURRENCE_NAME = 'ar_design_every_ten_minutes';
	private const STATUS_SLUG = 'manual-review';
	private const DELETE_BLOCKED_TRANSIENT_PREFIX = 'ard_delete_blocked_';
	private const STALE_MINUTES = 45;
	private const DEFAULT_RISK_THRESHOLD = 4;
	private const NIGHT_RISK_THRESHOLD = 3;
	private const NIGHT_HIGH_TOTAL_LIMIT = 80.0;
	private const OPTION_MANAGER_EMAIL = 'ardrg_manager_email';
	private const OPTION_SECRET_HASH = 'ardrg_secret_hash';
	private const OPTION_SECRET_CHANGED_AT = 'ardrg_secret_changed_at';
	private const OPTION_LAST_CRON_COMPLETED_AT_GMT = 'ardrg_last_cron_completed_at_gmt';
	private const SECURE_BIN_TABLE = 'ardrg_secure_bin_orders';
	private const AUDIT_TABLE = 'ardrg_secure_bin_audit';
	private const SECURE_DELETE_TOKEN_PREFIX = 'ardrg_secure_delete_';
	private const META_MANUAL_REVIEW_SEEN = '_ardrg_manual_review_seen';
	private const META_MANUAL_REVIEW_RETURNED = '_ardrg_manual_review_returned';

	public static function registerStatus(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\OrderStatusManager::registerStatus(self::STATUS_SLUG);
	}

	public static function registerStatusInLists(array $statuses): array
	{
		return \ArDesign\OrderReviewGuard\Application\Services\OrderStatusManager::registerStatusInLists($statuses, self::STATUS_SLUG);
	}

	public static function preventPermanentDelete(mixed $delete, \WP_Post $post): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Services\DeleteProtectionService::preventPermanentDelete(
			$delete,
			$post,
			self::SECURE_DELETE_TOKEN_PREFIX,
			self::DELETE_BLOCKED_TRANSIENT_PREFIX,
			self::AUDIT_TABLE
		);
	}

	public static function preventTrashOrder(mixed $trash, \WP_Post $post, mixed $previous_status): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Services\DeleteProtectionService::preventTrashOrder(
			$trash,
			$post,
			$previous_status,
			self::SECURE_DELETE_TOKEN_PREFIX,
			self::DELETE_BLOCKED_TRANSIENT_PREFIX,
			self::AUDIT_TABLE
		);
	}

	public static function preventWooOrderDelete(mixed $check, mixed $order, bool $force_delete): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Services\DeleteProtectionService::preventWooOrderDelete(
			$check,
			$order,
			$force_delete,
			self::SECURE_DELETE_TOKEN_PREFIX,
			self::DELETE_BLOCKED_TRANSIENT_PREFIX,
			self::AUDIT_TABLE
		);
	}

	public static function registerBulkAction(array $actions): array
	{
		return \ArDesign\OrderReviewGuard\Application\Services\AdminUiService::registerBulkAction($actions, self::STATUS_SLUG);
	}

	public static function handleBulkAction(string $redirect_to, string $action, array $ids): string
	{
		return \ArDesign\OrderReviewGuard\Application\Services\AdminUiService::handleBulkAction($redirect_to, $action, $ids, self::STATUS_SLUG);
	}

	public static function registerAdminActionButton(array $actions, mixed $order): array
	{
		return \ArDesign\OrderReviewGuard\Application\Services\AdminUiService::registerAdminActionButton($actions, $order, self::STATUS_SLUG);
	}

	public static function registerAdminReportPage(): void
	{
		add_submenu_page('woocommerce', __('AR Order Review Guard', 'ar-design-order-review-guard'), __('AR Review Guard', 'ar-design-order-review-guard'), 'manage_woocommerce', 'ar-order-review-guard', array(__CLASS__, 'renderAdminReportPage'));
	}

	public static function renderAdminReportPage(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\AdminUiService::renderAdminReportPage(
			self::OPTION_MANAGER_EMAIL,
			self::OPTION_SECRET_CHANGED_AT,
			self::AUDIT_TABLE,
			self::STATUS_SLUG,
			self::CRON_HOOK_NAME
		);
	}

	public static function renderGlobalBulkNotices(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\AdminUiService::renderGlobalBulkNotices();
	}

	public static function handleGenerateSecret(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\SecureBinActionService::handleGenerateSecret(
			self::OPTION_MANAGER_EMAIL,
			self::OPTION_SECRET_HASH,
			self::OPTION_SECRET_CHANGED_AT,
			self::AUDIT_TABLE
		);
	}

	public static function registerOrderMetaBox(): void
	{
		add_meta_box(
			'ardrg-secure-bin',
			__('AR Review Guard: Secure Bin', 'ar-design-order-review-guard'),
			array(__CLASS__, 'renderOrderMetaBox'),
			'shop_order',
			'side',
			'high'
		);
	}

	public static function renderOrderMetaBox(\WP_Post $post): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\AdminUiService::renderOrderMetaBox($post, self::STATUS_SLUG);
	}

	public static function renderOrderEditInlinePanel(mixed $order): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\AdminUiService::renderOrderEditInlinePanel($order, self::STATUS_SLUG);
	}

	public static function handleSecureBinOrder(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\SecureBinActionService::handleSecureBinOrder(
			self::STATUS_SLUG,
			self::OPTION_SECRET_HASH,
			self::SECURE_BIN_TABLE,
			self::AUDIT_TABLE,
			self::SECURE_DELETE_TOKEN_PREFIX
		);
	}

	public static function handleBulkSecureBinOrders(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\SecureBinActionService::handleBulkSecureBinOrders(
			self::STATUS_SLUG,
			self::OPTION_SECRET_HASH,
			self::SECURE_BIN_TABLE,
			self::AUDIT_TABLE,
			self::SECURE_DELETE_TOKEN_PREFIX
		);
	}

	public static function allowAuthorizedSecureDeletePreDeletePost(mixed $delete, \WP_Post $post): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::allowAuthorizedSecureDeletePreDeletePost($delete, $post, self::STATUS_SLUG, self::SECURE_DELETE_TOKEN_PREFIX);
	}

	public static function allowAuthorizedSecureDeletePreTrashPost(mixed $trash, \WP_Post $post, mixed $previous_status): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::allowAuthorizedSecureDeletePreTrashPost($trash, $post, self::STATUS_SLUG, self::SECURE_DELETE_TOKEN_PREFIX);
	}

	public static function allowAuthorizedSecureDeleteWoo(mixed $check, mixed $order, bool $force_delete): mixed
	{
		return \ArDesign\OrderReviewGuard\Application\Security\DeleteAuthorizationManager::allowAuthorizedSecureDeleteWoo($check, $order, self::STATUS_SLUG, self::SECURE_DELETE_TOKEN_PREFIX);
	}

	public static function blockStockReductionForManualReview(bool $can_reduce, mixed $order): bool
	{
		return \ArDesign\OrderReviewGuard\Application\Services\OrderStockManager::blockStockReductionForManualReview($can_reduce, $order, self::STATUS_SLUG);
	}

	public static function releaseStockOnManualReviewTransition(int $order_id, string $from, string $to, mixed $order): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\OrderStockManager::releaseStockOnManualReviewTransition($order_id, $to, $order, self::STATUS_SLUG);
	}

	public static function disableWooAutoCancelUnpaid(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\OrderStockManager::disableWooAutoCancelUnpaid();
	}

	public static function registerTenMinuteCron(array $schedules): array
	{
		return \ArDesign\OrderReviewGuard\Application\Services\ManualReviewWorkflowService::registerTenMinuteCron($schedules, self::CRON_RECURRENCE_NAME);
	}

	public static function moveStaleUnpaidOrdersToManualReview(): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\ManualReviewWorkflowService::moveStaleUnpaidOrdersToManualReview(
			self::STATUS_SLUG,
			self::STALE_MINUTES,
			self::OPTION_LAST_CRON_COMPLETED_AT_GMT,
			self::META_MANUAL_REVIEW_SEEN,
			self::META_MANUAL_REVIEW_RETURNED,
			self::DEFAULT_RISK_THRESHOLD,
			self::NIGHT_RISK_THRESHOLD,
			self::NIGHT_HIGH_TOTAL_LIMIT
		);
	}

	public static function trackManualReviewLifecycleFlags(int $order_id, string $from, string $to, mixed $order): void
	{
		\ArDesign\OrderReviewGuard\Application\Services\ManualReviewWorkflowService::trackManualReviewLifecycleFlags(
			$order_id,
			$from,
			$to,
			$order,
			self::STATUS_SLUG,
			self::META_MANUAL_REVIEW_SEEN,
			self::META_MANUAL_REVIEW_RETURNED
		);
	}

}

ArDesign\OrderReviewGuard\Application\Bootstrap::boot()->run();
register_activation_hook(ARDRG_FILE, array('ArDesign\\OrderReviewGuard\\Application\\Bootstrap', 'activate'));
register_deactivation_hook(ARDRG_FILE, array('ArDesign\\OrderReviewGuard\\Application\\Bootstrap', 'deactivate'));
