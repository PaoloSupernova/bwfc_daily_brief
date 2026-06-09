/* ============================================================
   BWFC Daily Brief - Morning Queue
   ============================================================ */

function queueScreen() {
    return {
        hours: 48,
        statusFilter: 'all',
        loading: false,
        refreshing: false,

        groups: [],
        statusCounts: { new: 0, ingested: 0, rejected: 0, duplicate: 0 },

        // Side panel state
        panelOpen: false,
        panelState: 'ingesting', // 'ingesting' | 'success' | 'error'
        ingesting: false,
        ingestingId: null,
        activeCandidateId: null,
        lastResult: {},
        lastError: '',

        statusMessage: '',

        async loadQueue() {
            this.loading = true;
            try {
                const data = await apiPost('queue_list.php', {
                    hours: this.hours,
                    status_filter: this.statusFilter,
                });
                this.groups = data.groups || [];
                this.statusCounts = data.status_counts || { new: 0, ingested: 0, rejected: 0, duplicate: 0 };
            } catch (err) {
                this.flash('Error loading queue: ' + err.message);
            } finally {
                this.loading = false;
            }
        },

        setHours(h) {
            if (this.hours === h) return;
            this.hours = h;
            this.loadQueue();
        },

        setStatus(s) {
            if (this.statusFilter === s) return;
            this.statusFilter = s;
            this.loadQueue();
        },

        totalAcrossStatuses() {
            const c = this.statusCounts;
            return (c.new || 0) + (c.ingested || 0) + (c.rejected || 0) + (c.duplicate || 0);
        },

        async refreshFeeds() {
            this.refreshing = true;
            this.flash('Polling feeds...');
            try {
                const result = await apiPost('sources_poll.php', { force: false });
                let msg = `Polled ${result.sources_polled} sources`;
                if (result.items_new > 0) msg += `, ${result.items_new} new candidate${result.items_new === 1 ? '' : 's'}`;
                if (result.errors && result.errors.length > 0) msg += ` (${result.errors.length} errors)`;
                this.flash(msg);
                this.loadQueue();
            } catch (err) {
                this.flash('Refresh failed: ' + err.message);
            } finally {
                this.refreshing = false;
            }
        },

        async ingest(candidate) {
            if (this.ingesting) return;
            this.ingesting = true;
            this.ingestingId = candidate.id;
            this.activeCandidateId = candidate.id;
            this.panelOpen = true;
            this.panelState = 'ingesting';
            this.lastResult = {};
            this.lastError = '';

            try {
                const result = await apiPost('queue_ingest.php', { candidate_id: candidate.id });
                this.lastResult = result;
                this.panelState = 'success';

                // Update the candidate locally so the UI reflects the new status
                candidate.status = 'ingested';
                candidate.ingested_article_id = result.article?.id;
                this.statusCounts.new = Math.max(0, (this.statusCounts.new || 0) - 1);
                this.statusCounts.ingested = (this.statusCounts.ingested || 0) + 1;
            } catch (err) {
                this.lastError = err.message;
                this.panelState = 'error';
            } finally {
                this.ingesting = false;
                this.ingestingId = null;
            }
        },

        async reject(candidate) {
            // No reason prompt for now — keep it one-click. Reasons can be added later if useful.
            try {
                await apiPost('queue_reject.php', { candidate_id: candidate.id });
                candidate.status = 'rejected';
                this.statusCounts.new = Math.max(0, (this.statusCounts.new || 0) - 1);
                this.statusCounts.rejected = (this.statusCounts.rejected || 0) + 1;
                this.flash('Rejected');
            } catch (err) {
                this.flash('Error: ' + err.message);
            }
        },

        async restore(candidate) {
            try {
                await apiPost('queue_restore.php', { candidate_id: candidate.id });
                const oldStatus = candidate.status;
                candidate.status = 'new';
                this.statusCounts[oldStatus] = Math.max(0, (this.statusCounts[oldStatus] || 0) - 1);
                this.statusCounts.new = (this.statusCounts.new || 0) + 1;
                this.flash('Restored');
            } catch (err) {
                this.flash('Error: ' + err.message);
            }
        },

        closePanel() {
            this.panelOpen = false;
            this.activeCandidateId = null;
        },

        goToEditor() {
            const briefId = this.lastResult.brief_id;
            if (briefId) {
                window.location.href = (window.BWFC_BASE || '') + '/?brief=' + briefId;
            }
        },

        briefUrl(candidate) {
            // Lookup brief_id from the article — we have it on lastResult only.
            // For ingested candidates we don't store brief_id directly on the candidate row,
            // so fall back to today's brief route.
            return (window.BWFC_BASE || '') + '/?brief=new';
        },

        relativeTime(timestamp) {
            if (!timestamp) return '';
            const d = new Date(timestamp.replace(' ', 'T'));
            if (isNaN(d.getTime())) return timestamp;
            const diff = (new Date() - d) / 1000;
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.round(diff / 60) + 'm ago';
            if (diff < 86400) return Math.round(diff / 3600) + 'h ago';
            return Math.round(diff / 86400) + 'd ago';
        },

        flash(msg) {
            this.statusMessage = msg;
            setTimeout(() => {
                if (this.statusMessage === msg) this.statusMessage = '';
            }, 3000);
        },
    };
}

window.queueScreen = queueScreen;
