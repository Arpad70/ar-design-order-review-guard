<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support;

defined('ABSPATH') || exit;

final class DateTimeFormatter
{
	public static function formatLocalDateTimeFromTimestamp(int $timestamp): string
	{
		$format = (string) (get_option('date_format') . ' ' . get_option('time_format'));

		return wp_date($format, $timestamp);
	}

	public static function formatLocalDateTimeFromGmtString(string $gmt_datetime): string
	{
		$timestamp = strtotime($gmt_datetime . ' UTC');
		if (false === $timestamp) {
			return $gmt_datetime;
		}

		return self::formatLocalDateTimeFromTimestamp((int) $timestamp);
	}

	public static function getManualReviewCronSummary(string $cronHookName): string
	{
		$last_completed_gmt = (string) get_option('ardrg_last_cron_completed_at_gmt', '');
		$next_scheduled = wp_next_scheduled($cronHookName);

		$last_label = '' !== $last_completed_gmt
			? self::formatLocalDateTimeFromGmtString($last_completed_gmt)
			: __('zatím neproběhlo', 'ar-design-order-review-guard');
		$next_label = false !== $next_scheduled
			? self::formatLocalDateTimeFromTimestamp((int) $next_scheduled)
			: __('nenaplánováno', 'ar-design-order-review-guard');

		return sprintf(
			/* translators: 1: last completed cron datetime or fallback, 2: next scheduled cron datetime or fallback. */
			__('Posledný dokončený WP CRON: %1$s (ďalšie plánované spustenie: %2$s)', 'ar-design-order-review-guard'),
			$last_label,
			$next_label
		);
	}
}