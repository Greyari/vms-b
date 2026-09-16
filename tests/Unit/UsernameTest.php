<?php

use App\Support\Username;

it('upper-cases and trims a username', function () {
    expect(Username::normalize('  pos-gerbang-1 '))->toBe('POS-GERBANG-1');
});

it('treats a blank username as no username', function (?string $value) {
    expect(Username::normalize($value))->toBeNull();
})->with([null, '', '   ']);
