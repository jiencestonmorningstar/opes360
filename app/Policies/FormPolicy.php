<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

class FormPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'forms';
    }

    /** Reading what people submitted is its own grant, separate from editing the form. */
    public function responses(User $user, Form $form): bool
    {
        return $this->owns($form) && $this->allows($user, 'responses');
    }
}
