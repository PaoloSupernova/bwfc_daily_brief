<?php
declare(strict_types=1);

/**
 * Shared editor view - used by new.php and edit.php.
 * Variables in scope: $brief, $articles, $sections, $isLocked
 */

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$briefDate = new DateTime($brief['brief_date']);
// Build related-coverage index keyed by parent_article_id
$relatedByParent = [];
foreach ($articles as $a) {
    if (!empty($a['parent_article_id'])) {
        $pid = (int)$a['parent_article_id'];
        $relatedByParent[$pid][] = [
            'id' => (int)$a['id'],
            'outlet' => (string)$a['outlet_name'],
            'headline' => (string)$a['headline'],
            'url' => (string)$a['url'],
        ];
    }
}

$jsState = [
    'briefId' => (int)$brief['id'],
    'briefDate' => $brief['brief_date'],
    'executiveSummary' => (string)($brief['executive_summary'] ?? ''),
    'status' => $brief['status'],
    'articles' => array_values(array_map(fn($a) => [
        'id' => (int)$a['id'],
        'url' => (string)$a['url'],
        'outlet' => (string)$a['outlet_name'],
        'headline' => (string)$a['headline'],
        'summary' => (string)$a['summary'],
        'section_name' => (string)$a['section_name'],
        'section_slug' => (string)$a['section_slug'],
        'sentiment' => (string)($a['sentiment'] ?? ''),
        'was_edited' => (bool)$a['was_edited'],
        'related' => $relatedByParent[(int)$a['id']] ?? [],
    ], array_filter($articles, fn($a) => empty($a['parent_article_id'])))),
    'sections' => array_values(array_map(fn($s) => [
        'slug' => (string)$s['slug'],
        'name' => (string)$s['name'],
    ], $sections)),
];
?>
<div class="editor" x-data="briefEditor(<?= htmlspecialchars(json_encode($jsState), ENT_QUOTES) ?>)">

    <header class="editor__header">
        <div>
            <div class="editor__date"><?= $briefDate->format('l jS F Y') ?></div>
            <div class="editor__subject">Subject line: <code>DAILY BRIEF: <?= strtoupper($briefDate->format('l jS F Y')) ?></code></div>
        </div>
        <div class="editor__header-actions">
            <span class="status-pill status-pill--draft" x-show="status === 'draft'">Draft</span>
            <span class="status-pill status-pill--sent" x-show="status === 'sent'">Sent &middot; Locked</span>
            <span class="editor__count"><span x-text="articles.length"></span> article<span x-show="articles.length !== 1">s</span></span>
        </div>
    </header>

    <!-- Step 1: Add article -->
    <section class="editor__section" x-show="status === 'draft'">
        <h2 class="heading-section">Add an article</h2>

        <div class="url-row">
            <input type="url" class="input input--url"
                   placeholder="Paste article URL (e.g. https://www.theboltonnews.co.uk/...)"
                   x-model="urlInput"
                   :disabled="fetching || processing"
                   @keydown.enter.prevent="fetchArticle()">
            <button type="button" class="btn btn--primary"
                    @click="fetchArticle()"
                    :disabled="!urlInput || fetching || processing">
                <span x-show="!fetching">Fetch article</span>
                <span x-show="fetching">Fetching...</span>
            </button>
        </div>

        <div class="fallback-panel" x-show="showFallback" x-cloak>
            <div class="fallback-panel__banner">
                <strong>Manual paste required.</strong>
                <span x-text="fetchError || 'This outlet blocks automated article fetching.'"></span>
            </div>
            <div class="field">
                <label class="field__label">Headline</label>
                <input type="text" class="input" x-model="pending.headline" placeholder="Article headline">
            </div>
            <div class="field">
                <label class="field__label">Outlet</label>
                <input type="text" class="input" x-model="pending.outlet" placeholder="Publication name">
            </div>
            <div class="field">
                <label class="field__label">Byline <span class="field__label-meta">&middot; journalist(s), or leave blank for Unassigned</span></label>
                <input type="text" class="input" x-model="pending.byline" placeholder="e.g. Marc Iles">
            </div>
            <div class="field">
                <label class="field__label">Article body</label>
                <textarea class="textarea textarea--tall" rows="10" x-model="pending.content"
                          placeholder="Paste the full article text here..."></textarea>
            </div>
            <div class="fallback-panel__actions">
                <button type="button" class="btn btn--secondary" @click="cancelPending()">Cancel</button>
                <button type="button" class="btn btn--primary"
                        @click="generateSummary(true)"
                        :disabled="!pending.headline || !pending.content || processing">
                    <span x-show="!processing">Generate summary</span>
                    <span x-show="processing">Generating...</span>
                </button>
            </div>
        </div>

        <!-- Prior coverage: this exact link appeared in an earlier brief -->
        <div class="prior-coverage-panel" x-show="priorCoverage" x-cloak>
            <div class="prior-coverage-panel__icon">&#9888;</div>
            <div class="prior-coverage-panel__body">
                <p class="prior-coverage-panel__title">Already covered in a previous brief</p>
                <p class="prior-coverage-panel__text">
                    This exact link was included in the brief dated
                    <strong x-text="priorCoverage && prettyDate(priorCoverage.brief_date)"></strong><span x-show="priorCoverage && priorCoverage.status === 'sent'"> (sent)</span>:
                </p>
                <p class="prior-coverage-panel__meta">
                    <a :href="priorCoverage && priorCoverage.url" target="_blank" rel="noopener"
                       x-text="priorCoverage && priorCoverage.headline"></a>
                </p>
                <p class="prior-coverage-panel__hint">To avoid duplication it won&rsquo;t be added. You can override this if you intend to feature it again.</p>
                <div class="prior-coverage-panel__actions">
                    <button type="button" class="btn btn--secondary" @click="dismissPriorCoverage()">
                        Don&rsquo;t add
                    </button>
                    <button type="button" class="btn btn--primary" @click="usePriorCoverageAnyway()" :disabled="processing">
                        Add it anyway
                    </button>
                </div>
            </div>
        </div>

        <!-- Duplicate / related coverage suggestion -->
        <div class="duplicate-panel" x-show="duplicateSuggestion" x-cloak>
            <div class="duplicate-panel__icon">&#9741;</div>
            <div class="duplicate-panel__body">
                <p class="duplicate-panel__title">Related coverage detected</p>
                <p class="duplicate-panel__text">
                    This article appears to cover the same story as:
                    <strong x-text="duplicateSuggestion && duplicateSuggestion.parentHeadline"></strong>
                </p>
                <p class="duplicate-panel__meta">
                    New article: <strong x-text="pending.outlet"></strong> &mdash;
                    <a :href="pending.url" target="_blank" rel="noopener" x-text="pending.headline"></a>
                </p>
                <p class="duplicate-panel__hint">Add it as a &ldquo;More:&rdquo; link under the existing summary, or generate a full summary for it instead.</p>
                <div class="duplicate-panel__actions">
                    <button type="button" class="btn btn--secondary"
                            @click="rejectDuplicate()"
                            :disabled="processing">
                        <span x-show="!processing">Generate full summary</span>
                        <span x-show="processing" x-cloak>Generating...</span>
                    </button>
                    <button type="button" class="btn btn--primary"
                            @click="acceptDuplicate()"
                            :disabled="processing">
                        Add as &ldquo;More:&rdquo; link
                    </button>
                </div>
            </div>
        </div>

        <div class="review-panel" x-show="showReview" x-cloak>
            <div class="review-panel__meta">
                <div class="review-panel__outlet">
                    <strong x-text="pending.outlet"></strong>
                    <span class="sentiment-badge"
                          x-show="pending.sentiment"
                          :class="'sentiment-badge--' + pending.sentiment"
                          x-text="pending.sentiment"
                          x-cloak></span>
                </div>
                <div class="review-panel__headline" x-text="pending.headline"></div>
                <a class="review-panel__link" x-show="pending.url" :href="pending.url" target="_blank" rel="noopener" x-text="pending.url"></a>
            </div>

            <div class="field">
                <label class="field__label">
                    Byline
                    <span class="field__label-meta" x-show="!pending.byline" x-cloak>&middot; none detected &mdash; Unassigned</span>
                </label>
                <input type="text" class="input" x-model="pending.byline" placeholder="Journalist(s) — leave blank for Unassigned">
                <p class="field__hint">Detected from the article. Correct it or add a name; separate multiple authors with &ldquo;and&rdquo;.</p>
            </div>

            <div class="field">
                <label class="field__label">
                    People mentioned
                    <span class="field__label-meta" x-show="!pending.people" x-cloak>&middot; none detected</span>
                </label>
                <input type="text" class="input" x-model="pending.people" placeholder="Players / staff named — e.g. Josh Sheehan, Steven Schumacher">
                <p class="field__hint">Auto-detected squad &amp; staff. Add or correct names, separated by commas.</p>
            </div>

            <div class="field">
                <label class="field__label">Section</label>
                <select class="select" x-model="pending.section_slug">
                    <template x-for="s in sections" :key="s.slug">
                        <option :value="s.slug" x-text="s.name"></option>
                    </template>
                </select>
                <p class="field__hint" x-show="pending.suggestedSection">Claude suggested: <strong x-text="suggestedSectionName()"></strong></p>
            </div>

            <div class="field">
                <label class="field__label">Topic</label>
                <select class="select" x-model="pending.topic">
                    <?php foreach (\BWFC\DailyBrief\Topics::all() as $t): ?>
                        <option value="<?= htmlspecialchars($t['slug'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['label'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field__hint">What the story is about. Auto-detected &mdash; change if needed.</p>
            </div>

            <div class="field">
                <label class="field__label">Sentiment</label>
                <div class="sentiment-choice">
                    <template x-for="s in ['positive','neutral','negative']" :key="s">
                        <button type="button" class="sentiment-toggle"
                                :class="['sentiment-toggle--' + s, pending.sentiment === s ? 'is-active' : '']"
                                @click="pending.sentiment = s" x-text="s"></button>
                    </template>
                </div>
                <p class="field__hint">Auto-detected &mdash; click to override before adding.</p>
            </div>

            <div class="field">
                <label class="field__label">
                    Summary
                    <span class="field__label-meta" x-show="pending.edited">&middot; Edited</span>
                </label>
                <textarea class="textarea" rows="6" x-model="pending.summary" @input="pending.edited = true"></textarea>
                <div class="field__actions">
                    <button type="button" class="btn btn--secondary btn--small"
                            @click="regenerateSummary()"
                            :disabled="processing">
                        <span x-show="!processing">Regenerate</span>
                        <span x-show="processing">Working...</span>
                    </button>
                    <span class="field__word-count"><span x-text="wordCount(pending.summary)"></span> words</span>
                </div>
            </div>

            <div class="violations" x-show="pending.violations && pending.violations.length > 0" x-cloak>
                <div class="violations__title">Style check flagged <span x-text="pending.violations.length"></span> issue<span x-show="pending.violations.length !== 1">s</span>:</div>
                <ul class="violations__list">
                    <template x-for="v in pending.violations" :key="v.position + v.term">
                        <li><span class="violations__term" x-text="v.term"></span> <span class="violations__type" x-text="v.type.replace('_', ' ')"></span></li>
                    </template>
                </ul>
            </div>

            <div class="review-panel__actions">
                <button type="button" class="btn btn--secondary" @click="cancelPending()">Discard</button>
                <button type="button" class="btn btn--primary"
                        @click="saveArticle()"
                        :disabled="processing">
                    Add to brief
                </button>
            </div>
        </div>

        <div class="status-line" x-show="statusMessage" x-cloak>
            <span x-text="statusMessage"></span>
        </div>
    </section>

    <!-- Articles in brief -->
    <section class="editor__section">
        <h2 class="heading-section">
            Brief contents
            <span class="heading-section__count" x-show="articles.length > 0">(<span x-text="articles.length"></span>)</span>
        </h2>

        <div class="empty-state" x-show="articles.length === 0">
            No articles added yet. Paste a URL above to get started.
        </div>

        <div class="article-list" x-show="articles.length > 0">
            <template x-for="(group, idx) in groupedArticles()" :key="'group-' + idx">
                <div class="article-group">
                    <h3 class="article-group__heading" x-text="group.sectionName"></h3>
                    <template x-for="item in group.items" :key="item.id">
                        <article class="article-card" :class="{ 'is-editing': editingArticleId === item.id }">
                            <div class="article-card__head">
                                <div class="article-card__outlet">
                                    <span x-text="item.outlet"></span>
                                    <span class="sentiment-badge"
                                          x-show="item.sentiment"
                                          :class="'sentiment-badge--' + item.sentiment"
                                          x-text="item.sentiment"
                                          x-cloak></span>
                                </div>
                                <div class="article-card__headline">
                                    <a :href="item.url" target="_blank" rel="noopener" x-text="item.headline"></a>
                                </div>
                            </div>
                            <div class="article-card__body" x-show="editingArticleId !== item.id">
                                <p x-text="item.summary"></p>
                            </div>
                            <div class="article-card__editor" x-show="editingArticleId === item.id" x-cloak>
                                <textarea class="textarea" rows="6" x-model="editingText"></textarea>
                                <div class="article-card__editor-actions">
                                    <button type="button" class="btn btn--secondary btn--small" @click="cancelEdit()">Cancel</button>
                                    <button type="button" class="btn btn--primary btn--small" @click="saveEdit(item.id)">Save edit</button>
                                </div>
                            </div>
                            <!-- Related coverage ("More:" line) -->
                            <div class="article-card__more" x-show="item.related && item.related.length > 0" x-cloak>
                                <span class="article-card__more-label">More:</span>
                                <template x-for="(r, ri) in item.related" :key="r.id">
                                    <span>
                                        <a :href="r.url" target="_blank" rel="noopener" class="article-card__more-link">
                                            <span x-text="r.outlet"></span>: <span x-text="r.headline"></span>
                                        </a>
                                        <button x-show="status === 'draft'" type="button"
                                                class="article-card__more-remove"
                                                @click="deleteRelated(item.id, r.id)"
                                                title="Remove this related link">&times;</button>
                                        <span class="article-card__more-sep" x-show="ri < item.related.length - 1">|</span>
                                    </span>
                                </template>
                            </div>
                            <div class="article-card__actions" x-show="status === 'draft' && editingArticleId !== item.id">
                                <button type="button" class="link-btn" @click="startEdit(item)">Edit</button>
                                <button type="button" class="link-btn link-btn--danger" @click="deleteArticle(item.id)">Remove</button>
                            </div>
                        </article>
                    </template>
                </div>
            </template>
        </div>
    </section>

    <!-- Executive summary -->
    <section class="editor__section" x-show="articles.length > 0">
        <h2 class="heading-section">Executive summary</h2>
        <p class="field__hint">Two-paragraph overview that sits above the BWFC section in the final brief. Generated from all articles.</p>

        <div class="field" x-show="status === 'draft'">
            <textarea class="textarea textarea--tall" rows="8" x-model="executiveSummary"
                      placeholder="Click 'Generate summary' to have Claude draft this, or write your own."
                      @input="executiveSummaryEdited = true"></textarea>
            <div class="field__actions">
                <button type="button" class="btn btn--secondary"
                        @click="generateExecutiveSummary()"
                        :disabled="processingExec || articles.length === 0">
                    <span x-show="!processingExec">Generate summary</span>
                    <span x-show="processingExec">Generating...</span>
                </button>
                <button type="button" class="btn btn--link"
                        @click="saveExecutiveSummary()"
                        x-show="executiveSummaryEdited">
                    Save edit
                </button>
            </div>
        </div>

        <div class="article-card" x-show="status === 'sent'">
            <div class="article-card__body" x-text="executiveSummary"></div>
        </div>
    </section>

    <!-- Next step -->
    <section class="editor__section editor__section--output" x-show="articles.length > 0">
        <h2 class="heading-section">Next step</h2>
        <p class="field__hint">When you've finished adding articles, go to the review screen to reorder, make final edits, and export.</p>

        <div class="output-actions">
            <button type="button" class="btn btn--primary btn--large" @click="goToReview()" :disabled="briefId === 0">
                Review &amp; export &rarr;
            </button>
        </div>
    </section>
</div>
