<?php

use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\Filament\FieldTypeRegistry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// S-1.3 (docs/TASK_BREAKDOWN.md): every field-types.json type maps to a real
// Filament form component and table column. Types with no DB dependency
// (everything except enum/multienum/relate) are exercised with a bare
// compiled-field array, matching MetadataRepository::compiled()'s own shape --
// this class never touches the Field Eloquent model directly.
uses(DatabaseTruncation::class);

it('maps text, textarea, bool, numeric, date and contact types to the contract-specified components', function (string $type, string $expectedComponent) {
    $registry = app(FieldTypeRegistry::class);
    $field = registryField('a_field', $type, ['required' => true, 'help' => 'Some help text']);

    $component = $registry->formComponent($field);

    expect($component)->toBeInstanceOf($expectedComponent)
        ->and($component->isRequired())->toBeTrue()
        ->and($component->getHelperText())->toBe('Some help text');
})->with([
    ['text', TextInput::class],
    ['textarea', Textarea::class],
    ['bool', Toggle::class],
    ['int', TextInput::class],
    ['decimal', TextInput::class],
    ['currency', TextInput::class],
    ['date', DatePicker::class],
    ['datetime', DateTimePicker::class],
    ['email', TextInput::class],
    ['phone', TextInput::class],
    ['url', TextInput::class],
    ['file', FileUpload::class],
    ['image', FileUpload::class],
]);

it('falls back to the field name, humanized, when no label is set', function () {
    $registry = app(FieldTypeRegistry::class);

    $component = $registry->formComponent(registryField('phone_mobile', 'phone'));

    expect($component->getLabel())->toBe('Phone mobile');
});

it('uses the real metadata label over the humanized fallback', function () {
    $registry = app(FieldTypeRegistry::class);

    $component = $registry->formComponent(registryField('phone_mobile', 'phone', ['label' => 'Mobile phone']));

    expect($component->getLabel())->toBe('Mobile phone');
});

it('builds an enum field as a Select populated from its option list, searchable past 8 options', function () {
    $list = OptionList::factory()->create();
    foreach (range(1, 9) as $i) {
        OptionItem::factory()->for($list, 'optionList')->create(['value' => "v{$i}", 'label' => "Value {$i}"]);
    }

    $registry = app(FieldTypeRegistry::class);
    $component = $registry->formComponent(registryField('stage', 'enum', ['option_list_id' => $list->id]));

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->getOptions())->toHaveCount(9)
        ->and($component->getOptions())->toHaveKey('v1', 'Value 1')
        ->and($component->isMultiple())->toBeFalse()
        ->and($component->isSearchable())->toBeTrue();
});

it('does not force search on an enum with 8 or fewer options', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->for($list, 'optionList')->create(['value' => 'a', 'label' => 'A']);

    $registry = app(FieldTypeRegistry::class);
    $component = $registry->formComponent(registryField('stage', 'enum', ['option_list_id' => $list->id]));

    expect($component->isSearchable())->toBeFalse();
});

it('builds a multienum field as a multiple Select', function () {
    $list = OptionList::factory()->create();
    OptionItem::factory()->for($list, 'optionList')->create(['value' => 'a', 'label' => 'A']);

    $registry = app(FieldTypeRegistry::class);
    $component = $registry->formComponent(registryField('tags', 'multienum', ['option_list_id' => $list->id]));

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->isMultiple())->toBeTrue();
});

it('builds a relate field as a searchable remote Select resolving labels from the related table', function () {
    $company = Module::factory()->create(['table_name' => 'companies']);
    $companyId = (string) Str::uuid();
    DB::table('companies')->insert([
        'id' => $companyId,
        'first_name' => 'Acme Immigration',
        'do_not_call' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $registry = app(FieldTypeRegistry::class);
    $component = $registry->formComponent(registryField('company_id', 'relate', [
        'related_module_id' => $company->id,
        'related_display_field' => 'first_name',
    ]));

    expect($component)->toBeInstanceOf(Select::class);

    // Select's own getSearchResults()/getOptionLabel() run the stored closure
    // through Filament's evaluate() pipeline, which expects a mounted
    // component with a live container -- reflection reaches the closures
    // this class actually built, which take no such context.
    $searchClosure = (new ReflectionProperty(Select::class, 'getSearchResultsUsing'))->getValue($component);
    expect($searchClosure('Acme'))->toBe([$companyId => 'Acme Immigration']);

    $labelClosure = (new ReflectionProperty(Select::class, 'getOptionLabelUsing'))->getValue($component);
    expect($labelClosure($companyId))->toBe('Acme Immigration');
});

it('maps table cells per the contract: badge for enum, boolean icon for bool, money for currency', function () {
    $registry = app(FieldTypeRegistry::class);

    expect($registry->tableColumn(registryField('stage', 'enum')))->toBeInstanceOf(BadgeColumn::class)
        ->and($registry->tableColumn(registryField('active', 'bool')))->toBeInstanceOf(IconColumn::class)
        ->and($registry->tableColumn(registryField('fee', 'currency')))->toBeInstanceOf(TextColumn::class)
        ->and($registry->tableColumn(registryField('photo', 'image')))->toBeInstanceOf(ImageColumn::class);
});

it('wires table column searchable/sortable straight from the contract, not the field metadata', function () {
    $registry = app(FieldTypeRegistry::class);

    // phone is filterable but not sortable per field-types.json
    $phone = $registry->tableColumn(registryField('phone_mobile', 'phone'));
    expect($phone->isSearchable())->toBeTrue()
        ->and($phone->isSortable())->toBeFalse();

    // url is neither, per field-types.json
    $url = $registry->tableColumn(registryField('website', 'url'));
    expect($url->isSearchable())->toBeFalse()
        ->and($url->isSortable())->toBeFalse();
});
