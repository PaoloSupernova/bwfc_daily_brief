<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;
use BWFC\DailyBrief\BriefRenderer;

// $brief, $articles come from router
$sections = BriefRepository::sections(true);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$subject = BriefRenderer::formatSubjectLine((string)$brief['brief_date']);

$jsState = [
    'briefId' => (int)$brief['id'],
    'briefDate' => (string)$brief['brief_date'],
    'subjectLine' => $subject,
    'status' => (string)$brief['status'],
    'executiveSummary' => (string)($brief['executive_summary'] ?? ''),
    'articles' => array_values(array_map(fn($a) => [
        'id' => (int)$a['id'],
        'url' => (string)$a['url'],
        'outlet' => (string)$a['outlet_name'],
        'byline' => (string)($a['byline'] ?? ''),
        'people' => (string)($a['people'] ?? ''),
        'topic' => (string)($a['topic'] ?? ''),
        'sentiment' => (string)($a['sentiment'] ?? ''),
        'headline' => (string)$a['headline'],
        'summary' => (string)$a['summary'],
        'section_id' => (int)$a['section_id'],
        'section_name' => (string)$a['section_name'],
        'section_slug' => (string)$a['section_slug'],
    ], $articles)),
    'sections' => array_values(array_map(fn($s) => [
        'id' => (int)$s['id'],
        'slug' => (string)$s['slug'],
        'name' => (string)$s['name'],
    ], $sections)),
];
?>
<div class="review" x-data="reviewScreen(<?= htmlspecialchars(json_encode($jsState), ENT_QUOTES) ?>)">

    <header class="editor__header">
        <div>
            <div class="editor__date">Final review &middot; <?= htmlspecialchars(BriefRenderer::formatDate((string)$brief['brief_date']), ENT_QUOTES) ?></div>
            <div class="editor__subject">Subject: <code><?= htmlspecialchars($subject, ENT_QUOTES) ?></code></div>
        </div>
        <div class="editor__header-actions">
            <a href="?brief=<?= (int)$brief['id'] ?>" class="btn btn--link">Back to editor</a>
            <span class="editor__count"><span x-text="articles.length"></span> articles</span>
        </div>
    </header>

    <section class="editor__section">
        <div class="review__intro">
            <p class="field__hint">Click anything to edit. Changes save automatically. Drag articles to reorder. Use the section dropdown to move an article.</p>
        </div>
    </section>

    <!-- Executive summary -->
    <section class="editor__section review__exec" x-show="executiveSummary || status === 'draft'">
        <h2 class="heading-section">Executive Summary</h2>
        <textarea class="textarea textarea--tall" rows="6" x-model="executiveSummary"
                  @blur="saveExecutiveSummary()"
                  placeholder="Two-paragraph executive summary..."></textarea>
        <div class="field__actions">
            <span class="field__word-count" x-text="wordCount(executiveSummary) + ' words'"></span>
            <span class="field__hint" x-show="execSaved" x-cloak>Saved</span>
            <button type="button" class="btn btn--secondary btn--small" @click="copyExecutiveSummary()"
                    :disabled="!executiveSummary.trim()">
                <span x-show="!execCopied">Copy to clipboard</span>
                <span x-show="execCopied" x-cloak>Copied &check;</span>
            </button>
        </div>
    </section>

    <!-- Sections with articles -->
    <template x-for="group in groupedArticles()" :key="'g-' + group.sectionSlug">
        <section class="editor__section review__section">
            <h2 class="heading-section" x-text="group.sectionName"></h2>

            <div class="review__articles" :id="'section-articles-' + group.sectionSlug" :data-section-id="group.sectionId">
                <template x-for="article in group.items" :key="article.id">
                    <div class="review-article" :data-id="article.id">
                        <div class="review-article__handle" title="Drag to reorder">&#8942;&#8942;</div>

                        <div class="review-article__body">
                            <div class="review-article__top">
                                <textarea class="review-article__outlet"
                                          x-model="article.outlet"
                                          x-init="autoResize($el)"
                                          @input="autoResize($event.target)"
                                          @blur="saveArticleField(article)"
                                          rows="1"
                                          placeholder="Outlet"></textarea>
                                <textarea class="review-article__headline"
                                          x-model="article.headline"
                                          x-init="autoResize($el)"
                                          @input="autoResize($event.target)"
                                          @blur="saveArticleField(article)"
                                          rows="1"
                                          placeholder="Headline"></textarea>
                            </div>

                            <input class="review-article__byline"
                                   x-model="article.byline"
                                   @blur="saveArticleField(article)"
                                   placeholder="Byline — journalist(s), or blank for Unassigned">

                            <input class="review-article__byline review-article__people"
                                   x-model="article.people"
                                   @blur="saveArticleField(article)"
                                   placeholder="People mentioned — players / staff, comma-separated">

                            <div class="review-article__image">
                                <img x-show="article.image_url" x-cloak :src="article.image_url" alt=""
                                     @error="article.image_url = ''; saveArticleField(article)">
                                <div class="review-article__image-controls">
                                    <input class="review-article__byline review-article__imgurl"
                                           x-model="article.image_url"
                                           @blur="saveArticleField(article)"
                                           placeholder="Image URL — paste to set, clear to remove (text-only)">
                                    <button type="button" class="btn btn--link btn--small"
                                            x-show="article.image_url" x-cloak
                                            @click="article.image_url = ''; saveArticleField(article)">Remove image</button>
                                </div>
                            </div>

                            <textarea class="review-article__summary" rows="4"
                                      x-model="article.summary"
                                      x-init="autoResize($el, 80)"
                                      @input="autoResize($event.target, 80)"
                                      @blur="saveArticleField(article)"></textarea>

                            <div class="review-article__controls">
                                <select class="select select--small" x-model="article.section_id" @change="changeSection(article)">
                                    <template x-for="s in sections" :key="s.id">
                                        <option :value="s.id" x-text="s.name"></option>
                                    </template>
                                </select>

                                <select class="select select--small" x-model="article.topic" @change="saveArticleField(article)" title="Topic">
                                    <option value="">— topic —</option>
                                    <?php foreach (\BWFC\DailyBrief\Topics::all() as $t): ?>
                                        <option value="<?= htmlspecialchars($t['slug'], ENT_QUOTES) ?>"><?= htmlspecialchars($t['label'], ENT_QUOTES) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="review-article__sentiment" title="Sentiment — click to override">
                                    <template x-for="s in ['positive','neutral','negative']" :key="s">
                                        <button type="button" class="sentiment-toggle"
                                                :class="['sentiment-toggle--' + s, article.sentiment === s ? 'is-active' : '']"
                                                @click="setSentiment(article, s)" x-text="s"></button>
                                    </template>
                                </div>

                                <div class="review-article__buttons">
                                    <button type="button" class="icon-btn" title="Move up" @click="moveUp(article)">&uarr;</button>
                                    <button type="button" class="icon-btn" title="Move down" @click="moveDown(article)">&darr;</button>
                                    <span class="field__word-count" x-text="wordCount(article.summary) + 'w'"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </template>

    <!-- Export actions -->
    <section class="editor__section review__export">
        <h2 class="heading-section">Export</h2>
        <p class="field__hint">Pick a format. Plain text matches the existing Daily Brief email template exactly. HTML is styled for Outlook paste. PDF is a branded download.</p>

        <div class="export-actions">
            <button type="button" class="btn btn--secondary" @click="copyFormat('text_with_links')">
                <span x-show="copiedFormat !== 'text_with_links'">Copy plain text</span>
                <span x-show="copiedFormat === 'text_with_links'">Copied</span>
            </button>
            <button type="button" class="btn btn--secondary" @click="copyFormat('html')">
                <span x-show="copiedFormat !== 'html'">Copy HTML for Outlook</span>
                <span x-show="copiedFormat === 'html'">Copied</span>
            </button>
            <a :href="pdfUrl()" target="_blank" class="btn btn--secondary">Download PDF</a>

            <div class="export-actions__send" x-show="status === 'draft'">
                <button type="button" class="btn btn--primary"
                        @click="markSent()"
                        :disabled="articles.length === 0">
                    Mark as sent &amp; lock
                </button>
            </div>
        </div>

        <div class="status-line" x-show="statusMessage" x-cloak x-text="statusMessage"></div>
    </section>
</div>
