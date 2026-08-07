<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="journos" x-data="journalistsScreen()" x-init="load()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">Journalists</h1>
            <p class="lede">Who's writing about the club, and how they're covering it. Counts articles in the flagged sections below.</p>
        </div>
        <div class="dash__window-buttons">
            <button type="button" class="chip" :class="{ 'is-active': days === 30 }" @click="setDays(30)">30 days</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 90 }" @click="setDays(90)">90 days</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 365 }" @click="setDays(365)">1 year</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 0 }" @click="setDays(0)">All time</button>
        </div>
    </header>

    <!-- Which sections count -->
    <details class="journos-sections">
        <summary>Counted sections <span class="journos-sections__hint" x-text="countedLabel()"></span></summary>
        <div class="journos-sections__body">
            <p class="field__hint">Only articles in ticked sections count toward these stats.</p>
            <div class="journos-sections__grid">
                <template x-for="s in sections" :key="s.id">
                    <label class="journos-section-toggle">
                        <input type="checkbox" :checked="Number(s.counts_for_journalists) === 1"
                               @change="toggleSection(s, $event.target.checked)">
                        <span x-text="s.name"></span>
                    </label>
                </template>
            </div>
        </div>
    </details>

    <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>
    <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>

    <div class="empty-state" x-show="!loading && journalists.length === 0 && unassigned.article_count === 0" x-cloak>
        <p>No attributed coverage in this window yet. Bylines are captured automatically as articles are added; you can also set them by hand on the review page.</p>
    </div>

    <table class="journos-table" x-show="!loading && (journalists.length > 0 || unassigned.article_count > 0)" x-cloak>
        <thead>
            <tr>
                <th class="journos-table__rank">#</th>
                <th>Journalist</th>
                <th>Outlet</th>
                <th class="journos-table__num">Articles</th>
                <th class="journos-table__sent">Sentiment</th>
                <th class="journos-table__num">% Positive</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="(j, i) in journalists" :key="j.id">
                <tr>
                    <td class="journos-table__rank" x-text="i + 1"></td>
                    <td class="journos-table__name" x-text="j.name"></td>
                    <td class="journos-table__outlet" x-text="j.outlet || '—'"></td>
                    <td class="journos-table__num" x-text="j.article_count"></td>
                    <td class="journos-table__sent">
                        <span class="sent-split">
                            <span class="sent-split__seg sent-split__seg--positive" :style="segStyle(j, 'positive')" :title="j.positive + ' positive'"></span>
                            <span class="sent-split__seg sent-split__seg--neutral"  :style="segStyle(j, 'neutral')"  :title="j.neutral + ' neutral'"></span>
                            <span class="sent-split__seg sent-split__seg--negative" :style="segStyle(j, 'negative')" :title="j.negative + ' negative'"></span>
                        </span>
                        <span class="sent-split__legend">
                            <span x-text="j.positive"></span> /
                            <span x-text="j.neutral"></span> /
                            <span x-text="j.negative"></span>
                        </span>
                    </td>
                    <td class="journos-table__num">
                        <span x-show="j.pct_positive !== null" x-text="(j.pct_positive ?? 0) + '%'"></span>
                        <span x-show="j.pct_positive === null" class="journos-table__muted">—</span>
                    </td>
                </tr>
            </template>

            <!-- Unassigned bucket -->
            <tr class="journos-table__unassigned" x-show="unassigned.article_count > 0">
                <td class="journos-table__rank">—</td>
                <td class="journos-table__name"><em>Unassigned</em></td>
                <td class="journos-table__outlet">—</td>
                <td class="journos-table__num" x-text="unassigned.article_count"></td>
                <td class="journos-table__sent">
                    <span class="sent-split__legend">
                        <span x-text="unassigned.positive"></span> /
                        <span x-text="unassigned.neutral"></span> /
                        <span x-text="unassigned.negative"></span>
                    </span>
                </td>
                <td class="journos-table__num journos-table__muted">—</td>
            </tr>
        </tbody>
    </table>

    <p class="journos-footer" x-show="!loading && (journalists.length > 0 || unassigned.article_count > 0)" x-cloak>
        Sentiment shown as positive / neutral / negative. Articles without a detected byline are grouped as Unassigned &mdash;
        set the byline on the final review page to attribute them.
    </p>
</div>

<script>
function journalistsScreen() {
    return {
        loading: true,
        error: '',
        days: 90,
        journalists: [],
        unassigned: { article_count: 0, positive: 0, neutral: 0, negative: 0 },
        sections: [],

        async load() {
            this.loading = true;
            this.error = '';
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res = await fetch(root + '/api/journalists_stats.php?days=' + this.days);
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed to load');
                this.journalists = data.journalists || [];
                this.unassigned = data.unassigned || this.unassigned;
                this.sections = data.sections || [];
            } catch (e) {
                this.error = 'Could not load journalist stats: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        setDays(d) {
            this.days = d;
            this.load();
        },

        async toggleSection(section, checked) {
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                await fetch(root + '/api/sections_journalist_toggle.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ section_id: section.id, counts: checked }),
                });
                section.counts_for_journalists = checked ? 1 : 0;
                this.load();
            } catch (e) {
                this.error = 'Could not update section: ' + e.message;
            }
        },

        countedLabel() {
            const on = this.sections.filter(s => Number(s.counts_for_journalists) === 1).map(s => s.name);
            return on.length ? '(' + on.join(', ') + ')' : '(none)';
        },

        segStyle(j, kind) {
            const tagged = j.positive + j.neutral + j.negative;
            if (tagged === 0) return 'width:0%';
            const pct = Math.round((j[kind] / tagged) * 100);
            return 'width:' + pct + '%';
        },
    };
}
</script>
