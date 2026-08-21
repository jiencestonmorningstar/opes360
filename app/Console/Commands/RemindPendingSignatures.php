<?php

namespace App\Console\Commands;

use App\Models\BusinessDocumentSignature;
use App\Notifications\SignatureReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Chases a signer who has had a request sitting for a while, by mailing
 * *them* their signing link again.
 *
 * `RemindOutstandingActions` (`opes:remind-actions`) already raises
 * `document.signature.overdue` for a stale request — but that is an
 * internal event, seen only by whoever the business's own notification
 * rules point at. It never reaches the actual signer, who in most cases
 * has no account here at all and nothing else ever tells them their link
 * still works. This command is that other half — a real gap
 * `docs/audits/production.md` named, closed here rather than folded into
 * the existing command, since "tell the business" and "tell the outside
 * party who is the actual blocker" are different audiences with different
 * content, not two calls to the same notification.
 *
 * Only ever reminds a signer who could actually act right now — a
 * sequential round's not-yet-turn signers are excluded the same way
 * DocumentSignatureRequests::request() excludes them from the first mail,
 * since reminding someone to do something they are blocked from doing
 * would just be confusing.
 */
class RemindPendingSignatures extends Command
{
    protected $signature = 'opes:remind-pending-signatures';

    protected $description = 'Nudge signers whose signature request has gone unanswered for a while';

    /** Days before a first reminder, and between every reminder after that. */
    protected const AFTER_DAYS = 3;

    public function handle(): int
    {
        $threshold = now()->subDays(self::AFTER_DAYS);

        $candidates = BusinessDocumentSignature::query()
            ->pending()
            ->where('created_at', '<=', $threshold)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<=', $threshold);
            })
            ->with('document.company')
            ->get();

        $sent = 0;

        foreach ($candidates as $signature) {
            $document = $signature->document;
            $company = $document?->company;

            if ($document === null || $company === null) {
                continue;
            }

            // Sequential round, not this signer's turn yet — nothing to nudge.
            if ($document->signature_mode === 'sequential'
                && $document->signatures()->pending()->orderBy('order')->value('id') !== $signature->id) {
                continue;
            }

            $daysWaiting = (int) $signature->created_at->diffInDays(now());

            Notification::route('mail', $signature->signer_email)
                ->notify(new SignatureReminder($signature, $company, $daysWaiting));

            $signature->forceFill(['last_reminded_at' => now()])->saveQuietly();
            $sent++;
        }

        $this->info("Sent {$sent} signature reminder(s).");

        return self::SUCCESS;
    }
}
