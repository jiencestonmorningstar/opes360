<?php

namespace App\Policies;

use App\Models\BusinessDocument;
use App\Models\User;

class BusinessDocumentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'papers';
    }

    /** A draft can be revised; an issued document is frozen. */
    public function update(User $user, BusinessDocument $paper): bool
    {
        return $paper->isDraft() && parent::update($user, $paper);
    }

    public function issue(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper) && $this->allows($user, 'issue');
    }

    public function void(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper) && $this->allows($user, 'void');
    }
}
