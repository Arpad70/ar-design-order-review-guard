<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support;

defined('ABSPATH') || exit;

final class OrderProductSummaryBuilder
{
	/**
	 * @return array{product_ids: array<int, int>, items: array<int, array<string, int|null>>}
	 */
	public static function build(\WC_Order $order): array
	{
		$product_ids = array();
		$items = array();

		foreach ($order->get_items() as $item) {
			if (! $item instanceof \WC_Order_Item_Product) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			if ($product_id > 0) {
				$product_ids[] = $product_id;
			}

			$items[] = array(
				'product_id' => $product_id,
				'variation_id' => $variation_id > 0 ? $variation_id : null,
				'qty' => (int) $item->get_quantity(),
			);
		}

		$product_ids = array_values(array_unique(array_filter($product_ids)));

		return array(
			'product_ids' => $product_ids,
			'items' => $items,
		);
	}
}