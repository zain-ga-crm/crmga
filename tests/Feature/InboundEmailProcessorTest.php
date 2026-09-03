<?php

use App\Models\Email;
use App\Models\Lead;
use App\Models\User;
use App\Support\Mail\InboundEmailProcessor;
use App\Support\Mail\ParsedInboundMessage;
use Illuminate\Foundation\Testing\DatabaseTruncation;

// Generic IMAP intake job (deferred item, ARCHITECTURE.md §"Mail intake") --
// the matching/threading/dedupe core, exercised without any real or mocked
// IMAP connection (see PollImapMailboxesJobTest.php for that thin adapter).
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

function parsedMessage(
    string $from = 'amina@example.com',
    ?string $messageId = null,
    ?string $inReplyTo = null,
): ParsedInboundMessage {
    return new ParsedInboundMessage(
        fromAddress: $from,
        toAddresses: ['support@crmga.test'],
        subject: 'Re: your application',
        bodyHtml: '<p>Hello</p>',
        bodyText: 'Hello',
        messageId: $messageId,
        inReplyTo: $inReplyTo,
    );
}

it('creates an Email record threaded to the record owning the sender\'s address', function () {
    $lead = Lead::factory()->create();
    $lead->attachEmailAddress('amina@example.com', primary: true);

    $email = (new InboundEmailProcessor)->process(parsedMessage(messageId: 'msg-1@mail.test'));

    expect($email)->not->toBeNull()
        ->and($email->subject_type)->toBe(Lead::class)
        ->and($email->subject_id)->toBe($lead->id)
        ->and($email->status)->toBe('received')
        ->and($email->message_id)->toBe('msg-1@mail.test');
});

it('skips a message whose sender address matches no CRM record', function () {
    $email = (new InboundEmailProcessor)->process(parsedMessage(from: 'nobody@example.com'));

    expect($email)->toBeNull()
        ->and(Email::query()->count())->toBe(0);
});

it('threads a reply onto its parent\'s subject via in_reply_to, without re-resolving the address', function () {
    $lead = Lead::factory()->create();
    $lead->attachEmailAddress('amina@example.com', primary: true);
    $first = (new InboundEmailProcessor)->process(parsedMessage(messageId: 'msg-1@mail.test'));

    // A different (unmatched) from-address on the reply -- proves the thread
    // lookup, not a fresh address resolution, is what found the subject.
    $reply = (new InboundEmailProcessor)->process(parsedMessage(
        from: 'someone-else@example.com',
        messageId: 'msg-2@mail.test',
        inReplyTo: 'msg-1@mail.test',
    ));

    expect($reply)->not->toBeNull()
        ->and($reply->subject_type)->toBe($first->subject_type)
        ->and($reply->subject_id)->toBe($first->subject_id);
});

it('skips a message whose message_id was already imported', function () {
    $lead = Lead::factory()->create();
    $lead->attachEmailAddress('amina@example.com', primary: true);
    (new InboundEmailProcessor)->process(parsedMessage(messageId: 'msg-1@mail.test'));

    $duplicate = (new InboundEmailProcessor)->process(parsedMessage(messageId: 'msg-1@mail.test'));

    expect($duplicate)->toBeNull()
        ->and(Email::query()->count())->toBe(1);
});

it('prefers the address owner marked primary when the address belongs to more than one record', function () {
    $notPrimary = Lead::factory()->create();
    $notPrimary->attachEmailAddress('amina@example.com', primary: false);
    $primary = Lead::factory()->create();
    $primary->attachEmailAddress('amina@example.com', primary: true);

    $email = (new InboundEmailProcessor)->process(parsedMessage(messageId: 'msg-1@mail.test'));

    expect($email->subject_id)->toBe($primary->id);
});
