<?php

use App\Support\IdentityNumber;

/*
 * The cloud hub normalizes identity numbers with exactly the same rule (decision BE-1),
 * and its repo carries a byte-identical copy of this fixture. Change both together.
 */
it('normalizes every shared identity-number vector', function () {
    $path = __DIR__.'/../Fixtures/identity-number-vectors.json';
    expect(is_file($path))->toBeTrue("Missing shared fixture {$path}");

    $vectors = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $vector) {
        expect(IdentityNumber::normalize($vector['input']))->toBe($vector['expected'], $vector['note']);
    }
});
