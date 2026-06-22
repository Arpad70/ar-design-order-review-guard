<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application\Security;

defined('ABSPATH') || exit;

final class DeleteAuthorizationManager
{
	public static function setSecureDeleteAuthorization(int $order_id, int $user_id, string $tokenPrefix): void
	{
		set_transient($tokenPrefix . $user_id . '_' . $order_id, '1', 120);
	}

	public static function clearSecureDeleteAuthorization(int $order_id, int $user_id, string $tokenPrefix): void
	{
		delete_transient($tokenPrefix . $user_id . '_' . $order_id);
	}

	public static function hasSecureDeleteAuthorization(int $order_id, string $tokenPrefix): bool
	{
		$user_id = get_current_user_id();
		if ($user_id <= 0 || $order_id <= 0) {
			return false;
		}

		return '1' === (string) get_transient($tokenPrefix . $user_id . '_' . $order_id);
	}

	public static function allowAuthorizedSecureDeletePreDeletePost(mixed $delete, \WP_Post $post, string $statusSlug, string $tokenPrefix): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $delete;
		}

		$order = wc_get_order((int) $post->ID);
		if ($order instanceof \WC_Order && $statusSlug === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID, $tokenPrefix)) {
			return null;
		}

		return $delete;
	}

	public static function allowAuthorizedSecureDeletePreTrashPost(mixed $trash, \WP_Post $post, string $statusSlug, string $tokenPrefix): mixed
	{
		if ('shop_order' !== $post->post_type) {
			return $trash;
		}

		$order = wc_get_order((int) $post->ID);
		if ($order instanceof \WC_Order && $statusSlug === $order->get_status() && self::hasSecureDeleteAuthorization((int) $post->ID, $tokenPrefix)) {
			return null;
		}

		return $trash;
	}

	public static function allowAuthorizedSecureDeleteWoo(mixed $check, mixed $order, string $statusSlug, string $tokenPrefix): mixed
	{
		if (! $order instanceof \WC_Order) {
			return $check;
		}

		$order_id = (int) $order->get_id();
		if ($statusSlug === $order->get_status() && self::hasSecureDeleteAuthorization($order_id, $tokenPrefix)) {
			return null;
		}

		return $check;
	}
}