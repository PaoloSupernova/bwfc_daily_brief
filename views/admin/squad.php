<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="admin" x-data="squadAdmin()" x-init="load()">

    <header class="admin__header">
        <h1 class="heading-display">Squad &amp; People</h1>
        <p class="lede">The known list used to detect who coverage is about. Add players, staff and execs here when the squad changes.</p>
        <div class="admin__nav">
            <a href="?admin=sections" class="site-nav__link">Sections</a>
            <a href="?admin=sources" class="site-nav__link">Sources</a>
            <a href="?admin=jobs" class="site-nav__link">Jobs</a>
            <a href="?admin=squad" class="site-nav__link site-nav__link--active">Squad</a>
            <a href="?admin=knowledge" class="site-nav__link">Knowledge</a>
        </div>
    </header>

    <!-- Add / edit form -->
    <section class="editor__section">
        <h2 class="heading-section" x-text="form.id ? 'Edit person' : 'Add a person'"></h2>
        <div class="squad-form">
            <div class="field">
                <label class="field__label">Name</label>
                <input type="text" class="input" x-model="form.name" placeholder="e.g. Josh Sheehan">
            </div>
            <div class="field">
                <label class="field__label">Role</label>
                <select class="select" x-model="form.role">
                    <option value="player">Player</option>
                    <option value="staff">Staff</option>
                    <option value="exec">Executive</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Aliases <span class="field__label-meta">&middot; optional, comma-separated</span></label>
                <input type="text" class="input" x-model="form.aliases" placeholder="e.g. Chris Forino">
            </div>
            <div class="squad-form__actions">
                <button type="button" class="btn btn--primary" @click="save()" :disabled="!form.name.trim() || saving">
                    <span x-show="!saving" x-text="form.id ? 'Save changes' : 'Add person'"></span>
                    <span x-show="saving" x-cloak>Saving&hellip;</span>
                </button>
                <button type="button" class="btn btn--link" x-show="form.id" @click="resetForm()">Cancel</button>
            </div>
        </div>
        <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>
    </section>

    <!-- Roster -->
    <section class="editor__section">
        <h2 class="heading-section">
            Known people (<span x-text="people.length"></span>)
            <button type="button" class="btn btn--link btn--small" @click="mergeOpen ? closeMerge() : openMerge()"
                    x-text="mergeOpen ? 'Close merge' : 'Merge duplicates'"></button>
        </h2>

        <!-- Merge duplicates -->
        <div class="merge-panel" x-show="mergeOpen" x-cloak>
            <p class="field__hint">
                Pick the record to <strong>keep</strong>, then tick the duplicate(s) to merge into it. All article links move to
                the kept record, and the duplicates' names become aliases so future detection still works. This can't be undone.
            </p>
            <div class="field">
                <label class="field__label">Keep this record</label>
                <select class="select" x-model.number="mergeTarget"
                        @change="mergeSources = mergeSources.filter(id => id !== Number(mergeTarget))">
                    <option value="0">&mdash; choose &mdash;</option>
                    <template x-for="p in people" :key="p.id">
                        <option :value="p.id" x-text="p.name + ' · ' + roleLabel(p.role) + ' (' + (p.article_count || 0) + ')'"></option>
                    </template>
                </select>
            </div>
            <div class="field" x-show="Number(mergeTarget) > 0" x-cloak>
                <label class="field__label">Merge these duplicates into it</label>
                <div class="merge-sources">
                    <template x-for="p in mergeCandidates()" :key="p.id">
                        <label class="merge-source">
                            <input type="checkbox" :checked="mergeSources.includes(Number(p.id))" @change="toggleMergeSource(p.id)">
                            <span class="merge-source__name" x-text="p.name"></span>
                            <span class="merge-source__meta"
                                  x-text="roleLabel(p.role) + ' · ' + (p.article_count || 0) + ' link' + ((p.article_count || 0) === 1 ? '' : 's')"></span>
                        </label>
                    </template>
                </div>
            </div>
            <div class="squad-form__actions" x-show="Number(mergeTarget) > 0" x-cloak>
                <button type="button" class="btn btn--primary" @click="doMerge()" :disabled="merging || mergeSources.length === 0">
                    <span x-show="!merging">Merge <span x-text="mergeSources.length"></span> into kept record</span>
                    <span x-show="merging" x-cloak>Merging&hellip;</span>
                </button>
            </div>
            <div class="notice" :class="mergeOk ? 'notice--success' : 'notice--error'" x-show="mergeMsg" x-cloak x-text="mergeMsg"></div>
        </div>

        <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>

        <table class="journos-table" x-show="!loading && people.length > 0" x-cloak>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Aliases</th>
                    <th>Status</th>
                    <th class="squad-table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="p in people" :key="p.id">
                    <tr :class="{ 'is-inactive': Number(p.active) === 0 }">
                        <td class="journos-table__name" x-text="p.name"></td>
                        <td x-text="roleLabel(p.role)"></td>
                        <td class="journos-table__outlet" x-text="p.aliases || '—'"></td>
                        <td>
                            <span class="status-pill" :class="Number(p.active) === 1 ? 'status-pill--sent' : 'status-pill--draft'"
                                  x-text="Number(p.active) === 1 ? 'Active' : 'Inactive'"></span>
                            <span class="people-tag" x-show="Number(p.is_known) === 0" x-cloak>ad hoc</span>
                        </td>
                        <td class="squad-table__actions">
                            <button type="button" class="btn btn--link btn--small" @click="edit(p)">Edit</button>
                            <button type="button" class="btn btn--link btn--small" @click="toggleActive(p)"
                                    x-text="Number(p.active) === 1 ? 'Deactivate' : 'Reactivate'"></button>
                            <button type="button" class="btn btn--link btn--small squad-del" @click="remove(p)">Delete</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </section>
</div>

<script>
function squadAdmin() {
    return {
        loading: true,
        saving: false,
        error: '',
        people: [],
        form: { id: 0, name: '', role: 'player', aliases: '', active: true },

        // Merge duplicates
        mergeOpen: false,
        mergeTarget: 0,
        mergeSources: [],
        merging: false,
        mergeMsg: '',
        mergeOk: false,

        root() { return (window.BWFC_BASE || '').replace(/\/public\/?$/, ''); },

        async load() {
            this.loading = true;
            try {
                const res = await fetch(this.root() + '/api/people_admin_list.php');
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed to load');
                this.people = data.people || [];
            } catch (e) {
                this.error = 'Could not load: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        resetForm() {
            this.form = { id: 0, name: '', role: 'player', aliases: '', active: true };
            this.error = '';
        },

        edit(p) {
            this.form = {
                id: p.id,
                name: p.name,
                role: p.role,
                aliases: p.aliases || '',
                active: Number(p.active) === 1,
            };
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        async save() {
            this.saving = true;
            this.error = '';
            try {
                const res = await fetch(this.root() + '/api/people_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.form),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Save failed');
                this.resetForm();
                await this.load();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.saving = false;
            }
        },

        async toggleActive(p) {
            try {
                await fetch(this.root() + '/api/people_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: p.id, name: p.name, role: p.role,
                        aliases: p.aliases || '', active: Number(p.active) === 1 ? 0 : 1,
                    }),
                });
                await this.load();
            } catch (e) {
                this.error = e.message;
            }
        },

        async remove(p) {
            if (!confirm('Delete ' + p.name + '? Past article links are removed, but the articles stay. To keep history, deactivate instead.')) return;
            try {
                await fetch(this.root() + '/api/people_delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: p.id }),
                });
                await this.load();
            } catch (e) {
                this.error = e.message;
            }
        },

        roleLabel(r) {
            return { player: 'Player', staff: 'Staff', exec: 'Executive', other: 'Other' }[r] || r;
        },

        // ---- Merge duplicates ----
        openMerge() {
            this.mergeOpen = true;
            this.mergeTarget = 0;
            this.mergeSources = [];
            this.mergeMsg = '';
            this.mergeOk = false;
        },
        closeMerge() { this.mergeOpen = false; },

        mergeCandidates() {
            return this.people.filter(p => Number(p.id) !== Number(this.mergeTarget));
        },

        toggleMergeSource(id) {
            id = Number(id);
            const i = this.mergeSources.indexOf(id);
            if (i >= 0) this.mergeSources.splice(i, 1);
            else this.mergeSources.push(id);
        },

        async doMerge() {
            const target = Number(this.mergeTarget);
            const sources = this.mergeSources.filter(id => Number(id) !== target);
            if (!target || sources.length === 0) return;

            const keep = this.people.find(p => Number(p.id) === target);
            const keepName = keep ? keep.name : 'the kept record';
            if (!confirm('Merge ' + sources.length + ' record(s) into "' + keepName +
                         '"? Their article links move across and the duplicate records are deleted. This cannot be undone.')) return;

            this.merging = true;
            this.mergeMsg = '';
            try {
                const res = await fetch(this.root() + '/api/merge_records.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: 'person', target_id: target, source_ids: sources }),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Merge failed');
                const r = data.result || {};
                this.mergeOk = true;
                this.mergeMsg = 'Merged ' + (r.merged || 0) + ' record(s) into ' + (r.target_name || keepName) +
                                ' — ' + (r.links_moved || 0) + ' article link(s) moved.';
                this.mergeSources = [];
                this.mergeTarget = 0;
                await this.load();
            } catch (e) {
                this.mergeOk = false;
                this.mergeMsg = 'Error: ' + e.message;
            } finally {
                this.merging = false;
            }
        },
    };
}
</script>
