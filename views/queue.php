<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="queue" x-data="queueScreen()" x-init="loadQueue()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">Morning Queue</h1>
            <p class="lede">Articles pulled from your feeds in the last <span x-text="hours"></span> hours. Click one to add it to today's brief.</p>
        </div>
        <div class="queue__header-action">
            <button type="button" class="btn btn--secondary" @click="refreshFeeds()" :disabled="refreshing">
                <span x-show="!refreshing">Refresh feeds</span>
                <span x-show="refreshing" x-cloak>Refreshing...</span>
            </button>
        </div>
    </header>

    <!-- Filters -->
    <div class="queue__filters">
        <div class="queue__filter-group">
            <span class="queue__filter-label">Window:</span>
            <button type="button" class="chip" :class="{ 'is-active': hours === 24 }" @click="setHours(24)">24h</button>
            <button type="button" class="chip" :class="{ 'is-active': hours === 48 }" @click="setHours(48)">48h</button>
            <button type="button" class="chip" :class="{ 'is-active': hours === 72 }" @click="setHours(72)">3 days</button>
            <button type="button" class="chip" :class="{ 'is-active': hours === 168 }" @click="setHours(168)">1 week</button>
        </div>
        <div class="queue__filter-group">
            <span class="queue__filter-label">Status:</span>
            <button type="button" class="chip" :class="{ 'is-active': statusFilter === 'all' }" @click="setStatus('all')">
                All <span class="chip__count" x-text="totalAcrossStatuses()"></span>
            </button>
            <button type="button" class="chip" :class="{ 'is-active': statusFilter === 'new' }" @click="setStatus('new')">
                New <span class="chip__count" x-text="statusCounts.new || 0"></span>
            </button>
            <button type="button" class="chip" :class="{ 'is-active': statusFilter === 'ingested' }" @click="setStatus('ingested')">
                Added <span class="chip__count" x-text="statusCounts.ingested || 0"></span>
            </button>
            <button type="button" class="chip" :class="{ 'is-active': statusFilter === 'rejected' }" @click="setStatus('rejected')">
                Rejected <span class="chip__count" x-text="statusCounts.rejected || 0"></span>
            </button>
            <button type="button" class="chip" :class="{ 'is-active': statusFilter === 'duplicate' }" @click="setStatus('duplicate')">
                Duplicate <span class="chip__count" x-text="statusCounts.duplicate || 0"></span>
            </button>
        </div>
    </div>

    <!-- Layout: list on the left, side panel slides in on the right -->
    <div class="queue__layout" :class="{ 'panel-open': panelOpen }">

        <div class="queue__list">

            <div class="empty-state" x-show="!loading && groups.length === 0" x-cloak>
                <p>No candidates in this window. Try a longer window or click "Refresh feeds" to poll for new items.</p>
            </div>

            <div class="queue__loading" x-show="loading" x-cloak>Loading queue...</div>

            <template x-for="group in groups" :key="group.key">
                <section class="queue-group">
                    <header class="queue-group__head">
                        <h2 class="queue-group__title" x-text="group.label"></h2>
                        <span class="queue-group__count">
                            <span x-text="group.count"></span> candidate<span x-show="group.count !== 1">s</span>
                        </span>
                    </header>
                    <div class="queue-group__items">
                        <template x-for="cand in group.items" :key="cand.id">
                            <article class="candidate"
                                     :class="{
                                         'is-ingested': cand.status === 'ingested',
                                         'is-rejected': cand.status === 'rejected',
                                         'is-duplicate': cand.status === 'duplicate',
                                         'is-active': activeCandidateId === cand.id,
                                     }">
                                <div class="candidate__head">
                                    <span class="candidate__outlet" x-text="cand.outlet_name || cand.source_name"></span>
                                    <span class="candidate__local" x-show="cand.source_is_local" x-cloak>LOCAL</span>
                                    <span class="candidate__time" x-text="relativeTime(cand.discovered_at)"></span>
                                    <template x-if="cand.status === 'ingested'">
                                        <span class="status-pill status-pill--sent">Added</span>
                                    </template>
                                    <template x-if="cand.status === 'rejected'">
                                        <span class="status-pill status-pill--rejected">Rejected</span>
                                    </template>
                                    <template x-if="cand.status === 'duplicate'">
                                        <span class="status-pill status-pill--duplicate">Already published</span>
                                    </template>
                                </div>
                                <h3 class="candidate__headline">
                                    <a :href="cand.url" target="_blank" rel="noopener" x-text="cand.headline"></a>
                                </h3>
                                <p class="candidate__description" x-show="cand.description" x-text="cand.description"></p>
                                <div class="candidate__actions">
                                    <template x-if="cand.status === 'new'">
                                        <button type="button" class="btn btn--primary btn--small"
                                                @click="ingest(cand)"
                                                :disabled="ingesting">
                                            <span x-show="ingesting && ingestingId === cand.id" x-cloak>Adding...</span>
                                            <span x-show="!(ingesting && ingestingId === cand.id)">Add to brief</span>
                                        </button>
                                    </template>
                                    <template x-if="cand.status === 'new'">
                                        <button type="button" class="link-btn link-btn--danger" @click="reject(cand)">Reject</button>
                                    </template>
                                    <template x-if="cand.status === 'ingested' && cand.ingested_article_id">
                                        <a :href="briefUrl(cand)" target="_blank" class="link-btn">View in brief &rarr;</a>
                                    </template>
                                    <template x-if="cand.status === 'rejected' || cand.status === 'duplicate'">
                                        <button type="button" class="link-btn" @click="restore(cand)">Restore to new</button>
                                    </template>
                                    <a :href="cand.url" target="_blank" rel="noopener" class="link-btn">Open article &rarr;</a>
                                </div>
                            </article>
                        </template>
                    </div>
                </section>
            </template>
        </div>

        <!-- Side panel: opens when a candidate is being ingested or has just been added -->
        <aside class="queue__panel" x-show="panelOpen" x-cloak @click.outside="closePanel()">
            <header class="queue__panel-head">
                <h2 class="queue__panel-title">
                    <span x-show="panelState === 'ingesting'" x-cloak>Adding to brief...</span>
                    <span x-show="panelState === 'success'" x-cloak>Added to brief</span>
                    <span x-show="panelState === 'error'" x-cloak>Could not add</span>
                </h2>
                <button type="button" class="queue__panel-close" @click="closePanel()" aria-label="Close">&times;</button>
            </header>

            <div class="queue__panel-body">

                <!-- Ingesting state -->
                <div x-show="panelState === 'ingesting'" x-cloak>
                    <div class="queue__panel-spinner">Working on it... fetching article and generating summary.</div>
                </div>

                <!-- Success state -->
                <div x-show="panelState === 'success'" x-cloak>
                    <div x-show="lastResult.was_paywall_fallback" x-cloak class="fallback-panel__banner" style="margin-bottom: 16px;">
                        <strong>Couldn't fetch full article body.</strong>
                        <p>The article was added using just the headline and feed description. <span x-text="lastResult.fetch_error"></span> You can paste the full article text in the editor for a better summary.</p>
                    </div>

                    <div class="field">
                        <label class="field__label">Outlet</label>
                        <div class="queue__panel-readonly" x-text="lastResult.article?.outlet_name"></div>
                    </div>

                    <div class="field">
                        <label class="field__label">Headline</label>
                        <div class="queue__panel-readonly" x-text="lastResult.article?.headline"></div>
                    </div>

                    <div class="field">
                        <label class="field__label">Suggested section</label>
                        <div class="queue__panel-readonly" x-text="lastResult.article?.section_name"></div>
                    </div>

                    <div class="field">
                        <label class="field__label">Summary</label>
                        <div class="queue__panel-summary" x-text="lastResult.article?.summary"></div>
                    </div>

                    <div class="violations" x-show="lastResult.violations && lastResult.violations.length > 0" x-cloak>
                        <div class="violations__title">Style check flagged <span x-text="lastResult.violations?.length"></span> issue<span x-show="(lastResult.violations?.length ?? 0) !== 1">s</span></div>
                        <p class="field__hint">Review and fix in the editor.</p>
                    </div>

                    <div class="queue__panel-actions">
                        <button type="button" class="btn btn--primary" @click="goToEditor()">Open in editor &rarr;</button>
                        <button type="button" class="btn btn--secondary" @click="closePanel()">Keep adding</button>
                    </div>
                </div>

                <!-- Error state -->
                <div x-show="panelState === 'error'" x-cloak>
                    <div class="violations" style="margin-top: 0;">
                        <div class="violations__title">Error</div>
                        <p x-text="lastError"></p>
                    </div>
                    <div class="queue__panel-actions">
                        <button type="button" class="btn btn--secondary" @click="closePanel()">Close</button>
                    </div>
                </div>
            </div>
        </aside>

    </div>

    <div class="status-line" x-show="statusMessage" x-cloak x-text="statusMessage"></div>
</div>
