<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="journos" x-data="storiesScreen()" x-init="load()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">Story Tracker</h1>
            <p class="lede">Follow running narratives across the archive &mdash; how long a story has run, its spread, and where sentiment is heading.</p>
        </div>
    </header>

    <!-- Create / edit -->
    <section class="editor__section">
        <h2 class="heading-section" x-text="form.id ? 'Edit story' : 'Track a new story'"></h2>
        <div class="squad-form">
            <div class="field">
                <label class="field__label">Title</label>
                <input type="text" class="input" x-model="form.title" placeholder="e.g. Max Dean transfer saga">
            </div>
            <div class="field" style="grid-column: span 2;">
                <label class="field__label">Keywords <span class="field__label-meta">&middot; words to match; use "quotes" for phrases</span></label>
                <input type="text" class="input" x-model="form.keywords" placeholder='e.g. Max Dean Gent striker'>
            </div>
            <div class="squad-form__actions">
                <button type="button" class="btn btn--primary" @click="save()" :disabled="!form.title.trim() || !form.keywords.trim() || saving">
                    <span x-show="!saving" x-text="form.id ? 'Save' : 'Track story'"></span>
                    <span x-show="saving" x-cloak>Saving&hellip;</span>
                </button>
                <button type="button" class="btn btn--link" x-show="form.id" @click="resetForm()">Cancel</button>
            </div>
        </div>
        <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>
    </section>

    <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>
    <div class="empty-state" x-show="!loading && stories.length === 0" x-cloak>
        <p>No tracked stories yet. Add one above &mdash; e.g. a transfer saga or an ownership story &mdash; and its timeline builds itself from your archive.</p>
    </div>

    <!-- Story cards -->
    <div class="stories" x-show="!loading && stories.length > 0" x-cloak>
        <template x-for="s in stories" :key="s.id">
            <div class="story-card">
                <div class="story-card__head">
                    <div>
                        <div class="story-card__title" x-text="s.title"></div>
                        <div class="story-card__kw" x-text="s.keywords"></div>
                    </div>
                    <div class="story-card__actions">
                        <button type="button" class="btn btn--link btn--small" @click="edit(s)">Edit</button>
                        <button type="button" class="btn btn--link btn--small squad-del" @click="remove(s)">Delete</button>
                    </div>
                </div>

                <template x-if="s.stats.total === 0">
                    <p class="story-card__empty">No coverage matched yet.</p>
                </template>

                <template x-if="s.stats.total > 0">
                    <div>
                        <div class="story-metrics">
                            <div class="story-metric"><span class="story-metric__v" x-text="s.stats.total"></span><span class="story-metric__l">articles</span></div>
                            <div class="story-metric"><span class="story-metric__v" x-text="s.stats.days_running"></span><span class="story-metric__l">days running</span></div>
                            <div class="story-metric"><span class="story-metric__v" x-text="s.stats.outlets"></span><span class="story-metric__l">outlets</span></div>
                            <div class="story-metric"><span class="story-metric__v" x-text="s.stats.national"></span><span class="story-metric__l">national</span></div>
                        </div>
                        <div class="story-sentiment">
                            <span class="sent-split" style="width:160px;">
                                <span class="sent-split__seg sent-split__seg--positive" :style="segStyle(s.stats.sentiment,'positive')"></span>
                                <span class="sent-split__seg sent-split__seg--neutral"  :style="segStyle(s.stats.sentiment,'neutral')"></span>
                                <span class="sent-split__seg sent-split__seg--negative" :style="segStyle(s.stats.sentiment,'negative')"></span>
                            </span>
                            <span class="sent-split__legend">
                                <span x-text="s.stats.sentiment.positive"></span> /
                                <span x-text="s.stats.sentiment.neutral"></span> /
                                <span x-text="s.stats.sentiment.negative"></span>
                            </span>
                            <span class="story-dates" x-text="formatDate(s.stats.first_date) + ' → ' + formatDate(s.stats.last_date)"></span>
                        </div>
                        <div class="story-latest" x-show="s.stats.latest">
                            <span class="story-latest__label">Latest:</span>
                            <a :href="s.stats.latest.url" target="_blank" rel="noopener" x-text="s.stats.latest.headline"></a>
                            <span class="story-latest__meta" x-text="'(' + s.stats.latest.outlet + ', ' + formatDate(s.stats.latest.brief_date) + ')'"></span>
                        </div>
                        <button type="button" class="btn btn--link btn--small" @click="toggleTimeline(s.id)">
                            <span x-show="openId !== s.id">Show timeline &darr;</span>
                            <span x-show="openId === s.id" x-cloak>Hide timeline &uarr;</span>
                        </button>
                        <div class="story-timeline" x-show="openId === s.id" x-cloak>
                            <div class="queue__loading" x-show="timelineLoading" x-cloak>Loading&hellip;</div>
                            <template x-for="a in timeline" :key="a.id">
                                <div class="story-tl-item">
                                    <span class="story-tl-item__date" x-text="formatDate(a.brief_date)"></span>
                                    <span class="story-tl-item__dot" :class="'sent-split__seg--' + (a.sentiment || 'neutral')"></span>
                                    <span class="story-tl-item__outlet" x-text="a.outlet"></span>
                                    <a class="story-tl-item__headline" :href="a.url" target="_blank" rel="noopener" x-text="a.headline"></a>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

<script>
function storiesScreen() {
    return {
        loading: true, saving: false, error: '',
        stories: [], form: { id: 0, title: '', keywords: '', active: true },
        openId: null, timeline: [], timelineLoading: false,

        root() { return (window.BWFC_BASE || '').replace(/\/public\/?$/, ''); },

        async load() {
            this.loading = true;
            try {
                const res = await fetch(this.root() + '/api/stories_list.php');
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');
                this.stories = data.stories || [];
            } catch (e) { this.error = 'Could not load: ' + e.message; }
            finally { this.loading = false; }
        },

        resetForm() { this.form = { id: 0, title: '', keywords: '', active: true }; this.error = ''; },
        edit(s) { this.form = { id: s.id, title: s.title, keywords: s.keywords, active: Number(s.is_active) === 1 }; window.scrollTo({ top: 0, behavior: 'smooth' }); },

        async save() {
            this.saving = true; this.error = '';
            try {
                const res = await fetch(this.root() + '/api/stories_save.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(this.form),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Save failed');
                this.resetForm(); await this.load();
            } catch (e) { this.error = e.message; } finally { this.saving = false; }
        },

        async remove(s) {
            if (!confirm('Stop tracking "' + s.title + '"?')) return;
            await fetch(this.root() + '/api/stories_delete.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: s.id }),
            });
            await this.load();
        },

        async toggleTimeline(id) {
            if (this.openId === id) { this.openId = null; return; }
            this.openId = id; this.timeline = []; this.timelineLoading = true;
            try {
                const res = await fetch(this.root() + '/api/story_detail.php?id=' + id);
                const data = await res.json();
                this.timeline = (data.articles || []);
            } catch (e) { /* ignore */ } finally { this.timelineLoading = false; }
        },

        segStyle(sent, kind) {
            const t = sent.positive + sent.neutral + sent.negative;
            if (t === 0) return 'width:0%';
            return 'width:' + Math.round((sent[kind] / t) * 100) + '%';
        },
        formatDate(d) {
            if (!d) return '';
            const dt = new Date(d + 'T00:00:00');
            if (isNaN(dt.getTime())) return d;
            return dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        },
    };
}
</script>
