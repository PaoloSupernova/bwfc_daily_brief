<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="insights" x-data="insightsScreen()" x-init="load()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">Weekly Insights</h1>
            <p class="lede">Claude-generated editorial analysis of the week's coverage, produced every Monday.</p>
        </div>
        <div class="queue__header-action">
            <button type="button" class="btn btn--secondary" @click="generate()" :disabled="generating">
                <span x-show="!generating">Generate for this week</span>
                <span x-show="generating" x-cloak>Generating&hellip;</span>
            </button>
        </div>
    </header>

    <!-- Loading -->
    <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>

    <!-- No insights yet -->
    <div class="empty-state" x-show="!loading && !insights" x-cloak>
        <p>No weekly insights generated yet. Click "Generate for this week" to produce the first one.</p>
    </div>

    <!-- Error -->
    <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>
    <div class="notice notice--success" x-show="generateSuccess" x-cloak>Insights generated successfully.</div>

    <!-- Insights card -->
    <div class="insights-card" x-show="!loading && insights" x-cloak>

        <div class="insights-card__week">
            Week of <span x-text="formatDate(insights?.week_start)"></span> &ndash; <span x-text="formatDate(insights?.week_end)"></span>
            <span class="insights-card__generated">
                Generated <span x-text="formatDateTime(insights?.generated_at)"></span>
                by <span x-text="insights?.generated_by"></span>
            </span>
        </div>

        <div class="insights-card__summary" x-html="formatSummary(insights?.summary_text)"></div>

        <!-- Metrics -->
        <div class="insights-metrics" x-show="insights?.metrics_json?.metrics">
            <h2 class="heading-section">Coverage metrics</h2>
            <div class="insights-metrics__grid">

                <div class="insights-metric">
                    <div class="insights-metric__value" x-text="insights?.metrics_json?.metrics?.total_articles ?? '—'"></div>
                    <div class="insights-metric__label">Articles published</div>
                </div>
                <div class="insights-metric">
                    <div class="insights-metric__value" x-text="insights?.metrics_json?.metrics?.total_briefs ?? '—'"></div>
                    <div class="insights-metric__label">Briefs sent</div>
                </div>
                <div class="insights-metric">
                    <div class="insights-metric__value" x-text="insights?.metrics_json?.metrics?.bwfc_articles ?? '—'"></div>
                    <div class="insights-metric__label">BWFC articles</div>
                </div>
                <div class="insights-metric">
                    <div class="insights-metric__value" x-text="(insights?.metrics_json?.metrics?.national_pickup_pct ?? '—') + (insights?.metrics_json?.metrics?.national_pickup_pct !== undefined ? '%' : '')"></div>
                    <div class="insights-metric__label">National pickup</div>
                </div>

            </div>

            <!-- Top outlets -->
            <div class="insights-outlets" x-show="insights?.metrics_json?.metrics?.top_outlets?.length > 0">
                <h3 class="insights-outlets__title">Top outlets</h3>
                <ol class="insights-outlets__list">
                    <template x-for="outlet in (insights?.metrics_json?.metrics?.top_outlets ?? [])" :key="outlet.name">
                        <li class="insights-outlets__item">
                            <span class="insights-outlets__name" x-text="outlet.name"></span>
                            <span class="insights-outlets__count" x-text="outlet.count + ' article' + (outlet.count !== 1 ? 's' : '')"></span>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Section breakdown -->
            <div class="insights-sections" x-show="insights?.metrics_json?.metrics?.section_breakdown?.length > 0">
                <h3 class="insights-outlets__title">By section</h3>
                <ol class="insights-outlets__list">
                    <template x-for="sec in (insights?.metrics_json?.metrics?.section_breakdown ?? [])" :key="sec.name">
                        <li class="insights-outlets__item">
                            <span class="insights-outlets__name" x-text="sec.name"></span>
                            <span class="insights-outlets__count" x-text="sec.count"></span>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Most-covered people -->
            <div class="insights-sections" x-show="insights?.metrics_json?.metrics?.top_people?.length > 0">
                <h3 class="insights-outlets__title">Most-covered people</h3>
                <ol class="insights-outlets__list">
                    <template x-for="p in (insights?.metrics_json?.metrics?.top_people ?? [])" :key="p.name">
                        <li class="insights-outlets__item">
                            <span class="insights-outlets__name" x-text="p.name"></span>
                            <span class="insights-outlets__count">
                                <span x-text="p.count"></span><span x-show="p.negative > 0" x-cloak class="insights-neg" x-text="' · ' + p.negative + ' neg'"></span>
                            </span>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Coverage by topic -->
            <div class="insights-sections" x-show="insights?.metrics_json?.metrics?.topic_breakdown?.length > 0">
                <h3 class="insights-outlets__title">By topic</h3>
                <ol class="insights-outlets__list">
                    <template x-for="t in (insights?.metrics_json?.metrics?.topic_breakdown ?? [])" :key="t.slug">
                        <li class="insights-outlets__item">
                            <span class="insights-outlets__name" x-text="t.label"></span>
                            <span class="insights-outlets__count" x-text="t.count"></span>
                        </li>
                    </template>
                </ol>
            </div>

            <!-- Week-on-week comparison -->
            <div class="insights-comparison" x-show="insights?.metrics_json?.comparison">
                <template x-if="insights?.metrics_json?.comparison?.prior_total_articles > 0">
                    <p class="insights-comparison__text">
                        Prior week (<span x-text="formatDate(insights?.metrics_json?.comparison?.prior_week_start)"></span>
                        &ndash; <span x-text="formatDate(insights?.metrics_json?.comparison?.prior_week_end)"></span>):
                        <strong x-text="insights?.metrics_json?.comparison?.prior_total_articles"></strong> articles vs
                        <strong x-text="insights?.metrics_json?.metrics?.total_articles"></strong> this week
                        (<span x-text="weekOnWeekLabel()"></span>).
                    </p>
                </template>
            </div>
        </div>

    </div>

</div>

<script>
function insightsScreen() {
    return {
        loading: true,
        insights: null,
        error: '',
        generating: false,
        generateSuccess: false,

        async load() {
            this.loading = true;
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res = await fetch(root + '/api/insights_latest.php');
                const data = await res.json();
                this.insights = data.insights ?? null;
            } catch (e) {
                this.error = 'Could not load insights: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        async generate() {
            this.generating = true;
            this.error = '';
            this.generateSuccess = false;
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res = await fetch(root + '/api/insights_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ force: true }),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Generation failed');
                this.generateSuccess = true;
                await this.load();
            } catch (e) {
                this.error = 'Generation failed: ' + e.message;
            } finally {
                this.generating = false;
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
        },

        formatDateTime(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr.replace(' ', 'T'));
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        },

        formatSummary(text) {
            if (!text) return '';
            return text
                .split(/\n\n+/)
                .map(p => '<p>' + p.replace(/\n/g, '<br>') + '</p>')
                .join('');
        },

        weekOnWeekLabel() {
            const current = this.insights?.metrics_json?.metrics?.total_articles ?? 0;
            const prior = this.insights?.metrics_json?.comparison?.prior_total_articles ?? 0;
            if (prior === 0) return 'no prior data';
            const delta = current - prior;
            const pct = Math.abs(Math.round((delta / prior) * 100));
            return (delta >= 0 ? '+' : '-') + pct + '%';
        },
    };
}
</script>
