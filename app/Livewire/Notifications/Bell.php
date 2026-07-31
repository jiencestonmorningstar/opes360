<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The in-app notification bell in the top bar. Polls rather than pushes —
 * this stack has no websocket server, and a page open for a while should
 * still pick up what happened without a hard refresh.
 */
class Bell extends Component
{
    public function markRead(string $id): void
    {
        $notification = auth()->user()->notifications()->whereKey($id)->first();

        $notification?->markAsRead();
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->each->markAsRead();
    }

    public function render(): View
    {
        return view('livewire.notifications.bell', [
            'notifications' => auth()->user()->notifications()->latest()->limit(12)->get(),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
