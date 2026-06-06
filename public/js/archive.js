/* ============================================================
   BWFC Daily Brief - Archive Screen
   Alpine component for the new archive view
   ============================================================ */

function archiveScreen(initial) {
    return {
        // ------ filter state ------
        filters: {
            query: '',
            sections: [],
            outlets: [],
            datePreset: 'all',
            dateFrom: '',
            dateTo: '',
            status: '',
            sentiment: '',
        },
        showAllOutlets: false,
        datePresets: [
            { key: 'week', label: 'This week' },
            { key: 'month', label: 'This month' },
            { key: '30d', label: 'Last 30 days' },
            { key: '90d', label: 'Last 90 days' },
            { key: 'all', label: 'All time' },
            { key: 'custom', label: 'Custom' },
        ],

        // ------ result state ------
        loading: false,
        mode: 'list',
        total: 0,
        page: 1,
        perPage: 20,
        totalPages: 1,
        results: [],
        groups: [],
        facets: {
            sections: [],
            outlets: [],
            status: { sent: 0, draft: 0 },
            sentiment: { positive: 0, neutral: 0, negative: 0 },
        },

        // ------ selection state ------
        selectedBriefs: [],
        bulkDownloading: false,
        bulkError: '',

        // ------ initial seed (for sidebar before first load) ------
        seedSections: initial.sections || [],

        // ============================================================
        // Lifecycle
        // ============================================================

        async loadResults() {
            this.loading = true;
            const dates = this.computeDateRange();
            const body = {
                query: this.filters.query.trim(),
                sections: this.filters.sections,
                outlets: this.filters.outlets,
                date_from: dates.from,
                date_to: dates.to,
                status: this.filters.status,
                sentiment: this.filters.sentiment,
                page: this.page,
                per_page: this.perPage,
            };

            try {
                const data = await apiPost('archive_search.php', body);
                this.mode = data.mode;
                this.total = data.total;
                this.totalPages = data.total_pages;
                this.results = data.results || [];
                this.groups = data.groups || [];
                this.facets = data.facets || { sections: [], outlets: [], status: { sent: 0, draft: 0 }, sentiment: { positive: 0, neutral: 0, negative: 0 } };
            } catch (err) {
                console.error('Archive search failed:', err);
            } finally {
                this.loading = false;
            }
        },

        resetAndLoad() {
            this.page = 1;
            this.loadResults();
        },

        goToPage(p) {
            if (p < 1 || p > this.totalPages) return;
            this.page = p;
            this.loadResults();
            // Scroll up so user sees the next page from the top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        // ============================================================
        // Filter manipulation
        // ============================================================

        toggleSection(slug) {
            const idx = this.filters.sections.indexOf(slug);
            if (idx === -1) {
                this.filters.sections.push(slug);
            } else {
                this.filters.sections.splice(idx, 1);
            }
            this.resetAndLoad();
        },

        toggleOutlet(name) {
            const idx = this.filters.outlets.indexOf(name);
            if (idx === -1) {
                this.filters.outlets.push(name);
            } else {
                this.filters.outlets.splice(idx, 1);
            }
            this.resetAndLoad();
        },

        setDatePreset(key) {
            this.filters.datePreset = key;
            if (key !== 'custom') {
                this.filters.dateFrom = '';
                this.filters.dateTo = '';
            }
            this.resetAndLoad();
        },

        computeDateRange() {
            const today = new Date();
            const fmt = (d) => d.toISOString().slice(0, 10);

            switch (this.filters.datePreset) {
                case 'week': {
                    // Monday of current week (UK convention)
                    const day = today.getDay() || 7;
                    const monday = new Date(today);
                    monday.setDate(today.getDate() - (day - 1));
                    return { from: fmt(monday), to: fmt(today) };
                }
                case 'month': {
                    const first = new Date(today.getFullYear(), today.getMonth(), 1);
                    return { from: fmt(first), to: fmt(today) };
                }
                case '30d': {
                    const past = new Date(today);
                    past.setDate(today.getDate() - 30);
                    return { from: fmt(past), to: fmt(today) };
                }
                case '90d': {
                    const past = new Date(today);
                    past.setDate(today.getDate() - 90);
                    return { from: fmt(past), to: fmt(today) };
                }
                case 'custom':
                    return { from: this.filters.dateFrom, to: this.filters.dateTo };
                case 'all':
                default:
                    return { from: '', to: '' };
            }
        },

        toggleSentiment(val) {
            this.filters.sentiment = this.filters.sentiment === val ? '' : val;
            this.resetAndLoad();
        },

        resetFilters() {
            this.filters = {
                query: '',
                sections: [],
                outlets: [],
                datePreset: 'all',
                dateFrom: '',
                dateTo: '',
                status: '',
                sentiment: '',
            };
            this.resetAndLoad();
        },

        hasActiveFilters() {
            return this.filters.query !== ''
                || this.filters.sections.length > 0
                || this.filters.outlets.length > 0
                || this.filters.datePreset !== 'all'
                || this.filters.status !== ''
                || this.filters.sentiment !== '';
        },

        visibleOutlets() {
            return this.showAllOutlets
                ? this.facets.outlets
                : this.facets.outlets.slice(0, 10);
        },

        // ============================================================
        // Display helpers
        // ============================================================

        /**
         * Convert API snippet sentinels (<<<term>>>) into highlighted span tags.
         * Also escapes any HTML in the source text first to prevent injection.
         */
        highlight(text) {
            if (!text) return '';
            // Escape any incoming HTML first
            const escaped = String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            // The sentinels are now &lt;&lt;&lt;...&gt;&gt;&gt; after escaping
            const withMarks = escaped.replace(
                /&lt;&lt;&lt;(.+?)&gt;&gt;&gt;/g,
                '<mark class="search-mark">$1</mark>'
            );

            // Also highlight inline matches in headlines (no sentinels there)
            const query = this.filters.query.trim();
            if (query && !withMarks.includes('<mark')) {
                const escQuery = query
                    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                if (escQuery.length > 0) {
                    const re = new RegExp('(' + escQuery + ')', 'gi');
                    return withMarks.replace(re, '<mark class="search-mark">$1</mark>');
                }
            }

            return withMarks;
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            const day = d.getDate();
            const month = d.toLocaleDateString('en-GB', { month: 'long' });
            const year = d.getFullYear();
            const dayName = d.toLocaleDateString('en-GB', { weekday: 'long' });
            const ord = (n) => {
                if (n >= 11 && n <= 13) return 'th';
                const m = n % 10;
                return m === 1 ? 'st' : m === 2 ? 'nd' : m === 3 ? 'rd' : 'th';
            };
            return `${dayName} ${day}${ord(day)} ${month} ${year}`;
        },

        dayOfWeek(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-GB', { weekday: 'short' }).toUpperCase();
        },

        dayOfMonth(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return isNaN(d.getTime()) ? '' : String(d.getDate());
        },

        briefUrl(briefId) {
            const base = window.BWFC_BASE || '';
            return base + '/?brief=' + briefId;
        },

        pdfUrl(briefId) {
            const base = window.BWFC_BASE || '';
            const root = base.replace(/\/public\/?$/, '');
            return root + '/api/export_pdf.php?brief_id=' + briefId;
        },

        // ============================================================
        // Bulk PDF selection & download
        // ============================================================

        toggleSelect(briefId) {
            const idx = this.selectedBriefs.indexOf(briefId);
            if (idx === -1) {
                this.selectedBriefs.push(briefId);
            } else {
                this.selectedBriefs.splice(idx, 1);
            }
        },

        isSelected(briefId) {
            return this.selectedBriefs.includes(briefId);
        },

        selectAllVisible() {
            const ids = this.visibleBriefIds();
            ids.forEach(id => {
                if (!this.selectedBriefs.includes(id)) this.selectedBriefs.push(id);
            });
        },

        clearSelection() {
            this.selectedBriefs = [];
            this.bulkError = '';
        },

        visibleBriefIds() {
            if (this.mode === 'list') {
                return this.groups.flatMap(g => g.briefs.map(b => b.id));
            }
            // In search mode, collect unique brief IDs from hits
            const seen = new Set();
            const ids = [];
            this.results.forEach(r => {
                if (!seen.has(r.brief_id)) { seen.add(r.brief_id); ids.push(r.brief_id); }
            });
            return ids;
        },

        allVisibleSelected() {
            const ids = this.visibleBriefIds();
            return ids.length > 0 && ids.every(id => this.selectedBriefs.includes(id));
        },

        async downloadSelected() {
            if (this.selectedBriefs.length === 0) return;
            if (this.selectedBriefs.length > 30) {
                this.bulkError = 'Maximum 30 PDFs per download. Please narrow your selection.';
                return;
            }
            this.bulkDownloading = true;
            this.bulkError = '';

            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res = await fetch(root + '/api/export_pdf_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ brief_ids: this.selectedBriefs }),
                });

                if (!res.ok) {
                    const err = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
                    throw new Error(err.error || 'Download failed');
                }

                // Trigger browser download via blob URL
                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'bwfc-briefs-' + new Date().toISOString().slice(0, 10) + '.zip';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                this.clearSelection();
            } catch (err) {
                this.bulkError = err.message;
            } finally {
                this.bulkDownloading = false;
            }
        },
    };
}

window.archiveScreen = archiveScreen;
