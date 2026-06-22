<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Application;

defined('ABSPATH') || exit;

final class Requirements
{
	public const MIN_WORDPRESS_VERSION = '6.7';
	public const MIN_PHP_VERSION = '8.0';

	public function hasWooCommerce(): bool
	{
		return class_exists('WooCommerce');
	}

	public function hasSupportedPhpVersion(): bool
	{
		return version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
	}

	public function hasSupportedWordPressVersion(): bool
	{
		global $wp_version;

		return isset($wp_version) && version_compare((string) $wp_version, self::MIN_WORDPRESS_VERSION, '>=');
	}

	public function canBoot(): bool
	{
		return $this->hasWooCommerce() && $this->hasSupportedPhpVersion() && $this->hasSupportedWordPressVersion();
	}

	public function getFailureMessage(): string
	{
		if (! $this->hasSupportedPhpVersion()) {
			return __('Plugin AR Design Order Review Guard vyžaduje PHP 8.0 nebo novější.', 'ar-design-order-review-guard');
		}

		if (! $this->hasSupportedWordPressVersion()) {
			return sprintf(
				/* translators: %s: minimum WordPress version */
				__('Plugin AR Design Order Review Guard vyžaduje WordPress %s nebo novější.', 'ar-design-order-review-guard'),
				self::MIN_WORDPRESS_VERSION
			);
		}

		if (! $this->hasWooCommerce()) {
			return __('Plugin AR Design Order Review Guard vyžaduje aktivní WooCommerce.', 'ar-design-order-review-guard');
		}

		return '';
	}
}
