'use strict';

const battleHero = document.getElementById('battle-hero');
const battleGrid = document.getElementById('battle-grid');
const weeklyBattle = document.getElementById('weekly-battle');
const weeklyRange = document.getElementById('weekly-range');
const weeklyLeftName = document.getElementById('weekly-left-name');
const weeklyRightName = document.getElementById('weekly-right-name');
const weeklyLeftCount = document.getElementById('weekly-left-count');
const weeklyRightCount = document.getElementById('weekly-right-count');
const weeklyLeftFill = document.getElementById('weekly-left-fill');
const weeklyRightFill = document.getElementById('weekly-right-fill');
const weeklyResult = document.getElementById('weekly-result');
const winnerBanner = document.getElementById('winner-banner');
const snapshotTime = document.getElementById('snapshot-time');
const competitionNote = document.getElementById('competition-note');
const syncDetails = document.getElementById('sync-details');
const syncStatus = document.getElementById('sync-status');
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

const numberFormatter = new Intl.NumberFormat('nl-NL');
const dateFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const dayFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
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
    .then(renderBattle)
    .catch((error) => {
        battleGrid.innerHTML = `<div class="error-card"><strong>Battle data is not ready.</strong><p>${escapeHtml(error.message)}</p></div>`;
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

function renderBattle(data) {
    const players = data.players ?? [];
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

    renderCompetitionNote(players, competition);
    renderSyncStatus(data.sync ?? {}, players);
    renderHero(players, competition, data.sync ?? {});
    renderWeeklyBattle(players, data.weekly_competition ?? {}, data.sync ?? {});
    renderStats(players, competition);
    renderRecent(players);
    renderShared(players, data.shared_games ?? []);
    renderRare(players);
    renderExcluded(data.excluded_games ?? []);

    if (data.generated_at) {
        snapshotTime.textContent = `Data refreshed: ${dateFormatter.format(new Date(data.generated_at))}`;
    }
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

function renderSyncStatus(sync, players) {
    if (syncDetails === null || syncStatus === null) {
        return;
    }

    const pendingGames = Number(sync.pending_games ?? 0);
    const missingGames = Number(sync.missing_games ?? 0);
    const unavailableGames = Number(sync.unavailable_games ?? 0);
    const rateLimitedUntil = Number(sync.rate_limited_until ?? 0);
    const rateLimited = rateLimitedUntil > Math.floor(Date.now() / 1000);
    const stalePlayers = players.filter((player) => Number(player.sync?.stale_cache_games ?? 0) > 0);
    const unavailableItems = Array.isArray(sync.unavailable_items) ? sync.unavailable_items : [];

    if (pendingGames === 0 && unavailableGames === 0 && !rateLimited && stalePlayers.length === 0) {
        syncDetails.hidden = true;
        syncStatus.textContent = '';
        return;
    }

    const messages = [];

    if (sync.initial_complete === false) {
        messages.push(`${numberFormatter.format(missingGames)} games still need initial achievement data.`);
    } else if (pendingGames > 0) {
        messages.push(`${numberFormatter.format(pendingGames)} changed games are still being refreshed.`);
    }

    if (rateLimited) {
        messages.push(`Steam requests are paused until ${dateFormatter.format(new Date(rateLimitedUntil * 1000))}.`);
    }

    if (unavailableGames > 0) {
        const names = unavailableItems.slice(0, 5).map((item) => item.name).filter(Boolean);
        messages.push(`${numberFormatter.format(unavailableGames)} games are temporarily marked unavailable and will be retried later${names.length > 0 ? `: ${names.join(', ')}` : ''}.`);
    }

    if (stalePlayers.length > 0) {
        messages.push(`Cached achievement data was retained for ${stalePlayers.map((player) => player.name).join(' & ')} where Steam temporarily failed.`);
    }

    syncStatus.textContent = messages.join(' ');
    syncDetails.hidden = false;
}

function renderHero(players, competition, sync) {
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
                    <strong>${numberFormatter.format(player.hours_played)}h</strong>
                    <span>included playtime</span>
                </div>
            </div>
        </article>
    `).join('');

    installImageFallbacks(battleGrid);

    const duration = reducedMotion ? 50 : 4200;
    const delay = reducedMotion ? 0 : 1150;
    const counters = [...document.querySelectorAll('.counter')];
    const fills = [...document.querySelectorAll('.progress-fill')];

    window.setTimeout(() => {
        fills.forEach((fill) => {
            fill.animate(
                [{ width: '0%' }, { width: `${fill.dataset.width}%` }],
                { duration, easing: 'cubic-bezier(.42,0,.85,1)', fill: 'forwards' },
            );
        });

        const startedAt = performance.now();

        function tick(now) {
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

            revealWinner(players);
        }

        requestAnimationFrame(tick);
    }, delay);
}

function renderWeeklyBattle(players, weeklyCompetition, sync) {
    if (
        weeklyBattle === null
        || weeklyRange === null
        || weeklyLeftName === null
        || weeklyRightName === null
        || weeklyLeftCount === null
        || weeklyRightCount === null
        || weeklyLeftFill === null
        || weeklyRightFill === null
        || weeklyResult === null
    ) {
        return;
    }

    if (sync.initial_complete === false) {
        weeklyBattle.hidden = true;
        return;
    }

    const [left, right] = players;
    const leftTarget = Number(left.weekly_achievement_count ?? 0);
    const rightTarget = Number(right.weekly_achievement_count ?? 0);
    const leftTargetPercent = weeklyLeftPercentage(leftTarget, rightTarget);
    const rightTargetPercent = 100 - leftTargetPercent;

    weeklyLeftName.textContent = left.name;
    weeklyRightName.textContent = right.name;
    weeklyLeftCount.textContent = '0';
    weeklyRightCount.textContent = '0';
    weeklyLeftFill.style.width = '50%';
    weeklyRightFill.style.width = '50%';
    weeklyResult.textContent = '';

    if (weeklyCompetition.starts_at && weeklyCompetition.ends_at) {
        weeklyRange.textContent = `${dayFormatter.format(new Date(weeklyCompetition.starts_at))} – ${dayFormatter.format(new Date(weeklyCompetition.ends_at))}`;
    } else {
        weeklyRange.textContent = 'Resets Monday 00:00 NL';
    }

    weeklyBattle.hidden = false;

    const delay = reducedMotion ? 0 : 1750;
    const duration = reducedMotion ? 50 : 3000;

    window.setTimeout(() => {
        const startedAt = performance.now();

        function tick(now) {
            const rawProgress = Math.min((now - startedAt) / duration, 1);
            const acceleratedProgress = rawProgress * rawProgress * rawProgress;
            const currentLeftPercent = 50 + ((leftTargetPercent - 50) * acceleratedProgress);
            const currentRightPercent = 100 - currentLeftPercent;

            weeklyLeftCount.textContent = numberFormatter.format(Math.round(leftTarget * acceleratedProgress));
            weeklyRightCount.textContent = numberFormatter.format(Math.round(rightTarget * acceleratedProgress));
            weeklyLeftFill.style.width = `${currentLeftPercent}%`;
            weeklyRightFill.style.width = `${currentRightPercent}%`;

            if (rawProgress < 1) {
                requestAnimationFrame(tick);
                return;
            }

            weeklyResult.textContent = weeklyResultText(left, right, leftTarget, rightTarget);
        }

        requestAnimationFrame(tick);
    }, delay);
}

function weeklyLeftPercentage(leftScore, rightScore) {
    if (leftScore === rightScore || leftScore + rightScore === 0) {
        return 50;
    }

    const proportional = (leftScore / (leftScore + rightScore)) * 100;

    return Math.min(85, Math.max(15, proportional));
}

function weeklyResultText(left, right, leftScore, rightScore) {
    if (leftScore === 0 && rightScore === 0) {
        return 'No achievements unlocked yet this week · resets Monday 00:00 NL';
    }

    if (leftScore === rightScore) {
        return `Weekly battle is tied at ${numberFormatter.format(leftScore)} · resets Monday 00:00 NL`;
    }

    const winner = leftScore > rightScore ? left : right;
    const lead = Math.abs(leftScore - rightScore);

    return `${winner.name} leads this week by ${numberFormatter.format(lead)} achievement${lead === 1 ? '' : 's'} · resets Monday 00:00 NL`;
}

function revealWinner(players) {
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

    if (!reducedMotion) {
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

    statsGrid.innerHTML = metrics.map(([label, leftValue, rightValue, formatter]) => `
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
                            <strong>${escapeHtml(game.name)}</strong>
                            <span>${numberFormatter.format(game.hours)} hours total</span>
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
                            <strong>${escapeHtml(achievement.name)}</strong>
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
        .map((game) => `${escapeHtml(game.name)} (AppID ${game.app_id})`)
        .join(', ')}. Edit <code>config/config.php</code> to change this list, then refresh the snapshot.`;
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
