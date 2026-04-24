<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

$recent = BriefRepository::recentBriefs(15);
$today = date('Y-m-d');
$hasDraftToday = false;
foreach ($recent as $r) {
    if ($r['brief_date'] === $today && $r['status'] === 'draft') {
        $hasDraftToday = true;
        break;
    }
}
?>
<div class="dashboard">
    <section class="dashboard__hero">
        <div class="dashboard__hero-text">
            <h1 class="heading-display">Daily Brief</h1>
            <p class="lede">Paste article links, review the summaries, distribute the brief. The house style is applied automatically.</p>
        </div>
        <div class="dashboard__hero-action">
            <a href="?brief=new" class="btn btn--primary btn--large">
                <?= $hasDraftToday ? 'Continue today\'s brief' : 'Start today\'s brief' ?>
            </a>
        </div>
    </section>

    <section class="dashboard__recent">
        <h2 class="heading-section">Recent briefs</h2>
        <?php if (count($recent) === 0): ?>
            <p class="empty-state">No briefs yet. Click "Start today's brief" above to begin.</p>
        <?php else: ?>
            <div class="brief-list">
                <?php foreach ($recent as $brief):
                    $date = new DateTime($brief['brief_date']);
                    $statusClass = $brief['status'] === 'sent' ? 'is-sent' : 'is-draft';
                ?>
                    <a href="?brief=<?= (int)$brief['id'] ?>" class="brief-list__item <?= $statusClass ?>">
                        <div class="brief-list__date">
                            <div class="brief-list__day"><?= $date->format('d') ?></div>
                            <div class="brief-list__month"><?= strtoupper($date->format('M')) ?></div>
                        </div>
                        <div class="brief-list__body">
                            <div class="brief-list__title"><?= $date->format('l jS F Y') ?></div>
                            <div class="brief-list__meta">
                                <span class="brief-list__count"><?= (int)$brief['article_count'] ?> article<?= (int)$brief['article_count'] === 1 ? '' : 's' ?></span>
                                <?php if ($brief['status'] === 'sent'): ?>
                                    <span class="status-pill status-pill--sent">Sent</span>
                                <?php else: ?>
                                    <span class="status-pill status-pill--draft">Draft</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
