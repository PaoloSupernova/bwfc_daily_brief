<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="dash" x-data="dashboardScreen()" x-init="loadAnalytics()">

    <!-- Reputation alerts -->
    <section class="alerts" x-show="visibleAlerts().length > 0" x-cloak>
        <template x-for="a in visibleAlerts()" :key="a.id">
            <div class="alert" :class="'alert--' + a.level">
                <div class="alert__icon" x-text="a.level === 'warning' ? '⚠' : 'ℹ'"></div>
                <div class="alert__body">
                    <div class="alert__title" x-text="a.title"></div>
                    <div class="alert__detail" x-text="a.detail"></div>
                </div>
                <button type="button" class="alert__dismiss" @click="dismissAlert(a.id)" title="Dismiss for today">&times;</button>
            </div>
        </template>
    </section>

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
                <h2 class="heading-section">Sentiment breakdown</h2>
                <p class="dash__panel-hint" x-show="sentimentTotal() === 0" x-cloak>
                    No sentiment data in this window yet. Articles added going forward will be tagged automatically.
                </p>
                <div class="sentiment-bars" x-show="sentimentTotal() > 0" x-cloak>
                    <button type="button" class="sentiment-bars__row sentiment-bars__row--clickable"
                            @click="openSentimentDrill('positive')"
                            :disabled="sentimentData.positive === 0">
                        <span class="sentiment-bars__label">
                            <span class="sentiment-badge sentiment-badge--positive">Positive</span>
                        </span>
                        <div class="sentiment-bars__track">
                            <div class="sentiment-bars__fill sentiment-bars__fill--positive"
                                 :style="'width: ' + sentimentPct(sentimentData.positive) + '%'"></div>
                        </div>
                        <span class="sentiment-bars__count" x-text="sentimentData.positive"></span>
                        <span class="sentiment-bars__pct" x-text="sentimentPct(sentimentData.positive) + '%'"></span>
                    </button>
                    <button type="button" class="sentiment-bars__row sentiment-bars__row--clickable"
                            @click="openSentimentDrill('neutral')"
                            :disabled="sentimentData.neutral === 0">
                        <span class="sentiment-bars__label">
                            <span class="sentiment-badge sentiment-badge--neutral">Neutral</span>
                        </span>
                        <div class="sentiment-bars__track">
                            <div class="sentiment-bars__fill sentiment-bars__fill--neutral"
                                 :style="'width: ' + sentimentPct(sentimentData.neutral) + '%'"></div>
                        </div>
                        <span class="sentiment-bars__count" x-text="sentimentData.neutral"></span>
                        <span class="sentiment-bars__pct" x-text="sentimentPct(sentimentData.neutral) + '%'"></span>
                    </button>
                    <button type="button" class="sentiment-bars__row sentiment-bars__row--clickable"
                            @click="openSentimentDrill('negative')"
                            :disabled="sentimentData.negative === 0">
                        <span class="sentiment-bars__label">
                            <span class="sentiment-badge sentiment-badge--negative">Negative</span>
                        </span>
                        <div class="sentiment-bars__track">
                            <div class="sentiment-bars__fill sentiment-bars__fill--negative"
                                 :style="'width: ' + sentimentPct(sentimentData.negative) + '%'"></div>
                        </div>
                        <span class="sentiment-bars__count" x-text="sentimentData.negative"></span>
                        <span class="sentiment-bars__pct" x-text="sentimentPct(sentimentData.negative) + '%'"></span>
                    </button>
                    <p class="sentiment-bars__footer">
                        <span x-text="sentimentTotal()"></span> article<span x-show="sentimentTotal() !== 1">s</span>
                        tagged in the last <span x-text="window"></span> days &middot;
                        <em>Click a row to see the articles</em>
                    </p>
                </div>
            </div>

            <div class="dash__panel">
                <h2 class="heading-section">Coverage by topic</h2>
                <p class="dash__panel-hint" x-show="topicBreakdown.length === 0" x-cloak>
                    No topic data in this window yet. Articles added going forward are classified automatically.
                </p>
                <div class="topic-bars" x-show="topicBreakdown.length > 0" x-cloak>
                    <template x-for="t in topicBreakdown" :key="t.slug">
                        <div class="topic-bars__row">
                            <span class="topic-bars__label" x-text="t.label"></span>
                            <div class="topic-bars__track">
                                <div class="topic-bars__fill" :style="'width: ' + topicBarPct(t.value) + '%'"></div>
                            </div>
                            <span class="topic-bars__count" x-text="t.value"></span>
                            <span class="topic-bars__neg" x-show="t.negative > 0" x-cloak
                                  :title="t.negative + ' negative'"
                                  x-text="'▾ ' + t.negative"></span>
                        </div>
                    </template>
                </div>
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

    <!-- Sentiment drill-down panel (slide in from right) -->
    <div class="drill-overlay" x-show="drillOpen" @click.self="closeDrill()" x-cloak
         x-transition:enter="drill-overlay--enter"
         x-transition:enter-start="drill-overlay--hidden"
         x-transition:enter-end="drill-overlay--visible"
         x-transition:leave="drill-overlay--enter"
         x-transition:leave-start="drill-overlay--visible"
         x-transition:leave-end="drill-overlay--hidden">
        <aside class="drill-panel">
            <div class="drill-panel__head">
                <div class="drill-panel__title">
                    <span class="sentiment-badge"
                          :class="'sentiment-badge--' + drillSentiment"
                          x-text="drillTitle()"></span>
                    <span class="drill-panel__subtitle">
                        articles &middot; last <span x-text="window"></span> days
                    </span>
                </div>
                <button type="button" class="drill-panel__close" @click="closeDrill()">&times;</button>
            </div>

            <div class="drill-panel__body">
                <div class="drill-panel__loading" x-show="drillLoading" x-cloak>Loading&hellip;</div>

                <div class="drill-panel__empty" x-show="!drillLoading && drillArticles.length === 0" x-cloak>
                    No articles found.
                </div>

                <template x-for="article in drillArticles" :key="article.id">
                    <div class="drill-article">
                        <div class="drill-article__meta">
                            <span class="drill-article__date" x-text="formatDateShort(article.brief_date)"></span>
                            <span class="drill-article__section" x-text="article.section_name"></span>
                            <span class="drill-article__outlet" x-text="article.outlet_name"></span>
                        </div>
                        <a class="drill-article__headline" :href="article.url" target="_blank" rel="noopener"
                           x-text="article.headline"></a>
                        <p class="drill-article__summary" x-text="article.summary"></p>
                        <div class="drill-article__actions">
                            <span class="drill-article__change-label">Change:</span>
                            <template x-for="s in ['positive', 'neutral', 'negative']" :key="s">
                                <button type="button"
                                        class="sentiment-toggle"
                                        :class="['sentiment-toggle--' + s, article.sentiment === s ? 'is-active' : '']"
                                        :disabled="article.sentiment === s"
                                        @click="updateSentiment(article.id, s)"
                                        x-text="s"></button>
                            </template>
                            <a class="drill-article__brief-link" :href="briefUrl(article.brief_id)">
                                View brief &rarr;
                            </a>
                        </div>
                    </div>
                </template>
            </div>
        </aside>
    </div>
</div>