<?php

use App\Filament\RelationManagers\CallsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\RelationManagers\EmailsRelationManager;
use App\Filament\RelationManagers\MeetingsRelationManager;
use App\Filament\RelationManagers\NotesRelationManager;
use App\Filament\RelationManagers\TasksRelationManager;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Mail\RecordEmail;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// S-4.3 (relation-manager half): the create/edit companion to the read-only
// ActivityTimelineWidget. Each test passes 'pageClass' => ViewLead::class
// explicitly, matching how Filament's own ViewRecord page mounts these --
// without it, RelationManager::isReadOnly() can't tell it's on a View page
// at all. The panel-level readOnlyRelationManagersOnResourceViewPagesByDefault(false)
// override in AdminPanelProvider is what makes create/edit/delete available
// there in the first place (Filament defaults View-page relation managers
// to list-only) -- live-verified in browser too.
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('lists all six relation managers on every Contactable resource', function () {
    expect(LeadResource::getRelations())->toHaveCount(6)
        ->and(LeadResource::getRelations())->toContain(
            MeetingsRelationManager::class,
            TasksRelationManager::class,
            NotesRelationManager::class,
            DocumentsRelationManager::class,
            CallsRelationManager::class,
            EmailsRelationManager::class,
        );
});

it('is not read-only on the lead view page, so create/edit/delete are available', function () {
    $lead = Lead::factory()->create();

    Livewire::test(MeetingsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->assertTableActionExists('create')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete');
});

it('creates a meeting and stamps created_by from the signed-in user', function () {
    $lead = Lead::factory()->create();
    $me = auth()->user();

    Livewire::test(MeetingsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('create', data: [
            'name' => 'Discovery call',
            'status' => 'planned',
            'date_start' => now(),
        ])
        ->assertHasNoTableActionErrors();

    $meeting = $lead->meetings()->where('name', 'Discovery call')->firstOrFail();
    expect($meeting->created_by)->toBe($me->id);
});

it('creates a task through the tasks relation manager', function () {
    $lead = Lead::factory()->create();

    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('create', data: ['name' => 'Follow up next week', 'status' => 'not_started', 'priority' => 'high'])
        ->assertHasNoTableActionErrors();

    expect($lead->tasks()->where('name', 'Follow up next week')->exists())->toBeTrue();
});

it('creates a note with an attachment via the Storage facade', function () {
    Storage::fake('local');
    $lead = Lead::factory()->create();
    $file = UploadedFile::fake()->create('memo.pdf', 10, 'application/pdf');

    Livewire::test(NotesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('create', data: ['name' => 'Call notes', 'body' => 'Discussed timeline', 'attachment_path' => $file])
        ->assertHasNoTableActionErrors();

    $note = $lead->notes()->where('name', 'Call notes')->firstOrFail();
    Storage::disk('local')->assertExists($note->attachment_path);
});

it('creates a document, uploads the file, and auto-populates file_mime_type', function () {
    Storage::fake('local');
    $lead = Lead::factory()->create();
    $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('create', data: ['name' => 'Signed contract', 'file_path' => $file, 'status' => 'active'])
        ->assertHasNoTableActionErrors();

    $document = $lead->documents()->where('name', 'Signed contract')->firstOrFail();
    Storage::disk('local')->assertExists($document->file_path);
    expect($document->file_mime_type)->toBe('application/pdf');
});

it('creates a call through the calls relation manager', function () {
    $lead = Lead::factory()->create();

    Livewire::test(CallsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('create', data: ['direction' => 'outbound', 'date_start' => now(), 'outcome' => 'connected'])
        ->assertHasNoTableActionErrors();

    expect($lead->calls()->where('outcome', 'connected')->exists())->toBeTrue();
});

it('offers compose, not a generic create row, for emails (S-5.4)', function () {
    // Every Email row is either written by PollImapMailboxesJob (inbound) or
    // EmailSender (outbound, via the 'compose' action below) -- a generic
    // Filament create row would produce one that looks sent/received but
    // isn't, with no message behind it, so that action stays absent.
    $lead = Lead::factory()->create();
    Email::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'from_address' => 'someone@example.test', 'to_addresses' => ['lead@example.test'],
        'status' => 'sent', 'subject_line' => 'Inquiry',
    ]);

    Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->assertCanSeeTableRecords($lead->emails)
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionExists('compose');
});

it('composes and sends an email from a record, logging it against that record (S-5.4)', function () {
    Mail::fake();
    $lead = Lead::factory()->create(['first_name' => 'Amina', 'last_name' => 'Khan', 'primary_email' => 'amina@example.com']);

    Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('compose', data: [
            'to' => ['amina@example.com'],
            'subject' => 'Hello {{full_name}}',
            'body_html' => '<p>Hi {{full_name}}, welcome.</p>',
        ])
        ->assertHasNoTableActionErrors();

    $log = $lead->emails()->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->subject_line)->toBe('Hello Amina Khan')
        ->and($log->body_html)->toBe('<p>Hi Amina Khan, welcome.</p>')
        ->and($log->to_addresses)->toBe(['amina@example.com']);

    Mail::assertSent(RecordEmail::class);
});

it('prefills the compose form\'s To field from the record\'s own primary_email', function () {
    $lead = Lead::factory()->create(['primary_email' => 'prefill@example.com']);

    Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->mountTableAction('compose')
        ->assertTableActionDataSet(['to' => ['prefill@example.com']]);
});

it('prefills subject and body from a chosen template, still editable before sending', function () {
    Mail::fake();
    $lead = Lead::factory()->create(['primary_email' => 'amina@example.com']);
    $template = EmailTemplate::factory()->create([
        'subject' => 'Welcome!',
        'body_html' => '<p>Thanks for reaching out.</p>',
    ]);

    Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->mountTableAction('compose')
        ->setTableActionData(['template_id' => $template->id])
        ->assertTableActionDataSet(['subject' => 'Welcome!', 'body_html' => '<p>Thanks for reaching out.</p>'])
        ->setTableActionData(['to' => ['amina@example.com']])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($lead->emails()->latest()->first()->subject_line)->toBe('Welcome!');
});
