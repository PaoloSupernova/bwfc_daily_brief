/* ============================================================
   BWFC Daily Brief - Admin pages (Sources, Jobs)
   ============================================================ */

function sourcesAdmin() {
    return {
        sources: [],
        loading: false,
        polling: false,
        adding: false,
        editingId: null,
        statusMessage: '',
        form: {
            id: null,
            name: '',
            url: '',
            source_type: 'rss',
            is_local: false,
            is_active: true,
            poll_frequency_minutes: 60,
        },

        async loadSources() {
            this.loading = true;
            try {
                const res = await fetch(apiUrl('sources_list.php'));
                const data = await res.json();
                if (data.ok) this.sources = data.sources || [];
            } catch (err) {
                this.flash('Error loading sources: ' + err.message);
            } finally {
                this.loading = false;
            }
        },

        startAdd() {
            this.adding = true;
            this.editingId = null;
            this.form = {
                id: null,
                name: '',
                url: '',
                source_type: 'rss',
                is_local: false,
                is_active: true,
                poll_frequency_minutes: 60,
            };
        },

        cancelAdd() {
            this.adding = false;
        },

        startEdit(source) {
            this.adding = false;
            this.editingId = source.id;
            this.form = {
                id: source.id,
                name: source.name,
                url: source.url,
                source_type: source.source_type,
                is_local: source.is_local,
                is_active: source.is_active,
                poll_frequency_minutes: source.poll_frequency_minutes,
            };
        },

        cancelEdit() {
            this.editingId = null;
        },

        async saveSource() {
            if (!this.form.name.trim() || !this.form.url.trim()) {
                this.flash('Name and URL are required');
                return;
            }
            try {
                await apiPost('sources_save.php', {
                    id: this.form.id,
                    name: this.form.name.trim(),
                    url: this.form.url.trim(),
                    source_type: this.form.source_type,
                    is_local: this.form.is_local ? 1 : 0,
                    is_active: this.form.is_active ? 1 : 0,
                    poll_frequency_minutes: this.form.poll_frequency_minutes,
                });
                this.adding = false;
                this.editingId = null;
                this.flash(this.form.id ? 'Source updated' : 'Source added');
                this.loadSources();
            } catch (err) {
                this.flash('Error: ' + err.message);
            }
        },

        async deleteSource(source) {
            if (!confirm(`Delete "${source.name}"? Its candidate history will remain but no further polling will happen.`)) return;
            try {
                await apiPost('sources_delete.php', { id: source.id });
                this.flash('Source deleted');
                this.loadSources();
            } catch (err) {
                this.flash('Error: ' + err.message);
            }
        },

        async pollOne(sourceId) {
            this.polling = true;
            this.flash('Polling...');
            try {
                const result = await apiPost('sources_poll.php', { source_id: sourceId });
                this.flash(`Found ${result.items_found} items, ${result.items_new} new`);
                this.loadSources();
            } catch (err) {
                this.flash('Error: ' + err.message);
            } finally {
                this.polling = false;
            }
        },

        async pollAll() {
            this.polling = true;
            this.flash('Polling all sources...');
            try {
                const result = await apiPost('sources_poll.php', { force: true });
                let msg = `Polled ${result.sources_polled} sources, ${result.items_new} new candidates`;
                if (result.errors && result.errors.length) {
                    msg += ` (${result.errors.length} errors)`;
                }
                this.flash(msg);
                this.loadSources();
            } catch (err) {
                this.flash('Error: ' + err.message);
            } finally {
                this.polling = false;
            }
        },

        formatFreq(minutes) {
            if (minutes < 60) return `${minutes} min`;
            if (minutes === 60) return '1 hour';
            if (minutes < 1440) return `${minutes / 60} hours`;
            return `${minutes / 1440} day${minutes === 1440 ? '' : 's'}`;
        },

        formatDate(s) {
            if (!s) return '';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) return s;
            const today = new Date();
            const isToday = d.toDateString() === today.toDateString();
            if (isToday) return `today at ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) +
                   ' at ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        },

        flash(msg) {
            this.statusMessage = msg;
            setTimeout(() => {
                if (this.statusMessage === msg) this.statusMessage = '';
            }, 3000);
        },
    };
}

function jobsAdmin() {
    return {
        recent: [],
        latestByJob: [],
        running: false,
        runningJob: null,

        async loadJobs() {
            try {
                const res = await fetch(apiUrl('jobs_list.php'));
                const data = await res.json();
                if (data.ok) {
                    this.recent = data.recent || [];
                    this.latestByJob = data.latest_by_job || [];
                }
            } catch (err) {
                console.error('Jobs load failed:', err);
            }
        },

        lastRunFor(jobName) {
            const found = this.latestByJob.find(j => j.job_name === jobName);
            if (!found) return null;
            return this.formatDate(found.started_at);
        },

        async runDiscovery() {
            this.running = true;
            this.runningJob = 'discovery';
            try {
                await apiPost('sources_poll.php', { force: false });
                await this.loadJobs();
            } catch (err) {
                alert('Polling failed: ' + err.message);
            } finally {
                this.running = false;
                this.runningJob = null;
            }
        },

        async runInsights() {
            this.running = true;
            this.runningJob = 'insights';
            try {
                await apiPost('insights_generate.php', {});
                await this.loadJobs();
            } catch (err) {
                alert('Insights generation failed: ' + err.message);
            } finally {
                this.running = false;
                this.runningJob = null;
            }
        },

        async runBackup() {
            this.running = true;
            this.runningJob = 'backup';
            try {
                const data = await apiPost('backup_run.php', {});
                await this.loadJobs();
                alert('Backup complete: ' + (data.message || 'done'));
            } catch (err) {
                alert('Backup failed: ' + err.message);
            } finally {
                this.running = false;
                this.runningJob = null;
            }
        },

        formatDate(s) {
            if (!s) return '';
            const d = new Date(s.replace(' ', 'T'));
            if (isNaN(d.getTime())) return s;
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) +
                   ' at ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        },

        duration(start, end) {
            if (!end) return '...';
            const diff = (new Date(end.replace(' ', 'T')) - new Date(start.replace(' ', 'T'))) / 1000;
            if (diff < 1) return '< 1s';
            if (diff < 60) return Math.round(diff) + 's';
            return Math.round(diff / 60) + 'm';
        },
    };
}

window.sourcesAdmin = sourcesAdmin;
window.jobsAdmin = jobsAdmin;
