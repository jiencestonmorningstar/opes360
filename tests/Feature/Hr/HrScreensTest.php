<?php

namespace Tests\Feature\Hr;

use App\Livewire\Business\Positions;
use App\Livewire\Hr\Attendance;
use App\Livewire\Hr\Reviews;
use App\Models\AttendanceRecord;
use App\Models\PerformanceReview;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The three HR screens, tested for the things that would be expensive to get
 * wrong: a doubled timesheet, an unauthorised hour, and a signature on a
 * document somebody could still edit.
 */
class HrScreensTest extends HrTestCase
{
    /** A member of this company holding exactly these abilities and no others. */
    protected function userWith(array $slugs): User
    {
        $role = Role::create([
            'slug' => 'limited-'.Str::lower(Str::random(6)),
            'name' => 'Limited',
            'level' => 6,
            'is_system' => false,
        ]);

        $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->all());

        $user = User::factory()->create();

        $this->company->users()->syncWithoutDetaching([
            $user->id => ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()],
        ]);

        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    // ── Positions ───────────────────────────────────────────────────────

    public function test_a_position_can_be_kept_from_the_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Positions::class)
            ->set('title', 'Delivery Driver')
            ->set('code', 'DRV')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Position::query()->where('title', 'Delivery Driver')->count());
    }

    public function test_the_screen_refuses_a_second_position_with_the_same_title(): void
    {
        $this->position(['title' => 'Driver']);

        $this->actingAs($this->owner);

        Livewire::test(Positions::class)
            ->set('title', 'Driver')
            ->call('save')
            ->assertHasErrors('title');
    }

    /**
     * A position is named on staff files and appraisals that already exist, so
     * the screen archives rather than deletes.
     */
    public function test_archiving_a_position_leaves_it_in_the_table(): void
    {
        $position = $this->position(['title' => 'Storeman']);

        $this->actingAs($this->owner);

        Livewire::test(Positions::class)->call('archive', $position->id);

        $this->assertFalse($position->fresh()->is_active);
        $this->assertNotNull($position->fresh());
    }

    public function test_somebody_who_may_only_read_positions_cannot_keep_one(): void
    {
        $this->actingAs($this->userWith(['positions.view']));

        Livewire::test(Positions::class)
            ->set('title', 'Driver')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Position::query()->count());
    }

    // ── Attendance ──────────────────────────────────────────────────────

    /**
     * The whole point of the unique key: a corrected day replaces the day it
     * corrects. Two rows would double every total built on the table, with no
     * way for a later reader to tell which one was real.
     */
    public function test_entering_the_same_day_again_overwrites_rather_than_appends(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->owner);

        $enter = fn (string $out) => Livewire::test(Attendance::class)
            ->set('employeeId', $employee->id)
            ->set('workedOn', '2026-09-10')
            ->set('status', 'present')
            ->set('checkedInAt', '08:00')
            ->set('checkedOutAt', $out)
            ->set('minutesWorked', '')
            ->call('save')
            ->assertHasNoErrors();

        $enter('12:00');
        $enter('17:00');

        $records = AttendanceRecord::query()->where('employee_id', $employee->id)->get();

        $this->assertCount(1, $records);
        $this->assertSame(540, $records->first()->minutes_worked);
    }

    /**
     * `record` is not implied by `view`. Seeing that a team turned up is a
     * supervisor's business; writing the hours that become a wage is not the
     * same trust.
     */
    public function test_someone_who_may_only_see_attendance_cannot_write_it(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->userWith(['attendance.view']));

        Livewire::test(Attendance::class)
            ->set('employeeId', $employee->id)
            ->set('workedOn', '2026-09-10')
            ->set('status', 'present')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_someone_who_may_only_see_attendance_can_still_open_the_screen(): void
    {
        $this->employee();

        $this->actingAs($this->userWith(['attendance.view']));

        Livewire::test(Attendance::class)->assertOk();
    }

    // ── Reviews ─────────────────────────────────────────────────────────

    public function test_a_review_can_be_written_and_shared(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->owner);

        $component = Livewire::test(Reviews::class)
            ->set('employeeId', $employee->id)
            ->set('cycle', 'annual')
            ->set('periodStartsOn', '2026-01-01')
            ->set('periodEndsOn', '2026-12-31')
            ->set('overallRating', '4')
            ->set('summary', 'A good year.')
            ->call('save')
            ->assertHasNoErrors();

        $review = PerformanceReview::query()->firstOrFail();
        $this->assertSame('draft', $review->status);

        $component->call('share', $review->id);

        $this->assertSame('shared', $review->fresh()->status);
    }

    /**
     * The right to acknowledge comes from being the subject of the document,
     * not from an ability — otherwise an administrator could hand somebody the
     * power to sign off a review that is not about them.
     */
    public function test_an_employee_can_acknowledge_only_their_own_review(): void
    {
        $me = $this->userWith([]);
        $mine = $this->employee(['user_id' => $me->id]);
        $theirs = $this->employee(['first_name' => 'Paul', 'last_name' => 'Ekwe']);

        $ownReview = $this->review($mine, ['status' => 'shared', 'overall_rating' => 3]);
        $otherReview = $this->review($theirs, ['status' => 'shared', 'overall_rating' => 3]);

        $this->actingAs($me);

        Livewire::test(Reviews::class)
            ->call('startAcknowledging', $ownReview->id)
            ->set('employeeComment', 'Noted.')
            ->call('acknowledge');

        $this->assertSame('acknowledged', $ownReview->fresh()->status);

        Livewire::test(Reviews::class)
            ->call('startAcknowledging', $otherReview->id)
            ->assertForbidden();

        $this->assertSame('shared', $otherReview->fresh()->status);
    }

    /**
     * An acknowledgement of a document that can still be edited is worth
     * nothing in the dispute it is kept for — so the verdict freezes. The
     * employee's own words do not: the one person who may still add to the
     * file is the one it is about.
     */
    public function test_acknowledging_freezes_the_verdict_but_not_the_comment(): void
    {
        $me = $this->userWith([]);
        $employee = $this->employee(['user_id' => $me->id]);
        $review = $this->review($employee, ['status' => 'shared', 'overall_rating' => 3, 'summary' => 'As agreed.']);

        $this->actingAs($me);

        Livewire::test(Reviews::class)
            ->call('startAcknowledging', $review->id)
            ->call('acknowledge');

        // The manager can no longer restate what was agreed.
        $this->actingAs($this->owner);

        Livewire::test(Reviews::class)
            ->call('edit', $review->id)
            ->set('overallRating', '5')
            ->set('summary', 'On reflection, outstanding.')
            ->call('save')
            ->assertHasErrors('summary');

        $this->assertSame(3, $review->fresh()->overall_rating);
        $this->assertSame('As agreed.', $review->fresh()->summary);

        // The employee's own comment is still theirs.
        $this->actingAs($me);

        Livewire::test(Reviews::class)
            ->call('startComment', $review->id)
            ->set('employeeComment', 'I disagree about the goals.')
            ->call('saveComment', $review->id);

        $this->assertSame('I disagree about the goals.', $review->fresh()->employee_comment);
    }
}
