<?php

declare(strict_types=1);

namespace AchievementBattle;

use RuntimeException;

final class AppConfig
{
    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, string> */
    private array $env;

    public function __construct(private readonly string $rootDirectory)
    {
        $settings = require $rootDirectory . '/config/config.php';

        if (!is_array($settings)) {
            throw new RuntimeException('config/config.php must return an array.');
        }

        /** @var array<string, mixed> $settings */
        $this->settings = $settings;
        $this->env = $this->loadEnv($rootDirectory . '/.env');
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    public function steamApiKey(): string
    {
        return $this->envString('STEAM_API_KEY');
    }

    public function steamLanguage(): string
    {
        return $this->envString('STEAM_LANGUAGE', 'english');
    }

    public function rarityCacheMaxAgeSeconds(): int
    {
        return $this->envInt('RARITY_CACHE_MAX_AGE_SECONDS', 604800);
    }

    public function steamConcurrency(): int
    {
        return max(1, min(10, $this->envInt('STEAM_CONCURRENCY', 6)));
    }

    /**
     * @return list<array{key: string, display_name: string, profile: string, profile_type: string}>
     */
    public function players(): array
    {
        $players = $this->settings['players'] ?? null;

        if (!is_array($players) || count($players) !== 2) {
            throw new RuntimeException('Exactly two players must be configured.');
        }

        $normalizedPlayers = [];

        foreach ($players as $player) {
            if (!is_array($player)) {
                throw new RuntimeException('Each player configuration must be an array.');
            }

            $key = $player['key'] ?? null;
            $displayName = $player['display_name'] ?? null;
            $profile = $player['profile'] ?? null;
            $profileType = $player['profile_type'] ?? null;

            if (
                !is_string($key)
                || !is_string($displayName)
                || !is_string($profile)
                || !is_string($profileType)
            ) {
                throw new RuntimeException('Invalid player configuration.');
            }

            $normalizedPlayers[] = [
                'key' => $key,
                'display_name' => $displayName,
                'profile' => $profile,
                'profile_type' => $profileType,
            ];
        }

        return $normalizedPlayers;
    }

    /** @return array<int, string> */
    public function excludedAppIds(): array
    {
        $excluded = $this->settings['excluded_app_ids'] ?? [];

        if (!is_array($excluded)) {
            throw new RuntimeException('excluded_app_ids must be an array.');
        }

        $normalized = [];

        foreach ($excluded as $appId => $name) {
            if (!is_int($appId) || !is_string($name)) {
                throw new RuntimeException('Excluded games must use integer AppIDs and string names.');
            }

            $normalized[$appId] = $name;
        }

        return $normalized;
    }

    public function rareAchievementCount(): int
    {
        return $this->settingInt('rare_achievement_count', 10);
    }

    public function recentGameCount(): int
    {
        return $this->settingInt('recent_game_count', 4);
    }

    public function sharedGameLimit(): int
    {
        return $this->settingInt('shared_game_limit', 100);
    }

    /** @return array<string, string> */
    private function loadEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);

        if ($values === false) {
            throw new RuntimeException('Unable to parse .env.');
        }

        $env = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $env[$key] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }

        return $env;
    }

    private function envString(string $name, string $default = ''): string
    {
        $runtimeValue = getenv($name);

        if ($runtimeValue !== false && $runtimeValue !== '') {
            return $runtimeValue;
        }

        return $this->env[$name] ?? $default;
    }

    private function envInt(string $name, int $default): int
    {
        $value = $this->envString($name);

        if ($value === '') {
            return $default;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : $default;
    }

    private function settingInt(string $name, int $default): int
    {
        $value = $this->settings[$name] ?? $default;

        return is_int($value) ? $value : $default;
    }
}
