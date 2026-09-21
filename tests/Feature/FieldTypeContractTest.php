<?php

use App\Support\FieldTypeContract;

it('lists every field type the contract defines, for the Field Manager type select', function () {
    $types = (new FieldTypeContract)->types();

    expect($types)->toBeArray()
        ->and($types)->toContain('text', 'enum', 'multienum', 'relate', 'decimal')
        ->and($types)->toBe(array_unique($types));
});
