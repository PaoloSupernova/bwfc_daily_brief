/* ============================================================
   BWFC Daily Brief - Client application logic
   Alpine.js components for editor, admin, and review screens
   ============================================================ */

function apiUrl(endpoint) {
    const base = window.BWFC_BASE || '';
    const root = base.replace(/\/public\/?$/, '');
    return root + '/api/' + endpoint;
}

async function apiPost(endpoint, body) {
    const res = await fetch(apiUrl(endpoint), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid JSON response' }));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
    }
    return data;
}

async function apiGet(endpoint) {
    const res = await fetch(apiUrl(endpoint));
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid JSON response' }));
    if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
    }
    return data;
}

const SECTION_SLUG_MAP = {
    'BWFC': 'bwfc',
    'EFL': 'efl',
    'LOCAL_COMMUNITY': 'local_community',
    'WOMENS_GAME': 'womens_game',
    'GENERAL_FOOTBALL': 'general_football',
    'LOCAL_BUSINESS': 'local_business',
    'OTHER_SPORT': 'other_sport',
};

function emptyPending() {
    return {
        url: '',
        headline: '',
        outlet: '',
        byline: '',
        people: '',
        content: '',
        summary: '',
        section_slug: 'bwfc',
        suggestedSection: '',
        sentiment: '',
        edited: false,
        was_paywall_fallback: false,
        violations: [],
    };
}

function emptyDuplicateSuggestion() {
    return null;
}

/**
 * Resize a textarea to match its content height.
 * Called on input and on initial mount.
 */
function autoResizeTextarea(el, minHeight) {
    if (!el) return;
    const min = minHeight || 0;
    el.style.height = 'auto';
    const newHeight = Math.max(el.scrollHeight, min);
    el.style.height = newHeight + 'px';
}

/* ============================================================
   BRIEF EDITOR
   ============================================================ */

function briefEditor(initial) {
    return {
        briefId: initial.briefId || 0,
        briefDate: initial.briefDate,
        status: initial.status || 'draft',
        articles: initial.articles || [],
        sections: initial.sections || [],
        executiveSummary: initial.executiveSummary || '',
        executiveSummaryEdited: false,

        urlInput: '',
        fetching: false,
        processing: false,
        processingExec: false,
        showFallback: false,
        showReview: false,
        duplicateSuggestion: null,
        priorCoverage: null,
        _fetchSucceeded: false,
        _fetchErrorMsg: '',
        fetchError: '',
        statusMessage: '',
        copied: false,
        previewOpen: false,
        previewHtmlContent: '',

        pending: {},
        editingArticleId: null,
        editingText: '',

        init() {
            this.pending = emptyPending();
        },

        resetPending() {
            this.pending = emptyPending();
            this.showFallback = false;
            this.showReview = false;
            this.duplicateSuggestion = null;
            this.priorCoverage = null;
            this.fetchError = '';
        },

        cancelPending() {
            this.resetPending();
            this.urlInput = '';
            this.statusMessage = '';
        },

        async fetchArticle() {
            if (!this.urlInput) return;
            this.fetching = true;
            this.fetchError = '';
            this.priorCoverage = null;
            this.statusMessage = 'Fetching article...';
            this.showReview = false;
            this.showFallback = false;

            try {
                const data = await apiPost('fetch_article.php', {
                    url: this.urlInput,
                    brief_id: this.briefId,
                });
                const a = data.article;

                this.pending = emptyPending();
                this.pending.url = this.urlInput;
                this.pending.outlet = a.outlet || 'Unknown';
                this.pending.headline = a.headline || '';
                this.pending.byline = a.byline_raw || '';
                this.pending.people = a.people_detected || '';
                this.pending.content = a.content || '';
                this._fetchSucceeded = !!a.success;
                this._fetchErrorMsg = a.error || '';

                // Prior-coverage gate: if this exact link appeared in an earlier
                // brief, stop and ask before doing anything else. Default is to
                // NOT repeat — the user must explicitly choose "Add it anyway".
                if (data.prior_coverage) {
                    this.priorCoverage = data.prior_coverage;
                    this.statusMessage = '';
                    return;
                }

                await this.continueAfterFetch();
            } catch (err) {
                this.fetchError = err.message;
                this.statusMessage = '';
                this.pending = emptyPending();
                this.pending.url = this.urlInput;
                this.pending.was_paywall_fallback = true;
                this.showFallback = true;
            } finally {
                this.fetching = false;
            }
        },

        /**
         * Continue the add-article flow after a fetch (and after any
         * prior-coverage override): summarise if we got the body, otherwise
         * drop into the manual-paste fallback.
         */
        async continueAfterFetch() {
            if (this._fetchSucceeded) {
                this.statusMessage = 'Article fetched. Generating summary...';
                await this.generateSummary(false);
            } else {
                this.fetchError = this._fetchErrorMsg || 'Could not extract article content. Paste the article body below.';
                this.pending.was_paywall_fallback = true;
                this.showFallback = true;
                this.statusMessage = '';
            }
        },

        /** User chose to use a previously-covered article anyway. */
        usePriorCoverageAnyway() {
            this.priorCoverage = null;
            this.continueAfterFetch();
        },

        /** User backed out of adding a previously-covered article. */
        dismissPriorCoverage() {
            this.priorCoverage = null;
            this.resetPending();
            this.urlInput = '';
            this.statusMessage = '';
        },

        async generateSummary(fromFallback, skipDuplicateCheck) {
            if (!this.pending.headline || !this.pending.content) {
                this.statusMessage = 'Headline and content required to generate a summary';
                return;
            }

            this.processing = true;
            this.statusMessage = this.briefId > 0 && !skipDuplicateCheck
                ? 'Checking for related coverage...'
                : 'Generating summary...';

            try {
                const data = await apiPost('summarise.php', {
                    headline: this.pending.headline,
                    outlet: this.pending.outlet,
                    content: this.pending.content,
                    brief_id: this.briefId,
                    skip_duplicate_check: skipDuplicateCheck || false,
                });

                // Duplicate detected — show confirmation card instead of summary
                if (data.duplicate) {
                    this.duplicateSuggestion = {
                        parentId: data.parent_id,
                        parentHeadline: data.parent_headline,
                    };
                    this.showFallback = false;
                    this.showReview = false;
                    this.statusMessage = '';
                    return;
                }

                this.pending.summary = data.summary;
                this.pending.suggestedSection = data.suggested_section || '';
                this.pending.section_slug = SECTION_SLUG_MAP[data.suggested_section] || 'bwfc';
                this.pending.sentiment = data.sentiment || '';
                this.pending.violations = (data.style_check && data.style_check.violations) || [];
                this.pending.edited = false;

                this.showFallback = false;
                this.showReview = true;
                this.duplicateSuggestion = null;
                this.statusMessage = '';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        async acceptDuplicate() {
            if (!this.duplicateSuggestion) return;
            this.processing = true;
            this.statusMessage = 'Adding related coverage...';
            try {
                const data = await apiPost('save_article.php', {
                    brief_id: this.briefId,
                    brief_date: this.briefDate,
                    parent_article_id: this.duplicateSuggestion.parentId,
                    url: this.pending.url,
                    outlet_name: this.pending.outlet,
                    headline: this.pending.headline,
                    article_content: this.pending.content,
                    summary: '',
                    was_paywall_fallback: this.pending.was_paywall_fallback,
                });

                // Attach the related item to its parent in local state
                const parent = this.articles.find(a => a.id === this.duplicateSuggestion.parentId);
                if (parent) {
                    parent.related = parent.related || [];
                    parent.related.push({
                        id: data.article_id,
                        outlet: this.pending.outlet,
                        headline: this.pending.headline,
                        url: this.pending.url,
                    });
                }

                this.urlInput = '';
                this.resetPending();
                this.statusMessage = 'Added as related coverage.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        rejectDuplicate() {
            this.duplicateSuggestion = null;
            this.generateSummary(this.pending.was_paywall_fallback, true);
        },

        async regenerateSummary() {
            this.processing = true;
            this.statusMessage = 'Regenerating summary...';
            try {
                const data = await apiPost('regenerate.php', {
                    headline: this.pending.headline,
                    outlet: this.pending.outlet,
                    content: this.pending.content,
                });
                this.pending.summary = data.summary;
                this.pending.violations = (data.style_check && data.style_check.violations) || [];
                this.pending.edited = false;
                this.statusMessage = '';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        suggestedSectionName() {
            if (!this.pending.suggestedSection) return '';
            const slug = SECTION_SLUG_MAP[this.pending.suggestedSection];
            const s = this.sections.find(x => x.slug === slug);
            return s ? s.name : this.pending.suggestedSection;
        },

        async saveArticle() {
            if (!this.pending.summary || !this.pending.section_slug) {
                this.statusMessage = 'Summary and section required';
                return;
            }

            this.processing = true;
            this.statusMessage = 'Adding to brief...';

            try {
                const data = await apiPost('save_article.php', {
                    brief_id: this.briefId,
                    brief_date: this.briefDate,
                    section_slug: this.pending.section_slug,
                    url: this.pending.url,
                    outlet_name: this.pending.outlet,
                    headline: this.pending.headline,
                    byline: this.pending.byline,
                    people: this.pending.people,
                    article_content: this.pending.content,
                    summary: this.pending.summary,
                    summary_original: this.pending.summary,
                    sentiment: this.pending.sentiment,
                    was_edited: this.pending.edited,
                    was_paywall_fallback: this.pending.was_paywall_fallback,
                });

                if (this.briefId === 0) {
                    this.briefId = data.brief_id;
                    const newUrl = window.location.pathname + '?brief=' + data.brief_id;
                    window.history.replaceState({}, '', newUrl);
                }

                const a = data.article;
                this.articles.push({
                    id: a.id,
                    url: a.url,
                    outlet: a.outlet_name,
                    byline: a.byline || '',
                    people: a.people || '',
                    headline: a.headline,
                    summary: a.summary,
                    section_name: a.section_name,
                    section_slug: a.section_slug,
                    sentiment: a.sentiment || '',
                    was_edited: !!a.was_edited,
                    related: [],
                });

                this.urlInput = '';
                this.resetPending();
                this.statusMessage = 'Added. Paste another link to continue.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        startEdit(article) {
            this.editingArticleId = article.id;
            this.editingText = article.summary;
        },

        cancelEdit() {
            this.editingArticleId = null;
            this.editingText = '';
        },

        async saveEdit(articleId) {
            if (!this.editingText.trim()) return;
            this.processing = true;
            try {
                await apiPost('update_summary.php', {
                    article_id: articleId,
                    summary: this.editingText,
                });
                const article = this.articles.find(a => a.id === articleId);
                if (article) {
                    article.summary = this.editingText;
                    article.was_edited = true;
                }
                this.editingArticleId = null;
                this.editingText = '';
                this.statusMessage = 'Summary updated.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        async deleteArticle(articleId) {
            const article = this.articles.find(a => a.id === articleId);
            const hasRelated = article && article.related && article.related.length > 0;
            const msg = hasRelated
                ? 'Remove this article and its ' + article.related.length + ' related coverage link(s)?'
                : 'Remove this article from the brief?';
            if (!confirm(msg)) return;
            try {
                await apiPost('delete_article.php', { article_id: articleId });
                this.articles = this.articles.filter(a => a.id !== articleId);
                this.statusMessage = 'Article removed.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        deleteRelated(parentId, relatedId) {
            if (!confirm('Remove this related coverage link?')) return;
            apiPost('delete_article.php', { article_id: relatedId }).then(() => {
                const parent = this.articles.find(a => a.id === parentId);
                if (parent) parent.related = parent.related.filter(r => r.id !== relatedId);
                this.statusMessage = 'Related coverage removed.';
            }).catch(err => {
                this.statusMessage = 'Error: ' + err.message;
            });
        },

        async generateExecutiveSummary() {
            if (this.articles.length === 0) return;
            if (this.briefId === 0) {
                this.statusMessage = 'Add at least one article first.';
                return;
            }
            this.processingExec = true;
            try {
                const data = await apiPost('generate_executive_summary.php', { brief_id: this.briefId });
                this.executiveSummary = data.executive_summary;
                this.executiveSummaryEdited = false;
                this.statusMessage = 'Executive summary generated.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processingExec = false;
            }
        },

        async saveExecutiveSummary() {
            try {
                await apiPost('update_executive_summary.php', {
                    brief_id: this.briefId,
                    executive_summary: this.executiveSummary,
                });
                this.executiveSummaryEdited = false;
                this.statusMessage = 'Executive summary saved.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        goToReview() {
            if (this.briefId === 0) return;
            window.location.href = window.location.pathname + '?brief=' + this.briefId + '&review=1';
        },

        async previewHtml() {
            try {
                const data = await apiPost('render.php', { brief_id: this.briefId, format: 'html' });
                this.previewHtmlContent = data.content;
                this.previewOpen = true;
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async copyHtml() {
            try {
                const data = await apiPost('render.php', { brief_id: this.briefId, format: 'html' });
                await navigator.clipboard.writeText(data.content);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async markSent() {
            if (!confirm('Mark this brief as sent? It will be locked from further edits.')) return;
            try {
                await apiPost('mark_sent.php', { brief_id: this.briefId });
                this.status = 'sent';
                this.statusMessage = 'Brief marked as sent. Reloading...';
                setTimeout(() => window.location.reload(), 600);
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        groupedArticles() {
            const groups = {};
            for (const a of this.articles) {
                const key = a.section_slug;
                groups[key] = groups[key] || { sectionSlug: key, sectionName: a.section_name, items: [] };
                groups[key].items.push(a);
            }
            const ordered = [];
            for (const s of this.sections) {
                if (groups[s.slug]) ordered.push(groups[s.slug]);
            }
            return ordered;
        },

        wordCount(text) {
            if (!text) return 0;
            return text.trim().split(/\s+/).filter(Boolean).length;
        },

        /** Format a YYYY-MM-DD date as e.g. "5 August 2026" for display. */
        prettyDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
        },
    };
}

/* ============================================================
   SECTIONS ADMIN
   ============================================================ */

function sectionsAdmin(initial) {
    return {
        sections: initial.sections || [],

        adding: false,
        newSection: { name: '', routing_description: '' },

        editingId: null,
        editBuffer: { name: '', routing_description: '' },

        statusMessage: '',
        _sortable: null,

        init() {
            this.$nextTick(() => {
                this.initSortable();
            });
        },

        initSortable() {
            const el = document.getElementById('sections-list');
            if (!el || typeof Sortable === 'undefined') return;
            if (this._sortable) { this._sortable.destroy(); }
            this._sortable = Sortable.create(el, {
                handle: '.section-row__drag',
                animation: 150,
                onEnd: async () => {
                    const orderedIds = Array.from(el.querySelectorAll('.section-row'))
                        .map(row => parseInt(row.dataset.id, 10))
                        .filter(Boolean);
                    try {
                        await apiPost('sections_reorder.php', { ids: orderedIds });
                        this.sections.sort((a, b) => orderedIds.indexOf(a.id) - orderedIds.indexOf(b.id));
                        this.flash('Order saved');
                    } catch (err) {
                        this.statusMessage = 'Error: ' + err.message;
                    }
                },
            });
        },

        startAdd() {
            this.adding = true;
            this.newSection = { name: '', routing_description: '' };
        },

        cancelAdd() {
            this.adding = false;
        },

        async saveNew() {
            if (!this.newSection.name.trim()) return;
            try {
                const data = await apiPost('sections_create.php', {
                    name: this.newSection.name.trim(),
                    routing_description: this.newSection.routing_description.trim(),
                });
                const s = data.section;
                this.sections.push({
                    id: parseInt(s.id, 10),
                    slug: s.slug,
                    name: s.name,
                    routing_description: s.routing_description || '',
                    display_order: parseInt(s.display_order, 10),
                    article_count: 0,
                });
                this.adding = false;
                this.flash('Section added');
                this.$nextTick(() => this.initSortable());
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        startEdit(section) {
            this.editingId = section.id;
            this.editBuffer = {
                name: section.name,
                routing_description: section.routing_description || '',
            };
        },

        cancelEdit() {
            this.editingId = null;
        },

        async saveEdit(section) {
            try {
                const data = await apiPost('sections_update.php', {
                    id: section.id,
                    name: this.editBuffer.name.trim(),
                    routing_description: this.editBuffer.routing_description.trim(),
                });
                section.name = data.section.name;
                section.routing_description = data.section.routing_description || '';
                this.editingId = null;
                this.flash('Section updated');
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async deleteSection(section) {
            try {
                const data = await apiPost('sections_delete.php', { id: section.id });
                if (data.needs_confirmation) {
                    if (!confirm(data.message)) return;
                    await apiPost('sections_delete.php', { id: section.id, confirm: true });
                }
                this.sections = this.sections.filter(s => s.id !== section.id);
                this.flash('Section deleted');
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        flash(message) {
            this.statusMessage = message;
            setTimeout(() => {
                if (this.statusMessage === message) this.statusMessage = '';
            }, 2500);
        },
    };
}

/* ============================================================
   REVIEW SCREEN
   ============================================================ */

function reviewScreen(initial) {
    return {
        briefId: initial.briefId,
        briefDate: initial.briefDate,
        subjectLine: initial.subjectLine,
        status: initial.status,
        executiveSummary: initial.executiveSummary || '',
        articles: initial.articles || [],
        sections: initial.sections || [],

        copiedFormat: null,
        statusMessage: '',
        execSaved: false,
        execCopied: false,
        _sortables: [],

        init() {
            this.$nextTick(() => {
                this.initSortables();
                // Resize all auto-grow textareas after initial render
                document.querySelectorAll('.review-article__outlet, .review-article__headline').forEach(el => {
                    autoResizeTextarea(el);
                });
                document.querySelectorAll('.review-article__summary').forEach(el => {
                    autoResizeTextarea(el, 80);
                });
            });
        },

        // Exposed so x-init can call it from the template
        autoResize(el, minHeight) {
            autoResizeTextarea(el, minHeight);
        },

        initSortables() {
            if (typeof Sortable === 'undefined') return;
            for (const s of this._sortables) s.destroy();
            this._sortables = [];

            const containers = document.querySelectorAll('.review__articles');
            containers.forEach(container => {
                const s = Sortable.create(container, {
                    group: 'review-articles',
                    handle: '.review-article__handle',
                    animation: 150,
                    onEnd: (evt) => this.onDragEnd(evt),
                });
                this._sortables.push(s);
            });
        },

        async onDragEnd(evt) {
            const containers = document.querySelectorAll('.review__articles');
            const updates = [];
            for (const container of containers) {
                const sectionId = parseInt(container.dataset.sectionId, 10);
                const ids = Array.from(container.querySelectorAll('.review-article'))
                    .map(el => parseInt(el.dataset.id, 10))
                    .filter(Boolean);
                updates.push({ sectionId, ids });
            }

            try {
                for (const u of updates) {
                    if (u.ids.length === 0) continue;
                    await apiPost('reorder_articles.php', {
                        brief_id: this.briefId,
                        article_ids: u.ids,
                        section_id: u.sectionId,
                    });
                }
                for (const u of updates) {
                    for (const id of u.ids) {
                        const a = this.articles.find(x => x.id === id);
                        if (a) a.section_id = u.sectionId;
                    }
                }
                this.syncArticleSectionLabels();
                this.flash('Order saved');
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        syncArticleSectionLabels() {
            for (const a of this.articles) {
                const section = this.sections.find(s => s.id === parseInt(a.section_id, 10));
                if (section) {
                    a.section_name = section.name;
                    a.section_slug = section.slug;
                }
            }
        },

        groupedArticles() {
            const groups = {};
            for (const a of this.articles) {
                const sid = parseInt(a.section_id, 10);
                const section = this.sections.find(s => s.id === sid);
                if (!section) continue;
                const key = section.slug;
                groups[key] = groups[key] || {
                    sectionSlug: section.slug,
                    sectionName: section.name,
                    sectionId: section.id,
                    items: [],
                };
                groups[key].items.push(a);
            }
            const ordered = [];
            for (const s of this.sections) {
                if (groups[s.slug]) ordered.push(groups[s.slug]);
            }
            return ordered;
        },

        /**
         * Save any subset of editable fields (headline, outlet, summary) on an article.
         * Called from blur on the inline textareas.
         */
        async saveArticleField(article) {
            try {
                await apiPost('update_article.php', {
                    article_id: article.id,
                    headline: article.headline,
                    outlet_name: article.outlet,
                    byline: article.byline || '',
                    people: article.people || '',
                    summary: article.summary,
                });
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async changeSection(article) {
            const newSectionId = parseInt(article.section_id, 10);
            const newSection = this.sections.find(s => s.id === newSectionId);
            if (!newSection) return;
            try {
                await apiPost('move_article.php', {
                    article_id: article.id,
                    section_slug: newSection.slug,
                });
                article.section_name = newSection.name;
                article.section_slug = newSection.slug;
                this.flash('Article moved to ' + newSection.name);
                this.$nextTick(() => this.initSortables());
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        moveUp(article) {
            const group = this.groupedArticles().find(g => g.items.some(i => i.id === article.id));
            if (!group) return;
            const idx = group.items.findIndex(i => i.id === article.id);
            if (idx <= 0) return;
            const ids = group.items.map(i => i.id);
            [ids[idx - 1], ids[idx]] = [ids[idx], ids[idx - 1]];
            this.saveGroupOrder(group.sectionId, ids);
        },

        moveDown(article) {
            const group = this.groupedArticles().find(g => g.items.some(i => i.id === article.id));
            if (!group) return;
            const idx = group.items.findIndex(i => i.id === article.id);
            if (idx < 0 || idx >= group.items.length - 1) return;
            const ids = group.items.map(i => i.id);
            [ids[idx], ids[idx + 1]] = [ids[idx + 1], ids[idx]];
            this.saveGroupOrder(group.sectionId, ids);
        },

        async saveGroupOrder(sectionId, ids) {
            try {
                await apiPost('reorder_articles.php', {
                    brief_id: this.briefId,
                    article_ids: ids,
                    section_id: sectionId,
                });
                const orderMap = {};
                ids.forEach((id, i) => { orderMap[id] = i; });
                this.articles.sort((a, b) => {
                    if (a.section_id !== b.section_id) return 0;
                    return (orderMap[a.id] ?? 999) - (orderMap[b.id] ?? 999);
                });
                this.flash('Order saved');
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async saveExecutiveSummary() {
            try {
                await apiPost('update_executive_summary.php', {
                    brief_id: this.briefId,
                    executive_summary: this.executiveSummary,
                });
                this.execSaved = true;
                setTimeout(() => { this.execSaved = false; }, 2000);
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        /**
         * Copy just the executive summary text to the clipboard so it can be
         * pasted straight into an email. Uses the in-memory value; falls back
         * to a manual-copy prompt if the Clipboard API is unavailable
         * (e.g. non-HTTPS context).
         */
        async copyExecutiveSummary() {
            const text = (this.executiveSummary || '').trim();
            if (text === '') {
                this.statusMessage = 'Nothing to copy — the executive summary is empty.';
                return;
            }
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    this.fallbackCopy(text);
                }
                this.execCopied = true;
                setTimeout(() => { this.execCopied = false; }, 2500);
            } catch (err) {
                this.fallbackCopy(text);
                this.execCopied = true;
                setTimeout(() => { this.execCopied = false; }, 2500);
            }
        },

        /** Legacy clipboard copy for browsers without the async Clipboard API. */
        fallbackCopy(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* no-op */ }
            document.body.removeChild(ta);
        },

        async copyFormat(format) {
            try {
                const data = await apiPost('render.php', {
                    brief_id: this.briefId,
                    format: format,
                });
                await navigator.clipboard.writeText(data.content);
                this.copiedFormat = format;
                setTimeout(() => { this.copiedFormat = null; }, 2500);
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        pdfUrl() {
            const base = (window.BWFC_BASE || '').replace(/\/public\/?$/, '');
            return base + '/api/export_pdf.php?brief_id=' + this.briefId;
        },

        async markSent() {
            if (!confirm('Mark this brief as sent? It will be locked from further edits.')) return;
            try {
                await apiPost('mark_sent.php', { brief_id: this.briefId });
                this.status = 'sent';
                this.flash('Brief sent. Redirecting...');
                setTimeout(() => {
                    window.location.href = window.location.pathname + '?brief=' + this.briefId;
                }, 800);
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        wordCount(text) {
            if (!text) return 0;
            return text.trim().split(/\s+/).filter(Boolean).length;
        },

        flash(message) {
            this.statusMessage = message;
            setTimeout(() => {
                if (this.statusMessage === message) this.statusMessage = '';
            }, 2500);
        },
    };
}

window.briefEditor = briefEditor;
window.sectionsAdmin = sectionsAdmin;
window.reviewScreen = reviewScreen;
