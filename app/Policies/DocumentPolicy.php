<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy extends CompanyScopedPolicy
{
    protected function group(): string
    {
        return 'sales';
    }

    /**
     * Issuing is separate from creating: a Sales Officer may draft a quotation
     * without being able to commit a permanent, numbered document.
     */
    public function issue(User $user, Document $document): bool
    {
        return $this->owns($document) && $this->allows($user, 'issue');
    }

    public function void(User $user, Document $document): bool
    {
        return $this->owns($document) && $this->allows($user, 'void');
    }

    public function approve(User $user, Document $document): bool
    {
        return $this->owns($document) && $this->allows($user, 'approve');
    }

    /**
     * Converting produces a new issued document, so it needs the issue right
     * rather than merely the right to create a draft.
     */
    public function convert(User $user, Document $document): bool
    {
        return $this->issue($user, $document);
    }
}
