<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support;

final class Autoloader
{
	public static function register(): void
	{
		spl_autoload_register(array(__CLASS__, 'autoload'));
	}

	private static function autoload(string $class): void
	{
		$prefix = 'ArDesign\\OrderReviewGuard\\';
		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		if (! is_string($relative) || $relative == '') {
			return;
		}

		$relative_path = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
		$file = ARDRG_PATH . 'src/' . $relative_path;
		if (is_readable($file)) {
			require_once $file;
		}
	}
}
