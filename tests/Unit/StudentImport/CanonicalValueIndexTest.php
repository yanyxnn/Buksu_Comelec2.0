<?php

use App\Services\StudentImport\CanonicalValueIndex;

function indexWith(array $values, array $known = []): CanonicalValueIndex
{
    $index = new CanonicalValueIndex;
    foreach ($values as $value) {
        $index->addFileValue('college', $value);
    }
    $index->addKnownValues('college', $known);

    return $index;
}

it('flags the minority spelling of a case-only inconsistency and keeps the dominant one', function () {
    $index = indexWith(array_merge(array_fill(0, 50, 'CON'), ['cON']));

    expect($index->isAmbiguous('college', 'CON'))->toBeFalse();
    expect($index->isAmbiguous('college', 'cON'))->toBeTrue();
});

it('flags every spelling when the file cannot decide (tie)', function () {
    $index = indexWith(['CON', 'cON']);

    expect($index->isAmbiguous('college', 'CON'))->toBeTrue();
    expect($index->isAmbiguous('college', 'cON'))->toBeTrue();
});

it('treats master data as the authority over the file majority', function () {
    $index = indexWith(array_fill(0, 10, 'con'), ['CON']);

    expect($index->isAmbiguous('college', 'con'))->toBeTrue();
    expect($index->isAmbiguous('college', 'CON'))->toBeFalse();
});

it('does not flag a single consistent spelling or genuinely different values', function () {
    $index = indexWith(['CON', 'CON', 'COE', 'CAS']);

    expect($index->isAmbiguous('college', 'CON'))->toBeFalse();
    expect($index->isAmbiguous('college', 'COE'))->toBeFalse();
    expect($index->isAmbiguous('college', 'CAS'))->toBeFalse();
});

it('keeps fields independent and ignores empty values', function () {
    $index = new CanonicalValueIndex;
    $index->addFileValue('college', 'CON');
    $index->addFileValue('college', 'cON');
    $index->addFileValue('course', 'BSN');

    expect($index->isAmbiguous('course', 'BSN'))->toBeFalse();
    expect($index->isAmbiguous('college', null))->toBeFalse();
    expect($index->isAmbiguous('college', ''))->toBeFalse();
});
