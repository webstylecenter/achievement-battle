<?php

declare(strict_types=1);

use AchievementBattle\BattleService;

/** @var BattleService $battle */
$battle = require dirname(__DIR__) . '/src/bootstrap.php';

fwrite(STDOUT, "Refreshing Steam achievement battle data...\n");

try {
    $snapshot = $battle->refresh();
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Refresh failed: %s\n", $exception->getMessage()));
    exit(1);
}

$players = $snapshot['players'] ?? [];

if (is_array($players)) {
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }

        $name = is_string($player['name'] ?? null) ? $player['name'] : 'Unknown';
        $count = is_int($player['achievement_count'] ?? null) ? $player['achievement_count'] : 0;
        fwrite(STDOUT, sprintf("- %s: %d achievements\n", $name, $count));
    }
}

fwrite(STDOUT, "Done.\n");
