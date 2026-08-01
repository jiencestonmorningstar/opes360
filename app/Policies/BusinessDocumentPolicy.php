<?php

namespace App\Policies;

use App\Models\BusinessDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BusinessDocumentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'papers';
    }

    /**
     * A draft can be revised; an issued document is frozen.
     *
     * Typed as Model, not BusinessDocument, because narrowing a parameter type
     * against the parent is a fatal error in PHP — and one that only fires the
     * moment this class is first loaded, which until now the Owner's blanket
     * gate short-circuit was quietly preventing.
     */
    public function update(User $user, Model $paper): bool
    {
        return $paper instanceof BusinessDocument
            && $paper->isDraft()
            && parent::update($user, $paper);
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
