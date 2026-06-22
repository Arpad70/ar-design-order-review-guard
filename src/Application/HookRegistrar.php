<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application;

defined('ABSPATH') || exit;

final class HookRegistrar
{
	public static function register(): void
	{
		add_action('init', array(\ArDesignOrderReviewGuard::class, 'registerStatus'));
		add_action('admin_menu', array(\ArDesignOrderReviewGuard::class, 'registerAdminReportPage'), 99);
		add_action('admin_notices', array(\ArDesignOrderReviewGuard::class, 'renderGlobalBulkNotices'));
		add_action('add_meta_boxes', array(\ArDesignOrderReviewGuard::class, 'registerOrderMetaBox'));
		add_action('woocommerce_admin_order_data_after_order_details', array(\ArDesignOrderReviewGuard::class, 'renderOrderEditInlinePanel'));
		add_action('admin_post_ardrg_generate_secret', array(\ArDesignOrderReviewGuard::class, 'handleGenerateSecret'));
		add_action('admin_post_ardrg_secure_bin_order', array(\ArDesignOrderReviewGuard::class, 'handleSecureBinOrder'));
		add_action('admin_post_ardrg_bulk_secure_bin_orders', array(\ArDesignOrderReviewGuard::class, 'handleBulkSecureBinOrders'));

		add_filter('wc_order_statuses', array(\ArDesignOrderReviewGuard::class, 'registerStatusInLists'));
		add_filter('bulk_actions-edit-shop_order', array(\ArDesignOrderReviewGuard::class, 'registerBulkAction'));
		add_filter('bulk_actions-woocommerce_page_wc-orders', array(\ArDesignOrderReviewGuard::class, 'registerBulkAction'));
		add_filter('handle_bulk_actions-edit-shop_order', array(\ArDesignOrderReviewGuard::class, 'handleBulkAction'), 10, 3);
		add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(\ArDesignOrderReviewGuard::class, 'handleBulkAction'), 10, 3);
		add_filter('woocommerce_admin_order_actions', array(\ArDesignOrderReviewGuard::class, 'registerAdminActionButton'), 20, 2);

		add_filter('woocommerce_can_reduce_order_stock', array(\ArDesignOrderReviewGuard::class, 'blockStockReductionForManualReview'), 10, 2);
		add_action('woocommerce_order_status_changed', array(\ArDesignOrderReviewGuard::class, 'releaseStockOnManualReviewTransition'), 20, 4);
		add_action('woocommerce_order_status_changed', array(\ArDesignOrderReviewGuard::class, 'trackManualReviewLifecycleFlags'), 30, 4);
		add_action('woocommerce_loaded', array(\ArDesignOrderReviewGuard::class, 'disableWooAutoCancelUnpaid'), 30);

		add_filter('cron_schedules', array(\ArDesignOrderReviewGuard::class, 'registerTenMinuteCron'));
		add_action(\ArDesignOrderReviewGuard::CRON_HOOK_NAME, array(\ArDesignOrderReviewGuard::class, 'moveStaleUnpaidOrdersToManualReview'));

		add_filter('pre_delete_post', array(\ArDesignOrderReviewGuard::class, 'preventPermanentDelete'), 10, 2);
		add_filter('pre_trash_post', array(\ArDesignOrderReviewGuard::class, 'preventTrashOrder'), 10, 3);
		add_filter('woocommerce_pre_delete_order', array(\ArDesignOrderReviewGuard::class, 'preventWooOrderDelete'), 10, 3);

		add_filter('pre_delete_post', array(\ArDesignOrderReviewGuard::class, 'allowAuthorizedSecureDeletePreDeletePost'), 999, 2);
		add_filter('pre_trash_post', array(\ArDesignOrderReviewGuard::class, 'allowAuthorizedSecureDeletePreTrashPost'), 999, 3);
		add_filter('woocommerce_pre_delete_order', array(\ArDesignOrderReviewGuard::class, 'allowAuthorizedSecureDeleteWoo'), 999, 3);
	}
}