<?php

namespace App\Services\Import;

use App\Enums\ImportSource;
use Illuminate\Support\Facades\File;

class ImportReport
{
    /**
     * @var array<string, array{read: int, inserted: int, updated: int, skipped: array{total: int, reasons: array<string, int>}}>
     */
    protected array $entities = [];

    /**
     * @var array<int, array{entity: string, key: string, details: mixed, at: string}>
     */
    protected array $conflicts = [];

    protected function initEntity(string $entity): void
    {
        if (! isset($this->entities[$entity])) {
            $this->entities[$entity] = [
                'read' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => [
                    'total' => 0,
                    'reasons' => [],
                ],
            ];
        }
    }

    public function incrementRead(string $entity, int $count = 1): void
    {
        $this->initEntity($entity);
        $this->entities[$entity]['read'] += $count;
    }

    public function incrementInserted(string $entity, int $count = 1): void
    {
        $this->initEntity($entity);
        $this->entities[$entity]['inserted'] += $count;
    }

    public function incrementUpdated(string $entity, int $count = 1): void
    {
        $this->initEntity($entity);
        $this->entities[$entity]['updated'] += $count;
    }

    public function incrementSkipped(string $entity, string $reason, int $count = 1): void
    {
        $this->initEntity($entity);
        $this->entities[$entity]['skipped']['total'] += $count;

        if (! isset($this->entities[$entity]['skipped']['reasons'][$reason])) {
            $this->entities[$entity]['skipped']['reasons'][$reason] = 0;
        }

        $this->entities[$entity]['skipped']['reasons'][$reason] += $count;
    }

    public function addConflict(string $entity, string $key, array|string $details): void
    {
        $this->conflicts[] = [
            'entity' => $entity,
            'key' => $key,
            'details' => $details,
            'at' => now()->toIso8601String(),
        ];
    }

    public function getEntityStats(string $entity): array
    {
        $this->initEntity($entity);

        return $this->entities[$entity];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $totalRead = 0;
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach ($this->entities as $stats) {
            $totalRead += $stats['read'];
            $totalInserted += $stats['inserted'];
            $totalUpdated += $stats['updated'];
            $totalSkipped += $stats['skipped']['total'];
        }

        return [
            'summary' => [
                'total_read' => $totalRead,
                'total_inserted' => $totalInserted,
                'total_updated' => $totalUpdated,
                'total_skipped' => $totalSkipped,
            ],
            'entities' => $this->entities,
            'conflicts' => $this->conflicts,
        ];
    }

    public function saveJsonReport(ImportSource|string $source, ?string $directory = null): string
    {
        $sourceValue = $source instanceof ImportSource ? $source->value : $source;
        $dir = $directory ?? storage_path('app/import-reports');

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $fileName = "import-report-{$sourceValue}-{$timestamp}.json";
        $fullPath = $dir.DIRECTORY_SEPARATOR.$fileName;

        $reportData = array_merge([
            'source' => $sourceValue,
            'generated_at' => now()->toIso8601String(),
        ], $this->toArray());

        File::put($fullPath, json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }
}
