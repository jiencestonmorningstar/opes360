<?php

namespace App\Livewire\Settings;

use App\Models\NotificationPreference;
use App\Support\CurrentCompany;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * What one person wants to hear about, and when.
 *
 * No permission gate, deliberately: these are your own settings, and a gate
 * here would mean somebody could be sent alerts they had no way to turn off.
 * An unmutable alert gets a mail filter written against the whole sender, and
 * that filter hides the critical ones too — so offering the switch is what
 * keeps the critical channel working.
 *
 * Which is also why `critical` rules are shown here as fixed rather than
 * hidden. Somebody who cannot see why an alert keeps arriving assumes the
 * setting is broken; somebody who is told "this one always comes through"
 * knows where they stand.
 */
class NotificationSettings extends Component
{
    /** category => channel => bool */
    public array $wanted = [];

    public string $mode = 'immediate';

    public string $quietFrom = '';

    public string $quietTo = '';

    public function mount(): void
    {
        $rows = $this->rows();

        foreach (NotificationCategories::keys() as $category) {
            foreach (array_keys(NotificationChannels::available()) as $channel) {
                $row = $rows->first(fn (NotificationPreference $p) => $p->category === $category && $p->channel === $channel);

                // Absent means on. A business that has never opened this
                // screen must behave the way it did before the screen existed.
                $this->wanted[$category][$channel] = $row?->enabled ?? true;
            }
        }

        $blanket = $rows->first(fn (NotificationPreference $p) => $p->category === '' && $p->channel === '');

        $this->mode = $blanket?->mode ?? 'immediate';
        $this->quietFrom = substr((string) $blanket?->quiet_from, 0, 5);
        $this->quietTo = substr((string) $blanket?->quiet_to, 0, 5);
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', 'in:immediate,digest'],
            'quietFrom' => ['nullable', 'date_format:H:i'],
            'quietTo' => ['nullable', 'date_format:H:i', 'required_with:quietFrom'],
        ], [
            'quietTo.required_with' => 'Quiet hours need an end as well as a start.',
        ]);

        $companyId = app(CurrentCompany::class)->id();
        $userId = auth()->id();

        // The blanket row carries the things that are not per-category:
        // the summary setting and the hours.
        NotificationPreference::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $userId, 'category' => '', 'channel' => ''],
            [
                'mode' => $this->mode,
                'quiet_from' => $this->quietFrom ?: null,
                'quiet_to' => $this->quietTo ?: null,
            ],
        );

        foreach ($this->wanted as $category => $channels) {
            if (! NotificationCategories::exists((string) $category)) {
                continue;
            }

            foreach ($channels as $channel => $enabled) {
                if (! NotificationChannels::isAvailable((string) $channel)) {
                    continue;
                }

                NotificationPreference::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'user_id' => $userId,
                        'category' => (string) $category,
                        'channel' => (string) $channel,
                    ],
                    ['enabled' => (bool) $enabled],
                );
            }
        }

        session()->flash('status', 'Saved. This applies to this business only.');
    }

    /** @return Collection<int, NotificationPreference> */
    protected function rows()
    {
        return NotificationPreference::query()
            ->where('user_id', auth()->id())
            ->get();
    }

    public function render(): View
    {
        return view('livewire.settings.notification-settings', [
            'categories' => NotificationCategories::all(),
            'channels' => NotificationChannels::available(),
            'unavailable' => array_diff_key(NotificationChannels::all(), NotificationChannels::available()),
        ]);
    }
}
