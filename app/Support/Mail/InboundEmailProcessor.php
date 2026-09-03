<?php

namespace App\Support\Mail;

use App\Models\Email;
use App\Models\EmailAddress;
use App\Models\EmailAddressRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * The generic IMAP intake job's matching/threading/dedupe core (deferred
 * item, ARCHITECTURE.md §"Mail intake" / §"Email"). No prior spec existed for
 * "which record does an inbound address belong to" beyond the polymorphic
 * EmailAddress/email_address_relations address book (BACKEND_BRIEF §7.2)
 * already used to denormalise primary_email -- this is its read-side
 * counterpart, not a new design.
 *
 * Unlike Ingest\Deduplicator (built for a single known target module/model,
 * e.g. WordPress/Meta leads), an inbound mailbox's sender could be a Lead,
 * Client, Company or any other Contactable -- so this searches the address
 * book across every owner of the address rather than one model class.
 */
final class InboundEmailProcessor
{
    /**
     * @return Email|null null means the message was skipped: already
     *                    imported (by message_id) or its sender/thread
     *                    could not be matched to any CRM record.
     */
    public function process(ParsedInboundMessage $message): ?Email
    {
        if ($message->messageId !== null && $this->alreadyImported($message->messageId)) {
            return null;
        }

        $subject = $this->resolveViaThread($message->inReplyTo) ?? $this->resolveViaAddress($message->fromAddress);
        if ($subject === null) {
            Log::channel('api')->info('imap_intake_unmatched_sender', [
                'from' => $message->fromAddress,
                'subject_line' => $message->subject,
                'message_id' => $message->messageId,
            ]);

            return null;
        }

        return Email::query()->create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_line' => $message->subject,
            'from_address' => $message->fromAddress,
            'to_addresses' => $message->toAddresses,
            'body_html' => $message->bodyHtml,
            'body_text' => $message->bodyText,
            'status' => 'received',
            'message_id' => $message->messageId,
            'in_reply_to' => $message->inReplyTo,
        ]);
    }

    private function alreadyImported(string $messageId): bool
    {
        return Email::query()->withoutGlobalScopes()->where('message_id', $messageId)->exists();
    }

    /**
     * A reply threads onto whatever record its parent was already matched
     * to -- cheaper and more reliable than re-resolving the sender's address
     * (which may have since changed owners, or belong to more than one).
     */
    private function resolveViaThread(?string $inReplyTo): ?Model
    {
        if ($inReplyTo === null || $inReplyTo === '') {
            return null;
        }

        $parent = Email::query()->withoutGlobalScopes()->where('message_id', $inReplyTo)->first();
        if ($parent === null) {
            return null;
        }

        return $this->findOwner($parent->subject_type, $parent->subject_id);
    }

    /**
     * Looks up the address book directly (BACKEND_BRIEF §7.2), not
     * Ingest\Deduplicator: the sender could belong to any Contactable model,
     * not one known-in-advance module. Prefers whichever owner marked the
     * address primary if it's linked to more than one record.
     */
    private function resolveViaAddress(string $fromAddress): ?Model
    {
        $address = EmailAddress::query()->where('email', mb_strtolower(trim($fromAddress)))->first();
        if ($address === null) {
            return null;
        }

        $relation = EmailAddressRelation::query()
            ->where('email_address_id', $address->id)
            ->orderByDesc('is_primary')
            ->first();

        if ($relation === null) {
            return null;
        }

        return $this->findOwner($relation->related_type, $relation->related_id);
    }

    private function findOwner(mixed $type, mixed $id): ?Model
    {
        if (! is_string($type) || $type === '' || (! is_string($id) && ! is_int($id))) {
            return null;
        }

        $ownerClass = Relation::getMorphedModel($type) ?? $type;
        if (! is_subclass_of($ownerClass, Model::class)) {
            return null;
        }

        // Internal matching, not a user-facing read -- must not be subject to
        // record-visibility ACL (no acting user exists in this queued-job
        // context, so the default scope would otherwise hide every record).
        return $ownerClass::query()->withoutGlobalScopes()->where('id', $id)->first();
    }
}
