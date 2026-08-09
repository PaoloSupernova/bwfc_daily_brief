<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRenderer;

// $brief and $articles provided by index.php
$rendered = BriefRenderer::renderHtml($brief, $articles);
$subject = BriefRenderer::formatSubjectLine((string)$brief['brief_date']);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<?php
$fullDoc = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
    . '<body style="margin:0; padding:16px; background:#fff;">' . $rendered . '</body></html>';
?>
<div class="editor" x-data="briefView()">
    <header class="editor__header">
        <div>
            <div class="editor__date"><?= (new DateTime($brief['brief_date']))->format('l jS F Y') ?></div>
            <div class="editor__subject">Subject: <code><?= htmlspecialchars($subject, ENT_QUOTES) ?></code></div>
            <?php if ($brief['sent_at']): ?>
                <div class="editor__meta">Sent <?= htmlspecialchars((string)$brief['sent_at'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </div>
        <div class="editor__header-actions">
            <span class="status-pill status-pill--sent">Sent · Locked</span>
            <span class="editor__count"><?= count($articles) ?> articles</span>
        </div>
    </header>

    <section class="editor__section">
        <h2 class="heading-section">Rendered brief</h2>
        <div class="output-actions">
            <button type="button" class="btn btn--secondary" @click="copyHtml()">
                <span x-show="!copied">Copy HTML for Outlook</span>
                <span x-show="copied">Copied to clipboard</span>
            </button>
            <a href="<?= $basePath ?>/" class="btn btn--link">Back to dashboard</a>
        </div>
    </section>

    <section class="editor__section">
        <div class="preview">
            <div class="preview__body">
                <iframe x-ref="frame" class="brief-iframe" @load="resize()"
                        title="Rendered brief preview"></iframe>
            </div>
        </div>
    </section>
</div>

<script>
function briefView() {
    return {
        copied: false,
        briefHtml: <?= json_encode($rendered, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        fullDoc: <?= json_encode($fullDoc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        init() { this.$refs.frame.srcdoc = this.fullDoc; },
        resize() {
            try {
                const doc = this.$refs.frame.contentWindow.document;
                this.$refs.frame.style.height = (doc.body.scrollHeight + 30) + 'px';
            } catch (e) {}
        },
        copyHtml() {
            navigator.clipboard.writeText(this.briefHtml);
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },
    };
}
</script>
