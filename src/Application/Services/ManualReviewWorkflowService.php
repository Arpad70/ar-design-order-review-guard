<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

require_once __DIR__ . '/OrderRiskEvaluator.php';

final class ManualReviewWorkflowService
{
	public static function registerTenMinuteCron(array $schedules, string $recurrenceName): array
	{
		if (! isset($schedules[$recurrenceName])) {
			$schedules[$recurrenceName] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display' => __('Every 10 Minutes', 'ar-design-order-review-guard'),
			);
		}

		return $schedules;
	}

	public static function moveStaleUnpaidOrdersToManualReview(
		string $statusSlug,
		int $staleMinutes,
		string $lastCronCompletedOption,
		string $seenMetaKey,
		string $returnedMetaKey,
		int $defaultRiskThreshold,
		int $nightRiskThreshold,
		float $nightHighTotalLimit
	): void {
		if (! function_exists('wc_get_orders')) {
			return;
		}

		$before = \gmdate('Y-m-d H:i:s', time() - ($staleMinutes * MINUTE_IN_SECONDS));
		$orders = \wc_get_orders(array(
			'type' => 'shop_order',
			'status' => array('pending', 'on-hold', 'processing'),
			'limit' => 100,
			'return' => 'objects',
			'date_created' => '<' . $before,
		));

		foreach ($orders as $order) {
			if (! $order instanceof \WC_Order || $order->is_paid() || $statusSlug === $order->get_status()) {
				continue;
			}

			if (OrderRiskEvaluator::isManualReviewReturnProtected($order, $seenMetaKey, $returnedMetaKey)) {
				continue;
			}

			$risk = OrderRiskEvaluator::evaluateOrderRisk($order, $nightHighTotalLimit);
			$threshold = OrderRiskEvaluator::resolveRiskThreshold($defaultRiskThreshold, $nightRiskThreshold);
			if (($risk['score'] ?? 0) < $threshold) {
				continue;
			}

			$order->update_status($statusSlug, OrderRiskEvaluator::buildManualReviewReason($risk, $threshold, false), true);

			if (function_exists('wc_release_stock_for_order')) {
				\wc_release_stock_for_order($order);
			}
		}

		update_option($lastCronCompletedOption, \gmdate('Y-m-d H:i:s'), false);
	}

	public static function trackManualReviewLifecycleFlags(int $order_id, string $from, string $to, mixed $order, string $statusSlug, string $seenMetaKey, string $returnedMetaKey): void
	{
		if (! $order instanceof \WC_Order) {
			$order = \wc_get_order($order_id);
		}

		if (! $order instanceof \WC_Order) {
			return;
		}

		if ($statusSlug === $to) {
			$order->update_meta_data($seenMetaKey, '1');
			$order->save_meta_data();
			return;
		}

		if ($statusSlug === $from && $statusSlug !== $to) {
			$order->update_meta_data($seenMetaKey, '1');
			$order->update_meta_data($returnedMetaKey, '1');
			$order->update_meta_data($returnedMetaKey . '_at_gmt', \gmdate('Y-m-d H:i:s'));
			$order->save_meta_data();
		}
	}

	/**
	 * @return array{totals:array{all:int,night:int,day:int},daily:array}
	 */
	public static function collectManualReviewStats(string $statusSlug): array
	{
		if (! function_exists('wc_get_orders')) {
			return array('totals' => array('all' => 0, 'night' => 0, 'day' => 0), 'daily' => array());
		}

		$orders = \wc_get_orders(array(
			'type' => 'shop_order',
			'status' => array($statusSlug),
			'limit' => 1000,
			'return' => 'objects',
		));

		$totals = array('all' => 0, 'night' => 0, 'day' => 0);
		foreach ($orders as $order) {
			if (! $order instanceof \WC_Order || ! $order->get_date_created()) {
				continue;
			}

			$totals['all']++;
			$hour = (int) \wp_date('G', $order->get_date_created()->getTimestamp());
			if ($hour >= 22 || $hour < 6) {
				$totals['night']++;
			} else {
				$totals['day']++;
			}
		}

		return array('totals' => $totals, 'daily' => array());
	}
}