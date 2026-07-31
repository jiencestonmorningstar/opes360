<?php

namespace App\Livewire\Business;

use App\Models\CompanyReview;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Moderation queue for public business reviews.
 *
 * Visitors submit from the public profile; nothing shows there until someone
 * with `business.update` publishes it here. The tenant scope on CompanyReview
 * keeps every action inside the current company.
 */
class Reviews extends Component
{
    use AuthorizesRequests;

    public function publish(string $id): void
    {
        $this->authorize('business.update');

        CompanyReview::findOrFail($id)->update(['is_published' => true]);
    }

    public function unpublish(string $id): void
    {
        $this->authorize('business.update');

        CompanyReview::findOrFail($id)->update(['is_published' => false]);
    }

    public function delete(string $id): void
    {
        $this->authorize('business.update');

        CompanyReview::findOrFail($id)->delete();
    }

    public function render(): View
    {
        $reviews = CompanyReview::query()->latest()->get();

        return view('livewire.business.reviews', [
            'pending' => $reviews->where('is_published', false)->values(),
            'published' => $reviews->where('is_published', true)->values(),
        ])->layout('components.layouts.app', ['title' => 'Reviews', 'active' => 'business']);
    }
}
