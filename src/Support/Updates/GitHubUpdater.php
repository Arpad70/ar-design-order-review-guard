<?php

declare(strict_types=1);

namespace ArDesign\OrderReviewGuard\Support\Updates;

final class GitHubUpdater
{
	private const CACHE_TTL = 900;

	private string $repositoryFullName;
	private string $pluginBasename;
	private string $currentVersion;

	public function __construct(string $repositoryFullName, string $pluginBasename, string $currentVersion)
	{
		$this->repositoryFullName = $repositoryFullName;
		$this->pluginBasename = $pluginBasename;
		$this->currentVersion = $currentVersion;
	}

	public function register(): void
	{
		add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdateData'));
		add_filter('plugins_api', array($this, 'injectPluginInfo'), 20, 3);
		add_action('upgrader_process_complete', array($this, 'clearCacheAfterUpgrade'), 10, 2);
	}

	public function injectUpdateData($transient)
	{
		if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
			return $transient;
		}

		$release = $this->getLatestRelease();
		if (empty($release)) {
			return $transient;
		}

		$latestVersion = (string) ($release['version'] ?? '');
		$packageUrl = (string) ($release['package_url'] ?? '');
		$detailsUrl = (string) ($release['details_url'] ?? '');

		if ('' === $latestVersion || '' === $packageUrl || version_compare($latestVersion, $this->currentVersion, '<=')) {
			return $transient;
		}

		$transient->response[$this->pluginBasename] = (object) array(
			'slug' => 'ar-design-order-review-guard',
			'plugin' => $this->pluginBasename,
			'new_version' => $latestVersion,
			'url' => $detailsUrl,
			'package' => $packageUrl,
		);

		return $transient;
	}

	public function injectPluginInfo($result, $action, $args)
	{
		if ('plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || 'ar-design-order-review-guard' !== $args->slug) {
			return $result;
		}

		$release = $this->getLatestRelease();
		$version = ! empty($release['version']) ? (string) $release['version'] : $this->currentVersion;
		$details = ! empty($release['details_url']) ? (string) $release['details_url'] : 'https://github.com/' . $this->repositoryFullName;
		$body = ! empty($release['body']) ? (string) $release['body'] : '';

		return (object) array(
			'name' => 'AR Design Order Review Guard',
			'slug' => 'ar-design-order-review-guard',
			'version' => $version,
			'author' => '<a href="https://github.com/' . esc_attr($this->repositoryFullName) . '">Arpad70</a>',
			'homepage' => $details,
			'download_link' => (string) ($release['package_url'] ?? ''),
			'sections' => array(
				'description' => __('Order review guard with secure bin workflow for WooCommerce.', 'ar-design-order-review-guard'),
				'changelog' => '' !== $body ? wp_kses_post(nl2br(esc_html($body))) : __('Changelog není dostupný.', 'ar-design-order-review-guard'),
			),
		);
	}

	private function getLatestRelease(): array
	{
		$cached = get_transient($this->getCacheKey());
		if (is_array($cached) && isset($cached['version'])) {
			return $cached;
		}

		$requestUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $this->repositoryFullName);
		$response = wp_remote_get(
			$requestUrl,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => 'ar-design-order-review-guard/' . $this->currentVersion,
				),
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		if (200 !== (int) wp_remote_retrieve_response_code($response)) {
			return array();
		}

		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($data)) {
			return array();
		}

		$version = ltrim((string) ($data['tag_name'] ?? ''), 'v');
		$package = $this->extractZipAssetUrl($data);
		$details = (string) ($data['html_url'] ?? '');
		$body = (string) ($data['body'] ?? '');

		if ('' === $version || '' === $package) {
			return array();
		}

		$release = array(
			'version' => $version,
			'package_url' => $package,
			'details_url' => $details,
			'body' => $body,
		);

		set_transient($this->getCacheKey(), $release, self::CACHE_TTL);
		return $release;
	}

	public function clearCacheAfterUpgrade($upgrader, $options): void
	{
		if (! is_array($options) || ! isset($options['type'], $options['action'])) {
			return;
		}

		if ('plugin' !== $options['type'] || 'update' !== $options['action']) {
			return;
		}

		$plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();
		if (in_array($this->pluginBasename, $plugins, true)) {
			delete_transient($this->getCacheKey());
		}
	}

	private function extractZipAssetUrl(array $releaseData): string
	{
		$assets = isset($releaseData['assets']) && is_array($releaseData['assets']) ? $releaseData['assets'] : array();
		$fallbackUrl = '';

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
			if ('' === $url || ! str_ends_with(strtolower($name), '.zip')) {
				continue;
			}

			if ('ar-design-order-review-guard.zip' === strtolower($name)) {
				return $url;
			}

			if ('' === $fallbackUrl) {
				$fallbackUrl = $url;
			}
		}

		return $fallbackUrl;
	}

	private function getCacheKey(): string
	{
		return 'ardrg_github_release_data_' . md5($this->repositoryFullName);
	}
}
