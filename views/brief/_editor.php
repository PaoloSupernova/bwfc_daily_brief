<?php
declare(strict_types=1);

/**
 * Shared editor view - used by new.php and edit.php.
 * Variables expected in scope: $brief (array), $articles (array), $sections (array), $isLocked (bool)
 */

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$briefDate = new DateTime($brief['brief_date']);
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
        'was_edited' => (bool)$a['was_edited'],
    ], $articles)),
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
            <span class="status-pill status-pill--sent" x-show="status === 'sent'">Sent · Locked</span>
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

        <!-- Paywall / extraction failure fallback -->
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

        <!-- Pending review panel -->
        <div class="review-panel" x-show="showReview" x-cloak>
            <div class="review-panel__meta">
                <div class="review-panel__outlet">
                    <strong x-text="pending.outlet"></strong>
                </div>
                <div class="review-panel__headline" x-text="pending.headline"></div>
                <a class="review-panel__link" x-show="pending.url" :href="pending.url" target="_blank" rel="noopener" x-text="pending.url"></a>
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
                <label class="field__label">
                    Summary
                    <span class="field__label-meta" x-show="pending.edited">· Edited</span>
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

            <!-- Style violations -->
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
            <template x-for="(article, idx) in groupedArticles()" :key="'group-' + idx">
                <div class="article-group">
                    <h3 class="article-group__heading" x-text="article.sectionName"></h3>
                    <template x-for="item in article.items" :key="item.id">
                        <article class="article-card" :class="{ 'is-editing': editingArticleId === item.id }">
                            <div class="article-card__head">
                                <div class="article-card__outlet" x-text="item.outlet"></div>
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
                      placeholder="Click &quot;Generate summary&quot; to have Claude draft this, or write your own."
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

    <!-- Output and send -->
    <section class="editor__section editor__section--output" x-show="articles.length > 0">
        <h2 class="heading-section">Output</h2>

        <div class="output-actions">
            <button type="button" class="btn btn--secondary" @click="copyHtml()">
                <span x-show="!copied">Copy HTML for Outlook</span>
                <span x-show="copied">Copied to clipboard</span>
            </button>
            <button type="button" class="btn btn--secondary" @click="previewHtml()">Preview rendered HTML</button>
            <button type="button" class="btn btn--primary"
                    x-show="status === 'draft'"
                    @click="markSent()"
                    :disabled="articles.length === 0 || !executiveSummary">
                Mark as sent
            </button>
        </div>

        <p class="field__hint" x-show="status === 'draft' && !executiveSummary">Generate the executive summary before marking as sent.</p>

        <div class="preview" x-show="previewOpen" x-cloak>
            <div class="preview__head">
                <span>Preview (as it will render in Outlook)</span>
                <button type="button" class="link-btn" @click="previewOpen = false">Close</button>
            </div>
            <div class="preview__body" x-html="previewHtmlContent"></div>
        </div>
    </section>
</div>
