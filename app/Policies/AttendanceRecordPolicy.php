<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Attendance has two abilities, `view` and `record`, and the catalogue is
 * explicit that the second is not implied by the first — writing what hours
 * somebody worked is the input to a wage. Every write verb maps to `record`.
 */
class AttendanceRecordPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'attendance';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'record');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->owns($record) && $this->allows($user, 'record');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->owns($record) && $this->allows($user, 'record');
    }
}
