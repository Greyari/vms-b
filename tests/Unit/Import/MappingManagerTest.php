<?php

namespace Tests\Unit\Import;

use App\Enums\ImportSource;
use App\Services\Import\MappingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MappingManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_puts_and_gets_single_mapping(): void
    {
        $manager = new MappingManager;
        $targetId = (string) Str::uuid();

        $manager->put(ImportSource::VmsOld, 'visitors', '1001', $targetId);

        $this->assertTrue($manager->hasMapping(ImportSource::VmsOld, 'visitors', '1001'));
        $this->assertSame($targetId, $manager->getTargetId(ImportSource::VmsOld, 'visitors', '1001'));
        $this->assertNull($manager->getTargetId(ImportSource::VmsOld, 'visitors', 'non_existent'));
        $this->assertNull($manager->getTargetId(ImportSource::Pilot, 'visitors', '1001'));
    }

    public function test_puts_batch_mappings_and_preloads(): void
    {
        $manager = new MappingManager;

        $uuid1 = (string) Str::uuid();
        $uuid2 = (string) Str::uuid();
        $uuid3 = (string) Str::uuid();

        $manager->putBatch(ImportSource::VmsOld, 'cards', [
            'VC01' => $uuid1,
            'VC02' => $uuid2,
        ]);

        $this->assertSame($uuid1, $manager->getTargetId(ImportSource::VmsOld, 'cards', 'VC01'));
        $this->assertSame($uuid2, $manager->getTargetId(ImportSource::VmsOld, 'cards', 'VC02'));

        // Update VC02 and add VC03
        $newUuid2 = (string) Str::uuid();
        $manager->putBatch(ImportSource::VmsOld, 'cards', [
            'VC02' => $newUuid2,
            'VC03' => $uuid3,
        ]);

        // Fresh manager instance to test DB persistence
        $freshManager = new MappingManager;
        $this->assertSame($newUuid2, $freshManager->getTargetId(ImportSource::VmsOld, 'cards', 'VC02'));
        $this->assertSame($uuid3, $freshManager->getTargetId(ImportSource::VmsOld, 'cards', 'VC03'));

        // Preload batch keys
        $preloaded = $freshManager->preloadKeys(ImportSource::VmsOld, 'cards', ['VC01', 'VC03', 'VC99']);
        $this->assertCount(2, $preloaded);
        $this->assertSame($uuid1, $preloaded['VC01']);
        $this->assertSame($uuid3, $preloaded['VC03']);

        // Load full entity map
        $fullMap = $freshManager->loadEntityMap(ImportSource::VmsOld, 'cards');
        $this->assertCount(3, $fullMap);
        $this->assertSame($uuid1, $fullMap['VC01']);
        $this->assertSame($newUuid2, $fullMap['VC02']);
        $this->assertSame($uuid3, $fullMap['VC03']);
    }
}
