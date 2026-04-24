<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

$recent = BriefRepository::recentBriefs(30);
?>
<div class="archive">
    <h1 class="heading-display">Archive</h1>
    <p class="lede">Full search, filters, and combined PDF export land in Phase 2. For now, here are the 30 most recent briefs.</p>

    <?php if (count($recent) === 0): ?>
        <p class="empty-state">No briefs yet.</p>
    <?php else: ?>
        <table class="archive-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Articles</th>
                    <th>Status</th>
                    <th>Sent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $brief):
                    $date = new DateTime($brief['brief_date']);
                ?>
                    <tr>
                        <td><?= $date->format('l jS F Y') ?></td>
                        <td><?= (int)$brief['article_count'] ?></td>
                        <td>
                            <?php if ($brief['status'] === 'sent'): ?>
                                <span class="status-pill status-pill--sent">Sent</span>
                            <?php else: ?>
                                <span class="status-pill status-pill--draft">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $brief['sent_at'] ? htmlspecialchars((string)$brief['sent_at'], ENT_QUOTES) : '—' ?></td>
                        <td><a href="?brief=<?= (int)$brief['id'] ?>" class="link-btn">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
