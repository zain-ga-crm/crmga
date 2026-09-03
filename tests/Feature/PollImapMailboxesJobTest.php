<?php

use App\Jobs\PollImapMailboxesJob;
use App\Models\Email;
use App\Models\Lead;
use App\Models\User;
use App\Support\Mail\InboundEmailProcessor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

// Generic IMAP intake job -- the thin adapter over webklex/php-imap. The
// actual matching/threading/dedupe logic is covered without any IMAP
// involvement at all in InboundEmailProcessorTest.php; this only proves the
// job's own orchestration (read mailboxes from Settings, connect, fetch
// unseen, mark seen, one broken mailbox doesn't stop the others).
uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

function mockImapMessage(string $from, string $messageId): Message
{
    $message = Mockery::mock(Message::class);
    $message->shouldReceive('getFrom')->andReturn(new Attribute('from', [new Address((object) ['mail' => $from])]));
    $message->shouldReceive('getTo')->andReturn(new Attribute('to', [new Address((object) ['mail' => 'support@crmga.test'])]));
    $message->shouldReceive('getSubject')->andReturn('Hello');
    $message->shouldReceive('getMessageId')->andReturn($messageId);
    $message->shouldReceive('getInReplyTo')->andReturn('');
    $message->shouldReceive('hasHTMLBody')->andReturn(false);
    $message->shouldReceive('hasTextBody')->andReturn(true);
    $message->shouldReceive('getTextBody')->andReturn('Hello there');
    $message->shouldReceive('setFlag')->with('Seen')->once()->andReturn(true);

    return $message;
}

it('does nothing when no mailboxes are configured', function () {
    $clientManager = Mockery::mock(ClientManager::class);
    $clientManager->shouldNotReceive('make');
    $this->app->instance(ClientManager::class, $clientManager);

    app(Settings::class)->set('mail.inbound.mailboxes', []);

    (new PollImapMailboxesJob)->handle(app(Settings::class), $clientManager, app(InboundEmailProcessor::class));
});

it('polls a configured mailbox, processes each unseen message, and marks it seen', function () {
    $lead = Lead::factory()->create();
    $lead->attachEmailAddress('amina@example.com', primary: true);

    app(Settings::class)->set('mail.inbound.mailboxes', [
        ['id' => 'support', 'host' => 'imap.example.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p', 'folder' => 'INBOX'],
    ], secret: true);

    $message = mockImapMessage('amina@example.com', 'msg-1@mail.test');

    $whereQuery = Mockery::mock(WhereQuery::class);
    $whereQuery->shouldReceive('unseen')->once()->andReturnSelf();
    $whereQuery->shouldReceive('get')->once()->andReturn(new MessageCollection([$message]));

    $folder = Mockery::mock(Folder::class);
    $folder->shouldReceive('messages')->once()->andReturn($whereQuery);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('connect')->once()->andReturnSelf();
    $client->shouldReceive('getFolder')->once()->with('INBOX')->andReturn($folder);

    $clientManager = Mockery::mock(ClientManager::class);
    $clientManager->shouldReceive('make')->once()->with(Mockery::on(
        fn (array $config): bool => $config['host'] === 'imap.example.com' && $config['username'] === 'u',
    ))->andReturn($client);

    (new PollImapMailboxesJob)->handle(app(Settings::class), $clientManager, app(InboundEmailProcessor::class));

    expect(Email::query()->where('message_id', 'msg-1@mail.test')->exists())->toBeTrue();
});

it('logs and continues when one mailbox fails to connect, without throwing', function () {
    app(Settings::class)->set('mail.inbound.mailboxes', [
        ['id' => 'broken', 'host' => 'bad-host', 'port' => 993, 'username' => 'u', 'password' => 'p'],
    ], secret: true);

    $clientManager = Mockery::mock(ClientManager::class);
    $clientManager->shouldReceive('make')->once()->andThrow(new RuntimeException('connection refused'));

    // A thrown connection error inside pollMailbox() is caught and logged --
    // reaching this line at all (rather than the test erroring) is the proof.
    (new PollImapMailboxesJob)->handle(app(Settings::class), $clientManager, app(InboundEmailProcessor::class));

    expect(true)->toBeTrue();
});
