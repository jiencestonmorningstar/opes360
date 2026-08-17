<?php

namespace App\Services\HR;

use App\Models\Employee;
use App\Models\User;
use RuntimeException;

/**
 * The states an employment moves through that are not an ending.
 *
 * Suspension is a pause, not a departure: the record stays, the contract
 * stays, and nothing here touches either — a suspended employee taken off
 * their contract would come back to a payroll that has forgotten their
 * salary. What a suspension means for pay is the payroll run's question,
 * answered there, not a side effect smuggled in here.
 */
class Employment
{
    public function suspend(Employee $employee, User $actor, ?string $reason = null): Employee
    {
        if ($employee->status === 'suspended') {
            throw new RuntimeException($employee->name().' is already suspended.');
        }

        if ($employee->status !== 'active') {
            throw new RuntimeException(
                $employee->name().' has left — only somebody on the team can be suspended.'
            );
        }

        $employee->forceFill([
            'status' => 'suspended',
            'notes' => filled($reason)
                ? trim(($employee->notes ? $employee->notes."\n" : '').now()->toDateString().' — suspended: '.$reason)
                : $employee->notes,
        ])->save();

        return $employee->refresh();
    }

    public function unsuspend(Employee $employee, User $actor): Employee
    {
        if ($employee->status !== 'suspended') {
            throw new RuntimeException($employee->name().' is not suspended.');
        }

        $employee->forceFill(['status' => 'active'])->save();

        return $employee->refresh();
    }
}
