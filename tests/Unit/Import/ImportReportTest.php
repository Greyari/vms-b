<?php

namespace Tests\Unit\Import;

use App\Enums\ImportSource;
use App\Services\Import\ImportReport;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportReportTest extends TestCase
{
    public function test_tracks_counts_and_reasons_per_entity(): void
    {
        $report = new ImportReport;

        $report->incrementRead('visitors', 10);
        $report->incrementInserted('visitors', 6);
        $report->incrementUpdated('visitors', 2);
        $report->incrementSkipped('visitors', 'duplicate_canonical', 1);
        $report->incrementSkipped('visitors', 'invalid_id', 1);

        $report->incrementRead('visits', 5);
        $report->incrementInserted('visits', 5);

        $report->addConflict('visitors', 'VIS-101', ['reason' => 'mismatched_gender', 'details' => 'legacy M vs new F']);

        $summary = $report->toArray();

        $this->assertSame(15, $summary['summary']['total_read']);
        $this->assertSame(11, $summary['summary']['total_inserted']);
        $this->assertSame(2, $summary['summary']['total_updated']);
        $this->assertSame(2, $summary['summary']['total_skipped']);

        $this->assertSame(10, $summary['entities']['visitors']['read']);
        $this->assertSame(6, $summary['entities']['visitors']['inserted']);
        $this->assertSame(2, $summary['entities']['visitors']['updated']);
        $this->assertSame(2, $summary['entities']['visitors']['skipped']['total']);
        $this->assertSame(1, $summary['entities']['visitors']['skipped']['reasons']['duplicate_canonical']);
        $this->assertSame(1, $summary['entities']['visitors']['skipped']['reasons']['invalid_id']);

        $this->assertCount(1, $summary['conflicts']);
        $this->assertSame('VIS-101', $summary['conflicts'][0]['key']);
    }

    public function test_saves_json_report_file(): void
    {
        $report = new ImportReport;
        $report->incrementRead('staff', 3);
        $report->incrementInserted('staff', 3);

        $tempDir = storage_path('framework/testing/import_reports_'.uniqid());
        $filePath = $report->saveJsonReport(ImportSource::VmsOld, $tempDir);

        $this->assertFileExists($filePath);
        $json = json_decode(file_get_contents($filePath), true);

        $this->assertSame('vms-old', $json['source']);
        $this->assertSame(3, $json['summary']['total_read']);
        $this->assertSame(3, $json['summary']['total_inserted']);

        if (File::isDirectory($tempDir)) {
            File::deleteDirectory($tempDir);
        }
    }
}
