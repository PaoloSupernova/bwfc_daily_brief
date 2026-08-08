<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

use Mpdf\Mpdf;
use RuntimeException;

/**
 * "Media Intelligence" report — a shareable PDF summarising coverage over a
 * window (volume, sentiment, topics, most-covered people, most-active
 * journalists, top outlets). Built for sending upward to the manager, CEO or
 * board. Reuses the sentiment / people / journalist / topic data captured
 * elsewhere in the app.
 */
final class MediaReportExporter
{
    private const LOCAL_OUTLETS = [
        'bwfc.co.uk', 'Bolton News', 'Lancashire Evening Post',
        'Manchester Evening News', 'BWitC', 'Bolton Stadium Hotel',
    ];

    private const NAVY = '#19223D';
    private const BLUE = '#003976';
    private const MUTED = '#6A6A6A';

    public static function generate(int $days = 30): string
    {
        if (!class_exists(Mpdf::class)) {
            throw new RuntimeException('mPDF library not installed. Run "composer require mpdf/mpdf".');
        }

        $days = $days > 0 ? $days : 30;
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime("-{$days} days"));

        $data = self::gather($from, $to);
        $html = self::renderHtml($from, $to, $days, $data);

        $tempDir = sys_get_temp_dir() . '/mpdf_bwfc';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'tempDir' => $tempDir,
        ]);
        $mpdf->SetTitle('BWFC Media Intelligence Report');
        $mpdf->SetAuthor('BWFC Communications');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public static function filename(int $days = 30): string
    {
        return 'BWFC-Media-Report-' . date('Y-m-d') . '.pdf';
    }

    /** @return array<string, mixed> */
    private static function gather(string $from, string $to): array
    {
        $p = ['from' => $from, 'to' => $to];

        $totalArticles = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM brief_articles a JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :from AND :to", $p
        )['n'] ?? 0);

        $totalBriefs = (int)(Database::selectOne(
            "SELECT COUNT(*) AS n FROM briefs
             WHERE deleted_at IS NULL AND status = 'sent' AND brief_date BETWEEN :from AND :to", $p
        )['n'] ?? 0);

        // Sentiment
        $sentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach (Database::select(
            "SELECT a.sentiment, COUNT(*) AS n FROM brief_articles a JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :from AND :to
               AND a.sentiment IS NOT NULL AND a.parent_article_id IS NULL
             GROUP BY a.sentiment", $p
        ) as $r) {
            if (isset($sentiment[$r['sentiment']])) $sentiment[$r['sentiment']] = (int)$r['n'];
        }

        // National pickup (BWFC section)
        $bwfcLocal = 0; $bwfcNational = 0;
        foreach (Database::select(
            "SELECT a.outlet_name FROM brief_articles a
             JOIN briefs b ON b.id = a.brief_id JOIN sections s ON s.id = a.section_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :from AND :to AND s.slug = 'bwfc'", $p
        ) as $r) {
            $isLocal = false;
            foreach (self::LOCAL_OUTLETS as $pat) {
                if (stripos((string)$r['outlet_name'], $pat) !== false) { $isLocal = true; break; }
            }
            $isLocal ? $bwfcLocal++ : $bwfcNational++;
        }
        $bwfcTotal = $bwfcLocal + $bwfcNational;

        // Topics
        $topics = Database::select(
            "SELECT a.topic, COUNT(*) AS n FROM brief_articles a JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :from AND :to
               AND a.topic IS NOT NULL AND a.parent_article_id IS NULL
             GROUP BY a.topic ORDER BY n DESC", $p
        );

        // Top outlets
        $outlets = Database::select(
            "SELECT a.outlet_name AS name, COUNT(*) AS n FROM brief_articles a JOIN briefs b ON b.id = a.brief_id
             WHERE b.deleted_at IS NULL AND b.brief_date BETWEEN :from AND :to
             GROUP BY a.outlet_name ORDER BY n DESC, a.outlet_name LIMIT 8", $p
        );

        return [
            'total_articles' => $totalArticles,
            'total_briefs' => $totalBriefs,
            'sentiment' => $sentiment,
            'bwfc_total' => $bwfcTotal,
            'bwfc_local' => $bwfcLocal,
            'bwfc_national' => $bwfcNational,
            'national_pickup_pct' => $bwfcTotal > 0 ? (int)round(($bwfcNational / $bwfcTotal) * 100) : null,
            'topics' => $topics,
            'outlets' => $outlets,
            'people' => array_slice(PeopleRepository::leaderboard($from, $to), 0, 10),
            'journalists' => array_slice(JournalistRepository::leaderboard($from, $to), 0, 10),
        ];
    }

    private static function renderHtml(string $from, string $to, int $days, array $d): string
    {
        $navy = self::NAVY; $blue = self::BLUE; $muted = self::MUTED;
        $fromLabel = date('j M Y', strtotime($from));
        $toLabel = date('j M Y', strtotime($to));
        $sen = $d['sentiment'];
        $senTotal = $sen['positive'] + $sen['neutral'] + $sen['negative'];
        $pickup = $d['national_pickup_pct'] !== null ? $d['national_pickup_pct'] . '%' : '—';

        $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $pct = static fn($n, $t) => $t > 0 ? round(($n / $t) * 100) : 0;

        $h = "<html><head><style>
            body { font-family: sans-serif; color: #1D1D1B; font-size: 11pt; }
            h1 { color: {$navy}; font-size: 22pt; margin: 0 0 2px; }
            .sub { color: {$muted}; font-size: 10pt; margin-bottom: 16px; }
            h2 { color: {$blue}; font-size: 13pt; border-bottom: 2px solid #E4E4E7; padding-bottom: 4px; margin: 20px 0 8px; }
            table { width: 100%; border-collapse: collapse; }
            td, th { text-align: left; padding: 4px 6px; font-size: 10pt; border-bottom: 1px solid #EEE; }
            th { color: {$muted}; font-size: 8.5pt; text-transform: uppercase; }
            .num { text-align: right; }
            .cards td { border: none; padding: 6px 8px; }
            .card { background: #F5F7FA; border-radius: 6px; }
            .card .v { color: {$navy}; font-size: 20pt; font-weight: bold; }
            .card .l { color: {$muted}; font-size: 9pt; }
            .pos { color: #2E9E5B; } .neu { color: #999; } .neg { color: #C0392B; }
        </style></head><body>";

        $h .= "<h1>Media Intelligence Report</h1>";
        $h .= "<div class='sub'>Bolton Wanderers Communications &middot; {$fromLabel} – {$toLabel} ({$days} days) &middot; generated " . date('j M Y') . "</div>";

        // Headline cards
        $h .= "<table class='cards'><tr>
            <td class='card'><div class='v'>{$d['total_articles']}</div><div class='l'>Articles covered</div></td>
            <td class='card'><div class='v'>{$d['total_briefs']}</div><div class='l'>Briefs sent</div></td>
            <td class='card'><div class='v'>{$pickup}</div><div class='l'>National pickup (BWFC)</div></td>
            <td class='card'><div class='v'>{$d['bwfc_total']}</div><div class='l'>BWFC-section articles</div></td>
        </tr></table>";

        // Sentiment
        $h .= "<h2>Sentiment</h2>";
        if ($senTotal > 0) {
            $h .= "<table><tr>
                <td class='pos'>Positive: {$sen['positive']} (" . $pct($sen['positive'], $senTotal) . "%)</td>
                <td class='neu'>Neutral: {$sen['neutral']} (" . $pct($sen['neutral'], $senTotal) . "%)</td>
                <td class='neg'>Negative: {$sen['negative']} (" . $pct($sen['negative'], $senTotal) . "%)</td>
            </tr></table>";
        } else {
            $h .= "<p class='sub'>No sentiment-tagged articles in this period.</p>";
        }

        // Topics
        $h .= "<h2>Coverage by topic</h2><table>";
        if (count($d['topics']) > 0) {
            foreach ($d['topics'] as $t) {
                $h .= "<tr><td>" . $esc(Topics::label((string)$t['topic'])) . "</td><td class='num'>" . (int)$t['n'] . "</td></tr>";
            }
        } else {
            $h .= "<tr><td class='sub'>No topic data.</td></tr>";
        }
        $h .= "</table>";

        // Most-covered people
        $h .= "<h2>Most-covered people</h2><table><tr><th>Person</th><th>Role</th><th class='num'>Articles</th><th class='num'>+ / = / −</th></tr>";
        if (count($d['people']) > 0) {
            foreach ($d['people'] as $p) {
                $role = ['player'=>'Player','staff'=>'Staff','exec'=>'Exec','other'=>'Other'][$p['role']] ?? $p['role'];
                $h .= "<tr><td>" . $esc($p['name']) . "</td><td>" . $esc($role) . "</td>"
                    . "<td class='num'>" . (int)$p['mention_count'] . "</td>"
                    . "<td class='num'>" . (int)$p['positive'] . " / " . (int)$p['neutral'] . " / <span class='neg'>" . (int)$p['negative'] . "</span></td></tr>";
            }
        } else {
            $h .= "<tr><td class='sub' colspan='4'>No attributed people in this period.</td></tr>";
        }
        $h .= "</table>";

        // Most-active journalists
        $h .= "<h2>Most-active journalists</h2><table><tr><th>Journalist</th><th>Outlet</th><th class='num'>Articles</th></tr>";
        if (count($d['journalists']) > 0) {
            foreach ($d['journalists'] as $j) {
                $h .= "<tr><td>" . $esc($j['name']) . "</td><td>" . $esc($j['last_outlet'] ?? '') . "</td><td class='num'>" . (int)$j['article_count'] . "</td></tr>";
            }
        } else {
            $h .= "<tr><td class='sub' colspan='3'>No attributed journalists in this period.</td></tr>";
        }
        $h .= "</table>";

        // Top outlets
        $h .= "<h2>Top outlets</h2><table>";
        foreach ($d['outlets'] as $o) {
            $h .= "<tr><td>" . $esc($o['name']) . "</td><td class='num'>" . (int)$o['n'] . "</td></tr>";
        }
        $h .= "</table>";

        $h .= "</body></html>";
        return $h;
    }
}
