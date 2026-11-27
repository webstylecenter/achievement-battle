import { randomBytes } from 'node:crypto';
import { readFile, mkdir, open, rename, unlink } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const outputPath = resolve(root, 'var/import/console-achievements.json');
const tokenPath = resolve(root, 'var/import/psn-tokens.json');
const players = ['peter', 'florian'];

function required(value, label) {
    if (typeof value !== 'string' || value.trim() === '') {
        throw new Error(label + ' must be configured.');
    }

    return value.trim();
}

function validIsoDate(value) {
    if (typeof value !== 'string' || !/(Z|[+-]\d{2}:\d{2})$/i.test(value)) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function percentage(value) {
    const number = Number(value);

    return value !== undefined && value !== null && value !== '' && Number.isFinite(number)
        && number >= 0 && number <= 100
        ? number
        : null;
}

async function readJson(path, fallback) {
    try {
        return JSON.parse(await readFile(path, 'utf8'));
    } catch (error) {
        if (error.code === 'ENOENT') {
            return fallback;
        }

        throw error;
    }
}

async function writePrivateJson(path, data) {
    await mkdir(dirname(path), { recursive: true, mode: 0o700 });
    const temporary = path + '.' + randomBytes(8).toString('hex') + '.tmp';
    const handle = await open(temporary, 'wx', 0o600);

    try {
        await handle.writeFile(JSON.stringify(data, null, 2) + '\n');
    } finally {
        await handle.close();
    }

    try {
        await rename(temporary, path);
    } catch (error) {
        await unlink(temporary);
        throw error;
    }
}

async function pause() {
    await new Promise((done) => setTimeout(done, Math.max(0, Number(process.env.CONSOLE_IMPORT_DELAY_MS ?? 350))));
}

async function psnAuthorization(psn) {
    const saved = await readJson(tokenPath, {});
    let tokens;

    if (typeof saved.refreshToken === 'string') {
        try {
            tokens = await psn.exchangeRefreshTokenForAuthTokens(saved.refreshToken);
        } catch {
            // The refresh token may be expired or revoked; NPSSO can bootstrap again.
        }
    }

    if (!tokens) {
        const npsso = required(process.env.PSN_NPSSO, 'PSN_NPSSO');
        const code = await psn.exchangeNpssoForAccessCode(npsso);
        tokens = await psn.exchangeAccessCodeForAuthTokens(code);
    }

    if (!tokens?.accessToken || !tokens?.refreshToken) {
        throw new Error('PSN did not return valid access and refresh tokens.');
    }

    await writePrivateJson(tokenPath, { refreshToken: tokens.refreshToken });

    return { accessToken: tokens.accessToken };
}

async function psnPages(getPage) {
    const items = [];
    let offset = 0;

    for (;;) {
        const response = await getPage(offset);

        if (!response || !Array.isArray(response.trophies)) {
            throw new Error('Unexpected PSN trophy response.');
        }

        items.push(...response.trophies);
        const total = response.totalItemCount;

        if (!Number.isInteger(total)) {
            throw new Error('PSN omitted trophy pagination metadata.');
        }

        if (response.trophies.length === 0 || items.length >= total) {
            if (Number.isInteger(total) && items.length < total) {
                throw new Error('PSN returned an incomplete trophy list.');
            }

            return items;
        }

        offset += response.trophies.length;
        await pause();
    }
}

export function psnGame(title, trophies, earned, accountId = '') {
    if (!title || typeof title.npCommunicationId !== 'string') {
        throw new Error('PSN title has no communication ID.');
    }

    const earnedById = new Map(earned.map((trophy) => [String(trophy.trophyId), trophy]));
    const achievements = [];

    for (const trophy of trophies) {
        const unlocked = earnedById.get(String(trophy.trophyId));

        if (unlocked?.earned !== true) {
            continue;
        }

        const row = {
            id: String(trophy.trophyId),
            name: trophy.trophyName || 'Unnamed trophy',
        };
        const unlockedAt = validIsoDate(unlocked.earnedDateTime);

        if (unlockedAt !== null) {
            row.unlocked_at = unlockedAt;
        }

        const rarity = percentage(trophy.trophyEarnedRate);

        if (rarity !== null) {
            row.rarity_percentage = rarity;
        }

        achievements.push(row);
    }

    return {
        id: title.npCommunicationId,
        name: title.trophyTitleName || title.npCommunicationId,
        image: title.trophyTitleIconUrl ?? '',
        total: trophies.length,
        source_updated_at: title.lastUpdatedDateTime ?? null,
        source_account_id: accountId,
        achievements,
    };
}

async function psnTitles(psn, authorization, accountId) {
    const titles = [];

    for (let offset = 0; ; offset += 800) {
        const response = await psn.getUserTitles(authorization, accountId, { limit: 800, offset });

        if (!response || !Array.isArray(response.trophyTitles)) {
            throw new Error('Unexpected PSN title list response.');
        }

        titles.push(...response.trophyTitles);

        if (titles.length >= response.totalItemCount || response.trophyTitles.length < 800) {
            if (Number.isInteger(response.totalItemCount) && titles.length < response.totalItemCount) {
                throw new Error('Incomplete PSN title list.');
            }

            return titles;
        }

        await pause();
    }
}

async function importPsn(psn, authorization, accountId, previous) {
    const titles = await psnTitles(psn, authorization, accountId);
    const cached = new Map(previous.map((game) => [game.id, game]));
    const games = [];

    for (const [index, title] of titles.entries()) {
        const old = cached.get(title.npCommunicationId);

        if (
            old
            && old.source_account_id === accountId
            && old.source_updated_at
            && old.source_updated_at === title.lastUpdatedDateTime
            && Array.isArray(old.achievements)
            && Number.isInteger(old.total)
        ) {
            games.push(old);
            continue;
        }

        const options = title.trophyTitlePlatform?.includes('PS5') ? {} : { npServiceName: 'trophy' };
        const trophyId = title.npCommunicationId;
        const trophies = await psnPages((offset) => psn.getTitleTrophies(
            authorization, trophyId, 'all', { ...options, limit: 100, offset },
        ));
        await pause();
        const earned = await psnPages((offset) => psn.getUserTrophiesEarnedForTitle(
            authorization, accountId, trophyId, 'all', { ...options, limit: 100, offset },
        ));

        games.push(psnGame(title, trophies, earned, accountId));
        console.log('PSN ' + accountId + ': ' + (index + 1) + '/' + titles.length + ' titles');
        await pause();
    }

    return games;
}

async function xblRequest(url, apiKey, request = fetch) {
    for (let attempt = 1; attempt <= 4; attempt++) {
        const response = await request(url, {
            headers: { 'X-Authorization': apiKey, Accept: 'application/json' },
            signal: AbortSignal.timeout(30000),
        });

        if (response.ok) {
            const json = await response.json();

            if (json.code && json.code !== 200) {
                throw new Error('OpenXBL returned upstream status ' + json.code);
            }

            const content = json.content ?? json;

            return typeof content === 'string' ? JSON.parse(content) : content;
        }

        if (response.status !== 429 && response.status < 500) {
            throw new Error('OpenXBL HTTP ' + response.status);
        }

        if (attempt === 4) {
            throw new Error('OpenXBL request failed after retries (HTTP ' + response.status + ').');
        }

        const retryAfter = Number(response.headers.get('retry-after'));
        const seconds = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.min(retryAfter, 60) : attempt * 3;
        await new Promise((done) => setTimeout(done, seconds * 1000));
    }

    throw new Error('Unable to fetch OpenXBL data.');
}

export function xboxGames(records) {
    const games = new Map();

    for (const achievement of records) {
        const title = achievement.titleAssociations?.[0];
        const unlocked = achievement.progressState === 'Achieved' || achievement.isUnlocked === true;

        if (!title?.id || !achievement.id) {
            continue;
        }

        const id = String(title.id);

        if (!games.has(id)) {
            games.set(id, { id, name: title.name || id, total: 0, achievements: [], seen: new Set() });
        }

        const game = games.get(id);
        const achievementId = String(achievement.id);

        if (game.seen.has(achievementId)) {
            continue;
        }

        game.seen.add(achievementId);
        game.total++;

        if (!unlocked) {
            continue;
        }

        const row = {
            id: String(achievement.id),
            name: achievement.name || String(achievement.id),
        };
        const unlockedAt = validIsoDate(achievement.progression?.timeUnlocked ?? achievement.timeUnlocked);

        if (unlockedAt !== null) {
            row.unlocked_at = unlockedAt;
        }

        const rarity = percentage(achievement.rarity?.currentPercentage ?? achievement.rarityPercentage);

        if (rarity !== null) {
            row.rarity_percentage = rarity;
        }

        game.achievements.push(row);
    }

    return [...games.values()].map(({ seen, ...game }) => game);
}

async function importXbox(xuid, apiKey, request = fetch) {
    const records = [];
    let next = null;
    const visited = new Set();

    for (;;) {
        const url = new URL('https://api.xbl.io/v3/achievements/player/' + encodeURIComponent(xuid));
        url.searchParams.set('maxItems', '200');

        if (next !== null) {
            url.searchParams.set('continuationToken', next);
        }

        const page = await xblRequest(url.href, apiKey, request);
        const achievements = Array.isArray(page) ? page : page?.achievements;

        if (!Array.isArray(achievements)) {
            throw new Error('Unexpected OpenXBL achievement response.');
        }

        records.push(...achievements);
        const token = page?.pagingInfo?.continuationToken;

        if (token === null || token === undefined || token === '') {
            break;
        }

        if (visited.has(String(token))) {
            throw new Error('OpenXBL repeated a continuation token.');
        }

        visited.add(String(token));
        next = String(token);
        await pause();
    }

    return xboxGames(records);
}

export async function sync(importPsnAccount = importPsn, importXboxAccount = importXbox) {
    const current = await readJson(outputPath, {});

    if (!current || typeof current !== 'object' || Array.isArray(current)) {
        throw new Error('Existing console import is malformed. Not overwriting it.');
    }

    const next = structuredClone(current);
    const psnAccounts = players.filter((key) => process.env['PSN_' + key.toUpperCase() + '_ACCOUNT_ID']);
    const xboxAccounts = players.filter((key) => process.env['XBOX_' + key.toUpperCase() + '_XUID']);

    if (psnAccounts.length === 0 && xboxAccounts.length === 0) {
        throw new Error('No console account IDs configured. Set PSN_*_ACCOUNT_ID or XBOX_*_XUID.');
    }

    if (psnAccounts.length > 0) {
        const psn = await import('psn-api');
        const authorization = await psnAuthorization(psn);

        for (const key of psnAccounts) {
            const accountId = required(process.env['PSN_' + key.toUpperCase() + '_ACCOUNT_ID'], key + ' PSN account ID');
            const previous = Array.isArray(current[key]?.playstation) ? current[key].playstation : [];
            const games = await importPsnAccount(psn, authorization, accountId, previous);
            next[key] ??= {};
            next[key].playstation = games;
            console.log('PSN ' + key + ': imported ' + games.length + ' games.');
        }
    }

    if (xboxAccounts.length > 0) {
        const apiKey = required(process.env.OPENXBL_API_KEY, 'OPENXBL_API_KEY');

        for (const key of xboxAccounts) {
            const xuid = required(process.env['XBOX_' + key.toUpperCase() + '_XUID'], key + ' Xbox XUID');

            if (!/^\d{10,20}$/.test(xuid)) {
                throw new Error('Invalid Xbox XUID for ' + key + '.');
            }

            const games = await importXboxAccount(xuid, apiKey);
            next[key] ??= {};
            next[key].xbox = games;
            console.log('Xbox ' + key + ': imported ' + games.length + ' games.');
        }
    }

    await writePrivateJson(outputPath, next);
    console.log('Console import saved. The dashboard will use it on the next request.');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    sync().catch((error) => {
        const secrets = [process.env.PSN_NPSSO, process.env.OPENXBL_API_KEY]
            .filter((secret) => typeof secret === 'string' && secret.length > 0);
        let message = error instanceof Error ? error.message : String(error);

        for (const secret of secrets) {
            message = message.replaceAll(secret, '[REDACTED]');
        }

        console.error('Console sync failed; previous import retained: ' + message);
        process.exitCode = 1;
    });
}
