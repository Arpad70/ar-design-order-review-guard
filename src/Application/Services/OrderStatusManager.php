<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Services;

defined('ABSPATH') || exit;

final class OrderStatusManager
{
	public static function registerStatus(string $statusSlug): void
	{
		ard_workflow_register_post_statuses(array($statusSlug), 'ar-design-order-review-guard');
	}

	public static function registerStatusInLists(array $statuses, string $statusSlug): array
	{
		return ard_workflow_insert_statuses_after($statuses, array($statusSlug), 'ar-design-order-review-guard', 'wc-pending');
	}
}