<?php

namespace App\Support;

use App\Models\GlobalBlacklist;
use Illuminate\Support\Collection;

/**
 * What the global blacklist mirror says about an identity number at the counter (hybrid spec §6.2,
 * decision SEC-4). Matching is by number across document types: the same number under the same type
 * blocks, under another type it is a warning the officer resolves by looking at the document.
 */
final class GlobalBlacklistCheck
{
    /** @return Collection<int, GlobalBlacklist> */
    public static function activeEntries(string $idNumber): Collection
    {
        return GlobalBlacklist::activeForNumber($idNumber)->orderBy('id_type')->get();
    }

    /**
     * @param  Collection<int, GlobalBlacklist>  $entries
     */
    public static function blocking(Collection $entries, string $idType): ?GlobalBlacklist
    {
        return $entries->first(fn (GlobalBlacklist $entry): bool => $entry->id_type === $idType);
    }

    /**
     * @param  Collection<int, GlobalBlacklist>  $entries
     * @return list<array<string, string>>
     */
    public static function warnings(Collection $entries, string $idType): array
    {
        return $entries
            ->reject(fn (GlobalBlacklist $entry): bool => $entry->id_type === $idType)
            ->map(fn (GlobalBlacklist $entry): array => ['code' => 'global_blacklist_other_document', ...self::describe($entry)])
            ->values()
            ->all();
    }

    /**
     * What the counter may show. Only the fixed category travels between yards (SEC-6d).
     *
     * @return array{id_type: string, name: string, reason_category: string, reason_label: string, registered_by_yard: string}
     */
    public static function describe(GlobalBlacklist $entry): array
    {
        return [
            'id_type' => $entry->id_type,
            'name' => $entry->name,
            'reason_category' => $entry->reason_category->value,
            'reason_label' => $entry->reason_category->label(),
            'registered_by_yard' => $entry->registered_by_yard,
        ];
    }
}
