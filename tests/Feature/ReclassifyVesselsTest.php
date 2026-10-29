<?php

namespace Tests\Feature;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\VesselArrival;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassifyVesselsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_simulates_reclassification_without_modifying_database_in_dry_run(): void
    {
        $vessel = Location::create([
            'name' => 'TB. SAMUDRA 01',
            'code' => 'TB-SAMUDRA-01',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'TUG BOAT',
        ]);

        $this->artisan('vms:reclassify-vessels', ['--dry-run' => true])
            ->expectsOutputToContain('VMS Vessel Reclassification (Tugboat & Barge)')
            ->expectsOutputToContain('TB. SAMUDRA 01')
            ->expectsOutputToContain('Simulation complete')
            ->assertSuccessful();

        $this->assertEquals(LocationType::RepairVessel, $vessel->fresh()->type);
    }

    public function test_it_reclassifies_vessels_based_on_vessel_type_specification_and_name(): void
    {
        // 1. Misclassified Tugboat with vessel_type specification
        $tugSpec = Location::create([
            'name' => 'SAMUDRA JAYA',
            'code' => 'SAM-JAYA',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'TUGBOAT',
        ]);

        // 2. Misclassified Barge with name pattern CAPITOL T25005
        $bargePattern = Location::create([
            'name' => 'CAPITOL T25005',
            'code' => 'CAPITOL-25005',
            'type' => LocationType::RepairVessel,
            'vessel_type' => null,
        ]);

        // 3. Regular repair cargo vessel (should remain RepairVessel)
        $cargoVessel = Location::create([
            'name' => 'MV. PACIFIC GLORY',
            'code' => 'IMO-9876543',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'CARGO SHIP',
        ]);

        $this->artisan('vms:reclassify-vessels')
            ->expectsOutputToContain('Successfully reclassified 2 vessel(s).')
            ->assertSuccessful();

        $this->assertEquals(LocationType::TugBoat, $tugSpec->fresh()->type);
        $this->assertEquals(LocationType::Barge, $bargePattern->fresh()->type);
        $this->assertEquals('BARGE', $bargePattern->fresh()->vessel_type);
        $this->assertEquals(LocationType::RepairVessel, $cargoVessel->fresh()->type);
    }

    public function test_it_supports_manual_override_with_vessel_and_to_options(): void
    {
        $vessel = Location::create([
            'name' => 'CUSTOM WORKBOAT 88',
            'code' => 'CUST-88',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'SPECIAL CRAFT',
        ]);

        $this->artisan('vms:reclassify-vessels', [
            '--vessel' => 'CUSTOM WORKBOAT 88',
            '--to' => 'tug_boat',
        ])
            ->expectsOutputToContain('Successfully reclassified 1 vessel(s).')
            ->assertSuccessful();

        $this->assertEquals(LocationType::TugBoat, $vessel->fresh()->type);
    }

    public function test_it_reclassifies_every_vessel_of_a_pic_to_tug_boat(): void
    {
        // AUDRIE KOSASIH's vessels, whatever their current type, case-insensitive match
        $repair = Location::create([
            'name' => 'MV. ANUGERAH 7',
            'code' => 'IMO-1111111',
            'type' => LocationType::RepairVessel,
            'pic' => 'AUDRIE KOSASIH',
        ]);
        $barge = Location::create([
            'name' => 'BG. SINAR 12',
            'code' => 'BG-SINAR-12',
            'type' => LocationType::Barge,
            'vessel_type' => 'BARGE',
            'pic' => 'audrie kosasih',
        ]);

        // Not AUDRIE KOSASIH's: untouched
        $other = Location::create([
            'name' => 'MV. LAUT BIRU',
            'code' => 'IMO-2222222',
            'type' => LocationType::RepairVessel,
            'pic' => 'Budi',
        ]);

        // A place under AUDRIE KOSASIH is never a vessel: untouched
        $place = Location::create([
            'name' => 'WORKSHOP A',
            'type' => LocationType::Location,
            'pic' => 'AUDRIE KOSASIH',
        ]);

        $this->artisan('vms:reclassify-vessels', [
            '--pic' => 'AUDRIE KOSASIH',
            '--to' => 'tug_boat',
        ])
            ->expectsOutputToContain('Successfully reclassified 2 vessel(s).')
            ->assertSuccessful();

        $this->assertEquals(LocationType::TugBoat, $repair->fresh()->type);
        $this->assertEquals('TUG BOAT', $repair->fresh()->vessel_type);
        $this->assertEquals(LocationType::TugBoat, $barge->fresh()->type);
        $this->assertEquals(LocationType::RepairVessel, $other->fresh()->type);
        $this->assertEquals(LocationType::Location, $place->fresh()->type);
    }

    public function test_it_matches_tug_barge_and_falls_back_to_tugboat_for_null_or_unknown_names(): void
    {
        // 1. Vessel with Tug spec or Tug name -> TugBoat
        $tug = Location::create([
            'name' => 'TB. SEJAHTERA 01',
            'code' => 'TB-SEJ-01',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'TUG BOAT',
            'pic' => 'AUDRIE KOSASIH',
        ]);

        // 2. Vessel with Barge spec or Barge name -> Barge
        $barge = Location::create([
            'name' => 'CAPITOL 3001',
            'code' => 'CAP-3001',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'BARGE',
            'pic' => 'AUDRIE KOSASIH',
        ]);

        // 3. Vessel with null spec and strange/generic name -> falls back to TugBoat
        $unknownNull = Location::create([
            'name' => 'ANEH ANEH 99',
            'code' => 'ANH-99',
            'type' => LocationType::RepairVessel,
            'vessel_type' => null,
            'pic' => 'AUDRIE KOSASIH',
        ]);

        // 4. Vessel with unknown/unrecognized spec -> falls back to TugBoat
        $unknownSpec = Location::create([
            'name' => 'KAPAL MISTERIUS',
            'code' => 'MST-01',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'SPECIAL WORKBOAT',
            'pic' => 'AUDRIE KOSASIH',
        ]);

        // Run reclassification by PIC without forcing --to
        $this->artisan('vms:reclassify-vessels', [
            '--pic' => 'AUDRIE KOSASIH',
        ])
            ->expectsOutputToContain('Successfully reclassified 4 vessel(s).')
            ->assertSuccessful();

        $this->assertEquals(LocationType::TugBoat, $tug->fresh()->type);
        $this->assertEquals(LocationType::Barge, $barge->fresh()->type);
        $this->assertEquals(LocationType::TugBoat, $unknownNull->fresh()->type);
        $this->assertEquals('TUG BOAT', $unknownNull->fresh()->vessel_type);
        $this->assertEquals(LocationType::TugBoat, $unknownSpec->fresh()->type);
    }

    public function test_it_reclassifies_and_consolidates_staying_vessel_arrivals_when_consolidate_flag_is_given(): void
    {
        // Misclassified barge with 2 arrivals
        $barge = Location::create([
            'name' => 'CAPITOL T25005',
            'code' => 'CAP-T25005',
            'type' => LocationType::RepairVessel,
            'vessel_type' => 'BARGE',
        ]);

        // Two arrivals: first checked out, second active
        VesselArrival::create([
            'location_id' => $barge->id,
            'vessel_name' => $barge->name,
            'sign_in_date' => '2025-01-01',
            'sign_out_date' => '2025-01-10',
        ]);

        VesselArrival::create([
            'location_id' => $barge->id,
            'vessel_name' => $barge->name,
            'sign_in_date' => '2026-02-01',
            'sign_out_date' => null,
        ]);

        $this->artisan('vms:reclassify-vessels', ['--consolidate' => true])
            ->expectsOutputToContain('Successfully reclassified 1 vessel(s).')
            ->expectsOutputToContain('Running staying vessels consolidation command...')
            ->expectsOutputToContain('Consolidating 2 arrivals for staying vessel: [CAPITOL T25005]')
            ->assertSuccessful();

        $this->assertEquals(LocationType::Barge, $barge->fresh()->type);

        // Verify that duplicate arrivals were merged to 1 master arrival
        $arrivals = VesselArrival::where('location_id', $barge->id)->get();
        $this->assertCount(1, $arrivals);
        $this->assertNull($arrivals->first()->sign_out_date);
    }
}
