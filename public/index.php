<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Achievement Battle</title>
    <link rel="preconnect" href="https://cdn.akamai.steamstatic.com">
    <link rel="preconnect" href="https://avatars.fastly.steamstatic.com">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main>
        <section class="hero" id="battle-hero" aria-labelledby="battle-title">
            <div class="versus-portraits" aria-hidden="true">
                <div class="portrait-panel portrait-panel-left">
                    <img id="left-portrait" src="/assets/fighters/peter.webp" alt="">
                </div>
                <div class="portrait-panel portrait-panel-right">
                    <img id="right-portrait" src="/assets/fighters/florian.webp" alt="">
                </div>
                <div class="impact-burst"></div>
            </div>
            <div class="hero-backdrop"></div>
            <div class="hero-content">
                <p class="eyebrow">Steam Achievement Battle</p>
                <h1 id="battle-title">Peter vs Florian</h1>
                <p class="hero-intro">One scoreboard. Every counted achievement. No mercy.</p>
                <p class="competition-note" id="competition-note"></p>
                <div class="config-note" id="sync-status" hidden></div>

                <div class="battle-grid" id="battle-grid" aria-live="polite">
                    <article class="fighter fighter-loading">
                        <div class="fighter-skeleton"></div>
                    </article>
                    <div class="versus">VS</div>
                    <article class="fighter fighter-loading">
                        <div class="fighter-skeleton"></div>
                    </article>
                </div>

                <div class="winner-banner" id="winner-banner" hidden></div>
                <p class="snapshot-time" id="snapshot-time"></p>
                <a class="scroll-cue" href="#comparison">Scroll for the damage report <span>↓</span></a>
            </div>
            <div class="confetti" id="confetti" aria-hidden="true"></div>
        </section>

        <section class="section" id="comparison">
            <div class="section-heading">
                <p class="eyebrow">Head to head</p>
                <h2>Battle statistics</h2>
            </div>
            <div class="stats-grid" id="stats-grid"></div>
        </section>

        <section class="section">
            <div class="section-heading">
                <p class="eyebrow">Recently played</p>
                <h2>What they have been playing</h2>
            </div>
            <div class="recent-columns" id="recent-games"></div>
        </section>

        <section class="section">
            <div class="section-heading">
                <p class="eyebrow">Recent shared games</p>
                <h2>Unfinished games both players have played</h2>
                <p>Sorted by the most recent activity. Games where either player already reached 100% are hidden.</p>
            </div>
            <div class="shared-games" id="shared-games"></div>
        </section>

        <section class="section" id="rarity-section">
            <div class="section-heading">
                <p class="eyebrow">Bragging rights</p>
                <h2>Rarest unlocked achievements</h2>
            </div>
            <div class="rarity-columns" id="rare-achievements"></div>
        </section>

        <section class="section section-compact">
            <div class="config-note" id="excluded-games"></div>
        </section>
    </main>

    <script src="/assets/app.js" defer></script>
</body>
</html>
