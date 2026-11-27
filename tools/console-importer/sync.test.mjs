import assert from 'node:assert/strict';
import test from 'node:test';
import { psnGame, xboxGames } from './sync.mjs';

test('PlayStation converts earned trophies with IDs, dates and rarity', () => {
    const title = {
        npCommunicationId: 'NPWR12345_00',
        trophyTitleName: 'Sample game',
        trophyTitleIconUrl: 'https://example.com/image.jpg',
        lastUpdatedDateTime: '2026-11-20T13:00:00Z',
    };
    const game = psnGame(
        title,
        [
            { trophyId: 1, trophyName: 'First', trophyEarnedRate: '4.3' },
            { trophyId: 2, trophyName: 'Second', trophyEarnedRate: '20' },
        ],
        [
            { trophyId: 1, earned: true, earnedDateTime: '2026-11-20T13:00:00Z' },
            { trophyId: 2, earned: false },
        ],
        'account-123',
    );

    assert.equal(game.id, 'NPWR12345_00');
    assert.equal(game.total, 2);
    assert.equal(game.source_account_id, 'account-123');
    assert.deepEqual(game.achievements, [
        { id: '1', name: 'First', unlocked_at: '2026-11-20T13:00:00.000Z', rarity_percentage: 4.3 },
    ]);
});

test('Xbox converts completed achievements and deduplicates titles', () => {
    const games = xboxGames([
        {
            id: '1',
            titleAssociations: [{ id: 556, name: 'Sample game' }],
            name: 'One',
            progressState: 'Achieved',
            progression: { timeUnlocked: '2026-11-20T08:00:00.0000000Z' },
        },
        {
            id: '2',
            titleAssociations: [{ id: 556, name: 'Sample game' }],
            name: 'Two',
            progressState: 'NotStarted',
        },
        {
            id: '1',
            titleAssociations: [{ id: 556, name: 'Sample game' }],
            name: 'Duplicate page entry',
            progressState: 'Achieved',
        },
    ]);

    assert.equal(games.length, 1);
    assert.equal(games[0].id, '556');
    assert.equal(games[0].total, 2);
    assert.deepEqual(games[0].achievements, [
        { id: '1', name: 'One', unlocked_at: '2026-11-20T08:00:00.000Z' },
    ]);
});

test('Xbox preserves older unlocks without a timestamp for lifetime counts', () => {
    const games = xboxGames([
        {
            id: '56',
            titleAssociations: [{ id: '123', name: 'Legacy game' }],
            progressState: 'Achieved',
            name: 'Undated achievement',
        },
    ]);

    assert.deepEqual(games[0].achievements, [{ id: '56', name: 'Undated achievement' }]);
});
