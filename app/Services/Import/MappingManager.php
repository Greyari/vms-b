<?php

namespace App\Services\Import;

use App\Enums\ImportSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MappingManager
{
    /**
     * In-memory cache: [source][entity][source_key] => target_id
     *
     * @var array<string, array<string, array<string, string>>>
     */
    protected array $cache = [];

    protected function resolveSource(ImportSource|string $source): string
    {
        return $source instanceof ImportSource ? $source->value : $source;
    }

    public function getTargetId(ImportSource|string $source, string $entity, string $sourceKey): ?string
    {
        $src = $this->resolveSource($source);
        $key = (string) $sourceKey;

        if (isset($this->cache[$src][$entity][$key])) {
            return $this->cache[$src][$entity][$key];
        }

        $row = DB::table('import_mappings')
            ->where('source', $src)
            ->where('entity', $entity)
            ->where('source_key', $key)
            ->value('target_id');

        if ($row) {
            $this->cache[$src][$entity][$key] = (string) $row;

            return (string) $row;
        }

        return null;
    }

    public function hasMapping(ImportSource|string $source, string $entity, string $sourceKey): bool
    {
        return $this->getTargetId($source, $entity, $sourceKey) !== null;
    }

    public function put(ImportSource|string $source, string $entity, string $sourceKey, string $targetId): void
    {
        $this->putBatch($source, $entity, [(string) $sourceKey => (string) $targetId]);
    }

    /**
     * Batch insert or update mappings.
     *
     * @param  array<string, string>  $keyValuePairs  [source_key => target_id]
     */
    public function putBatch(ImportSource|string $source, string $entity, array $keyValuePairs): void
    {
        if (empty($keyValuePairs)) {
            return;
        }

        $src = $this->resolveSource($source);
        $now = now();
        $rows = [];

        foreach ($keyValuePairs as $sourceKey => $targetId) {
            $keyStr = (string) $sourceKey;
            $targetIdStr = (string) $targetId;

            $this->cache[$src][$entity][$keyStr] = $targetIdStr;

            $rows[] = [
                'id' => (string) Str::uuid(),
                'source' => $src,
                'entity' => $entity,
                'source_key' => $keyStr,
                'target_id' => $targetIdStr,
                'imported_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_mappings')->upsert(
                $chunk,
                ['source', 'entity', 'source_key'],
                ['target_id', 'imported_at']
            );
        }
    }

    /**
     * Preload mappings for a specific set of keys into memory.
     *
     * @param  array<int, string>  $sourceKeys
     * @return array<string, string>
     */
    public function preloadKeys(ImportSource|string $source, string $entity, array $sourceKeys): array
    {
        if (empty($sourceKeys)) {
            return [];
        }

        $src = $this->resolveSource($source);
        $missing = [];
        $result = [];

        foreach ($sourceKeys as $key) {
            $keyStr = (string) $key;
            if (isset($this->cache[$src][$entity][$keyStr])) {
                $result[$keyStr] = $this->cache[$src][$entity][$keyStr];
            } else {
                $missing[] = $keyStr;
            }
        }

        if (! empty($missing)) {
            foreach (array_chunk($missing, 500) as $chunk) {
                $records = DB::table('import_mappings')
                    ->where('source', $src)
                    ->where('entity', $entity)
                    ->whereIn('source_key', $chunk)
                    ->pluck('target_id', 'source_key');

                foreach ($records as $k => $v) {
                    $kStr = (string) $k;
                    $vStr = (string) $v;
                    $this->cache[$src][$entity][$kStr] = $vStr;
                    $result[$kStr] = $vStr;
                }
            }
        }

        return $result;
    }

    /**
     * Load the entire entity mapping into memory.
     *
     * @return array<string, string>
     */
    public function loadEntityMap(ImportSource|string $source, string $entity): array
    {
        $src = $this->resolveSource($source);

        $records = DB::table('import_mappings')
            ->where('source', $src)
            ->where('entity', $entity)
            ->pluck('target_id', 'source_key');

        $map = [];
        foreach ($records as $k => $v) {
            $kStr = (string) $k;
            $vStr = (string) $v;
            $this->cache[$src][$entity][$kStr] = $vStr;
            $map[$kStr] = $vStr;
        }

        return $map;
    }
}
