<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="dash" x-data="dashboardScreen()" x-init="loadAnalytics()">

    <header class="dash__hero">
        <div class="dash__hero-text">
            <h1 class="heading-display">Daily Brief</h1>
            <p class="lede">Coverage at a glance, last <span x-text="window"></span> days. Click into a brief or hit the Archive for deeper digs.</p>
        </div>
        <div class="dash__hero-action">
            <a href="?brief=new" class="btn btn--primary btn--large">Start today's brief</a>
        </div>
    </header>

    <div class="dash__window-toggle">
        <span class="dash__window-label">Showing data for:</span>
        <div class="dash__window-buttons">
            <button type="button" class="chip" :class="{ 'is-active': window === 7 }" @click="setWindow(7)">7 days</button>
            <button type="button" class="chip" :class="{ 'is-active': window === 30 }" @click="setWindow(30)">30 days</button>
            <button type="button" class="chip" :class="{ 'is-active': window === 90 }" @click="setWindow(90)">90 days</button>
        </div>
        <span class="dash__window-loading" x-show="loading" x-cloak>Loading...</span>
    </div>

    <!-- Headline stats: now 5 cards including National Pickup -->
    <section class="dash__stats">
        <div class="stat-card">
            <div class="stat-card__label">Briefs sent (all time)</div>
            <div class="stat-card__value" x-text="headlineStats.total_briefs ?? 0"></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__label">Articles in window</div>
            <div class="stat-card__value" x-text="headlineStats.articles_this_window ?? 0"></div>
            <div class="stat-card__delta"
                 x-show="headlineStats.articles_delta_pct !== null && headlineStats.articles_delta_pct !== undefined"
                 :class="(headlineStats.articles_delta_pct ?? 0) >= 0 ? 'is-up' : 'is-down'"
                 x-cloak>
                <span x-text="(headlineStats.articles_delta_pct ?? 0) >= 0 ? '↑' : '↓'"></span>
                <span x-text="Math.abs(headlineStats.articles_delta_pct ?? 0) + '% vs prior'"></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card__label">National pickup</div>
            <template x-if="(headlineStats.national_pickup?.total_bwfc ?? 0) > 0">
                <div>
                    <div class="stat-card__value"
                         x-text="(headlineStats.national_pickup?.pct ?? 0) + '%'"></div>
                    <div class="stat-card__sub">
                        <span x-text="headlineStats.national_pickup?.national_count ?? 0"></span>
                        of
                        <span x-text="headlineStats.national_pickup?.total_bwfc ?? 0"></span>
                        BWFC stor<span x-text="(headlineStats.national_pickup?.total_bwfc ?? 0) === 1 ? 'y' : 'ies'"></span>
                    </div>
                </div>
            </template>
            <template x-if="(headlineStats.national_pickup?.total_bwfc ?? 0) === 0">
                <div>
                    <div class="stat-card__value stat-card__value--small">No BWFC stories</div>
                    <div class="stat-card__sub">in this window</div>
                </div>
            </template>
        </div>

        <div class="stat-card">
            <div class="stat-card__label">Top outlet</div>
            <div class="stat-card__value stat-card__value--small"
                 x-text="headlineStats.top_outlet?.name ?? '—'"></div>
            <div class="stat-card__sub" x-show="headlineStats.top_outlet" x-cloak>
                <span x-text="headlineStats.top_outlet?.count"></span>
                article<span x-show="(headlineStats.top_outlet?.count ?? 0) !== 1">s</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card__label">Busiest section</div>
            <div class="stat-card__value stat-card__value--small"
                 x-text="headlineStats.busiest_section?.name ?? '—'"></div>
            <div class="stat-card__sub" x-show="headlineStats.busiest_section" x-cloak>
                <span x-text="headlineStats.busiest_section?.count"></span>
                article<span x-show="(headlineStats.busiest_section?.count ?? 0) !== 1">s</span>
            </div>
        </div>
    </section>

    <div class="dash__grid">

        <div class="dash__charts">

            <div class="dash__row">
                <div class="dash__panel dash__panel--half">
                    <h2 class="heading-section">Section share</h2>
                    <p class="dash__panel-hint" x-show="sectionShareEmpty()" x-cloak>No articles in this window yet.</p>
                    <canvas id="chart-section-share" x-show="!sectionShareEmpty()"></canvas>
                </div>

                <div class="dash__panel dash__panel--half">
                    <h2 class="heading-section">BWFC coverage: local vs national</h2>
                    <p class="dash__panel-hint" x-show="localNationalEmpty()" x-cloak>No BWFC-section articles in this window.</p>
                    <canvas id="chart-local-national" x-show="!localNationalEmpty()"></canvas>
                    <p class="dash__caption" x-show="!localNationalEmpty()" x-cloak>
                        Showing BWFC-section articles only. Local outlets: bwfc.co.uk, Bolton News, Lancashire Evening Post, Manchester Evening News, BWitC. Everything else counts as national pickup.
                    </p>
                </div>
            </div>

            <div class="dash__panel">
                <h2 class="heading-section">Top outlets</h2>
                <p class="dash__panel-hint" x-show="topOutlets.length === 0" x-cloak>No outlet data in this window yet.</p>
                <canvas id="chart-top-outlets" x-show="topOutlets.length > 0"></canvas>
            </div>

            <div class="dash__panel">
                <h2 class="heading-section">Coverage themes</h2>
                <p class="dash__panel-hint" x-show="wordCloud.length === 0" x-cloak>Not enough text yet to build a word cloud.</p>
                <div class="word-cloud" x-show="wordCloud.length > 0" x-ref="cloud"></div>
            </div>

        </div>

        <aside class="dash__sidebar">
            <h2 class="heading-section">Recent briefs</h2>
            <div class="empty-state" x-show="recentBriefs.length === 0" x-cloak>
                No briefs yet. Click "Start today's brief" above.
            </div>
            <div class="recent-list" x-show="recentBriefs.length > 0" x-cloak>
                <template x-for="brief in recentBriefs" :key="brief.id">
                    <a :href="briefUrl(brief.id)" class="recent-item">
                        <div class="recent-item__date-block">
                            <div class="recent-item__day-name" x-text="dayOfWeek(brief.brief_date)"></div>
                            <div class="recent-item__day-num" x-text="dayOfMonth(brief.brief_date)"></div>
                        </div>
                        <div class="recent-item__body">
                            <div class="recent-item__title" x-text="formatDateShort(brief.brief_date)"></div>
                            <div class="recent-item__meta">
                                <span x-text="brief.article_count + ' article' + (brief.article_count === 1 ? '' : 's')"></span>
                                <span class="status-pill"
                                      :class="brief.status === 'sent' ? 'status-pill--sent' : 'status-pill--draft'"
                                      x-text="brief.status === 'sent' ? 'Sent' : 'Draft'"></span>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
            <a href="?archive=1" class="dash__sidebar-cta">View full archive &rarr;</a>
        </aside>

    </div>
</div>