<?php

declare(strict_types=1);

use AchievementBattle\AppConfig;
use AchievementBattle\BattleApplicationService;
use AchievementBattle\MonthlyBattleService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $rootDirectory = dirname(__DIR__);

    /** @var BattleApplicationService $battle */
    $battle = require $rootDirectory . '/src/bootstrap.php';
    require_once $rootDirectory . '/src/MonthlyBattleService.php';

    $snapshot = $battle->cachedSnapshot();

    if ($snapshot === null) {
        http_response_code(503);
        echo json_encode([
            'error' => 'No battle snapshot exists yet.',
            'hint' => 'Run: ddev exec php bin/refresh.php',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $monthlyBattle = new MonthlyBattleService(new AppConfig($rootDirectory));
    $snapshot = $monthlyBattle->enrich($snapshot);

    echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
