'use strict';

const battleHero = document.getElementById('battle-hero');
const battleGrid = document.getElementById('battle-grid');
const winnerBanner = document.getElementById('winner-banner');
const snapshotTime = document.getElementById('snapshot-time');
const competitionNote = document.getElementById('competition-note');
const syncDetails = document.getElementById('sync-details');
const statsGrid = document.getElementById('stats-grid');
const recentGames = document.getElementById('recent-games');
const sharedGames = document.getElementById('shared-games');
const raritySection = document.getElementById('rarity-section');
const rareAchievements = document.getElementById('rare-achievements');
const excludedGames = document.getElementById('excluded-games');
const confetti = document.getElementById('confetti');
const leftPortrait = document.getElementById('left-portrait');
const rightPortrait = document.getElementById('right-portrait');
const gameFallbackImage = '/assets/game-fallback.svg';
const platformFilter = document.getElementById('platform-filter');
const platformStatus = document.getElementById('platform-status');
const sharedGamesSection = document.getElementById('shared-games-section');
let currentPlatform = 'all';
let battleData = null;
let renderId = 0;

const numberFormatter = new Intl.NumberFormat('nl-NL');
const dateFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const dayFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
});
const historyMonthFormatter = new Intl.DateTimeFormat('nl-NL', {
    month: 'long',
    year: 'numeric',
    timeZone: 'Europe/Amsterdam',
});
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

triggerVersusIntro();

fetch('/api.php', { cache: 'no-store' })
    .then(async (response) => {
        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.hint ? `${data.error} ${data.hint}` : data.error);
        }

        return data;
    })
    .then((data) => {
        battleData = data;
        renderBattle(data);
    })
    .catch((error) => {
        battleGrid.innerHTML = `<div class="error-card"><strong>Battle data is not ready.</strong><p>${escapeHtml(error.message)}</p></div>`;
    });

platformFilter?.addEventListener('change', (event) => {
    const target = event.target;

    if (!(target instanceof HTMLInputElement) || target.name !== 'platform') {
        return;
    }

    if (!['all', 'pc', 'xbox', 'playstation'].includes(target.value)) {
        return;
    }

    currentPlatform = target.value;

    if (battleData !== null) {
        renderBattle(battleData, true);
    }
});

function triggerVersusIntro() {
    if (reducedMotion || battleHero === null) {
        return;
    }

    window.setTimeout(() => {
        battleHero.classList.add('impact');
    }, 1050);

    window.setTimeout(() => {
        battleHero.classList.remove('impact');
    }, 1550);
}

function renderBattle(data, skipAnimations = false) {
    const players = displayPlayers(data.players ?? []);
    const generation = ++renderId;
    const competition = data.competition ?? {};

    if (players.length !== 2) {
        throw new Error('Expected exactly two players.');
    }

    if (leftPortrait !== null && players[0].fighter_image) {
        leftPortrait.src = players[0].fighter_image;
    }

    if (rightPortrait !== null && players[1].fighter_image) {
        rightPortrait.src = players[1].fighter_image;
    }

    renderPlatformStatus(data.players ?? []);
    renderCompetitionNote(players, competition);
    renderHero(players, competition, data.sync ?? {}, skipAnimations, generation);
    renderMonthlyBattle(players, data.monthly_competition ?? {}, data.sync ?? {}, skipAnimations, generation);
    renderMonthlyHistory(filteredMonthlyHistory(data), players);
    renderStats(players, competition);
    renderRecent(players);
    if (sharedGamesSection !== null) {
        sharedGamesSection.hidden = currentPlatform === 'xbox' || currentPlatform === 'playstation';
    }

    if (sharedGamesSection === null || !sharedGamesSection.hidden) {
        renderShared(players, data.shared_games ?? []);
    }
    renderRare(players);
    renderExcluded(data.excluded_games ?? []);

    if (data.generated_at) {
        snapshotTime.textContent = `Data refreshed: ${dateFormatter.format(new Date(data.generated_at))}`;
    }
}


function displayPlayers(players) {
    return players.map((player) => {
        const sources = player.platform_scores;

        if (!sources) {
            return player;
        }

        const keys = currentPlatform === 'all' ? ['pc', 'xbox', 'playstation'] : [currentPlatform];
        const selected = keys.map((key) => sources[key]).filter(Boolean);
        const sum = (field) => selected.reduce((total, source) => total + Number(source[field] ?? 0), 0);
        const rare = selected.flatMap((source) => source.rare_achievements ?? []);
        const recent = selected.flatMap((source) => source.recent_games ?? []);

        rare.sort((a, b) => Number(a.percentage) - Number(b.percentage));
        recent.sort((a, b) => Number(b.last_played ?? 0) - Number(a.last_played ?? 0));

        return {
            ...player,
            achievement_count: sum('achievement_count'),
            lifetime_achievement_count: sum('lifetime_achievement_count'),
            completed_games: sum('completed_games'),
            lifetime_completed_games: sum('lifetime_completed_games'),
            games_with_achievements: sum('games_with_achievements'),
            monthly_achievement_count: sum('monthly_achievement_count'),
            hours_played: currentPlatform === 'pc' || currentPlatform === 'all'
                ? Number(sources.pc?.hours_played ?? 0)
                : 0,
            rare_achievements: rare.slice(0, 10),
            recent_games: recent.slice(0, 4),
        };
    });
}

function filteredMonthlyHistory(data) {
    const source = data.platform_monthly_histories ?? {};
    const pcHistory = source.pc ?? data.monthly_history ?? [];

    if (currentPlatform === 'pc') {
        return pcHistory;
    }

    if (currentPlatform !== 'all') {
        return source[currentPlatform] ?? [];
    }

    return pcHistory.map((row, index) => {
        const scores = {...row.players};

        for (const platform of ['xbox', 'playstation']) {
            const record = source[platform]?.[index]?.players ?? {};

            for (const playerKey of Object.keys(scores)) {
                scores[playerKey] += Number(record[playerKey] ?? 0);
            }
        }

        return {...row, players: scores};
    });
}

function renderPlatformStatus(players) {
    if (platformStatus === null) {
        return;
    }

    const names = { pc: 'PC', xbox: 'Xbox', playstation: 'PlayStation' };
    const platforms = currentPlatform === 'all' ? ['xbox', 'playstation'] : [currentPlatform];
    const missing = [];

    for (const platform of platforms) {
        if (platform === 'pc') {
            continue;
        }

        for (const player of players) {
            if (player.platform_scores?.[platform]?.imported !== true) {
                missing.push(`${player.name}: ${names[platform]}`);
            }
        }
    }

    platformStatus.textContent = missing.length > 0
        ? `Not imported yet: ${missing.join(', ')}. Totals include imported sources only.`
        : currentPlatform === 'all'
            ? 'Achievements from each platform are counted separately.'
            : `${names[currentPlatform]} achievements only.`;
}

function sourceBadge(platform) {
    const types = {
        pc: ['PC', 'PC / Steam'],
        xbox: ['X', 'Xbox'],
        playstation: ['PS', 'PlayStation'],
    };
    const [label, title] = types[platform] ?? types.pc;
    const source = Object.hasOwn(types, platform) ? platform : 'pc';

    return `<span class="source-badge source-badge-${source}" title="${title}" aria-label="${title}">${label}</span>`;
}

function renderCompetitionNote(players, competition) {
    if (!competition.starts_at) {
        competitionNote.textContent = 'Battle score counts all unlocked achievements.';
        return;
    }

    const startDate = dayFormatter.format(new Date(competition.starts_at));
    const source = players.find((player) => player.key === competition.source_player);
    competitionNote.textContent = source
        ? `Battle score counts achievements unlocked since ${startDate}, when ${source.name}'s Steam account was created.`
        : `Battle score counts achievements unlocked since ${startDate}.`;
}

function renderHero(players, competition, sync, skipAnimations = false, generation = renderId) {
    const maxAchievements = Math.max(...players.map((player) => player.achievement_count), 1);
    const counterLabel = competition.starts_at ? 'achievements since battle start' : 'counted achievements';
    const completedLabel = competition.starts_at ? 'perfect games since start' : 'perfect games';

    battleGrid.innerHTML = players.map((player, index) => `
        ${index === 1 ? '<div class="versus">VS</div>' : ''}
        <article class="fighter" data-player="${escapeHtml(player.key)}">
            <div class="fighter-top">
                <img class="avatar" src="${escapeAttribute(player.avatar)}" alt="${escapeAttribute(player.name)}">
                <div>
                    <h2 class="fighter-name">${escapeHtml(player.name)}</h2>
                    <p class="steam-name">Steam: ${escapeHtml(player.steam_name)}</p>
                </div>
            </div>
            <div class="counter-wrap">
                <span class="counter" data-target="${player.achievement_count}">0</span>
                <div class="counter-label">${counterLabel}</div>
            </div>
            <div class="progress-track" aria-hidden="true">
                <div class="progress-fill" data-width="${(player.achievement_count / maxAchievements) * 100}"></div>
            </div>
            <div class="mini-stats">
                <div class="mini-stat">
                    <strong>${numberFormatter.format(player.completed_games)}</strong>
                    <span>${completedLabel}</span>
                </div>
                <div class="mini-stat">
                    <strong>${currentPlatform === 'xbox' || currentPlatform === 'playstation' ? '—' : `${numberFormatter.format(player.hours_played)}h`}</strong>
                    <span>${currentPlatform === 'xbox' || currentPlatform === 'playstation' ? 'playtime not imported' : 'Steam playtime'}</span>
                </div>
            </div>
        </article>
    `).join('');

    installImageFallbacks(battleGrid);

    const duration = reducedMotion || skipAnimations ? 50 : 2000;
    const delay = reducedMotion || skipAnimations ? 0 : 700;
    const counters = [...document.querySelectorAll('.counter')];
    const fills = [...document.querySelectorAll('.progress-fill')];

    window.setTimeout(() => {
        if (generation !== renderId) {
            return;
        }

        fills.forEach((fill) => {
            fill.animate(
                [{ width: '0%' }, { width: `${fill.dataset.width}%` }],
                { duration, easing: 'cubic-bezier(.42,0,.85,1)', fill: 'forwards' },
            );
        });

        const startedAt = performance.now();

        function tick(now) {
            if (generation !== renderId) {
                return;
            }

            const rawProgress = Math.min((now - startedAt) / duration, 1);
            const acceleratedProgress = rawProgress * rawProgress * rawProgress;

            counters.forEach((counter) => {
                const target = Number(counter.dataset.target);
                counter.textContent = numberFormatter.format(Math.round(target * acceleratedProgress));
            });

            if (rawProgress < 1) {
                requestAnimationFrame(tick);
                return;
            }

            if (sync.initial_complete === false) {
                winnerBanner.hidden = true;
                return;
            }

            revealWinner(players, !skipAnimations);
        }

        requestAnimationFrame(tick);
    }, delay);
}

function renderMonthlyBattle(players, competition, sync, skipAnimations = false, generation = renderId) {
    const battle = document.getElementById('monthly-battle');
    const range = document.getElementById('monthly-range');
    const leftName = document.getElementById('monthly-left-name');
    const rightName = document.getElementById('monthly-right-name');
    const leftCount = document.getElementById('monthly-left-count');
    const rightCount = document.getElementById('monthly-right-count');
    const leftFill = document.getElementById('monthly-left-fill');
    const rightFill = document.getElementById('monthly-right-fill');
    const result = document.getElementById('monthly-result');

    if (
        battle === null
        || range === null
        || leftName === null
        || rightName === null
        || leftCount === null
        || rightCount === null
        || leftFill === null
        || rightFill === null
        || result === null
    ) {
        return;
    }

    if (sync.initial_complete === false) {
        battle.hidden = true;
        return;
    }

    const [left, right] = players;
    const leftTarget = Number(left.monthly_achievement_count ?? 0);
    const rightTarget = Number(right.monthly_achievement_count ?? 0);
    const leftTargetPercent = battleLeftPercentage(leftTarget, rightTarget);

    leftName.textContent = left.name;
    rightName.textContent = right.name;
    leftCount.textContent = '0';
    rightCount.textContent = '0';
    leftFill.style.width = '50%';
    rightFill.style.width = '50%';
    result.textContent = '';

    const resetTime = 'the 1st of the month at 00:00 NL';

    if (competition.starts_at && competition.ends_at) {
        range.textContent = `${dayFormatter.format(new Date(competition.starts_at))} – ${dayFormatter.format(new Date(competition.ends_at))}`;
    } else {
        range.textContent = `Resets ${resetTime}`;
    }

    battle.hidden = false;

    const delay = reducedMotion || skipAnimations ? 0 : 700;
    const duration = reducedMotion || skipAnimations ? 50 : 2000;

    window.setTimeout(() => {
        if (generation !== renderId) {
            return;
        }

        const startedAt = performance.now();

        function tick(now) {
            if (generation !== renderId) {
                return;
            }

            const rawProgress = Math.min((now - startedAt) / duration, 1);
            const progress = rawProgress * rawProgress * rawProgress;
            const currentLeftPercent = 50 + ((leftTargetPercent - 50) * progress);

            leftCount.textContent = numberFormatter.format(Math.round(leftTarget * progress));
            rightCount.textContent = numberFormatter.format(Math.round(rightTarget * progress));
            leftFill.style.width = `${currentLeftPercent}%`;
            rightFill.style.width = `${100 - currentLeftPercent}%`;

            if (rawProgress < 1) {
                requestAnimationFrame(tick);
                return;
            }

            result.textContent = monthlyResultText(left, right, leftTarget, rightTarget);
        }

        requestAnimationFrame(tick);
    }, delay);
}

function battleLeftPercentage(leftScore, rightScore) {
    if (leftScore === rightScore || leftScore + rightScore === 0) {
        return 50;
    }

    const proportional = (leftScore / (leftScore + rightScore)) * 100;

    return Math.min(85, Math.max(15, proportional));
}

function monthlyResultText(left, right, leftScore, rightScore) {
    const resetTime = 'the 1st of the month at 00:00 NL';

    if (leftScore === 0 && rightScore === 0) {
        return `No achievements unlocked yet this month · resets ${resetTime}`;
    }

    if (leftScore === rightScore) {
        return `Monthly battle is tied at ${numberFormatter.format(leftScore)} · resets ${resetTime}`;
    }

    const winner = leftScore > rightScore ? left : right;
    const lead = Math.abs(leftScore - rightScore);

    return `${winner.name} leads this month by ${numberFormatter.format(lead)} achievement${lead === 1 ? '' : 's'} · resets ${resetTime}`;
}

function renderMonthlyHistory(rows, players) {
    const toggle = document.getElementById('monthly-history-toggle');
    const container = document.getElementById('monthly-history');

    if (toggle === null || container === null || players.length !== 2) {
        return;
    }

    const [left, right] = players;
    const history = Array.isArray(rows) ? rows : [];
    const validRows = history.filter((row) => (
        row !== null
        && typeof row === 'object'
        && typeof row.starts_at === 'string'
        && typeof row.ends_at === 'string'
        && row.players !== null
        && typeof row.players === 'object'
    ));

    container.innerHTML = `
        <div class="period-history-heading">
            <strong>Previous months</strong>
            <span>${escapeHtml(left.name)} / ${escapeHtml(right.name)}</span>
        </div>
        <div class="period-history-list">
            ${validRows.map((row) => {
                const start = new Date(row.starts_at);
                const end = new Date(row.ends_at);
                const leftScore = Math.max(0, Number(row.players[left.key] ?? 0));
                const rightScore = Math.max(0, Number(row.players[right.key] ?? 0));

                if (!Number.isFinite(start.getTime()) || !Number.isFinite(end.getTime())) {
                    return '';
                }

                const label = historyMonthFormatter.format(start);
                const leftPercent = battleLeftPercentage(leftScore, rightScore);

                return `
                    <div class="period-history-row">
                        <span class="period-history-date">${escapeHtml(label)}</span>
                        <div class="period-history-scores" aria-label="${escapeAttribute(`${left.name}: ${leftScore}, ${right.name}: ${rightScore}`)}">
                            <strong class="period-history-peter">${numberFormatter.format(leftScore)}</strong>
                            <span class="period-history-meter" aria-hidden="true">
                                <span class="period-history-meter-left" style="width: ${leftPercent}%"></span>
                                <span class="period-history-meter-right" style="width: ${100 - leftPercent}%"></span>
                            </span>
                            <strong class="period-history-florian">${numberFormatter.format(rightScore)}</strong>
                        </div>
                    </div>
                `;
            }).join('') || '<p class="period-history-empty">No previous months available yet.</p>'}
        </div>
    `;

    container.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Show previous months');

    toggle.onclick = () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        container.hidden = expanded;
        toggle.setAttribute('aria-expanded', String(!expanded));
        toggle.setAttribute('aria-label', `${expanded ? 'Show' : 'Hide'} previous months`);
    };
}

function revealWinner(players, showConfetti = true) {
    const [left, right] = players;

    if (left.achievement_count === right.achievement_count) {
        winnerBanner.textContent = `Dead heat: both have ${numberFormatter.format(left.achievement_count)} counted achievements.`;
        winnerBanner.hidden = false;
        return;
    }

    const winner = left.achievement_count > right.achievement_count ? left : right;
    const loser = winner === left ? right : left;
    const lead = winner.achievement_count - loser.achievement_count;

    winnerBanner.textContent = `${winner.name} leads by ${numberFormatter.format(lead)} achievements.`;
    winnerBanner.hidden = false;

    if (!reducedMotion && showConfetti) {
        launchConfetti(winner.key);
    }
}

function launchConfetti(winnerKey) {
    const colors = winnerKey === 'peter'
        ? ['#4bbcff', '#93dcff', '#ffd166', '#ffffff']
        : ['#ff6b9a', '#ffb0ca', '#ffd166', '#ffffff'];

    for (let index = 0; index < 110; index++) {
        const piece = document.createElement('span');
        piece.className = 'confetti-piece';
        piece.style.left = `${Math.random() * 100}%`;
        piece.style.background = colors[index % colors.length];
        piece.style.setProperty('--delay', `${Math.random() * 0.8}s`);
        piece.style.setProperty('--duration', `${2.7 + Math.random() * 1.3}s`);
        piece.style.setProperty('--drift', `${-120 + Math.random() * 240}px`);
        piece.style.setProperty('--rotation', `${360 + Math.random() * 720}deg`);
        confetti.appendChild(piece);
    }

    window.setTimeout(() => {
        confetti.replaceChildren();
    }, 5200);
}

function renderStats(players, competition) {
    const [left, right] = players;
    const metrics = competition.starts_at
        ? [
            ['Achievements since start', left.achievement_count, right.achievement_count, formatNumber],
            ['Perfect games since start', left.completed_games, right.completed_games, formatNumber],
            ['Lifetime achievements after exclusions', left.lifetime_achievement_count, right.lifetime_achievement_count, formatNumber],
            ['Lifetime perfect games', left.lifetime_completed_games, right.lifetime_completed_games, formatNumber],
            ['Games with achievements', left.games_with_achievements, right.games_with_achievements, formatNumber],
            ['Included playtime', left.hours_played, right.hours_played, (value) => `${numberFormatter.format(value)}h`],
        ]
        : [
            ['Achievements', left.achievement_count, right.achievement_count, formatNumber],
            ['Perfect games', left.completed_games, right.completed_games, formatNumber],
            ['Games with achievements', left.games_with_achievements, right.games_with_achievements, formatNumber],
            ['Included playtime', left.hours_played, right.hours_played, (value) => `${numberFormatter.format(value)}h`],
        ];

    const visibleMetrics = metrics.filter(([label]) => (
        (currentPlatform === 'pc' || currentPlatform === 'all') || label !== 'Included playtime'
    ));

    statsGrid.innerHTML = visibleMetrics.map(([label, leftValue, rightValue, formatter]) => `
        <article class="stat-card">
            <div class="stat-title">${label}</div>
            <div class="stat-comparison">
                <strong>${formatter(leftValue)}</strong>
                <span>VS</span>
                <strong>${formatter(rightValue)}</strong>
            </div>
            <div class="stat-names">
                <span>${escapeHtml(left.name)}</span>
                <span>${escapeHtml(right.name)}</span>
            </div>
        </article>
    `).join('');
}

function renderRecent(players) {
    recentGames.innerHTML = players.map((player) => `
        <article class="player-recent">
            <h3 class="panel-title">${escapeHtml(player.name)}</h3>
            <div class="game-cards">
                ${(player.recent_games ?? []).map((game) => `
                    <div class="game-card">
                        <img src="${escapeAttribute(game.image)}" data-fallback="${escapeAttribute(game.icon)}" data-final-fallback="${gameFallbackImage}" alt="" loading="lazy">
                        <div class="game-card-body">
                            <strong>${sourceBadge(game.platform ?? 'pc')} ${escapeHtml(game.name)}</strong>
                            <span>${game.hours === null
                                ? 'Last achievement: ' + dateFormatter.format(new Date(Number(game.last_played) * 1000))
                                : numberFormatter.format(game.hours) + ' hours total'}</span>
                        </div>
                    </div>
                `).join('') || '<p>No recent games available.</p>'}
            </div>
        </article>
    `).join('');

    installImageFallbacks(recentGames);
}

function renderShared(players, games) {
    const [left, right] = players;

    if (games.length === 0) {
        sharedGames.innerHTML = '<div class="config-note">No unfinished games that both players have played were found.</div>';
        return;
    }

    sharedGames.innerHTML = games.map((game) => {
        const leftStats = game.players[left.key];
        const rightStats = game.players[right.key];
        const lastPlayed = game.last_played > 0
            ? `Last activity ${dayFormatter.format(new Date(game.last_played * 1000))}`
            : 'No recent activity date';

        return `
            <article class="shared-row">
                <div class="shared-game-info">
                    <img class="shared-game-cover" src="${escapeAttribute(game.image)}" data-fallback="${escapeAttribute(game.icon)}" data-final-fallback="${gameFallbackImage}" alt="" loading="lazy">
                    <div class="shared-game-copy">
                        <strong>${escapeHtml(game.name)}</strong>
                        <span>${numberFormatter.format(game.achievement_total)} achievements · ${lastPlayed}</span>
                    </div>
                </div>
                ${sharedPlayer(left, leftStats, game.winner === left.key, game.achievement_total)}
                ${sharedPlayer(right, rightStats, game.winner === right.key, game.achievement_total)}
            </article>
        `;
    }).join('');

    installImageFallbacks(sharedGames);
}

function sharedPlayer(player, stats, isWinner, total) {
    return `
        <div class="shared-player ${isWinner ? 'winner' : ''}">
            <div class="shared-player-top">
                <strong>${escapeHtml(player.name)}</strong>
                <span>${stats.unlocked} / ${total}</span>
            </div>
            <div class="micro-progress"><span style="width:${Math.min(stats.percentage, 100)}%"></span></div>
            <small>${stats.percentage}% complete · ${numberFormatter.format(stats.hours)}h played ${isWinner ? '· leading' : ''}</small>
        </div>
    `;
}

function renderRare(players) {
    const hasRarityData = players.some((player) => (player.rare_achievements ?? []).length > 0);

    if (!hasRarityData) {
        raritySection.hidden = true;
        return;
    }

    raritySection.hidden = false;
    rareAchievements.innerHTML = players.map((player) => `
        <article class="rare-column">
            <h3 class="panel-title">${escapeHtml(player.name)}</h3>
            <div class="rare-list">
                ${(player.rare_achievements ?? []).map((achievement) => `
                    <div class="rare-item">
                        <img src="${escapeAttribute(achievement.game_image)}" data-final-fallback="${gameFallbackImage}" alt="" loading="lazy">
                        <div>
                            <strong>${sourceBadge(achievement.platform ?? 'pc')} ${escapeHtml(achievement.name)}</strong>
                            <span>${escapeHtml(achievement.game_name)}</span>
                        </div>
                        <div class="rarity-percent">${Number(achievement.percentage).toFixed(1)}%</div>
                    </div>
                `).join('') || '<p>No rarity data available for this player.</p>'}
            </div>
        </article>
    `).join('');

    installImageFallbacks(rareAchievements);
}

function renderExcluded(games) {
    if (games.length === 0) {
        excludedGames.innerHTML = '<strong>Excluded games:</strong> none.';
        return;
    }

    excludedGames.innerHTML = `<strong>Excluded from every battle statistic:</strong> ${games
        .map((game) => `${escapeHtml(game.name)}`)
        .join(', ')}.`;
}

function installImageFallbacks(container) {
    container.querySelectorAll('img[data-fallback], img[data-final-fallback]').forEach((image) => {
        image.addEventListener('error', () => {
            const fallback = image.dataset.fallback ?? '';
            const finalFallback = image.dataset.finalFallback ?? gameFallbackImage;

            if (image.dataset.fallbackAttempted !== 'true' && fallback !== '') {
                image.dataset.fallbackAttempted = 'true';
                image.src = fallback;
                return;
            }

            const finalUrl = new URL(finalFallback, document.baseURI).href;

            if (image.src !== finalUrl) {
                image.src = finalFallback;
            }
        });
    });
}

function formatNumber(value) {
    return numberFormatter.format(value);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function escapeAttribute(value) {
    return escapeHtml(value);
}
