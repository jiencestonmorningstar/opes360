<?php

namespace App\Notifications;

use App\Models\BusinessDocumentComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Somebody named you in a comment on a document. */
class DocumentCommentMention extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected BusinessDocumentComment $comment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $document = $this->comment->document;

        return (new MailMessage)
            ->subject($this->comment->author->firstName().' mentioned you on '.$document->title)
            ->line($this->comment->author->name.' mentioned you in a comment on "'.$document->title.'".')
            ->line('"'.\Illuminate\Support\Str::limit($this->comment->body, 200).'"');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_comment_mention',
            'document_id' => $this->comment->business_document_id,
            'comment_id' => $this->comment->id,
            'from' => $this->comment->author->name,
        ];
    }
}
