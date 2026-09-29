<?php

use App\Support\YardSignature;

/*
 * Decisions SEC-1 + SEC-2: the signing contract shared with the cloud hub. Every expected canonical
 * string in the fixture was written by hand from docs/vms-hybrid-architecture-spec.md §5.2 and every
 * expected signature was computed with .NET HMACSHA256, independently of this PHP code. The hub repo
 * carries a byte-identical copy of tests/Fixtures/hmac-vectors.json; change both together.
 */
it('builds the canonical string and signature of every shared vector', function () {
    $path = __DIR__.'/../Fixtures/hmac-vectors.json';
    expect(is_file($path))->toBeTrue("Missing shared fixture {$path}");

    $vectors = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($vectors)->not->toBeEmpty();

    foreach ($vectors as $v) {
        $canonical = YardSignature::canonical(
            $v['yard'], $v['key_id'], $v['timestamp'], $v['nonce'], $v['method'], $v['path'], $v['raw_query'], $v['body'],
        );

        expect($canonical)->toBe($v['expected_canonical'], $v['note'])
            ->and(YardSignature::sign($canonical, $v['secret']))->toBe($v['expected_signature'], $v['note']);
    }
});
