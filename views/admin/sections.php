<?php
declare(strict_types=1);

use BWFC\DailyBrief\BriefRepository;

$sections = BriefRepository::sections(true);
foreach ($sections as &$s) {
    $s['article_count'] = BriefRepository::countArticlesInSection((int)$s['id']);
}

$jsState = [
    'sections' => array_values(array_map(fn($s) => [
        'id' => (int)$s['id'],
        'slug' => (string)$s['slug'],
        'name' => (string)$s['name'],
        'routing_description' => (string)($s['routing_description'] ?? ''),
        'display_order' => (int)$s['display_order'],
        'article_count' => (int)$s['article_count'],
    ], $sections)),
];
?>
<div class="admin" x-data="sectionsAdmin(<?= htmlspecialchars(json_encode($jsState), ENT_QUOTES) ?>)">
    <header class="admin__header">
        <h1 class="heading-display">Sections</h1>
        <p class="lede">Manage the categories Claude uses to classify articles. Drag to reorder. Routing descriptions tell Claude what belongs in each section.</p>
    </header>

    <section class="editor__section">
        <div class="admin__toolbar">
            <button type="button" class="btn btn--primary" @click="startAdd()" x-show="!adding">
                + Add new section
            </button>
            <span class="admin__status" x-show="statusMessage" x-text="statusMessage" x-cloak></span>
        </div>

        <!-- Add new panel -->
        <div class="section-editor" x-show="adding" x-cloak>
            <h3 class="heading-section">Add new section</h3>
            <div class="field">
                <label class="field__label">Section name</label>
                <input type="text" class="input" x-model="newSection.name" placeholder="e.g. Match Day Coverage">
            </div>
            <div class="field">
                <label class="field__label">Routing description</label>
                <p class="field__hint">Tell Claude what kind of articles belong here. Be specific: mention outlets, topics, subject matter.</p>
                <textarea class="textarea" rows="4" x-model="newSection.routing_description" placeholder="e.g. Match previews, match reports, post-match reactions, tactical analysis from any outlet."></textarea>
            </div>
            <div class="admin__actions">
                <button type="button" class="btn btn--secondary" @click="cancelAdd()">Cancel</button>
                <button type="button" class="btn btn--primary" @click="saveNew()" :disabled="!newSection.name">Save section</button>
            </div>
        </div>

        <!-- Sections list (reorderable) -->
        <div id="sections-list" class="sections-list" x-show="!adding">
            <template x-for="(section, idx) in sections" :key="section.id">
                <div class="section-row" :data-id="section.id">
                    <div class="section-row__drag" title="Drag to reorder">&#8942;&#8942;</div>

                    <div class="section-row__body" x-show="editingId !== section.id">
                        <div class="section-row__name" x-text="section.name"></div>
                        <div class="section-row__description" x-text="section.routing_description || 'No routing description set'"></div>
                        <div class="section-row__meta">
                            <span x-text="section.article_count + ' article' + (section.article_count === 1 ? '' : 's') + ' across past briefs'"></span>
                            <span class="section-row__slug" x-text="'slug: ' + section.slug"></span>
                        </div>
                    </div>

                    <div class="section-row__editor" x-show="editingId === section.id" x-cloak>
                        <div class="field">
                            <label class="field__label">Name</label>
                            <input type="text" class="input" x-model="editBuffer.name">
                        </div>
                        <div class="field">
                            <label class="field__label">Routing description</label>
                            <textarea class="textarea" rows="4" x-model="editBuffer.routing_description"></textarea>
                        </div>
                        <div class="admin__actions">
                            <button type="button" class="btn btn--secondary btn--small" @click="cancelEdit()">Cancel</button>
                            <button type="button" class="btn btn--primary btn--small" @click="saveEdit(section)">Save changes</button>
                        </div>
                    </div>

                    <div class="section-row__actions" x-show="editingId !== section.id">
                        <button type="button" class="link-btn" @click="startEdit(section)">Edit</button>
                        <button type="button" class="link-btn link-btn--danger" @click="deleteSection(section)">Delete</button>
                    </div>
                </div>
            </template>
        </div>
    </section>
</div>
