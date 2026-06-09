<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="admin" x-data="sourcesAdmin()" x-init="loadSources()">

    <header class="admin__header">
        <h1 class="heading-display">Discovery Sources</h1>
        <p class="lede">RSS feeds and Google News alerts polled for the morning queue. Adding a feed here means new articles appear on the dashboard automatically.</p>
        <div class="admin__nav">
            <a href="?admin=sections" class="site-nav__link">Sections</a>
            <a href="?admin=sources" class="site-nav__link site-nav__link--active">Sources</a>
            <a href="?admin=jobs" class="site-nav__link">Jobs</a>
        </div>
    </header>

    <div class="admin__toolbar">
        <div class="admin__status" x-text="statusMessage" x-cloak></div>
        <div class="admin__toolbar-right">
            <button type="button" class="btn btn--secondary" @click="pollAll()" :disabled="polling">
                <span x-show="!polling">Refresh all feeds</span>
                <span x-show="polling" x-cloak>Polling...</span>
            </button>
            <button type="button" class="btn btn--primary" @click="startAdd()" x-show="!adding && editingId === null">+ Add source</button>
        </div>
    </div>

    <!-- Add new source form -->
    <div class="section-editor" x-show="adding" x-cloak>
        <h3 class="heading-section">New source</h3>
        <div class="field">
            <label class="field__label">Name</label>
            <input type="text" class="input" x-model="form.name" placeholder="e.g. Athletic Bolton tag">
        </div>
        <div class="field">
            <label class="field__label">URL</label>
            <input type="text" class="input" x-model="form.url" placeholder="https://example.com/feed.xml">
            <p class="field__hint">For Google News, use the RSS URL of a search query: https://news.google.com/rss/search?q=...&hl=en-GB&gl=GB&ceid=GB:en</p>
        </div>
        <div class="admin__form-row">
            <div class="field">
                <label class="field__label">Type</label>
                <select class="select" x-model="form.source_type">
                    <option value="rss">RSS / Atom</option>
                    <option value="google_news">Google News RSS</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Poll every</label>
                <select class="select" x-model="form.poll_frequency_minutes">
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="720">12 hours</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="checkbox-label">
                <input type="checkbox" x-model="form.is_local"> Local outlet (counts as Bolton-area for analytics)
            </label>
        </div>
        <div class="field">
            <label class="checkbox-label">
                <input type="checkbox" x-model="form.is_active"> Active (poll this source)
            </label>
        </div>
        <div class="admin__actions">
            <button type="button" class="btn btn--secondary" @click="cancelAdd()">Cancel</button>
            <button type="button" class="btn btn--primary" @click="saveSource()">Save source</button>
        </div>
    </div>

    <!-- Sources list -->
    <div class="sources-list" x-show="!adding && editingId === null">
        <template x-for="source in sources" :key="source.id">
            <article class="source-row" :class="{ 'is-inactive': !source.is_active }">
                <div class="source-row__main">
                    <div class="source-row__head">
                        <h3 class="source-row__name" x-text="source.name"></h3>
                        <span class="source-row__type-pill" x-text="source.source_type === 'google_news' ? 'Google News' : 'RSS'"></span>
                        <span class="source-row__local-pill" x-show="source.is_local" x-cloak>LOCAL</span>
                        <span class="source-row__inactive-pill" x-show="!source.is_active" x-cloak>INACTIVE</span>
                    </div>
                    <div class="source-row__url" x-text="source.url"></div>
                    <div class="source-row__meta">
                        <span>Poll every <span x-text="formatFreq(source.poll_frequency_minutes)"></span></span>
                        <span>&middot;</span>
                        <span>Last polled: <span x-text="formatDate(source.last_polled_at) || 'never'"></span></span>
                        <span>&middot;</span>
                        <span class="source-row__candidates"><strong x-text="source.new_candidates"></strong> new candidate<span x-show="source.new_candidates !== 1">s</span></span>
                        <span x-show="source.last_error" x-cloak class="source-row__error">
                            &middot; ERROR: <span x-text="source.last_error"></span>
                        </span>
                    </div>
                </div>
                <div class="source-row__actions">
                    <button type="button" class="btn btn--secondary btn--small" @click="pollOne(source.id)" :disabled="polling">Poll now</button>
                    <button type="button" class="btn btn--secondary btn--small" @click="startEdit(source)">Edit</button>
                    <button type="button" class="link-btn link-btn--danger" @click="deleteSource(source)">Delete</button>
                </div>
            </article>
        </template>
        <div class="empty-state" x-show="sources.length === 0 && !loading" x-cloak>
            No sources configured yet. Add one to start collecting candidates.
        </div>
    </div>

    <!-- Edit form -->
    <div class="section-editor" x-show="editingId !== null" x-cloak>
        <h3 class="heading-section">Edit source</h3>
        <div class="field">
            <label class="field__label">Name</label>
            <input type="text" class="input" x-model="form.name">
        </div>
        <div class="field">
            <label class="field__label">URL</label>
            <input type="text" class="input" x-model="form.url">
        </div>
        <div class="admin__form-row">
            <div class="field">
                <label class="field__label">Type</label>
                <select class="select" x-model="form.source_type">
                    <option value="rss">RSS / Atom</option>
                    <option value="google_news">Google News RSS</option>
                </select>
            </div>
            <div class="field">
                <label class="field__label">Poll every</label>
                <select class="select" x-model="form.poll_frequency_minutes">
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="720">12 hours</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label class="checkbox-label">
                <input type="checkbox" x-model="form.is_local"> Local outlet
            </label>
        </div>
        <div class="field">
            <label class="checkbox-label">
                <input type="checkbox" x-model="form.is_active"> Active
            </label>
        </div>
        <div class="admin__actions">
            <button type="button" class="btn btn--secondary" @click="cancelEdit()">Cancel</button>
            <button type="button" class="btn btn--primary" @click="saveSource()">Save changes</button>
        </div>
    </div>
</div>
