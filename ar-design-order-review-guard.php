<?php
/**
 * Plugin Name: AR Design Order Review Guard
 * Description: Nahrádza auto-rušenie nezaplatených objednávok bezpečným mezistavom pre manuálnu kontrolu bez rezervácie a odpočtu skladu.
 * Version: 0.1.0
 * Author: AR Design
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
	exit;
}

final class ArDesignOrderReviewGuard
{
	private const STATUS_SLUG = 'manual-review';
	private const CRON_HOOK = 'ar_design_move_unpaid_to_manual_review';
	private const CRON_RECURRENCE = 'ar_design_every_ten_minutes';
	private const STALE_MINUTES = 45;
	private const DEFAULT_RISK_THRESHOLD = 4;
	private const NIGHT_RISK_THRESHOLD = 3;
	private const NIGHT_HIGH_TOTAL_LIMIT = 80.0;

	public static function bootstrap(): void
	{
		add_action('init', array(__CLASS__, 'registerStatus'));
		add_action('admin_menu', array(__CLASS__, 'registerAdminReportPage'));
		add_filter('wc_order_statuses', array(__CLASS__, 'registerStatusInLists'));
		add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'registerBulkAction'));
		add_filter('woocommerce_admin_order_actions', array(__CLASS__, 'registerAdminActionButton'), 20, 2);

		add_filter('woocommerce_can_reduce_order_stock', array(__CLASS__, 'blockStockReductionForManualReview'), 10, 2);
		add_action('woocommerce_order_status_changed', array(__CLASS__, 'releaseStockOnManualReviewTransition'), 20, 4);

		add_action('woocommerce_loaded', array(__CLASS__, 'disableWooAutoCancelUnpaid'), 30);

		add_filter('cron_schedules', array(__CLASS__, 'registerTenMinuteCron'));
		add_action(self::CRON_HOOK, array(__CLASS__, 'moveStaleUnpaidOrdersToManualReview'));
	}

	public static function registerAdminReportPage(): void
	{
		add_submenu_page(
			'woocommerce',
			__('AR Order Review Guard', 'ar-design-order-review-guard'),
			__('AR Review Guard', 'ar-design-order-review-guard'),
			'manage_woocommerce',
			'ar-order-review-guard',
			array(__CLASS__, 'renderAdminReportPage')
		);
	}

	public static function renderAdminReportPage(): void
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Nedostatečná oprávnění.', 'ar-design-order-review-guard'));
		}

		$stats = self::collectManualReviewStats();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('AR Order Review Guard', 'ar-design-order-review-guard') . '</h1>';
		echo '<p>' . esc_html__('Přehled objednávek přesunutých do manuální kontroly (den vs. noc).', 'ar-design-order-review-guard') . '</p>';

		echo '<table class="widefat striped" style="max-width:920px;">';
		echo '<thead><tr><th>' . esc_html__('Metrika', 'ar-design-order-review-guard') . '</th><th>' . esc_html__('Hodnota', 'ar-design-order-review-guard') . '</th></tr></thead><tbody>';
		echo '<tr><td>' . esc_html__('Manuální kontrola celkem', 'ar-design-order-review-guard') . '</td><td><strong>' . esc_html((string) $stats['totals']['all']) . '</strong></td></tr>';
		echo '<tr><td>' . esc_html__('Noční (22:00-06:00)', 'ar-design-order-review-guard') . '</td><td>' . esc_html((string) $stats['totals']['night']) . '</td></tr>';
		echo '<tr><td>' . esc_html__('Denní (06:00-22:00)', 'ar-design-order-review-guard') . '</td><td>' . esc_html((string) $stats['totals']['day']) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px;">' . esc_html__('Trend za posledních 14 dní', 'ar-design-order-review-guard') . '</h2>';
		echo '<table class="widefat striped" style="max-width:920px;">';
		echo '<thead><tr><th>' . esc_html__('Den', 'ar-design-order-review-guard') . '</th><th>' . esc_html__('Noční', 'ar-design-order-review-guard') . '</th><th>' . esc_html__('Denní', 'ar-design-order-review-guard') . '</th><th>' . esc_html__('Celkem', 'ar-design-order-review-guard') . '</th></tr></thead><tbody>';
		if (empty($stats['daily'])) {
			echo '<tr><td colspan="4">' . esc_html__('Zatím bez dat.', 'ar-design-order-review-guard') . '</td></tr>';
		} else {
			foreach ($stats['daily'] as $row) {
				echo '<tr>';
				echo '<td>' . esc_html((string) $row['date']) . '</td>';
				echo '<td>' . esc_html((string) $row['night']) . '</td>';
				echo '<td>' . esc_html((string) $row['day']) . '</td>';
				echo '<td><strong>' . esc_html((string) $row['total']) . '</strong></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	public static function activate(): void
	{
		if (! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_RECURRENCE, self::CRON_HOOK);
		}
	}

	public static function deactivate(): void
	{
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		while (false !== $timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	public static function registerStatus(): void
	{
		register_post_status(
			'wc-' . self::STATUS_SLUG,
			array(
				'label' => _x('Manuální kontrola', 'Order status', 'ar-design-order-review-guard'),
				'public' => true,
				'exclude_from_search' => false,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				'label_count' => _n_noop(
					'Manuální kontrola <span class="count">(%s)</span>',
					'Manuální kontrola <span class="count">(%s)</span>',
					'ar-design-order-review-guard'
				),
			)
		);
	}

	public static function registerStatusInLists(array $statuses): array
	{
		$result = array();
		$inserted = false;
		foreach ($statuses as $key => $label) {
			$result[$key] = $label;
			if ('wc-pending' === $key) {
				$result['wc-' . self::STATUS_SLUG] = __('Manuální kontrola', 'ar-design-order-review-guard');
				$inserted = true;
			}
		}

		if (! $inserted) {
			$result['wc-' . self::STATUS_SLUG] = __('Manuální kontrola', 'ar-design-order-review-guard');
		}

		return $result;
	}

	public static function registerBulkAction(array $actions): array
	{
		$actions['mark_' . self::STATUS_SLUG] = __('Změnit na Manuální kontrola', 'ar-design-order-review-guard');
		return $actions;
	}

	public static function registerAdminActionButton(array $actions, $order): array
	{
		if (! $order instanceof WC_Order) {
			return $actions;
		}
		if (self::STATUS_SLUG === $order->get_status()) {
			return $actions;
		}

		$actions[self::STATUS_SLUG] = array(
			'url' => wp_nonce_url(
				admin_url('admin-ajax.php?action=woocommerce_mark_order_status&status=' . self::STATUS_SLUG . '&order_id=' . $order->get_id()),
				'woocommerce-mark-order-status'
			),
			'name' => __('Manuální kontrola', 'ar-design-order-review-guard'),
			'action' => self::STATUS_SLUG,
		);

		return $actions;
	}

	public static function blockStockReductionForManualReview(bool $can_reduce, $order): bool
	{
		if (! $order instanceof WC_Order) {
			return $can_reduce;
		}

		if (self::STATUS_SLUG === $order->get_status()) {
			return false;
		}

		return $can_reduce;
	}

	public static function releaseStockOnManualReviewTransition(int $order_id, string $from, string $to, $order): void
	{
		if (self::STATUS_SLUG !== $to) {
			return;
		}

		if (! $order instanceof WC_Order) {
			$order = wc_get_order($order_id);
		}
		if (! $order instanceof WC_Order) {
			return;
		}

		if (function_exists('wc_release_stock_for_order')) {
			wc_release_stock_for_order($order);
		}
	}

	public static function disableWooAutoCancelUnpaid(): void
	{
		// Nahrazujeme defaultní auto-cancel vlastním flow do manuální kontroly.
		remove_action('woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders');
	}

	public static function registerTenMinuteCron(array $schedules): array
	{
		if (! isset($schedules[self::CRON_RECURRENCE])) {
			$schedules[self::CRON_RECURRENCE] = array(
				'interval' => 10 * MINUTE_IN_SECONDS,
				'display' => __('Every 10 Minutes', 'ar-design-order-review-guard'),
			);
		}
		return $schedules;
	}

	public static function moveStaleUnpaidOrdersToManualReview(): void
	{
		if (! function_exists('wc_get_orders')) {
			return;
		}

		$before = gmdate('Y-m-d H:i:s', time() - (self::STALE_MINUTES * MINUTE_IN_SECONDS));
		$orders = wc_get_orders(array(
			'type' => 'shop_order',
			'status' => array('pending', 'on-hold', 'failed'),
			'limit' => 100,
			'return' => 'objects',
			'date_created' => '<' . $before,
		));

		if (empty($orders)) {
			return;
		}

		foreach ($orders as $order) {
			if (! $order instanceof WC_Order) {
				continue;
			}

			if ($order->is_paid()) {
				continue;
			}

			if (self::STATUS_SLUG === $order->get_status()) {
				continue;
			}

			$risk = self::evaluateOrderRisk($order);
			$risk_threshold = self::resolveRiskThreshold($order);
			$force_move_all_stale = (bool) apply_filters('ar_design_order_review_guard_move_all_stale_unpaid', false, $order);

			if (! $force_move_all_stale && $risk['score'] < $risk_threshold) {
				continue;
			}

			$reason = self::buildManualReviewReason($risk, $risk_threshold, $force_move_all_stale);
			$order->update_status(
				self::STATUS_SLUG,
				$reason,
				true
			);

			if (function_exists('wc_release_stock_for_order')) {
				wc_release_stock_for_order($order);
			}
		}
	}

	/**
	 * @return array{score:int,reasons:string[]}
	 */
	private static function evaluateOrderRisk(WC_Order $order): array
	{
		$score = 0;
		$reasons = array();

		$total = (float) $order->get_total();
		$high_total_limit = self::resolveHighTotalLimit($order);
		if ($total >= $high_total_limit) {
			$score += 2;
			$reasons[] = sprintf(__('vyšší hodnota objednávky (>= %s)', 'ar-design-order-review-guard'), wc_format_decimal($high_total_limit, 2));
		}

		if (0 === (int) $order->get_customer_id()) {
			$score += 1;
			$reasons[] = __('host objednávka bez registrovaného účtu', 'ar-design-order-review-guard');
		}

		$email = strtolower(trim((string) $order->get_billing_email()));
		$free_domains = (array) apply_filters(
			'ar_design_order_review_guard_free_email_domains',
			array('gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'seznam.cz', 'email.cz', 'centrum.cz', 'post.cz', 'zoznam.sk', 'azet.sk')
		);
		$email_domain = '';
		if (false !== strpos($email, '@')) {
			$email_domain = substr($email, (int) strpos($email, '@') + 1);
		}
		if ('' !== $email_domain && in_array($email_domain, array_map('strtolower', $free_domains), true)) {
			$score += 1;
			$reasons[] = __('free-mailová doména', 'ar-design-order-review-guard');
		}

		$payment_method = (string) $order->get_payment_method();
		$risky_payment_methods = (array) apply_filters(
			'ar_design_order_review_guard_risky_payment_methods',
			array('cod', 'bacs', 'cheque')
		);
		if (in_array($payment_method, $risky_payment_methods, true)) {
			$score += 2;
			$reasons[] = sprintf(__('rizikovější platební metoda (%s)', 'ar-design-order-review-guard'), $payment_method);
		}

		$ip_address = (string) $order->get_customer_ip_address();
		if ('' === $ip_address) {
			$score += 1;
			$reasons[] = __('chybějící IP adresa zákazníka', 'ar-design-order-review-guard');
		}

		$name = strtolower(trim((string) $order->get_formatted_billing_full_name()));
		if ('' !== $name && preg_match('/^(test|asdf|qwerty|fake|guest)/i', $name)) {
			$score += 2;
			$reasons[] = __('podezřelý billing name pattern', 'ar-design-order-review-guard');
		}

		$address_1 = trim((string) $order->get_billing_address_1());
		if ('' === $address_1 || strlen($address_1) < 5) {
			$score += 1;
			$reasons[] = __('neúplná fakturační adresa', 'ar-design-order-review-guard');
		}

		$item_count = (int) $order->get_item_count();
		if ($item_count >= 8) {
			$score += 1;
			$reasons[] = __('nezvykle vysoký počet položek', 'ar-design-order-review-guard');
		}

		if (self::isNightWindow()) {
			$score += 1;
			$reasons[] = __('noční ultra-strict režim', 'ar-design-order-review-guard');
		}

		/**
		 * Umožní externě upravit výpočet skóre.
		 *
		 * @param array{score:int,reasons:string[]} $risk
		 * @param WC_Order $order
		 */
		$risk = apply_filters(
			'ar_design_order_review_guard_calculated_risk',
			array(
				'score' => $score,
				'reasons' => $reasons,
			),
			$order
		);

		$final_score = isset($risk['score']) ? (int) $risk['score'] : $score;
		$final_reasons = isset($risk['reasons']) && is_array($risk['reasons']) ? array_values(array_filter(array_map('strval', $risk['reasons']))) : $reasons;

		return array(
			'score' => $final_score,
			'reasons' => $final_reasons,
		);
	}

	/**
	 * @param array{score:int,reasons:string[]} $risk
	 */
	private static function buildManualReviewReason(array $risk, int $threshold, bool $force_move_all_stale): string
	{
		$prefix = __('Automaticky přesunuto do manuální kontroly místo auto-rušení nezaplacené objednávky.', 'ar-design-order-review-guard');
		if ($force_move_all_stale) {
			return $prefix . ' ' . __('(režim: přesun všech starších nezaplacených)', 'ar-design-order-review-guard');
		}

		$reasons = $risk['reasons'];
		if (empty($reasons)) {
			return sprintf(
				/* translators: 1: score, 2: threshold */
				__('%1$s Risk score: %2$d (threshold: %3$d).', 'ar-design-order-review-guard'),
				$prefix,
				(int) $risk['score'],
				$threshold
			);
		}

		return sprintf(
			/* translators: 1: base message, 2: score, 3: threshold, 4: reason list */
			__('%1$s Risk score: %2$d (threshold: %3$d). Důvody: %4$s.', 'ar-design-order-review-guard'),
			$prefix,
			(int) $risk['score'],
			$threshold,
			implode(', ', $reasons)
		);
	}

	private static function resolveRiskThreshold(WC_Order $order): int
	{
		$default = self::isNightWindow() ? self::NIGHT_RISK_THRESHOLD : self::DEFAULT_RISK_THRESHOLD;
		return (int) apply_filters('ar_design_order_review_guard_risk_threshold', $default, $order);
	}

	private static function resolveHighTotalLimit(WC_Order $order): float
	{
		$default = self::isNightWindow() ? self::NIGHT_HIGH_TOTAL_LIMIT : 120.0;
		return (float) apply_filters('ar_design_order_review_guard_high_total_limit', $default, $order);
	}

	private static function isNightWindow(): bool
	{
		$hour = (int) wp_date('G');
		$start = (int) apply_filters('ar_design_order_review_guard_night_start_hour', 22);
		$end = (int) apply_filters('ar_design_order_review_guard_night_end_hour', 6);

		$start = max(0, min(23, $start));
		$end = max(0, min(23, $end));

		if ($start === $end) {
			return true;
		}

		if ($start < $end) {
			return $hour >= $start && $hour < $end;
		}

		return $hour >= $start || $hour < $end;
	}

	/**
	 * @return array{
	 *   totals: array{all:int,night:int,day:int},
	 *   daily: array<int,array{date:string,night:int,day:int,total:int}>
	 * }
	 */
	private static function collectManualReviewStats(): array
	{
		if (! function_exists('wc_get_orders')) {
			return array(
				'totals' => array('all' => 0, 'night' => 0, 'day' => 0),
				'daily' => array(),
			);
		}

		$orders = wc_get_orders(array(
			'type' => 'shop_order',
			'status' => array(self::STATUS_SLUG),
			'limit' => 1000,
			'return' => 'objects',
			'orderby' => 'date',
			'order' => 'DESC',
		));

		$night_start = (int) apply_filters('ar_design_order_review_guard_night_start_hour', 22);
		$night_end = (int) apply_filters('ar_design_order_review_guard_night_end_hour', 6);
		$tz = wp_timezone();

		$totals = array('all' => 0, 'night' => 0, 'day' => 0);
		$daily = array();
		$cutoff = new DateTimeImmutable('now', $tz);
		$cutoff = $cutoff->modify('-14 days');

		foreach ($orders as $order) {
			if (! $order instanceof WC_Order) {
				continue;
			}
			$created = $order->get_date_created();
			if (! $created) {
				continue;
			}
			$local_dt = (new DateTimeImmutable('@' . $created->getTimestamp()))->setTimezone($tz);
			$hour = (int) $local_dt->format('G');
			$date_key = $local_dt->format('Y-m-d');
			$is_night = self::isHourInNightWindow($hour, $night_start, $night_end);

			$totals['all']++;
			if ($is_night) {
				$totals['night']++;
			} else {
				$totals['day']++;
			}

			if ($local_dt < $cutoff) {
				continue;
			}

			if (! isset($daily[$date_key])) {
				$daily[$date_key] = array('date' => $date_key, 'night' => 0, 'day' => 0, 'total' => 0);
			}
			$daily[$date_key]['total']++;
			if ($is_night) {
				$daily[$date_key]['night']++;
			} else {
				$daily[$date_key]['day']++;
			}
		}

		krsort($daily);

		return array(
			'totals' => $totals,
			'daily' => array_values($daily),
		);
	}

	private static function isHourInNightWindow(int $hour, int $start, int $end): bool
	{
		$start = max(0, min(23, $start));
		$end = max(0, min(23, $end));
		if ($start === $end) {
			return true;
		}
		if ($start < $end) {
			return $hour >= $start && $hour < $end;
		}
		return $hour >= $start || $hour < $end;
	}
}

ArDesignOrderReviewGuard::bootstrap();
register_activation_hook(__FILE__, array('ArDesignOrderReviewGuard', 'activate'));
register_deactivation_hook(__FILE__, array('ArDesignOrderReviewGuard', 'deactivate'));
