<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

final class OrderStockManager
{
	public static function blockStockReductionForManualReview(bool $can_reduce, mixed $order, string $statusSlug): bool
	{
		if ($order instanceof \WC_Order && $statusSlug === $order->get_status()) {
			return false;
		}

		return $can_reduce;
	}

	public static function releaseStockOnManualReviewTransition(int $order_id, string $to, mixed $order, string $statusSlug): void
	{
		if ($statusSlug !== $to) {
			return;
		}

		if (! $order instanceof \WC_Order) {
			$order = wc_get_order($order_id);
		}

		if ($order instanceof \WC_Order && function_exists('wc_release_stock_for_order')) {
			wc_release_stock_for_order($order);
		}
	}

	public static function disableWooAutoCancelUnpaid(): void
	{
		remove_action('woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders');
	}
}