'use strict';

const battleGrid = document.getElementById('battle-grid');
const winnerBanner = document.getElementById('winner-banner');
const snapshotTime = document.getElementById('snapshot-time');
const statsGrid = document.getElementById('stats-grid');
const recentGames = document.getElementById('recent-games');
const sharedGames = document.getElementById('shared-games');
const rareAchievements = document.getElementById('rare-achievements');
const excludedGames = document.getElementById('excluded-games');
const confetti = document.getElementById('confetti');

const numberFormatter = new Intl.NumberFormat('nl-NL');
const dateFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

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

function renderBattle(data) {
    const players = data.players ?? [];

    if (players.length !== 2) {
        throw new Error('Expected exactly two players.');
    }

    renderHero(players);
    renderStats(players);
    renderRecent(players);
    renderShared(players, data.shared_games ?? []);
    renderRare(players);
    renderExcluded(data.excluded_games ?? []);

    if (data.generated_at) {
        snapshotTime.textContent = `Data refreshed: ${dateFormatter.format(new Date(data.generated_at))}`;
    }
}

function renderHero(players) {
    const maxAchievements = Math.max(...players.map((player) => player.achievement_count), 1);

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
                <div class="counter-label">counted achievements</div>
            </div>
            <div class="progress-track" aria-hidden="true">
                <div class="progress-fill" data-width="${(player.achievement_count / maxAchievements) * 100}"></div>
            </div>
            <div class="mini-stats">
                <div class="mini-stat">
                    <strong>${numberFormatter.format(player.completed_games)}</strong>
                    <span>perfect games</span>
                </div>
                <div class="mini-stat">
                    <strong>${numberFormatter.format(player.hours_played)}h</strong>
                    <span>included playtime</span>
                </div>
            </div>
        </article>
    `).join('');

    const duration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 50 : 4200;
    const counters = [...document.querySelectorAll('.counter')];
    const fills = [...document.querySelectorAll('.progress-fill')];

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

        revealWinner(players);
    }

    requestAnimationFrame(tick);
}

function revealWinner(players) {
    const [left, right] = players;

    if (left.achievement_count === right.achievement_count) {
        winnerBanner.textContent = `Dead heat: both have ${numberFormatter.format(left.achievement_count)} achievements.`;
        winnerBanner.hidden = false;
        return;
    }

    const winner = left.achievement_count > right.achievement_count ? left : right;
    const loser = winner === left ? right : left;
    const lead = winner.achievement_count - loser.achievement_count;

    winnerBanner.textContent = `${winner.name} leads by ${numberFormatter.format(lead)} achievements.`;
    winnerBanner.hidden = false;

    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
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

function renderStats(players) {
    const [left, right] = players;
    const metrics = [
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
                        <img src="${escapeAttribute(game.image)}" data-fallback="${escapeAttribute(game.icon)}" alt="" loading="lazy">
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
        sharedGames.innerHTML = '<div class="config-note">No shared games with achievement data were found.</div>';
        return;
    }

    sharedGames.innerHTML = games.map((game) => {
        const leftStats = game.players[left.key];
        const rightStats = game.players[right.key];

        return `
            <article class="shared-row">
                <div class="shared-game-info" style="--game-image: url('${escapeCssUrl(game.image)}')">
                    <div>
                        <strong>${escapeHtml(game.name)}</strong>
                        <span>${numberFormatter.format(game.achievement_total)} achievements</span>
                    </div>
                </div>
                ${sharedPlayer(left, leftStats, game.winner === left.key, game.achievement_total)}
                ${sharedPlayer(right, rightStats, game.winner === right.key, game.achievement_total)}
            </article>
        `;
    }).join('');
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
    rareAchievements.innerHTML = players.map((player) => `
        <article class="rare-column">
            <h3 class="panel-title">${escapeHtml(player.name)}</h3>
            <div class="rare-list">
                ${(player.rare_achievements ?? []).map((achievement) => `
                    <div class="rare-item">
                        <img src="${escapeAttribute(achievement.game_image)}" alt="" loading="lazy">
                        <div>
                            <strong>${escapeHtml(achievement.name)}</strong>
                            <span>${escapeHtml(achievement.game_name)}</span>
                        </div>
                        <div class="rarity-percent">${Number(achievement.percentage).toFixed(2)}%</div>
                    </div>
                `).join('') || '<p>No rarity data available.</p>'}
            </div>
        </article>
    `).join('');
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
    container.querySelectorAll('img[data-fallback]').forEach((image) => {
        image.addEventListener('error', () => {
            if (image.dataset.fallback && image.src !== image.dataset.fallback) {
                image.src = image.dataset.fallback;
            }
        }, { once: true });
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

function escapeCssUrl(value) {
    return String(value).replaceAll("'", '%27').replaceAll(')', '%29');
}
