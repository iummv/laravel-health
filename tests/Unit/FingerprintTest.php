<?php

use Iummv\LaravelHealth\Errors\Fingerprint;

it('matches the monitor\'s test vectors', function (?string $class, ?string $file, ?int $line, string $message, string $expected) {
    expect(Fingerprint::make($class, $file, $line, $message))->toBe($expected);
})->with([
    ['Illuminate\Database\QueryException', 'app/Services/Submission.php', 42, 'any', '5d7e58b11bd8eea4ebb0f0491bf3da5c75b4eacc'],
    ['RuntimeException', null, null, 'any', '6627146ba985dda626f8bba1d6e9568757d6fef3'],
    [null, null, null, 'Payment 1042 failed for user "ali" (ref 3f2b8c1e-9d4a-4c6b-8a1f-0e2d3c4b5a69)', '3b46231c039ec3a2cb6c21773badb14fc6a7309d'],
    [null, null, null, 'Payment 7 failed for user "sara" (ref 11111111-2222-3333-4444-555555555555)', '3b46231c039ec3a2cb6c21773badb14fc6a7309d'],
]);

it('ignores an exception\'s message', function () {
    expect(Fingerprint::make('RuntimeException', 'app/A.php', 1, 'one'))
        ->toBe(Fingerprint::make('RuntimeException', 'app/A.php', 1, 'two'));
});
