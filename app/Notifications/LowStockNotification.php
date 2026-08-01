<?php

namespace App\Notifications;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Stock has fallen to or below its reorder level.
 *
 * The dashboard already shows this, which only helps somebody who is looking
 * at the dashboard — the person who needs to reorder usually finds out when a
 * customer asks for something that is not there.
 *
 * One message covering every affected item, once a day, rather than one per
 * item: an alert that arrives twenty times in a morning gets filtered, and a
 * filtered alert is the same as no alert.
 */
class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Collection<int, Item>  $items */
    public function __construct(protected Collection $items) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->items->count();

        $mail = (new MailMessage)
            ->subject($count === 1 ? '1 item is running low' : "{$count} items are running low")
            ->greeting($count === 1 ? 'An item needs reordering' : 'Some items need reordering');

        foreach ($this->items->take(15) as $item) {
            $mail->line('**'.$item->name.'** — '.rtrim(rtrim(number_format($item->stockOnHand(), 2, '.', ''), '0'), '.')
                .' left, reorder at '.rtrim(rtrim(number_format((float) $item->reorder_level, 2, '.', ''), '0'), '.'));
        }

        if ($count > 15) {
            $mail->line('…and '.($count - 15).' more.');
        }

        return $mail
            ->action('View products', url('/products'))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->items->count() === 1 ? '1 item is running low' : $this->items->count().' items are running low',
            'body' => $this->items->take(3)->pluck('name')->implode(', ')
                .($this->items->count() > 3 ? ' and more' : ''),
            'url' => '/products',
        ];
    }
}
