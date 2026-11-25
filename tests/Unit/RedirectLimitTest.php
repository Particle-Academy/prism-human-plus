<?php

declare(strict_types=1);

use Prism\HumanPlus\Transport\RelayEndpoint;

/*
 * One definition of "redirects off", read the way Guzzle reads
 * `allow_redirects` (RedirectMiddleware::__invoke and guardMax): an empty
 * value, an empty array, or an array whose `max` is not a positive whole number
 * is off. A value that is neither `true` nor an array is one Guzzle refuses; it
 * is read as off here, never as permission. The hop limit is the caller's, and
 * never more than five.
 */
dataset('redirectsOff', [
    'false' => [false],
    'null' => [null],
    'zero' => [0],
    'one' => [1],
    'empty string' => [''],
    'string zero' => ['0'],
    'string false' => ['false'],
    'string true' => ['true'],
    'an object' => [new stdClass],
    'empty array' => [[]],
    'max 0' => [['max' => 0]],
    'max null' => [['max' => null]],
    'max -1' => [['max' => -1]],
    "max '0'" => [['max' => '0']],
    'max false' => [['max' => false]],
    'max true' => [['max' => true]],
    "max 'abc'" => [['max' => 'abc']],
    'max 1.5' => [['max' => 1.5]],
    "max '-1'" => [['max' => '-1']],
]);

it('reads every redirects-off form as off', function (mixed $value): void {
    expect(RelayEndpoint::redirectLimit($value))->toBeNull();
})->with('redirectsOff');

it('reads the hop limit as the one asked for, never more than five', function (mixed $value, int $limit): void {
    expect(RelayEndpoint::redirectLimit($value))->toBe($limit);
})->with([
    'true' => [true, 5],
    'an array without max' => [['strict' => true], 5],
    'max 1' => [['max' => 1], 1],
    'max 3' => [['max' => 3], 3],
    "max '3'" => [['max' => '3'], 3],
    'max 5' => [['max' => 5], 5],
    'max 50' => [['max' => 50], 5],
]);
