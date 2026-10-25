<?php

declare(strict_types=1);

namespace AchievementBattle;

use CurlHandle;
use RuntimeException;

final class SteamApiClient
{
    private const BASE_URL = 'https://api.steampowered.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $language,
        private readonly int $concurrency,
        private readonly int $batchDelayMilliseconds,
        private readonly int $retryCount,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('STEAM_API_KEY is missing. Copy .env.example to .env and configure it.');
        }
    }

    public function resolveVanityUrl(string $vanityUrl): string
    {
        $data = $this->get('ISteamUser', 'ResolveVanityURL', 'v1', [
            'vanityurl' => $vanityUrl,
        ]);
        $response = $data['response'] ?? null;

        if (!is_array($response) || ($response['success'] ?? null) !== 1 || !is_string($response['steamid'] ?? null)) {
            throw new RuntimeException(sprintf('Unable to resolve Steam vanity profile "%s".', $vanityUrl));
        }

        return $response['steamid'];
    }

    /** @return array<string, mixed> */
    public function playerSummary(string $steamId): array
    {
        $data = $this->get('ISteamUser', 'GetPlayerSummaries', 'v2', [
            'steamids' => $steamId,
        ]);
        $response = $data['response'] ?? null;
        $players = is_array($response) ? ($response['players'] ?? null) : null;

        if (!is_array($players) || !isset($players[0]) || !is_array($players[0])) {
            throw new RuntimeException(sprintf('No public Steam profile data returned for %s.', $steamId));
        }

        /** @var array<string, mixed> $player */
        $player = $players[0];

        return $player;
    }

    /** @return list<array<string, mixed>> */
    public function ownedGames(string $steamId): array
    {
        $data = $this->get('IPlayerService', 'GetOwnedGames', 'v1', [
            'steamid' => $steamId,
            'include_appinfo' => 'true',
            'include_played_free_games' => 'true',
            'include_free_sub' => 'true',
            'skip_unvetted_apps' => 'false',
            'include_family_licenses' => 'true',
            'language' => $this->language,
        ]);
        $response = $data['response'] ?? null;
        $games = is_array($response) ? ($response['games'] ?? null) : null;

        if (!is_array($games)) {
            throw new RuntimeException(
                sprintf('Owned games are unavailable for %s. The Steam game details may be private.', $steamId),
            );
        }

        return array_values(array_filter($games, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function recentlyPlayedGames(string $steamId, int $count): array
    {
        $data = $this->get('IPlayerService', 'GetRecentlyPlayedGames', 'v1', [
            'steamid' => $steamId,
            'count' => (string) $count,
        ]);
        $response = $data['response'] ?? null;
        $games = is_array($response) ? ($response['games'] ?? null) : null;

        if (!is_array($games)) {
            return [];
        }

        return array_values(array_filter($games, 'is_array'));
    }

    /**
     * @param list<array{key: string, steam_id: string, app_id: int}> $requests
     * @return array<string, array<string, mixed>|null>
     */
    public function playerAchievementsBatch(array $requests): array
    {
        $urls = [];

        foreach ($requests as $request) {
            $urls[$request['key']] = $this->url('ISteamUserStats', 'GetPlayerAchievements', 'v1', [
                'steamid' => $request['steam_id'],
                'appid' => (string) $request['app_id'],
                'l' => $this->language,
            ]);
        }

        return $this->multiGet($urls);
    }

    /**
     * @param list<int> $appIds
     * @return array<int, array<string, mixed>|null>
     */
    public function globalAchievementPercentagesBatch(array $appIds): array
    {
        $urls = [];

        foreach ($appIds as $appId) {
            $urls[(string) $appId] = sprintf(
                '%s/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2/?gameid=%d&format=json',
                self::BASE_URL,
                $appId,
            );
        }

        $responses = $this->multiGet($urls);
        $result = [];

        foreach ($responses as $appId => $response) {
            $result[(int) $appId] = $response;
        }

        return $result;
    }

    /**
     * @param array<string, scalar> $parameters
     * @return array<string, mixed>
     */
    private function get(string $interface, string $method, string $version, array $parameters): array
    {
        $data = $this->singleUrl($this->url($interface, $method, $version, $parameters), true);

        if ($data === null) {
            throw new RuntimeException(sprintf('Steam API request failed: %s/%s.', $interface, $method));
        }

        return $data;
    }

    /** @param array<string, scalar> $parameters */
    private function url(string $interface, string $method, string $version, array $parameters): string
    {
        $parameters['key'] = $this->apiKey;
        $parameters['format'] = 'json';

        return sprintf(
            '%s/%s/%s/%s/?%s',
            self::BASE_URL,
            rawurlencode($interface),
            rawurlencode($method),
            rawurlencode($version),
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        );
    }

    /** @return array<string, mixed>|null */
    private function singleUrl(string $url, bool $throwOnHttpError): ?array
    {
        $lastHttpCode = 0;
        $lastError = '';

        for ($attempt = 1; $attempt <= $this->retryCount; $attempt++) {
            $handle = $this->createHandle($url);
            $response = curl_exec($handle);
            $lastHttpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($handle);
            curl_close($handle);

            if (is_string($response) && $lastHttpCode < 400) {
                $decoded = json_decode($response, true);

                return is_array($decoded) ? $decoded : null;
            }

            if (!$this->isRetryableHttpCode($lastHttpCode) || $attempt === $this->retryCount) {
                break;
            }

            $this->retryDelay($attempt);
        }

        if ($throwOnHttpError) {
            throw new RuntimeException(
                sprintf('Steam API HTTP request failed with status %d: %s', $lastHttpCode, $lastError),
            );
        }

        return null;
    }

    /**
     * @param array<string, string> $urls
     * @return array<string, array<string, mixed>|null>
     */
    private function multiGet(array $urls): array
    {
        $results = [];
        $pending = $urls;

        for ($attempt = 1; $attempt <= $this->retryCount && $pending !== []; $attempt++) {
            $retry = [];
            $chunks = array_chunk($pending, $this->concurrency, true);

            foreach ($chunks as $chunkIndex => $chunk) {
                $multiHandle = curl_multi_init();
                /** @var array<string, CurlHandle> $handles */
                $handles = [];

                foreach ($chunk as $key => $url) {
                    $handle = $this->createHandle($url);
                    $handles[$key] = $handle;
                    curl_multi_add_handle($multiHandle, $handle);
                }

                do {
                    $status = curl_multi_exec($multiHandle, $active);

                    if ($status !== CURLM_OK) {
                        break;
                    }

                    if ($active > 0) {
                        curl_multi_select($multiHandle, 1.0);
                    }
                } while ($active > 0);

                foreach ($handles as $key => $handle) {
                    $response = curl_multi_getcontent($handle);
                    $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    $decoded = is_string($response) && $httpCode < 400
                        ? json_decode($response, true)
                        : null;

                    if (is_array($decoded)) {
                        $results[$key] = $decoded;
                    } elseif ($this->isRetryableHttpCode($httpCode) && $attempt < $this->retryCount) {
                        $retry[$key] = $urls[$key];
                    } else {
                        $results[$key] = null;
                    }

                    curl_multi_remove_handle($multiHandle, $handle);
                    curl_close($handle);
                }

                curl_multi_close($multiHandle);

                if ($chunkIndex < count($chunks) - 1 && $this->batchDelayMilliseconds > 0) {
                    usleep($this->batchDelayMilliseconds * 1000);
                }
            }

            $pending = $retry;

            if ($pending !== [] && $attempt < $this->retryCount) {
                $this->retryDelay($attempt);
            }
        }

        foreach ($urls as $key => $_url) {
            if (!array_key_exists($key, $results)) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    private function retryDelay(int $attempt): void
    {
        $milliseconds = max(500, $this->batchDelayMilliseconds) * (2 ** ($attempt - 1));
        usleep(min($milliseconds, 8000) * 1000);
    }

    private function isRetryableHttpCode(int $httpCode): bool
    {
        return $httpCode === 0 || $httpCode === 429 || $httpCode >= 500;
    }

    private function createHandle(string $url): CurlHandle
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'AchievementBattle/1.2',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        return $handle;
    }
}
