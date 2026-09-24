<?php

use App\Models\Staff;
use App\Models\Visit;
use App\Models\VisitorCard;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsAdmin();
});

it('answers 404 when attempting to check out a non-existent visit', function () {
    $randomId = (string) Str::uuid7();

    $response = $this->postJson("/api/visits/{$randomId}/checkout");

    $response->assertNotFound();
});

it('answers 404 when attempting to delete a non-existent visit without reporting an error', function () {
    Exceptions::fake();
    $randomId = (string) Str::uuid7();

    $response = $this->deleteJson("/api/visits/{$randomId}");

    $response->assertNotFound();
    Exceptions::assertNothingReported();
});

it('reports an unexpected exception during checkout and hides the error message', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();
    $visit = Visit::factory()->create([
        'staff_id' => $staff->id,
        'checked_out_at' => null,
    ]);
    $visit->visitorCards()->attach($card->id);

    Exceptions::fake();

    Visit::updating(function (): void {
        throw new RuntimeException('DATABASE-DEADLOCK-SIMULATION');
    });

    try {
        $response = $this->postJson("/api/visits/{$visit->id}/checkout");

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Checkout failed',
                'error' => 'An unexpected error stopped the checkout from being completed.',
            ]);

        expect($visit->fresh()->checked_out_at)->toBeNull();
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'DATABASE-DEADLOCK-SIMULATION');
    } finally {
        Visit::flushEventListeners();
        Visit::clearBootedModels();
    }
});
