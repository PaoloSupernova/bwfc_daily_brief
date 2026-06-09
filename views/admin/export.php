<?php
declare(strict_types=1);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<nav class="admin-subnav">
    <a href="<?= $basePath ?>/?admin=sections" class="admin-subnav__link">Sections</a>
    <a href="<?= $basePath ?>/?admin=export"   class="admin-subnav__link is-active">Export data</a>
</nav>

<div class="admin" x-data="exportAdmin()" x-init="init()">

    <header class="admin__header">
        <h1 class="heading-display">Export Data</h1>
        <p class="lede">Download a complete snapshot of all briefs, articles, sections, and settings as a portable ZIP file. Use this to migrate to a new server or keep an offline backup.</p>
    </header>

    <section class="editor__section">

        <div class="export-card" x-show="state === 'idle'">
            <div class="export-card__icon">&#128230;</div>
            <div class="export-card__body">
                <h2 class="export-card__title">What's included</h2>
                <ul class="export-card__list">
                    <li>All briefs and article summaries</li>
                    <li>Related "More:" coverage links</li>
                    <li>Sections and routing rules</li>
                    <li>Outlets, users, and prompt templates</li>
                    <li>Full audit log</li>
                    <li>Banner images</li>
                    <li>Pre-filled <code>.env</code> template</li>
                    <li>Step-by-step <code>IMPORT.md</code> migration guide</li>
                </ul>
                <button type="button" class="btn btn--primary btn--large"
                        @click="runExport()">
                    Generate export package
                </button>
            </div>
        </div>

        <div class="export-loading" x-show="state === 'loading'" x-cloak>
            <div class="export-loading__spinner"></div>
            <p>Building export&hellip; this may take a few seconds.</p>
        </div>

        <div x-show="state === 'error'" x-cloak>
            <div class="notice notice--error">
                <strong>Export failed:</strong> <span x-text="errorMessage"></span>
            </div>
            <button type="button" class="btn btn--secondary" style="margin-top:12px" @click="reset()">Try again</button>
        </div>

        <div x-show="state === 'done'" x-cloak>

            <!-- SQL-only fallback notice -->
            <div class="notice notice--warning" x-show="mode === 'sql'" x-cloak style="margin-bottom:16px;">
                <strong>ZIP extension not enabled.</strong>
                Your export contains the database SQL only — banner images and config template are not included.
                To get the full ZIP export, open <code>C:\xampp\php\php.ini</code>, find <code>;extension=zip</code>,
                remove the <code>;</code>, then restart Apache.
            </div>

            <!-- Download button -->
            <div class="export-download-bar">
                <a :href="downloadUrl" class="btn btn--primary btn--large" download>
                    <span x-show="mode === 'zip'">&#11015; Download ZIP</span>
                    <span x-show="mode === 'sql'" x-cloak>&#11015; Download SQL</span>
                </a>
                <span class="export-download-bar__note">
                    Link is valid for 1 hour &middot; <button type="button" class="btn btn--link" @click="reset()">Generate another</button>
                </span>
            </div>

            <!-- Summary table -->
            <div class="export-summary">
                <h2 class="heading-section">Export summary</h2>
                <table class="export-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Item</th>
                            <th class="export-table__num">Count / Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="export-table__group">
                            <td rowspan="3"><strong>Briefs</strong></td>
                            <td>Sent</td>
                            <td class="export-table__num" x-text="fmt(summary.briefs_sent)"></td>
                        </tr>
                        <tr>
                            <td>Draft</td>
                            <td class="export-table__num" x-text="fmt(summary.briefs_draft)"></td>
                        </tr>
                        <tr class="export-table__subtotal">
                            <td>Total</td>
                            <td class="export-table__num" x-text="fmt((summary.briefs_sent || 0) + (summary.briefs_draft || 0))"></td>
                        </tr>
                        <tr class="export-table__divider"><td colspan="3"></td></tr>

                        <tr class="export-table__group">
                            <td rowspan="2"><strong>Articles</strong></td>
                            <td>Full summaries</td>
                            <td class="export-table__num" x-text="fmt(summary.articles)"></td>
                        </tr>
                        <tr>
                            <td>Related &ldquo;More:&rdquo; links</td>
                            <td class="export-table__num" x-text="fmt(summary.related_links)"></td>
                        </tr>
                        <tr class="export-table__divider"><td colspan="3"></td></tr>

                        <tr class="export-table__group">
                            <td rowspan="4"><strong>Configuration</strong></td>
                            <td>Sections</td>
                            <td class="export-table__num" x-text="fmt(summary.sections)"></td>
                        </tr>
                        <tr>
                            <td>Outlets</td>
                            <td class="export-table__num" x-text="fmt(summary.outlets)"></td>
                        </tr>
                        <tr>
                            <td>Users</td>
                            <td class="export-table__num" x-text="fmt(summary.users)"></td>
                        </tr>
                        <tr>
                            <td>Prompt templates</td>
                            <td class="export-table__num" x-text="fmt(summary.prompt_templates)"></td>
                        </tr>
                        <tr class="export-table__divider"><td colspan="3"></td></tr>

                        <tr>
                            <td><strong>Audit log</strong></td>
                            <td>Entries</td>
                            <td class="export-table__num" x-text="fmt(summary.audit_entries)"></td>
                        </tr>
                        <tr class="export-table__divider"><td colspan="3"></td></tr>

                        <tr class="export-table__group">
                            <td rowspan="2"><strong>Files</strong></td>
                            <td>Banner images</td>
                            <td class="export-table__num" x-text="fmt(summary.banner_images)"></td>
                        </tr>
                        <tr>
                            <td>Banner total size</td>
                            <td class="export-table__num" x-text="fmtBytes(summary.banner_bytes)"></td>
                        </tr>
                        <tr class="export-table__divider"><td colspan="3"></td></tr>

                        <tr class="export-table__total">
                            <td><strong>Export</strong></td>
                            <td>ZIP file size</td>
                            <td class="export-table__num" x-text="fmtBytes(summary.zip_size)"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="export-cli-hint">
                <strong>Prefer the command line?</strong>
                You can also run the export from your terminal:
                <pre class="export-cli-hint__code">php export.php</pre>
                Or with a custom output path:
                <pre class="export-cli-hint__code">php export.php --output /path/to/backup.zip</pre>
            </div>
        </div>

    </section>

    <!-- Sentiment backfill -->
    <section class="editor__section" x-data="backfillSentiment()">
        <h2 class="heading-section">Sentiment backfill</h2>
        <p class="field__hint">
            Articles added before sentiment tagging was enabled have no tag. Run this once to classify all existing articles
            using Claude. May take 1–3 minutes depending on archive size.
        </p>

        <div x-show="bfState === 'idle'">
            <button type="button" class="btn btn--secondary" @click="run()">
                Tag untagged articles
            </button>
        </div>

        <div class="export-loading" x-show="bfState === 'loading'" x-cloak>
            <div class="export-loading__spinner"></div>
            <p>Classifying articles&hellip; please keep this page open.</p>
        </div>

        <div class="notice notice--success" x-show="bfState === 'done'" x-cloak>
            <strong>Done.</strong>
            Tagged <strong x-text="bfResult.processed"></strong> articles
            <template x-if="bfResult.skipped > 0">
                <span> (<span x-text="bfResult.skipped"></span> skipped — no headline/summary)</span>
            </template>.
            Reload the dashboard to see updated sentiment charts.
        </div>

        <div class="notice notice--error" x-show="bfState === 'error'" x-cloak>
            <strong>Failed:</strong> <span x-text="bfError"></span>
        </div>
    </section>
</div>

<script>
function backfillSentiment() {
    return {
        bfState: 'idle',
        bfResult: {},
        bfError: '',

        async run() {
            if (!confirm('This will call Claude once for every untagged article. Continue?')) return;
            this.bfState = 'loading';
            this.bfError = '';
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res  = await fetch(root + '/api/admin_backfill_sentiment.php', { method: 'POST' });
                const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
                if (!res.ok || !data.ok) throw new Error(data.error || 'HTTP ' + res.status);
                this.bfResult = data;
                this.bfState  = 'done';
            } catch (err) {
                this.bfError = err.message;
                this.bfState = 'error';
            }
        },
    };
}
</script>

<script>
function exportAdmin() {
    return {
        state: 'idle',
        errorMessage: '',
        summary: {},
        downloadUrl: '',
        mode: 'zip',

        init() {},

        async runExport() {
            this.state = 'loading';
            this.errorMessage = '';
            try {
                const base = window.BWFC_BASE || '';
                const root = base.replace(/\/public\/?$/, '');
                const res  = await fetch(root + '/api/export_data.php', { method: 'POST' });
                const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || 'HTTP ' + res.status);
                }
                this.summary     = data.summary;
                this.downloadUrl = data.download_url;
                this.mode        = data.mode || 'zip';
                this.state       = 'done';
            } catch (err) {
                this.errorMessage = err.message;
                this.state = 'error';
            }
        },

        reset() {
            this.state       = 'idle';
            this.summary     = {};
            this.downloadUrl = '';
            this.errorMessage = '';
        },

        fmt(n) {
            return (n ?? 0).toLocaleString('en-GB');
        },

        fmtBytes(n) {
            n = n || 0;
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
            return (n / (1024 * 1024)).toFixed(2) + ' MB';
        },
    };
}
</script>
