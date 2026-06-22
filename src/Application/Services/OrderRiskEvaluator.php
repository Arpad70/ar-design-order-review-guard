<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

final class OrderRiskEvaluator
{
	public static function isManualReviewReturnProtected(\WC_Order $order, string $seenMetaKey, string $returnedMetaKey): bool
	{
		$seen = (string) $order->get_meta($seenMetaKey, true);
		$returned = (string) $order->get_meta($returnedMetaKey, true);

		return ('1' === $seen) && ('1' === $returned);
	}

	/**
	 * @return array{score:int,reasons:array<int,string>}
	 */
	public static function evaluateOrderRisk(\WC_Order $order, float $nightHighTotalLimit): array
	{
		$score = 0;
		$reasons = array();
		$total = (float) $order->get_total();
		$limit = self::resolveHighTotalLimit($nightHighTotalLimit);

		if ($total >= $limit) {
			$score += 2;
			$reasons[] = 'vyšší hodnota objednávky';
		}

		if (0 === (int) $order->get_customer_id()) {
			$score += 1;
			$reasons[] = 'host objednávka';
		}

		$payment_method = (string) $order->get_payment_method();
		if (in_array($payment_method, array('cod', 'bacs', 'cheque'), true)) {
			$score += 2;
			$reasons[] = 'riziková platební metoda';
		}

		if ('' === (string) $order->get_customer_ip_address()) {
			$score += 1;
			$reasons[] = 'chybějící IP';
		}

		if (self::isNightWindow()) {
			$score += 1;
			$reasons[] = 'noční režim';
		}

		return array('score' => $score, 'reasons' => $reasons);
	}

	public static function buildManualReviewReason(array $risk, int $threshold, bool $force): string
	{
		return sprintf('Automaticky přesunuto do manuální kontroly. Risk score: %d (threshold: %d).', (int) ($risk['score'] ?? 0), $threshold);
	}

	public static function resolveRiskThreshold(int $defaultRiskThreshold, int $nightRiskThreshold): int
	{
		return self::isNightWindow() ? $nightRiskThreshold : $defaultRiskThreshold;
	}

	public static function resolveHighTotalLimit(float $nightHighTotalLimit, float $dayHighTotalLimit = 120.0): float
	{
		return self::isNightWindow() ? $nightHighTotalLimit : $dayHighTotalLimit;
	}

	public static function isNightWindow(): bool
	{
		$hour = (int) \wp_date('G');

		return $hour >= 22 || $hour < 6;
	}
}