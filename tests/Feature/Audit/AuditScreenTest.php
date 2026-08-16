<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\History;
use App\Livewire\Audit\Index;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * The screen that makes the trail worth keeping.
 */
class AuditScreenTest extends AuditTestCase
{
    public function test_it_lists_this_companys_entries_only(): void
    {
        $this->logEntry(['event' => 'updated', 'subject_label' => 'Ours']);
        $this->logEntry(['event' => 'updated', 'subject_label' => 'Theirs', 'company_id' => $this->otherCompany->id]);

        Livewire::test(Index::class)
            ->assertSee('Ours')
            ->assertDontSee('Theirs');
    }

    public function test_it_filters_by_actor(): void
    {
        $other = User::factory()->create(['name' => 'Blaise Fouda']);
        $this->asRole($other, Role::MANAGER);

        $this->logEntry(['subject_label' => 'Mine']);
        $this->logEntry(['subject_label' => 'Theirs', 'user_id' => $other->id]);

        Livewire::test(Index::class)
            ->set('actorId', (string) $other->id)
            ->assertSee('Theirs')
            ->assertDontSee('Mine');
    }

    public function test_it_filters_by_action(): void
    {
        $this->logEntry(['event' => 'created', 'subject_label' => 'A Creation']);
        $this->logEntry(['event' => 'deleted', 'subject_label' => 'A Deletion']);

        Livewire::test(Index::class)
            ->set('event', 'deleted')
            ->assertSee('A Deletion')
            ->assertDontSee('A Creation');
    }

    public function test_the_date_filter_includes_the_last_day_of_the_range(): void
    {
        /*
         * The bug this exists to stop: a date-only bound compares against
         * midnight, so "to 10 August" silently drops everything that happened
         * on 10 August — the day the disputed change is most likely to be on,
         * because that is the day somebody went looking.
         */
        $day = now()->startOfMonth()->addDays(9);

        $this->logEntry(['subject_label' => 'Late In The Day', 'created_at' => $day->copy()->setTime(23, 47)]);

        Livewire::test(Index::class)
            ->set('from', $day->toDateString())
            ->set('to', $day->toDateString())
            ->assertSee('Late In The Day');
    }

    public function test_it_filters_by_record(): void
    {
        $contact = Contact::create(['name' => 'Traced Customer']);
        $this->logEntry(['subject_label' => 'Unrelated Thing']);

        Livewire::test(Index::class)
            ->set('subjectType', Contact::class)
            ->set('subjectId', $contact->id)
            ->assertSee('Traced Customer')
            ->assertDontSee('Unrelated Thing');
    }

    public function test_a_manager_cannot_open_the_audit_screen(): void
    {
        $manager = User::factory()->create();
        $this->asRole($manager, Role::MANAGER);
        $this->actingAs($manager);

        Livewire::test(Index::class)->assertForbidden();
    }

    public function test_the_record_history_panel_shows_only_that_records_entries(): void
    {
        $contact = Contact::create(['name' => 'Watched Customer']);
        $contact->update(['name' => 'Renamed Customer']);

        // A different record, touched by a different person, so "shows only
        // this record" is actually observable in the rendered panel.
        $intruder = User::factory()->create(['name' => 'Unrelated Person']);
        $this->logEntry(['user_id' => $intruder->id]);

        Livewire::test(History::class, ['subjectType' => Contact::class, 'subjectId' => $contact->id])
            ->assertSee('Awa Ndiaye')
            ->assertSee('changed')
            ->assertDontSee('Unrelated Person');
    }

    public function test_the_history_panel_will_not_read_another_companys_record(): void
    {
        // Someone who knows a ULID must not be able to read its history by
        // dropping the panel's parameters into a request.
        $entry = $this->logEntry([
            'company_id' => $this->otherCompany->id,
            'subject_label' => 'Rival Secret',
            'subject_id' => 'foreign-subject',
        ]);

        Livewire::test(History::class, ['subjectType' => Contact::class, 'subjectId' => 'foreign-subject'])
            ->assertDontSee('Rival Secret');

        $this->assertNotNull($entry->fresh());
    }

    protected function logEntry(array $attributes = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'event' => 'updated',
            'subject_type' => Contact::class,
            'subject_id' => 'subj-'.uniqid(),
            'created_at' => now(),
        ], $attributes));
    }
}
