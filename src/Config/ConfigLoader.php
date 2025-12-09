<?php

namespace DraftSignal\Algorithm\Config;

use RuntimeException;

final class ConfigLoader {
	private string $configPath;

	public function __construct(?string $configPath = null) {
		$this->configPath = $configPath ?? dirname(__DIR__, 2) . '/config';
	}

	public function loadBustThresholds(): array {
		return $this->loadJsonFile('bust-thresholds.json');
	}

	public function loadTierMappings(): array {
		return $this->loadJsonFile('tier-mappings.json');
	}

	private function loadJsonFile(string $filename): array {
		$filePath = $this->configPath . '/' . $filename;

		if (!file_exists($filePath)) {
			throw new RuntimeException("Config file not found: {$filePath}");
		}

		$contents = file_get_contents($filePath);
		if ($contents === false) {
			throw new RuntimeException("Failed to read config file: {$filePath}");
		}

		$data = json_decode($contents, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException("Invalid JSON in config file {$filePath}: " . json_last_error_msg());
		}

		return $data;
	}
}
