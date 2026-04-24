/* ============================================================
   BWFC Daily Brief - Client application logic
   Alpine.js component for the editor workflow
   ============================================================ */

/**
 * Build a full API URL from the base path.
 * public/ is the web root in XAMPP; api/ sits one directory up.
 */
function apiUrl(endpoint) {
    const base = window.BWFC_BASE || '';
    // Strip trailing /public if present (so /bwfc-daily-brief/public → /bwfc-daily-brief)
    const root = base.replace(/\/public\/?$/, '');
    return root + '/api/' + endpoint;
}

/**
 * POST helper returning parsed JSON.
 */
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

/**
 * Section slug to display name lookup.
 */
const SECTION_SLUG_MAP = {
    'BWFC': 'bwfc',
    'EFL': 'efl',
    'WOMENS_GAME': 'womens_game',
    'GENERAL_FOOTBALL': 'general_football',
    'OTHER_SPORT': 'other_sport',
};

/**
 * Build a fresh empty pending object. Standalone so it can be called
 * from init() and from cancel/save paths.
 */
function emptyPending() {
    return {
        url: '',
        headline: '',
        outlet: '',
        content: '',
        summary: '',
        section_slug: 'bwfc',
        suggestedSection: '',
        edited: false,
        was_paywall_fallback: false,
        violations: [],
    };
}

/**
 * Main editor Alpine component.
 * Receives server-side state as an initial object.
 */
function briefEditor(initial) {
    return {
        // --- Server-synced state ---
        briefId: initial.briefId || 0,
        briefDate: initial.briefDate,
        status: initial.status || 'draft',
        articles: initial.articles || [],
        sections: initial.sections || [],
        executiveSummary: initial.executiveSummary || '',
        executiveSummaryEdited: false,

        // --- Transient UI state ---
        urlInput: '',
        fetching: false,
        processing: false,
        processingExec: false,
        showFallback: false,
        showReview: false,
        fetchError: '',
        statusMessage: '',
        copied: false,
        previewOpen: false,
        previewHtmlContent: '',

        // Pending article currently under review (populated in init)
        pending: {},

        // Article being edited inline
        editingArticleId: null,
        editingText: '',

        // --- Lifecycle ---
        init() {
            this.pending = emptyPending();
        },

        resetPending() {
            this.pending = emptyPending();
            this.showFallback = false;
            this.showReview = false;
            this.fetchError = '';
        },

        cancelPending() {
            this.resetPending();
            this.urlInput = '';
            this.statusMessage = '';
        },

        // --- Fetch article ---
        async fetchArticle() {
            if (!this.urlInput) return;

            this.fetching = true;
            this.fetchError = '';
            this.statusMessage = 'Fetching article...';
            this.showReview = false;
            this.showFallback = false;

            try {
                const data = await apiPost('fetch_article.php', { url: this.urlInput });
                const a = data.article;

                // Seed the pending object regardless of success
                this.pending = emptyPending();
                this.pending.url = this.urlInput;
                this.pending.outlet = a.outlet || 'Unknown';
                this.pending.headline = a.headline || '';
                this.pending.content = a.content || '';

                if (!a.success) {
                    // Fetch failed or extraction too short: open paste fallback
                    this.fetchError = a.error || 'Could not extract article content. Paste the article body below.';
                    this.pending.was_paywall_fallback = true;
                    this.showFallback = true;
                    this.statusMessage = '';
                } else {
                    // Success: go straight to summary generation
                    this.statusMessage = 'Article fetched. Generating summary...';
                    await this.generateSummary(false);
                }
            } catch (err) {
                this.fetchError = err.message;
                this.statusMessage = '';
                // Open fallback so the user can still add the article manually
                this.pending = emptyPending();
                this.pending.url = this.urlInput;
                this.pending.was_paywall_fallback = true;
                this.showFallback = true;
            } finally {
                this.fetching = false;
            }
        },

        // --- Generate summary ---
        async generateSummary(fromFallback) {
            if (!this.pending.headline || !this.pending.content) {
                this.statusMessage = 'Headline and content required to generate a summary';
                return;
            }

            this.processing = true;
            this.statusMessage = 'Generating summary...';

            try {
                const data = await apiPost('summarise.php', {
                    headline: this.pending.headline,
                    outlet: this.pending.outlet,
                    content: this.pending.content,
                });

                this.pending.summary = data.summary;
                this.pending.suggestedSection = data.suggested_section || '';
                this.pending.section_slug = SECTION_SLUG_MAP[data.suggested_section] || 'bwfc';
                this.pending.violations = (data.style_check && data.style_check.violations) || [];
                this.pending.edited = false;

                this.showFallback = false;
                this.showReview = true;
                this.statusMessage = '';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            } finally {
                this.processing = false;
            }
        },

        // --- Regenerate pending summary ---
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

        // --- Save article to brief ---
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
                    article_content: this.pending.content,
                    summary: this.pending.summary,
                    summary_original: this.pending.summary,
                    was_edited: this.pending.edited,
                    was_paywall_fallback: this.pending.was_paywall_fallback,
                });

                // If the brief was just created server-side, update the URL so a refresh loads this brief
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
                    headline: a.headline,
                    summary: a.summary,
                    section_name: a.section_name,
                    section_slug: a.section_slug,
                    was_edited: !!a.was_edited,
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

        // --- Edit existing article inline ---
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
            if (!confirm('Remove this article from the brief?')) return;
            try {
                await apiPost('delete_article.php', { article_id: articleId });
                this.articles = this.articles.filter(a => a.id !== articleId);
                this.statusMessage = 'Article removed.';
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        // --- Executive summary ---
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

        // --- Output ---
        async previewHtml() {
            try {
                const data = await apiPost('render_html.php', { brief_id: this.briefId });
                this.previewHtmlContent = data.html;
                this.previewOpen = true;
            } catch (err) {
                this.statusMessage = 'Error: ' + err.message;
            }
        },

        async copyHtml() {
            try {
                const data = await apiPost('render_html.php', { brief_id: this.briefId });
                await navigator.clipboard.writeText(data.html);
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

        // --- Grouping helpers ---
        groupedArticles() {
            const groups = {};
            for (const a of this.articles) {
                const key = a.section_slug;
                groups[key] = groups[key] || { sectionSlug: key, sectionName: a.section_name, items: [] };
                groups[key].items.push(a);
            }
            // Preserve the order from this.sections (which is already ordered by display_order)
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
    };
}

// Expose globally so Alpine can find it
window.briefEditor = briefEditor;