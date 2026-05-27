/* ============================================================
   BWFC Daily Brief - Dashboard
   Alpine component using Chart.js for graphs and SVG for word cloud
   ============================================================ */

function dashboardScreen() {
    return {
        window: 30,
        loading: false,

        headlineStats: {},
        sectionShare: [],
        topOutlets: [],
        localVsNational: { local: 0, national: 0 },
        sentimentData: { positive: 0, neutral: 0, negative: 0 },
        wordCloud: [],
        recentBriefs: [],

        // Sentiment drill-down panel
        drillOpen: false,
        drillSentiment: '',
        drillLoading: false,
        drillArticles: [],

        // Chart instances (kept so we can destroy/recreate on window change)
        _charts: {},

        // ============================================================
        // Lifecycle
        // ============================================================

        async loadAnalytics() {
            this.loading = true;
            try {
                const data = await apiPost('dashboard_analytics.php', { window: this.window });
                this.headlineStats = data.headline_stats || {};
                this.sectionShare = data.section_share || [];
                this.topOutlets = data.top_outlets || [];
                this.localVsNational = data.local_vs_national || { local: 0, national: 0 };
                this.sentimentData = data.sentiment || { positive: 0, neutral: 0, negative: 0 };
                this.wordCloud = data.word_cloud || [];
                this.recentBriefs = data.recent_briefs || [];

                // Render after DOM updates so canvases exist
                this.$nextTick(() => {
                    this.renderCharts();
                    this.renderWordCloud();
                });
            } catch (err) {
                console.error('Dashboard load failed:', err);
            } finally {
                this.loading = false;
            }
        },

        setWindow(days) {
            if (this.window === days) return;
            this.window = days;
            this.loadAnalytics();
        },

        sectionShareEmpty() {
            return this.sectionShare.length === 0;
        },

        localNationalEmpty() {
            return this.localVsNational.local === 0 && this.localVsNational.national === 0;
        },

        // ============================================================
        // Chart rendering
        // ============================================================

        renderCharts() {
            // Wait until Chart.js loads (via CDN, async)
            if (typeof Chart === 'undefined') {
                setTimeout(() => this.renderCharts(), 100);
                return;
            }

            this.renderSectionShare();
            this.renderTopOutlets();
            this.renderLocalVsNational();
        },

        // BWFC palette + tonal variations for chart fills
        chartColors() {
            return [
                '#003976', // BWFC blue
                '#EF3E33', // BWFC red
                '#19223D', // BWFC navy
                '#902E29', // BWFC dark red
                '#5687C7', // tonal blue
                '#F08379', // tonal red
                '#3D4A6E', // tonal navy
                '#C66B65', // tonal dark red
                '#8FAFD6', // pale blue
                '#F5B5AE', // pale red
            ];
        },

        renderSectionShare() {
            const ctx = document.getElementById('chart-section-share');
            if (!ctx) return;
            if (this._charts.sectionShare) this._charts.sectionShare.destroy();

            if (this.sectionShare.length === 0) return;

            const colors = this.chartColors();
            this._charts.sectionShare = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: this.sectionShare.map(s => s.label),
                    datasets: [{
                        data: this.sectionShare.map(s => s.value),
                        backgroundColor: this.sectionShare.map((_, i) => colors[i % colors.length]),
                        borderColor: '#FFFFFF',
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                font: { family: "'Satoshi', 'Inter', sans-serif", size: 12 },
                                color: '#1D1D1B',
                                boxWidth: 12,
                                padding: 10,
                            },
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                    return `${ctx.label}: ${ctx.parsed} (${pct}%)`;
                                },
                            },
                        },
                    },
                },
            });
        },

        renderTopOutlets() {
            const ctx = document.getElementById('chart-top-outlets');
            if (!ctx) return;
            if (this._charts.topOutlets) this._charts.topOutlets.destroy();

            if (this.topOutlets.length === 0) return;

            this._charts.topOutlets = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: this.topOutlets.map(o => o.label),
                    datasets: [{
                        label: 'Articles',
                        data: this.topOutlets.map(o => o.value),
                        backgroundColor: '#003976',
                        borderColor: '#19223D',
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.parsed.x} article${ctx.parsed.x === 1 ? '' : 's'}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: { family: "'Satoshi', sans-serif", size: 11 },
                                color: '#6A6A6A',
                            },
                            grid: { color: '#EDEEF2' },
                        },
                        y: {
                            ticks: {
                                font: { family: "'Satoshi', sans-serif", size: 12 },
                                color: '#1D1D1B',
                            },
                            grid: { display: false },
                        },
                    },
                },
            });
        },

        renderLocalVsNational() {
            const ctx = document.getElementById('chart-local-national');
            if (!ctx) return;
            if (this._charts.localNational) this._charts.localNational.destroy();

            const total = this.localVsNational.local + this.localVsNational.national;
            if (total === 0) return;

            this._charts.localNational = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Local', 'National'],
                    datasets: [{
                        label: 'Articles',
                        data: [this.localVsNational.local, this.localVsNational.national],
                        backgroundColor: ['#EF3E33', '#003976'],
                        borderRadius: 6,
                        borderSkipped: false,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const pct = total > 0 ? Math.round((ctx.parsed.y / total) * 100) : 0;
                                    return `${ctx.parsed.y} article${ctx.parsed.y === 1 ? '' : 's'} (${pct}%)`;
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            ticks: {
                                font: { family: "'Satoshi', sans-serif", size: 13, weight: 600 },
                                color: '#1D1D1B',
                            },
                            grid: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: { family: "'Satoshi', sans-serif", size: 11 },
                                color: '#6A6A6A',
                            },
                            grid: { color: '#EDEEF2' },
                        },
                    },
                },
            });
        },

        // ============================================================
        // Word cloud (SVG-based, no external library)
        // Words placed in spiral pattern around centre, sized by frequency.
        // ============================================================

        renderWordCloud() {
            const container = this.$refs.cloud;
            if (!container) return;
            container.innerHTML = '';
            if (this.wordCloud.length === 0) return;

            const width = container.clientWidth || 600;
            const height = 280;
            const cx = width / 2;
            const cy = height / 2;

            // Scale font size by frequency (linear between min and max occurrences)
            const max = this.wordCloud[0].value;
            const min = this.wordCloud[this.wordCloud.length - 1].value;
            const fontMin = 12;
            const fontMax = 42;

            const scale = (v) => {
                if (max === min) return (fontMin + fontMax) / 2;
                return fontMin + ((v - min) / (max - min)) * (fontMax - fontMin);
            };

            // BWFC palette for the cloud
            const colors = ['#003976', '#19223D', '#EF3E33', '#902E29', '#3D4A6E', '#5687C7'];

            // SVG container
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.setAttribute('width', '100%');
            svg.setAttribute('height', height);
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

            // Naive non-overlapping spiral layout
            const placed = [];
            const sortedWords = [...this.wordCloud].sort((a, b) => b.value - a.value);

            sortedWords.forEach((word, idx) => {
                const fontSize = scale(word.value);
                const approxWidth = word.text.length * fontSize * 0.55;
                const approxHeight = fontSize * 1.1;

                // Spiral search outwards from centre until we find a non-overlapping spot
                let placedHere = false;
                let radius = 0;
                let angle = idx * 0.9; // staggered start angle per word

                for (let attempt = 0; attempt < 200; attempt++) {
                    const x = cx + radius * Math.cos(angle) - approxWidth / 2;
                    const y = cy + radius * Math.sin(angle);

                    // Bounding box for this word
                    const box = {
                        x1: x,
                        y1: y - approxHeight / 2,
                        x2: x + approxWidth,
                        y2: y + approxHeight / 2,
                    };

                    // Check if it fits within bounds
                    const inBounds = box.x1 >= 4 && box.x2 <= width - 4 &&
                                     box.y1 >= 4 && box.y2 <= height - 4;

                    if (inBounds) {
                        // Check overlap with existing placed words
                        const overlaps = placed.some(p =>
                            !(box.x2 < p.x1 || box.x1 > p.x2 || box.y2 < p.y1 || box.y1 > p.y2));

                        if (!overlaps) {
                            placed.push(box);
                            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                            text.setAttribute('x', String(x));
                            text.setAttribute('y', String(y));
                            text.setAttribute('font-size', String(fontSize));
                            text.setAttribute('font-family', "'Nippo', 'Archivo Narrow', sans-serif");
                            text.setAttribute('font-weight', word.value > (min + max) / 2 ? '700' : '500');
                            text.setAttribute('fill', colors[idx % colors.length]);
                            text.setAttribute('dominant-baseline', 'middle');
                            text.textContent = word.text;
                            // Tooltip via title
                            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                            title.textContent = `${word.text}: ${word.value} mention${word.value === 1 ? '' : 's'}`;
                            text.appendChild(title);
                            svg.appendChild(text);
                            placedHere = true;
                            break;
                        }
                    }

                    // Spiral outwards
                    angle += 0.35;
                    radius += 1.2;
                }
                // If we couldn't place after 200 tries, drop the word silently
            });

            container.appendChild(svg);
        },

        // ============================================================
        // Display helpers
        // ============================================================

        formatDateShort(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            if (isNaN(d.getTime())) return dateStr;
            const day = d.getDate();
            const month = d.toLocaleDateString('en-GB', { month: 'short' });
            const ord = (n) => {
                if (n >= 11 && n <= 13) return 'th';
                const m = n % 10;
                return m === 1 ? 'st' : m === 2 ? 'nd' : m === 3 ? 'rd' : 'th';
            };
            return `${day}${ord(day)} ${month}`;
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

        async openSentimentDrill(sentiment) {
            this.drillSentiment = sentiment;
            this.drillOpen = true;
            this.drillLoading = true;
            this.drillArticles = [];
            try {
                const data = await apiPost('sentiment_articles.php', { sentiment, window: this.window });
                this.drillArticles = data.articles || [];
            } catch (err) {
                console.error('Sentiment drill failed:', err);
            } finally {
                this.drillLoading = false;
            }
        },

        closeDrill() {
            this.drillOpen = false;
            this.drillSentiment = '';
            this.drillArticles = [];
        },

        async updateSentiment(articleId, newSentiment) {
            const article = this.drillArticles.find(a => a.id === articleId);
            if (!article || article.sentiment === newSentiment) return;

            const oldSentiment = article.sentiment;
            article.sentiment = newSentiment; // optimistic update

            try {
                await apiPost('update_sentiment.php', { article_id: articleId, sentiment: newSentiment });
                // Remove from drill list (no longer belongs in this sentiment category)
                this.drillArticles = this.drillArticles.filter(a => a.id !== articleId);
                // Adjust bar chart counts
                if (this.sentimentData[oldSentiment] !== undefined) this.sentimentData[oldSentiment]--;
                if (this.sentimentData[newSentiment] !== undefined) this.sentimentData[newSentiment]++;
            } catch (err) {
                article.sentiment = oldSentiment; // roll back on failure
                console.error('Sentiment update failed:', err);
            }
        },

        drillTitle() {
            const labels = { positive: 'Positive', neutral: 'Neutral', negative: 'Negative' };
            return labels[this.drillSentiment] || '';
        },

        sentimentTotal() {
            return this.sentimentData.positive + this.sentimentData.neutral + this.sentimentData.negative;
        },

        sentimentPct(n) {
            const total = this.sentimentTotal();
            if (total === 0) return 0;
            return Math.round((n / total) * 100);
        },
    };
}

window.dashboardScreen = dashboardScreen;
