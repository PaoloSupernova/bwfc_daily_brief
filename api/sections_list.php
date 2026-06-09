<?php
/**
 * GET /api/sections_list.php
 * Returns: { sections: [{id, slug, name, routing_description, display_order, article_count}] }
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use BWFC\DailyBrief\BriefRepository;

$sections = BriefRepository::sections(true);

foreach ($sections as &$s) {
    $s['article_count'] = BriefRepository::countArticlesInSection((int)$s['id']);
    $s['id'] = (int)$s['id'];
    $s['display_order'] = (int)$s['display_order'];
    $s['is_active'] = (int)$s['is_active'];
}

api_success(['sections' => $sections]);
