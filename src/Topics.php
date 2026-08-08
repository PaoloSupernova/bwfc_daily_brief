<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Controlled topic taxonomy for articles — "what the story is about", distinct
 * from section (routing) and sentiment (tone). A fixed list keeps the data
 * clean and aggregatable across the dashboard, weekly insights, and reports.
 */
final class Topics
{
    /** slug => human label. Order is the display order. */
    public const LIST = [
        'transfer'   => 'Transfers & signings',
        'match'      => 'Match preview, report & reaction',
        'injury'     => 'Injuries & fitness',
        'manager'    => 'Manager & coaching',
        'ownership'  => 'Ownership, finance & boardroom',
        'academy'    => 'Academy & youth',
        'community'  => 'Fans & community',
        'discipline' => 'Discipline, legal & controversy',
        'other'      => 'Other',
    ];

    /** @return array<int, array{slug:string, label:string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::LIST as $slug => $label) {
            $out[] = ['slug' => $slug, 'label' => $label];
        }
        return $out;
    }

    public static function isValid(string $slug): bool
    {
        return isset(self::LIST[$slug]);
    }

    public static function label(string $slug): string
    {
        return self::LIST[$slug] ?? ucfirst($slug);
    }

    /** Coerce arbitrary text to a valid slug, defaulting to 'other'. */
    public static function normalise(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z_]/', '', $slug) ?? $slug;
        return self::isValid($slug) ? $slug : 'other';
    }

    /** The rule block injected into the classification prompt. */
    public static function promptRules(): string
    {
        $lines = [
            '- transfer: signings, loans, contracts, transfer speculation, departures',
            '- match: match previews, reports, player ratings, post-match reaction',
            '- injury: injuries, fitness, returns from injury, medical updates',
            '- manager: the head coach/manager, coaching staff, tactics, management change',
            '- ownership: owners, board, finances, takeover, accounts, stadium/business',
            '- academy: academy, youth teams, under-21s, young prospects',
            '- community: fans, supporters, community schemes, charity, fan culture',
            '- discipline: red cards, bans, legal matters, controversy, misconduct',
            '- other: anything that does not fit the above',
        ];
        return implode("\n", $lines);
    }
}
