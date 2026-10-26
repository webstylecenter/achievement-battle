'use strict';

const weeklyBattle = document.getElementById('weekly-battle');
const weeklyRange = document.getElementById('weekly-range');
const weeklyLeftName = document.getElementById('weekly-left-name');
const weeklyRightName = document.getElementById('weekly-right-name');
const weeklyLeftCount = document.getElementById('weekly-left-count');
const weeklyRightCount = document.getElementById('weekly-right-count');
const weeklyLeftFill = document.getElementById('weekly-left-fill');
const weeklyRightFill = document.getElementById('weekly-right-fill');
const weeklyResult = document.getElementById('weekly-result');

const weeklyNumberFormatter = new Intl.NumberFormat('nl-NL');
const weeklyDayFormatter = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
});
const weeklyReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

fetch('/api.php', { cache: 'no-store' })
    .then(async (response) => {
        if (!response.ok) {
            return null;
        }

        return response.json();
    })
    .then((data) => {
        if (data !== null) {
            renderWeeklyBattle(data);
        }
    })
    .catch(() => {
        if (weeklyBattle !== null) {
            weeklyBattle.hidden = true;
        }
    });

function renderWeeklyBattle(data) {
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

    const players = Array.isArray(data.players) ? data.players : [];
    const weeklyCompetition = data.weekly_competition ?? {};
    const sync = data.sync ?? {};

    if (players.length !== 2 || sync.initial_complete === false) {
        weeklyBattle.hidden = true;
        return;
    }

    const [left, right] = players;
    const leftTarget = Number(left.weekly_achievement_count ?? 0);
    const rightTarget = Number(right.weekly_achievement_count ?? 0);
    const leftTargetPercent = weeklyLeftPercentage(leftTarget, rightTarget);
    const rightTargetPercent = 100 - leftTargetPercent;

    weeklyLeftName.textContent = String(left.name ?? 'Peter');
    weeklyRightName.textContent = String(right.name ?? 'Florian');
    weeklyLeftCount.textContent = '0';
    weeklyRightCount.textContent = '0';
    weeklyLeftFill.style.width = '50%';
    weeklyRightFill.style.width = '50%';
    weeklyResult.textContent = '';

    if (weeklyCompetition.starts_at && weeklyCompetition.ends_at) {
        weeklyRange.textContent = `${weeklyDayFormatter.format(new Date(weeklyCompetition.starts_at))} – ${weeklyDayFormatter.format(new Date(weeklyCompetition.ends_at))}`;
    } else {
        weeklyRange.textContent = 'Resets Monday 00:00 NL';
    }

    weeklyBattle.hidden = false;

    const delay = weeklyReducedMotion ? 0 : 1750;
    const duration = weeklyReducedMotion ? 50 : 3000;

    window.setTimeout(() => {
        const startedAt = performance.now();

        function tick(now) {
            const rawProgress = Math.min((now - startedAt) / duration, 1);
            const acceleratedProgress = rawProgress * rawProgress * rawProgress;
            const currentLeftPercent = 50 + ((leftTargetPercent - 50) * acceleratedProgress);
            const currentRightPercent = 100 - currentLeftPercent;

            weeklyLeftCount.textContent = weeklyNumberFormatter.format(Math.round(leftTarget * acceleratedProgress));
            weeklyRightCount.textContent = weeklyNumberFormatter.format(Math.round(rightTarget * acceleratedProgress));
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
        return `Weekly battle is tied at ${weeklyNumberFormatter.format(leftScore)} · resets Monday 00:00 NL`;
    }

    const winner = leftScore > rightScore ? left : right;
    const lead = Math.abs(leftScore - rightScore);

    return `${String(winner.name)} leads this week by ${weeklyNumberFormatter.format(lead)} achievement${lead === 1 ? '' : 's'} · resets Monday 00:00 NL`;
}
