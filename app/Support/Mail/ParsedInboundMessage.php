<?php

namespace App\Support\Mail;

/**
 * The generic IMAP intake job's own shape for one inbound message, decoupled
 * from webklex/php-imap's Message class -- keeps InboundEmailProcessor
 * (the actual matching/threading/dedupe logic) unit-testable without a real
 * or mocked IMAP connection.
 */
final readonly class ParsedInboundMessage
{
    /**
     * @param  list<string>  $toAddresses
     */
    public function __construct(
        public string $fromAddress,
        public array $toAddresses,
        public ?string $subject,
        public ?string $bodyHtml,
        public ?string $bodyText,
        public ?string $messageId,
        public ?string $inReplyTo,
    ) {}
}
