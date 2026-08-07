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
        <h2 class="heading-section">Known people (<span x-text="people.length"></span>)</h2>
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
    };
}
</script>
