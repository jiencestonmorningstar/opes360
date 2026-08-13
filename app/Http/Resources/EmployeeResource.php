<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a staff record.
 *
 * The national id, CNPS and NIU numbers, the bank account and the emergency
 * contact are deliberately absent. They are the identity-theft-shaped fields
 * in this table, `employees.view` is held by every manager, and nothing has
 * yet needed to read them back over HTTP. Adding a field later is easy;
 * un-publishing one is not.
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'job_title' => $this->job_title,
            'department' => $this->department,
            'status' => $this->status,
            'email' => $this->email,
            'phone' => $this->phone,
            'hired_on' => $this->hired_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
