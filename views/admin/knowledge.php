<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="admin" x-data="knowledgeAdmin()" x-init="load()">

    <header class="admin__header">
        <h1 class="heading-display">Knowledge Base</h1>
        <p class="lede">Durable facts, terminology and style rules injected into every AI summary — so the writing stays accurate and on-house-voice.</p>
        <div class="admin__nav">
            <a href="?admin=sections" class="site-nav__link">Sections</a>
            <a href="?admin=sources" class="site-nav__link">Sources</a>
            <a href="?admin=jobs" class="site-nav__link">Jobs</a>
            <a href="?admin=squad" class="site-nav__link">Squad</a>
            <a href="?admin=knowledge" class="site-nav__link site-nav__link--active">Knowledge</a>
        </div>
    </header>

    <!-- Add / edit -->
    <section class="editor__section">
        <h2 class="heading-section" x-text="form.id ? 'Edit entry' : 'Add knowledge'"></h2>
        <div class="squad-form">
            <div class="field">
                <label class="field__label">Category</label>
                <select class="select" x-model="form.category">
                    <template x-for="c in categories" :key="c">
                        <option :value="c" x-text="catLabel(c)"></option>
                    </template>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Title</label>
                <input type="text" class="input" x-model="form.title" placeholder="e.g. Stadium name">
            </div>
            <div class="field" style="grid-column: 1 / -1;">
                <label class="field__label">Content</label>
                <textarea class="textarea" rows="3" x-model="form.content" placeholder="The fact, terminology preference or style rule..."></textarea>
            </div>
            <div class="squad-form__actions">
                <button type="button" class="btn btn--primary" @click="save()" :disabled="!form.title.trim() || !form.content.trim() || saving">
                    <span x-show="!saving" x-text="form.id ? 'Save changes' : 'Add entry'"></span>
                    <span x-show="saving" x-cloak>Saving&hellip;</span>
                </button>
                <button type="button" class="btn btn--link" x-show="form.id" @click="resetForm()">Cancel</button>
            </div>
        </div>
        <div class="notice notice--error" x-show="error" x-cloak x-text="error"></div>
    </section>

    <!-- Distil style rules from edits -->
    <section class="editor__section">
        <h2 class="heading-section">Learn style rules from your edits</h2>
        <p class="field__hint">Analyse how your team has edited recent AI summaries and suggest reusable style rules. Keep the ones you like &mdash; they become active knowledge.</p>
        <button type="button" class="btn btn--secondary" @click="suggest()" :disabled="suggesting">
            <span x-show="!suggesting">Suggest style rules from my edits</span>
            <span x-show="suggesting" x-cloak>Analysing&hellip;</span>
        </button>
        <p class="field__hint" x-show="suggestNote" x-cloak x-text="suggestNote"></p>
        <div class="suggested-rules" x-show="suggestions.length > 0" x-cloak>
            <template x-for="(rule, i) in suggestions" :key="i">
                <div class="suggested-rule">
                    <span class="suggested-rule__text" x-text="rule"></span>
                    <button type="button" class="btn btn--link btn--small" @click="keepRule(rule, i)">Keep</button>
                    <button type="button" class="btn btn--link btn--small" @click="suggestions.splice(i,1)">Dismiss</button>
                </div>
            </template>
        </div>
    </section>

    <!-- Entries -->
    <section class="editor__section">
        <h2 class="heading-section">Entries (<span x-text="entries.length"></span>)</h2>
        <div class="queue__loading" x-show="loading" x-cloak>Loading&hellip;</div>
        <table class="journos-table" x-show="!loading && entries.length > 0" x-cloak>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Title</th>
                    <th>Content</th>
                    <th>Status</th>
                    <th class="squad-table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="e in entries" :key="e.id">
                    <tr :class="{ 'is-inactive': Number(e.is_active) === 0 }">
                        <td><span class="people-tag" x-text="catLabel(e.category)"></span></td>
                        <td class="journos-table__name" x-text="e.title"></td>
                        <td class="journos-table__outlet" x-text="e.content"></td>
                        <td>
                            <span class="status-pill" :class="Number(e.is_active) === 1 ? 'status-pill--sent' : 'status-pill--draft'"
                                  x-text="Number(e.is_active) === 1 ? 'Active' : 'Off'"></span>
                        </td>
                        <td class="squad-table__actions">
                            <button type="button" class="btn btn--link btn--small" @click="edit(e)">Edit</button>
                            <button type="button" class="btn btn--link btn--small" @click="toggleActive(e)"
                                    x-text="Number(e.is_active) === 1 ? 'Turn off' : 'Turn on'"></button>
                            <button type="button" class="btn btn--link btn--small squad-del" @click="remove(e)">Delete</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </section>
</div>

<script>
function knowledgeAdmin() {
    return {
        loading: true, saving: false, error: '',
        suggesting: false, suggestions: [], suggestNote: '',
        entries: [], categories: [],
        form: { id: 0, category: 'general', title: '', content: '', active: true },

        root() { return (window.BWFC_BASE || '').replace(/\/public\/?$/, ''); },
        catLabel(c) {
            return ({ fact:'Facts', terminology:'Terminology', context:'Context', style:'Style rules', avoid:'Avoid', general:'General' })[c] || c;
        },

        async load() {
            this.loading = true;
            try {
                const res = await fetch(this.root() + '/api/knowledge_list.php');
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');
                this.entries = data.entries || [];
                this.categories = data.categories || [];
            } catch (e) { this.error = 'Could not load: ' + e.message; }
            finally { this.loading = false; }
        },

        resetForm() { this.form = { id: 0, category: 'general', title: '', content: '', active: true }; this.error = ''; },
        edit(e) {
            this.form = { id: e.id, category: e.category, title: e.title, content: e.content, active: Number(e.is_active) === 1 };
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        async save() {
            this.saving = true; this.error = '';
            try {
                const res = await fetch(this.root() + '/api/knowledge_save.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.form),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Save failed');
                this.resetForm(); await this.load();
            } catch (e) { this.error = e.message; }
            finally { this.saving = false; }
        },

        async toggleActive(e) {
            await fetch(this.root() + '/api/knowledge_save.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: e.id, category: e.category, title: e.title, content: e.content, active: Number(e.is_active) === 1 ? 0 : 1 }),
            });
            await this.load();
        },

        async remove(e) {
            if (!confirm('Delete "' + e.title + '"?')) return;
            await fetch(this.root() + '/api/knowledge_delete.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: e.id }),
            });
            await this.load();
        },

        async suggest() {
            this.suggesting = true; this.suggestNote = ''; this.suggestions = [];
            try {
                const res = await fetch(this.root() + '/api/style_rules_suggest.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');
                this.suggestions = data.rules || [];
                this.suggestNote = data.note || (this.suggestions.length ? 'Review and keep the ones you like.' : '');
            } catch (e) { this.suggestNote = 'Error: ' + e.message; }
            finally { this.suggesting = false; }
        },

        async keepRule(rule, i) {
            await fetch(this.root() + '/api/knowledge_save.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ category: 'style', title: 'Style rule', content: rule, active: 1 }),
            });
            this.suggestions.splice(i, 1);
            await this.load();
        },
    };
}
</script>
