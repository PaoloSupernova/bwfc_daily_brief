<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="journos" x-data="peopleScreen()" x-init="load()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">People in the news</h1>
            <p class="lede">Which players, staff and execs coverage is about &mdash; and how it's landing. Counts mentions across all sections.</p>
        </div>
        <div class="dash__window-buttons">
            <button type="button" class="chip" :class="{ 'is-active': days === 30 }" @click="setDays(30)">30 days</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 90 }" @click="setDays(90)">90 days</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 365 }" @click="setDays(365)">1 year</button>
            <button type="button" class="chip" :class="{ 'is-active': days === 0 }" @click="setDays(0)">All time</button>
        </div>
    </header>

    <div class="people-roles">
        <button type="button" class="chip" :class="{ 'is-active': role === '' }" @click="setRole('')">All</button>
        <button type="button" class="chip" :class="{ 'is-active': role === 'player' }" @click="setRole('player')">Players</button>
        <button type="button" class="chip" :class="{ 'is-active': role === 'staff' }" @click="setRole('staff')">Staff</button>
        <button type="button" class="chip" :class="{ 'is-active': role === 'exec' }" @click="setRole('exec')">Execs</button>
        <button type="button" class="chip" :class="{ 'is-active': role === 'other' }" @click="setRole('other')">Other</button>
        <a class="people-roles__admin" :href="squadAdminUrl()">Manage squad &rarr;</a>
    </div>

    <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>
    <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>

    <div class="empty-state" x-show="!loading && people.length === 0" x-cloak>
        <p>No people tracked in this window yet. Squad &amp; staff are detected automatically as articles are added; you can also set names by hand on the review page.</p>
    </div>

    <table class="journos-table" x-show="!loading && people.length > 0" x-cloak>
        <thead>
            <tr>
                <th class="journos-table__rank">#</th>
                <th>Person</th>
                <th>Role</th>
                <th class="journos-table__num">Mentions</th>
                <th class="journos-table__sent">Sentiment</th>
                <th class="journos-table__num">% Positive</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="(p, i) in people" :key="p.id">
                <tr>
                    <td class="journos-table__rank" x-text="i + 1"></td>
                    <td class="journos-table__name">
                        <span x-text="p.name"></span>
                        <span class="people-tag" x-show="p.is_known === 0" x-cloak title="Not on the known squad list">ad hoc</span>
                    </td>
                    <td class="journos-table__outlet" x-text="roleLabel(p.role)"></td>
                    <td class="journos-table__num" x-text="p.mention_count"></td>
                    <td class="journos-table__sent">
                        <span class="sent-split">
                            <span class="sent-split__seg sent-split__seg--positive" :style="segStyle(p, 'positive')" :title="p.positive + ' positive'"></span>
                            <span class="sent-split__seg sent-split__seg--neutral"  :style="segStyle(p, 'neutral')"  :title="p.neutral + ' neutral'"></span>
                            <span class="sent-split__seg sent-split__seg--negative" :style="segStyle(p, 'negative')" :title="p.negative + ' negative'"></span>
                        </span>
                        <span class="sent-split__legend">
                            <span x-text="p.positive"></span> /
                            <span x-text="p.neutral"></span> /
                            <span x-text="p.negative"></span>
                        </span>
                    </td>
                    <td class="journos-table__num">
                        <span x-show="p.pct_positive !== null" x-text="(p.pct_positive ?? 0) + '%'"></span>
                        <span x-show="p.pct_positive === null" class="journos-table__muted">—</span>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>

    <p class="journos-footer" x-show="!loading && people.length > 0" x-cloak>
        Sentiment shown as positive / neutral / negative and inherited from each article. &ldquo;ad hoc&rdquo; means a name added by hand or off the known squad list &mdash; promote it in Manage squad.
    </p>
</div>

<script>
function peopleScreen() {
    return {
        loading: true,
        error: '',
        days: 90,
        role: '',
        people: [],

        async load() {
            this.loading = true;
            this.error = '';
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const q = '?days=' + this.days + (this.role ? '&role=' + this.role : '');
                const res = await fetch(root + '/api/people_stats.php' + q);
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed to load');
                this.people = data.people || [];
            } catch (e) {
                this.error = 'Could not load people stats: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        setDays(d) { this.days = d; this.load(); },
        setRole(r) { this.role = r; this.load(); },

        roleLabel(r) {
            return { player: 'Player', staff: 'Staff', exec: 'Executive', other: 'Other' }[r] || r;
        },

        squadAdminUrl() {
            return (window.BWFC_BASE || '') + '/?admin=squad';
        },

        segStyle(p, kind) {
            const tagged = p.positive + p.neutral + p.negative;
            if (tagged === 0) return 'width:0%';
            return 'width:' + Math.round((p[kind] / tagged) * 100) + '%';
        },
    };
}
</script>
