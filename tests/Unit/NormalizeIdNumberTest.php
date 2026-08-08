<?php

use App\Http\Controllers\Api\VisitorController;

/**
 * VisitorController::normalizeIdNumber is the M5 guard: spreadsheet "ID Number"
 * cells arrive as int/float from the reader and must be coerced back to their
 * literal digit string — never scientific notation, never a trailing ".0".
 * It is a private helper, exercised here directly via reflection.
 */
function normalizeIdNumber(mixed $value): string
{
    $controller = (new ReflectionClass(VisitorController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(VisitorController::class, 'normalizeIdNumber');
    $method->setAccessible(true);

    return $method->invoke($controller, $value);
}

it('refuses an integer id cell — numeric cells can be pre-rounded (M5)', function () {
    expect(normalizeIdNumber(1234567890123456))->toBe('');
});

it('refuses a float id cell — a numeric cell may already be rounded (M5)', function () {
    expect(normalizeIdNumber(1234567890123456.0))->toBe('');
});

it('trims a string id', function () {
    expect(normalizeIdNumber('  A1234567  '))->toBe('A1234567');
});

it('returns an empty string for null', function () {
    expect(normalizeIdNumber(null))->toBe('');
});
