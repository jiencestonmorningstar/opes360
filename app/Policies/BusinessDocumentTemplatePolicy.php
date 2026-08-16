<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Reading the template catalogue and writing to it are different jobs — the
 * same reasoning DepartmentPolicy and WorkflowPolicy already use. Anyone who
 * may generate a document may see what templates exist; only document
 * administration may create, edit or publish one, because a published
 * template is offered to everybody who opens the gallery.
 */
class BusinessDocumentTemplatePolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'papers';
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->owns($model) && $this->allows($user, 'manage');
    }
}
