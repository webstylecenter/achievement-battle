<?php

declare(strict_types=1);

use AchievementBattle\AppConfig;
use AchievementBattle\ConsoleAchievementService;

require_once dirname(__DIR__) . '/src/AppConfig.php';
require_once dirname(__DIR__) . '/src/ConsoleAchievementService.php';

$directory = sys_get_temp_dir() . '/achievement-battle-test-' . bin2hex(random_bytes(8));

if (!mkdir($directory . '/config', 0700, true) || !mkdir($directory . '/var/import', 0700, true)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    file_put_contents(
        $directory . '/config/config.php',
        <<<'PHP'
<?php

return [
    'players' => [
        ['key' => 'peter', 'display_name' => 'Peter', 'profile' => 'a', 'profile_type' => 'steamid', 'fighter_image' => ''],
        ['key' => 'florian', 'display_name' => 'Florian', 'profile' => 'b', 'profile_type' => 'steamid', 'fighter_image' => ''],
    ],
];
PHP,
    );

    $month = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Europe/Amsterdam'));
    $previous = $month->modify('-1 month');
    $now = $month->modify('+12 hours')->format(DATE_ATOM);
    $prior = $previous->modify('+12 hours')->format(DATE_ATOM);
    $import = [
        'peter' => [
            'xbox' => [
                [
                    'id' => 'same-game',
                    'name' => 'A game',
                    'total' => 2,
                    'achievements' => [
                        ['id' => 'same-achievement', 'unlocked_at' => $now, 'rarity_percentage' => 3.2],
                        ['id' => 'other-achievement', 'unlocked_at' => $prior],
                    ],
                ],
            ],
            'playstation' => [
                [
                    'id' => 'same-game',
                    'name' => 'A game',
                    'total' => 1,
                    'achievements' => [
                        ['id' => 'same-achievement', 'unlocked_at' => $now],
                        ['id' => 'same-achievement', 'unlocked_at' => $now],
                    ],
                ],
            ],
        ],
        'florian' => ['xbox' => [], 'playstation' => []],
    ];

    file_put_contents(
        $directory . '/var/import/console-achievements.json',
        json_encode($import, JSON_THROW_ON_ERROR),
    );

    $snapshot = [
        'competition' => ['mode' => 'all_time', 'timestamp' => null],
        'monthly_competition' => [
            'starts_at' => $month->format(DATE_ATOM),
            'ends_at' => $month->modify('+1 month')->format(DATE_ATOM),
        ],
        'monthly_history' => [
            [
                'starts_at' => $previous->format(DATE_ATOM),
                'ends_at' => $month->format(DATE_ATOM),
                'players' => ['peter' => 0, 'florian' => 0],
            ],
        ],
        'players' => [
            [
                'key' => 'peter',
                'achievement_count' => 10,
                'lifetime_achievement_count' => 10,
                'completed_games' => 0,
                'lifetime_completed_games' => 0,
                'games_with_achievements' => 1,
                'monthly_achievement_count' => 3,
                'hours_played' => 9,
                'rare_achievements' => [],
                'recent_games' => [],
            ],
            [
                'key' => 'florian',
                'achievement_count' => 5,
                'lifetime_achievement_count' => 5,
                'completed_games' => 0,
                'lifetime_completed_games' => 0,
                'games_with_achievements' => 1,
                'monthly_achievement_count' => 1,
                'hours_played' => 6,
                'rare_achievements' => [],
                'recent_games' => [],
            ],
        ],
    ];

    $result = (new ConsoleAchievementService(new AppConfig($directory)))->enrich($snapshot);
    $peter = $result['players'][0]['platform_scores'];
    $florian = $result['players'][1]['platform_scores'];

    if (
        $peter['pc']['achievement_count'] !== 10
        || $peter['xbox']['achievement_count'] !== 2
        || $peter['playstation']['achievement_count'] !== 1
        || $peter['xbox']['monthly_achievement_count'] !== 1
        || $peter['playstation']['monthly_achievement_count'] !== 1
        || $peter['xbox']['lifetime_completed_games'] !== 1
        || $peter['playstation']['lifetime_completed_games'] !== 1
        || $florian['xbox']['achievement_count'] !== 0
        || $florian['xbox']['imported'] !== true
        || $result['platform_monthly_histories']['xbox'][0]['players']['peter'] !== 1
        || $result['platform_monthly_histories']['playstation'][0]['players']['peter'] !== 0
    ) {
        throw new RuntimeException('Cross-platform achievement aggregation failed.');
    }

    fwrite(STDOUT, "Console achievement aggregation smoke test passed.\n");
} finally {
    @unlink($directory . '/var/import/console-achievements.json');
    @unlink($directory . '/config/config.php');
    @rmdir($directory . '/var/import');
    @rmdir($directory . '/var');
    @rmdir($directory . '/config');
    @rmdir($directory);
}
