<?php
declare(strict_types=1);

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<div class="ask" x-data="askScreen()">

    <header class="queue__header">
        <div>
            <h1 class="heading-display">Ask the Archive</h1>
            <p class="lede">Ask questions about your logged coverage &mdash; recall, trends, people and journalists. Answers are grounded in your briefs and cite their sources.</p>
        </div>
    </header>

    <div class="ask-suggestions" x-show="messages.length === 0" x-cloak>
        <p class="field__hint">Try one of these, or ask your own:</p>
        <div class="ask-chips">
            <template x-for="q in examples" :key="q">
                <button type="button" class="chip" @click="ask(q)" x-text="q"></button>
            </template>
        </div>
    </div>

    <div class="ask-thread" x-ref="thread">
        <template x-for="(m, i) in messages" :key="i">
            <div class="ask-msg" :class="'ask-msg--' + m.role">
                <div class="ask-msg__bubble">
                    <div x-html="m.html"></div>
                    <template x-if="m.role === 'assistant' && m.sources && m.sources.length">
                        <div class="ask-sources">
                            <div class="ask-sources__label">Sources</div>
                            <template x-for="s in m.sources" :key="s.brief_id + s.headline">
                                <div class="ask-source">
                                    <span class="ask-source__date" x-text="formatDate(s.brief_date)"></span>
                                    <span class="ask-source__outlet" x-text="s.outlet"></span>
                                    <a class="ask-source__headline" :href="s.url" target="_blank" rel="noopener" x-text="s.headline"></a>
                                    <a class="ask-source__brief" :href="briefUrl(s.brief_id)">in brief &rarr;</a>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>
        <div class="ask-msg ask-msg--assistant" x-show="loading" x-cloak>
            <div class="ask-msg__bubble ask-msg__bubble--thinking">Searching the archive&hellip;</div>
        </div>
    </div>

    <form class="ask-form" @submit.prevent="ask(draft)">
        <input type="text" class="input" x-model="draft" :disabled="loading"
               placeholder="Ask about your coverage..." x-ref="input">
        <button type="submit" class="btn btn--primary" :disabled="loading || !draft.trim()">Ask</button>
        <button type="button" class="btn btn--link" x-show="messages.length > 0" @click="reset()">Clear</button>
    </form>
</div>

<script>
function askScreen() {
    return {
        draft: '',
        messages: [],   // {role, content, html, sources?}
        loading: false,
        examples: [
            'Summarise our coverage of the ownership story.',
            'Which journalist has been most negative about the club?',
            'How has sentiment changed over the last month?',
            'Who has been in the news most this week?',
        ],

        root() { return (window.BWFC_BASE || '').replace(/\/public\/?$/, ''); },
        briefUrl(id) { return (window.BWFC_BASE || '') + '/?brief=' + id; },

        async ask(q) {
            q = (q || '').trim();
            if (!q || this.loading) return;
            this.draft = '';
            this.messages.push({ role: 'user', content: q, html: this.escape(q) });
            this.scroll();
            this.loading = true;

            // Build history (exclude the message we just pushed; send prior turns).
            const history = this.messages.slice(0, -1).map(m => ({ role: m.role, content: m.content }));

            try {
                const res = await fetch(this.root() + '/api/ask.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question: q, history }),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Failed');
                this.messages.push({ role: 'assistant', content: data.answer, html: this.format(data.answer), sources: data.sources || [] });
            } catch (e) {
                this.messages.push({ role: 'assistant', content: '', html: '<em>Sorry — ' + this.escape(e.message) + '</em>', sources: [] });
            } finally {
                this.loading = false;
                this.scroll();
            }
        },

        reset() { this.messages = []; this.draft = ''; },

        escape(s) {
            const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
        },
        format(s) {
            // Light formatting: escape, bold **x**, paragraphs, line breaks.
            let h = this.escape(s);
            h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            h = h.split(/\n\n+/).map(p => '<p>' + p.replace(/\n/g, '<br>') + '</p>').join('');
            return h;
        },
        formatDate(d) {
            if (!d) return '';
            const dt = new Date(d + 'T00:00:00');
            return isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
        },
        scroll() {
            this.$nextTick(() => { const t = this.$refs.thread; if (t) t.scrollTop = t.scrollHeight; });
        },
    };
}
</script>
