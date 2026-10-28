<?php

declare(strict_types=1);

use AchievementBattle\AppConfig;
use AchievementBattle\BattleApplicationService;
use AchievementBattle\BattleService;
use AchievementBattle\CacheRepository;
use AchievementBattle\HistoricalAchievementService;
use AchievementBattle\IncrementalBattleService;
use AchievementBattle\SteamApiClient;

require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/CacheRepository.php';
require_once __DIR__ . '/SteamApiClient.php';
require_once __DIR__ . '/BattleService.php';
require_once __DIR__ . '/IncrementalBattleService.php';
require_once __DIR__ . '/HistoricalAchievementService.php';
require_once __DIR__ . '/BattleApplicationService.php';

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
$battle = new BattleService($config, $steam, $cache);
$incremental = new IncrementalBattleService($config, $steam, $cache, $battle);
$historical = new HistoricalAchievementService($config, $cache);

return new BattleApplicationService($incremental, $historical, $cache);
