<?php

use App\Support\IdentityNumber;

it('strips spaces and dashes and upper-cases', function (string $raw, string $expected) {
    expect(IdentityNumber::normalize($raw))->toBe($expected);
})->with([
    'ktp with punctuation' => ['3210-0000 0000 0001', '3210000000000001'],
    'surrounding whitespace' => ['  a1234567 ', 'A1234567'],
    'sim with dashes' => ['1234-5678-901234', '12345678901234'],
    'tab and newline' => ["AB\t12\n34", 'AB1234'],
    'already normal' => ['X9876543', 'X9876543'],
]);

it('returns an empty string for null or blank input', function () {
    expect(IdentityNumber::normalize(null))->toBe('');
    expect(IdentityNumber::normalize('  - '))->toBe('');
});
