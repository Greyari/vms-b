<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleCard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleCardMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
    }

    public function test_it_lists_and_filters_vehicle_cards(): void
    {
        VehicleCard::create(['card_no' => 'VH-001', 'status' => 'available']);
        VehicleCard::create(['card_no' => 'VH-002', 'status' => 'in_use']);

        $res = $this->actingAs($this->admin)->getJson('/api/vehicle-cards?search=VH-001');
        $res->assertStatus(200);
        $this->assertCount(1, $res->json());
        $this->assertSame('VH-001', $res->json()[0]['card_no']);

        $resFiltered = $this->actingAs($this->admin)->getJson('/api/vehicle-cards?status=in_use');
        $resFiltered->assertStatus(200);
        $this->assertCount(1, $resFiltered->json());
        $this->assertSame('VH-002', $resFiltered->json()[0]['card_no']);
    }

    public function test_it_supports_pagination(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            VehicleCard::create(['card_no' => sprintf('VH-%03d', $i), 'status' => 'available']);
        }

        $res = $this->actingAs($this->admin)->getJson('/api/vehicle-cards?page=1&per_page=10');
        $res->assertStatus(200);
        $res->assertJsonStructure(['data', 'current_page', 'last_page', 'total']);
        $this->assertCount(10, $res->json()['data']);
        $this->assertSame(20, $res->json()['total']);
    }

    public function test_it_creates_updates_and_deletes_vehicle_card(): void
    {
        $createRes = $this->actingAs($this->admin)->postJson('/api/vehicle-cards', [
            'card_no' => 'VH-NEW',
        ]);
        $createRes->assertStatus(201);
        $cardId = $createRes->json()['id'];

        $updateRes = $this->actingAs($this->admin)->putJson("/api/vehicle-cards/{$cardId}", [
            'card_no' => 'VH-UPDATED',
            'status' => 'in_use',
        ]);
        $updateRes->assertStatus(200);
        $this->assertSame('VH-UPDATED', $updateRes->json()['card_no']);
        $this->assertSame('in_use', $updateRes->json()['status']);

        $deleteRes = $this->actingAs($this->admin)->deleteJson("/api/vehicle-cards/{$cardId}");
        $deleteRes->assertStatus(200);
        $this->assertSoftDeleted('vehicle_cards', ['id' => $cardId]);
    }

    public function test_it_exports_vehicle_cards(): void
    {
        VehicleCard::create(['card_no' => 'VH-EXP-1', 'status' => 'available']);

        $res = $this->actingAs($this->admin)->get('/api/vehicle-cards/export');
        $res->assertStatus(200);
        $this->assertStringContainsString('vehicle_cards.xlsx', (string) $res->headers->get('content-disposition'));
    }
}
