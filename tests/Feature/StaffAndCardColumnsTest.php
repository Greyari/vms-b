<?php

use App\Enums\CardType;
use App\Models\Staff;
use App\Models\VisitorCard;

it('lets a staff member exist without a department and stores the profile', function () {
    $staff = Staff::create([
        'staff_id' => 'LEGACY-12',
        'name' => 'Rina',
        'company' => 'PT Contoh Galangan',
        'gender' => 'F',
        'birth_date' => '1985-02-01',
        'occupation' => 'HR Officer',
    ])->fresh();

    expect($staff->department)->toBeNull();
    expect($staff->is_active)->toBeTrue();
    expect($staff->birth_date->toDateString())->toBe('1985-02-01');
    expect($staff->company)->toBe('PT Contoh Galangan');
    expect($staff->gender)->toBe('F');
    expect($staff->occupation)->toBe('HR Officer');
});

it('stores the legacy card attributes and defaults a card to active', function () {
    $card = VisitorCard::create([
        'card_no' => 'CRW-0001',
        'status' => 'available',
        'absenteeism_no' => 'CRW-0001',
        'card_type' => 'crew',
        'note' => 'CREW CARD',
    ])->fresh();

    expect($card->is_active)->toBeTrue();
    expect($card->card_type)->toBe(CardType::Crew);
    expect($card->note)->toBe('CREW CARD');
    expect($card->absenteeism_no)->toBe('CRW-0001');
});

it('refuses a card_type value outside the enum', function () {
    expect(function () {
        VisitorCard::create([
            'card_no' => 'CRW-0002',
            'status' => 'available',
            'card_type' => 'CREW CARD',
        ]);
    })->toThrow(ValueError::class);
});
