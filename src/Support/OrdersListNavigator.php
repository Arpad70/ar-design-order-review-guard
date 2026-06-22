<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support;

defined('ABSPATH') || exit;

final class OrdersListNavigator
{
	public static function buildPostSecureBinRedirectUrl(string $notice, string $return_to_raw, string $manualReviewStatusSlug): string
	{
		$base_url = self::sanitizeOrdersListReturnUrl($return_to_raw, $manualReviewStatusSlug);

		return add_query_arg('ardrg_notice', sanitize_key($notice), $base_url);
	}

	public static function resolveOrdersListReturnUrl(string $manualReviewStatusSlug): string
	{
		$from_query = isset($_GET['ardrg_return_to']) ? (string) wp_unslash($_GET['ardrg_return_to']) : '';
		$from_query = rawurldecode($from_query);
		if ('' !== $from_query) {
			return self::sanitizeOrdersListReturnUrl($from_query, $manualReviewStatusSlug);
		}

		$referer = wp_get_referer();
		if (is_string($referer) && '' !== $referer) {
			return self::sanitizeOrdersListReturnUrl($referer, $manualReviewStatusSlug);
		}

		return self::defaultOrdersListUrl($manualReviewStatusSlug);
	}

	public static function sanitizeOrdersListReturnUrl(string $candidate_url, string $manualReviewStatusSlug): string
	{
		$url = wp_validate_redirect($candidate_url, '');
		if ('' === $url) {
			return self::defaultOrdersListUrl($manualReviewStatusSlug);
		}

		$parts = wp_parse_url($url);
		if (! is_array($parts)) {
			return self::defaultOrdersListUrl($manualReviewStatusSlug);
		}

		$query = array();
		if (isset($parts['query']) && is_string($parts['query'])) {
			parse_str($parts['query'], $query);
		}

		$page = isset($query['page']) ? (string) $query['page'] : '';
		$post_type = isset($query['post_type']) ? (string) $query['post_type'] : '';
		$action = isset($query['action']) ? (string) $query['action'] : '';
		$id = isset($query['id']) ? (int) $query['id'] : 0;

		$is_hpos_orders_list = ('wc-orders' === $page) && ('edit' !== $action) && ($id <= 0);
		$is_legacy_orders_list = ('shop_order' === $post_type);

		if ($is_hpos_orders_list) {
			return $url;
		}

		if ($is_legacy_orders_list && self::isHposOrdersTableEnabled()) {
			$status = isset($query['post_status']) ? sanitize_key((string) $query['post_status']) : ard_workflow_wc_status_key($manualReviewStatusSlug);

			return admin_url('admin.php?page=wc-orders&status=' . $status);
		}

		if ($is_legacy_orders_list) {
			return $url;
		}

		return self::defaultOrdersListUrl($manualReviewStatusSlug);
	}

	public static function defaultOrdersListUrl(string $manualReviewStatusSlug): string
	{
		$manual_status = ard_workflow_wc_status_key($manualReviewStatusSlug);
		if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
			&& method_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url('admin.php?page=wc-orders&status=' . $manual_status);
		}

		return admin_url('edit.php?post_type=shop_order&post_status=' . $manual_status);
	}

	private static function isHposOrdersTableEnabled(): bool
	{
		return class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
			&& method_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
