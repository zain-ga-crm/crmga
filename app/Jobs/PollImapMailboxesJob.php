<?php

namespace App\Jobs;

use App\Support\Mail\InboundEmailProcessor;
use App\Support\Mail\ParsedInboundMessage;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Generic IMAP intake (deferred item, ARCHITECTURE.md §"Mail intake" /
 * §"Email": "IMAP intake job -> Email records"). No mailbox-configuration
 * screen exists yet (Studio/Settings UI is unbuilt) -- mailboxes are
 * configured directly through Settings until one does:
 *
 *   Settings::set('mail.inbound.mailboxes', [
 *       ['id' => 'support', 'host' => 'imap.example.com', 'port' => 993,
 *        'encryption' => 'ssl', 'validate_cert' => true,
 *        'username' => '...', 'password' => '...', 'folder' => 'INBOX'],
 *       ...
 *   ], secret: true);
 *
 * The whole list is stored as one secret value (Settings::set() only takes a
 * single secret flag per key, and every mailbox here carries a password) --
 * unlike RuntimeMailConfigurator's single flat-keyed outbound mailbox, this
 * supports any number of inbound mailboxes per BACKEND_BRIEF §14's
 * `mail.inbound` settings group.
 */
final class PollImapMailboxesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct()
    {
        // Inbound payload processing (BACKEND_BRIEF §11's queue table),
        // same as the webhook dispatcher.
        $this->onQueue('integrations');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(Settings $settings, ClientManager $clientManager, InboundEmailProcessor $processor): void
    {
        $mailboxes = $settings->get('mail.inbound.mailboxes', []);
        if (! is_array($mailboxes)) {
            return;
        }

        foreach ($mailboxes as $mailbox) {
            if (is_array($mailbox)) {
                $this->pollMailbox($clientManager, $processor, $mailbox);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $mailbox
     */
    private function pollMailbox(ClientManager $clientManager, InboundEmailProcessor $processor, array $mailbox): void
    {
        $id = is_string($mailbox['id'] ?? null) ? $mailbox['id'] : 'unnamed';
        $folderName = is_string($mailbox['folder'] ?? null) ? $mailbox['folder'] : 'INBOX';

        try {
            $client = $clientManager->make([
                'host' => $mailbox['host'] ?? null,
                'port' => $mailbox['port'] ?? 993,
                'protocol' => 'imap',
                'encryption' => $mailbox['encryption'] ?? 'ssl',
                'validate_cert' => $mailbox['validate_cert'] ?? true,
                'username' => $mailbox['username'] ?? null,
                'password' => $mailbox['password'] ?? null,
            ]);
            $client->connect();

            $folder = $client->getFolder($folderName);
            if ($folder === null) {
                Log::channel('api')->warning('imap_intake_folder_not_found', ['mailbox' => $id, 'folder' => $folderName]);

                return;
            }

            foreach ($folder->messages()->unseen()->get() as $message) {
                if ($message instanceof Message) {
                    $processor->process($this->toParsedMessage($message));
                    $message->setFlag('Seen');
                }
            }
        } catch (\Throwable $e) {
            // One misconfigured mailbox must not stop the others from being
            // polled -- reported, not rethrown.
            Log::channel('api')->error('imap_intake_mailbox_failed', ['mailbox' => $id, 'error' => $e->getMessage()]);
        }
    }

    private function toParsedMessage(Message $message): ParsedInboundMessage
    {
        return new ParsedInboundMessage(
            fromAddress: $this->firstAddress($message->getFrom()->all()) ?? '',
            toAddresses: $this->allAddresses($message->getTo()->all()),
            subject: $this->nullableString($message->getSubject()),
            bodyHtml: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            bodyText: $message->hasTextBody() ? $message->getTextBody() : null,
            messageId: $this->nullableString($message->getMessageId()),
            inReplyTo: $this->nullableString($message->getInReplyTo()),
        );
    }

    /**
     * @param  array<array-key, mixed>  $addresses
     */
    private function firstAddress(array $addresses): ?string
    {
        $first = array_values($addresses)[0] ?? null;

        return $first instanceof Address ? $first->mail : null;
    }

    /**
     * @param  array<array-key, mixed>  $addresses
     * @return list<string>
     */
    private function allAddresses(array $addresses): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $address): ?string => $address instanceof Address ? $address->mail : null,
            array_values($addresses),
        )));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! ($value instanceof \Stringable)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
