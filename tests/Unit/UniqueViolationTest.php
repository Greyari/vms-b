<?php

use App\Support\UniqueViolation;
use Illuminate\Database\UniqueConstraintViolationException;

function makeUniqueViolation(string $driverMessage): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException('testing', 'insert into x', [], new PDOException($driverMessage));
}

it('retries the numbering races a new attempt can resolve', function (string $driverMessage) {
    expect(UniqueViolation::isRetryable(makeUniqueViolation($driverMessage)))->toBeTrue();
})->with([
    'postgres ticket' => 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "visits_ticket_number_unique"',
    'postgres visitor number' => 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "visitors_visitor_no_unique"',
    'sqlite ticket' => 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: visits.ticket_number',
    'sqlite visitor number' => 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: visitors.visitor_no',
]);

it('does not retry any other unique violation', function (string $driverMessage) {
    expect(UniqueViolation::isRetryable(makeUniqueViolation($driverMessage)))->toBeFalse();
})->with([
    'postgres identity' => 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "visitors_id_type_id_number_unique"',
    'sqlite identity' => 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: visitors.id_type, visitors.id_number',
    'postgres staff number' => 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "staff_staff_id_unique"',
    'sqlite card number' => 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: visitor_cards.card_no',
]);
