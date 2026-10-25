<?php

declare(strict_types=1);

use AchievementBattle\AppConfig;
use AchievementBattle\BattleService;
use AchievementBattle\CacheRepository;
use AchievementBattle\SteamApiClient;

require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/CacheRepository.php';
require_once __DIR__ . '/SteamApiClient.php';
require_once __DIR__ . '/BattleService.php';

$rootDirectory = dirname(__DIR__);
$config = new AppConfig($rootDirectory);
$cache = new CacheRepository($rootDirectory . '/var/cache');
$steam = new SteamApiClient(
    $config->steamApiKey(),
    $config->steamLanguage(),
    $config->steamConcurrency(),
    $config->steamBatchDelayMilliseconds(),
    $config->steamRetryCount(),
);

return new BattleService($config, $steam, $cache);
