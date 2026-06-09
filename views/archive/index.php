<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

// Initial state - hydrated client-side, but seed the sections list so the sidebar
// has something to render before the first AJAX call returns.
$sections = BriefRepository::sections(true);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$jsState = [
    'sections' => array_values(array_map(fn($s) => [
        'slug' => (string)$s['slug'],
        'name' => (string)$s['name'],
    ], $sections)),
];
?>
<div class="archive" x-data="archiveScreen(<?= htmlspecialchars(json_encode($jsState), ENT_QUOTES) ?>)" x-init="loadResults()">

    <header class="archive__header">
        <h1 class="heading-display">Archive</h1>
        <p class="lede">Search every brief, every article. Filter by section, outlet, status, or date.</p>
    </header>

    <div class="archive__layout">
        <!-- LEFT: filter sidebar -->
        <aside class="archive__sidebar">

            <div class="archive__search">
                <input type="text" class="input archive__search-input"
                       placeholder="Search articles, summaries, outlets..."
                       x-model="filters.query"
                       @input.debounce.350ms="resetAndLoad()">
                <button type="button" class="archive__search-clear"
                        x-show="filters.query"
                        @click="filters.query = ''; resetAndLoad()"
                        x-cloak>&times;</button>
            </div>

            <div class="archive__filter-group">
                <h3 class="archive__filter-heading">Date range</h3>
                <div class="archive__chip-row">
                    <template x-for="preset in datePresets" :key="preset.key">
                        <button type="button"
                                class="chip"
                                :class="{ 'is-active': filters.datePreset === preset.key }"
                                @click="setDatePreset(preset.key)"
                                x-text="preset.label"></button>
                    </template>
                </div>
                <div class="archive__date-custom" x-show="filters.datePreset === 'custom'" x-cloak>
                    <input type="date" class="input input--small" x-model="filters.dateFrom" @change="resetAndLoad()">
                    <span class="archive__date-sep">to</span>
                    <input type="date" class="input input--small" x-model="filters.dateTo" @change="resetAndLoad()">
                </div>
            </div>

            <div class="archive__filter-group">
                <h3 class="archive__filter-heading">Status</h3>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.status === '' }"
                        @click="filters.status = ''; resetAndLoad()">
                    <span>All briefs</span>
                    <span class="filter-row__count" x-text="(facets.status.sent || 0) + (facets.status.draft || 0)"></span>
                </button>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.status === 'sent' }"
                        @click="filters.status = 'sent'; resetAndLoad()">
                    <span>Sent</span>
                    <span class="filter-row__count" x-text="facets.status.sent || 0"></span>
                </button>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.status === 'draft' }"
                        @click="filters.status = 'draft'; resetAndLoad()">
                    <span>Draft</span>
                    <span class="filter-row__count" x-text="facets.status.draft || 0"></span>
                </button>
            </div>

            <div class="archive__filter-group">
                <h3 class="archive__filter-heading">Sections</h3>
                <template x-for="section in facets.sections" :key="section.slug">
                    <button type="button" class="filter-row"
                            :class="{ 'is-active': filters.sections.includes(section.slug) }"
                            @click="toggleSection(section.slug)">
                        <span x-text="section.name"></span>
                        <span class="filter-row__count" x-text="section.count"></span>
                    </button>
                </template>
                <p class="archive__filter-empty" x-show="facets.sections.length === 0" x-cloak>No section data yet</p>
            </div>

            <div class="archive__filter-group" x-show="facets.sentiment && (facets.sentiment.positive > 0 || facets.sentiment.neutral > 0 || facets.sentiment.negative > 0)" x-cloak>
                <h3 class="archive__filter-heading">Sentiment</h3>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.sentiment === 'positive' }"
                        @click="toggleSentiment('positive')">
                    <span>
                        <span class="sentiment-badge sentiment-badge--positive">Positive</span>
                    </span>
                    <span class="filter-row__count" x-text="facets.sentiment ? (facets.sentiment.positive || 0) : 0"></span>
                </button>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.sentiment === 'neutral' }"
                        @click="toggleSentiment('neutral')">
                    <span>
                        <span class="sentiment-badge sentiment-badge--neutral">Neutral</span>
                    </span>
                    <span class="filter-row__count" x-text="facets.sentiment ? (facets.sentiment.neutral || 0) : 0"></span>
                </button>
                <button type="button" class="filter-row"
                        :class="{ 'is-active': filters.sentiment === 'negative' }"
                        @click="toggleSentiment('negative')">
                    <span>
                        <span class="sentiment-badge sentiment-badge--negative">Negative</span>
                    </span>
                    <span class="filter-row__count" x-text="facets.sentiment ? (facets.sentiment.negative || 0) : 0"></span>
                </button>
            </div>

            <div class="archive__filter-group">
                <h3 class="archive__filter-heading">Outlets</h3>
                <template x-for="(outlet, idx) in visibleOutlets()" :key="outlet.name">
                    <button type="button" class="filter-row"
                            :class="{ 'is-active': filters.outlets.includes(outlet.name) }"
                            @click="toggleOutlet(outlet.name)">
                        <span x-text="outlet.name"></span>
                        <span class="filter-row__count" x-text="outlet.count"></span>
                    </button>
                </template>
                <button type="button" class="archive__filter-toggle"
                        x-show="facets.outlets.length > 10"
                        @click="showAllOutlets = !showAllOutlets">
                    <span x-show="!showAllOutlets">Show all (<span x-text="facets.outlets.length"></span>)</span>
                    <span x-show="showAllOutlets" x-cloak>Show top 10</span>
                </button>
                <p class="archive__filter-empty" x-show="facets.outlets.length === 0" x-cloak>No outlets yet</p>
            </div>

            <button type="button" class="btn btn--link archive__reset"
                    x-show="hasActiveFilters()"
                    @click="resetFilters()"
                    x-cloak>Clear all filters</button>

        </aside>

        <!-- RIGHT: results -->
        <section class="archive__results">

            <div class="archive__results-head">
                <div class="archive__results-summary">
                    <span x-show="loading" x-cloak>Searching...</span>
                    <template x-if="!loading && mode === 'search'">
                        <span>
                            <strong x-text="total"></strong>
                            result<span x-show="total !== 1">s</span>
                            for &ldquo;<strong x-text="filters.query"></strong>&rdquo;
                        </span>
                    </template>
                    <template x-if="!loading && mode === 'list'">
                        <span>
                            <strong x-text="total"></strong>
                            brief<span x-show="total !== 1">s</span>
                            <span x-show="hasActiveFilters()"> match your filters</span>
                        </span>
                    </template>
                </div>
                <div class="archive__results-actions" x-show="!loading && visibleBriefIds().length > 0" x-cloak>
                    <button type="button" class="btn btn--secondary btn--small"
                            @click="allVisibleSelected() ? clearSelection() : selectAllVisible()">
                        <span x-text="allVisibleSelected() ? 'Deselect all' : 'Select all on page'"></span>
                    </button>
                </div>
            </div>

            <!-- Empty state -->
            <div class="empty-state" x-show="!loading && total === 0" x-cloak>
                <p x-show="mode === 'search'">No matches for &ldquo;<span x-text="filters.query"></span>&rdquo;. Try a shorter or different keyword.</p>
                <p x-show="mode === 'list'">No briefs match the active filters.</p>
            </div>

            <!-- Search mode: result cards with snippets -->
            <div class="archive__search-results" x-show="mode === 'search' && !loading && results.length > 0" x-cloak>
                <template x-for="hit in results" :key="hit.article_id">
                    <article class="search-hit" :class="{ 'is-selected': isSelected(hit.brief_id) }">
                        <label class="search-hit__checkbox" :title="isSelected(hit.brief_id) ? 'Deselect' : 'Select for download'">
                            <input type="checkbox" :checked="isSelected(hit.brief_id)" @change="toggleSelect(hit.brief_id)">
                        </label>
                        <div class="search-hit__head">
                            <span class="search-hit__date" x-text="formatDate(hit.brief_date)"></span>
                            <span class="search-hit__section" x-text="hit.section_name"></span>
                            <span class="search-hit__outlet" x-text="hit.outlet_name"></span>
                            <span class="sentiment-badge"
                                  x-show="hit.sentiment"
                                  :class="'sentiment-badge--' + hit.sentiment"
                                  x-text="hit.sentiment"
                                  x-cloak></span>
                        </div>
                        <h3 class="search-hit__headline">
                            <a :href="hit.url" target="_blank" rel="noopener" x-html="highlight(hit.headline)"></a>
                        </h3>
                        <p class="search-hit__snippet" x-html="highlight(hit.snippet)"></p>
                        <div class="search-hit__actions">
                            <a :href="briefUrl(hit.brief_id)" target="_blank" class="link-btn">Jump to brief &rarr;</a>
                            <a :href="pdfUrl(hit.brief_id)" download class="link-btn">PDF</a>
                            <a :href="wordUrl(hit.brief_id)" download class="link-btn">Word</a>
                        </div>
                    </article>
                </template>
            </div>

            <!-- List mode: timeline grouped by month -->
            <div class="archive__timeline" x-show="mode === 'list' && !loading && groups.length > 0" x-cloak>
                <template x-for="group in groups" :key="group.month_key">
                    <section class="month-group">
                        <header class="month-group__head">
                            <h2 class="month-group__title" x-text="group.month_label"></h2>
                            <span class="month-group__count">
                                <span x-text="group.count"></span> brief<span x-show="group.count !== 1">s</span>
                            </span>
                        </header>
                        <div class="month-group__items">
                            <template x-for="brief in group.briefs" :key="brief.id">
                                <article class="brief-card" :class="{ 'is-selected': isSelected(brief.id) }">
                                    <label class="brief-card__checkbox" :title="isSelected(brief.id) ? 'Deselect' : 'Select for download'">
                                        <input type="checkbox" :checked="isSelected(brief.id)" @change="toggleSelect(brief.id)">
                                    </label>
                                    <div class="brief-card__date-block">
                                        <div class="brief-card__day-name" x-text="dayOfWeek(brief.brief_date)"></div>
                                        <div class="brief-card__day-num" x-text="dayOfMonth(brief.brief_date)"></div>
                                    </div>
                                    <div class="brief-card__body">
                                        <div class="brief-card__top">
                                            <span class="brief-card__date-text" x-text="formatDate(brief.brief_date)"></span>
                                            <span class="status-pill"
                                                  :class="brief.status === 'sent' ? 'status-pill--sent' : 'status-pill--draft'"
                                                  x-text="brief.status === 'sent' ? 'Sent' : 'Draft'"></span>
                                        </div>
                                        <div class="brief-card__meta">
                                            <span class="brief-card__articles">
                                                <span x-text="brief.article_count"></span>
                                                article<span x-show="brief.article_count !== 1">s</span>
                                            </span>
                                            <span class="brief-card__outlets" x-show="brief.outlet_list.length > 0">
                                                <template x-for="(outlet, i) in brief.outlet_list" :key="outlet">
                                                    <span>
                                                        <span x-text="outlet"></span><span x-show="i < brief.outlet_list.length - 1">, </span>
                                                    </span>
                                                </template>
                                                <span x-show="brief.outlet_total > 3"> &middot; +<span x-text="brief.outlet_total - 3"></span> more</span>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="brief-card__action">
                                        <a :href="pdfUrl(brief.id)" download class="btn btn--secondary btn--small">PDF</a>
                                        <a :href="wordUrl(brief.id)" download class="btn btn--secondary btn--small">Word</a>
                                        <a :href="briefUrl(brief.id)" target="_blank" class="btn btn--secondary btn--small">Open &rarr;</a>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </section>
                </template>
            </div>

            <!-- Pagination -->
            <nav class="archive__pagination" x-show="!loading && totalPages > 1" x-cloak>
                <button type="button" class="btn btn--secondary btn--small"
                        @click="goToPage(page - 1)"
                        :disabled="page <= 1">&larr; Previous</button>
                <span class="archive__page-info">
                    Page <strong x-text="page"></strong> of <strong x-text="totalPages"></strong>
                </span>
                <button type="button" class="btn btn--secondary btn--small"
                        @click="goToPage(page + 1)"
                        :disabled="page >= totalPages">Next &rarr;</button>
            </nav>

        </section>
    </div>

    <!-- Floating bulk-download bar (appears when briefs are selected) -->
    <div class="bulk-bar" x-show="selectedBriefs.length > 0" x-cloak
         x-transition:enter="bulk-bar--enter"
         x-transition:enter-start="bulk-bar--hidden"
         x-transition:enter-end="bulk-bar--visible"
         x-transition:leave="bulk-bar--enter"
         x-transition:leave-start="bulk-bar--visible"
         x-transition:leave-end="bulk-bar--hidden">
        <div class="bulk-bar__inner">
            <span class="bulk-bar__count">
                <strong x-text="selectedBriefs.length"></strong>
                brief<span x-show="selectedBriefs.length !== 1">s</span> selected
                <span x-show="selectedBriefs.length > 30" class="bulk-bar__warning">&nbsp;(max 30)</span>
            </span>
            <div class="bulk-bar__actions">
                <div class="bulk-bar__error" x-show="bulkError" x-text="bulkError" x-cloak></div>
                <button type="button" class="btn btn--link bulk-bar__clear" @click="clearSelection()">
                    Clear
                </button>
                <button type="button" class="btn btn--secondary"
                        @click="downloadSelectedWord()"
                        :disabled="bulkDownloadingWord || bulkDownloading || selectedBriefs.length > 30">
                    <span x-show="!bulkDownloadingWord">
                        &#11015; Word<span x-show="selectedBriefs.length !== 1"> ZIP</span>
                    </span>
                    <span x-show="bulkDownloadingWord" x-cloak>Generating&hellip;</span>
                </button>
                <button type="button" class="btn btn--primary"
                        @click="downloadSelected()"
                        :disabled="bulkDownloading || bulkDownloadingWord || selectedBriefs.length > 30">
                    <span x-show="!bulkDownloading">
                        &#11015; PDF<span x-show="selectedBriefs.length !== 1"> ZIP</span>
                    </span>
                    <span x-show="bulkDownloading" x-cloak>Generating&hellip;</span>
                </button>
            </div>
        </div>
    </div>
</div>
