<?php

use App\Support\Filament\LayoutRowPacker;

it('pairs consecutive half slots into one row', function () {
    $slots = [
        ['field' => 'a', 'span' => 'half'],
        ['field' => 'b', 'span' => 'half'],
    ];

    expect(LayoutRowPacker::pack($slots))->toBe([
        [['field' => 'a', 'span' => 'half'], ['field' => 'b', 'span' => 'half']],
    ]);
});

it('gives a full-span slot its own row, even with a pending half before it', function () {
    $slots = [
        ['field' => 'a', 'span' => 'half'],
        ['field' => 'b', 'span' => 'full'],
        ['field' => 'c', 'span' => 'half'],
    ];

    expect(LayoutRowPacker::pack($slots))->toBe([
        [['field' => 'a', 'span' => 'half']],
        [['field' => 'b', 'span' => 'full']],
        [['field' => 'c', 'span' => 'half']],
    ]);
});

it('gives a trailing unpaired half slot its own row', function () {
    $slots = [
        ['field' => 'a', 'span' => 'half'],
        ['field' => 'b', 'span' => 'half'],
        ['field' => 'c', 'span' => 'half'],
    ];

    expect(LayoutRowPacker::pack($slots))->toBe([
        [['field' => 'a', 'span' => 'half'], ['field' => 'b', 'span' => 'half']],
        [['field' => 'c', 'span' => 'half']],
    ]);
});

it('treats a missing span as half', function () {
    $slots = [['field' => 'a'], ['field' => 'b']];

    expect(LayoutRowPacker::pack($slots))->toBe([
        [['field' => 'a'], ['field' => 'b']],
    ]);
});

it('unpacks rows back into the original flat order', function () {
    $rows = [
        [['field' => 'a', 'span' => 'half'], ['field' => 'b', 'span' => 'half']],
        [['field' => 'c', 'span' => 'full']],
    ];

    expect(LayoutRowPacker::unpack($rows))->toBe([
        ['field' => 'a', 'span' => 'half'],
        ['field' => 'b', 'span' => 'half'],
        ['field' => 'c', 'span' => 'full'],
    ]);
});

it('round-trips pack then unpack for a flat slot list', function () {
    $slots = [
        ['field' => 'a', 'span' => 'half'],
        ['field' => 'b', 'span' => 'half'],
        ['field' => 'c', 'span' => 'full'],
        ['field' => 'd', 'span' => 'half'],
    ];

    expect(LayoutRowPacker::unpack(LayoutRowPacker::pack($slots)))->toBe($slots);
});
