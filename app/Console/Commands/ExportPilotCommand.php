<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ExportPilotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:export-pilot
                            {--connection=pilot : Database connection name for the pilot database}
                            {--path= : Output directory path to store exported files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export pilot database data to RFC 4180 CSV files and manifest.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path');
        if (! $path) {
            $this->error('The --path option is required.');

            return 1;
        }

        $connection = $this->option('connection') ?: 'pilot';

        try {
            $db = DB::connection($connection);
            $schema = $db->getSchemaBuilder();
        } catch (\Throwable $e) {
            $this->error("Cannot connect to database [{$connection}]: ".$e->getMessage());

            return 1;
        }

        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        $tableDefinitions = [
            'visitors' => ['file' => 'visitors.csv', 'candidates' => ['visitors']],
            'staffs' => ['file' => 'staffs.csv', 'candidates' => ['staffs', 'staff']],
            'cards' => ['file' => 'cards.csv', 'candidates' => ['visitor_cards', 'cards']],
            'vehicle_cards' => ['file' => 'vehicle_cards.csv', 'candidates' => ['vehicle_cards']],
            'visits' => ['file' => 'visits.csv', 'candidates' => ['visits']],
            'users' => ['file' => 'users.csv', 'candidates' => ['users']],
            'visit_visitor_card' => ['file' => 'visit_visitor_card.csv', 'candidates' => ['visit_visitor_card']],
        ];

        $hasRoles = $schema->hasTable('model_has_roles') && $schema->hasTable('roles');

        $manifest = [
            'source' => 'pilot',
            'exported_at' => now()->toIso8601String(),
            'tables' => [],
        ];

        foreach ($tableDefinitions as $alias => $def) {
            $actualTable = null;
            foreach ($def['candidates'] as $candidate) {
                if ($schema->hasTable($candidate)) {
                    $actualTable = $candidate;
                    break;
                }
            }

            if (! $actualTable) {
                $this->warn("Table for [{$alias}] not found in connection [{$connection}], skipping.");

                continue;
            }

            $fileName = $def['file'];
            $filePath = $path.DIRECTORY_SEPARATOR.$fileName;
            $this->info("Exporting [{$actualTable}] to [{$fileName}]...");

            $handle = fopen($filePath, 'w');
            if ($handle === false) {
                $this->error("Failed to open file for writing: {$filePath}");

                return 1;
            }

            $first = true;
            $rowCount = 0;

            foreach ($db->table($actualTable)->cursor() as $row) {
                $rowArray = (array) $row;

                if ($alias === 'users' && $hasRoles) {
                    $userId = $rowArray['id'] ?? null;
                    if ($userId !== null) {
                        $roleNames = $db->table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_id', $userId)
                            ->pluck('roles.name')
                            ->all();
                        $rowArray['roles'] = implode(',', $roleNames);
                    } else {
                        $rowArray['roles'] = '';
                    }
                }

                if ($first) {
                    fputcsv($handle, array_keys($rowArray));
                    $first = false;
                }

                fputcsv($handle, array_map(function ($val) {
                    if (is_null($val)) {
                        return '';
                    }
                    if (is_bool($val)) {
                        return $val ? '1' : '0';
                    }

                    return (string) $val;
                }, array_values($rowArray)));

                $rowCount++;
            }

            if ($first) {
                // Table was empty, write column headers from schema
                $columns = $schema->getColumnListing($actualTable);
                if ($alias === 'users' && $hasRoles) {
                    $columns[] = 'roles';
                }
                if (! empty($columns)) {
                    fputcsv($handle, $columns);
                }
            }

            fclose($handle);

            $manifest['tables'][$alias] = [
                'file' => $fileName,
                'row_count' => $rowCount,
            ];

            $this->line("Done [{$alias}]: {$rowCount} rows.");
        }

        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Export completed. Manifest written to [{$manifestPath}].");

        return 0;
    }
}
