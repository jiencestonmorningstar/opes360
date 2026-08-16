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

    public function view(User $user, Model $paper): bool
    {
        return parent::view($user, $paper) && $this->readable($user, $paper);
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
            && parent::update($user, $paper)
            && $this->readable($user, $paper);
    }

    public function delete(User $user, Model $paper): bool
    {
        return parent::delete($user, $paper) && $this->readable($user, $paper);
    }

    public function issue(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper)
            && $this->allows($user, 'issue')
            && $this->readable($user, $paper);
    }

    public function void(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper)
            && $this->allows($user, 'void')
            && $this->readable($user, $paper);
    }

    /**
     * Sending a document outside the business. A separate act from writing
     * one, and never implied by being able to read it.
     */
    public function share(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper)
            && $this->allows($user, 'share')
            && $this->readable($user, $paper);
    }

    /**
     * Document administration: filing anyone's document, and the key to the
     * restricted ones.
     */
    public function manage(User $user, BusinessDocument $paper): bool
    {
        return $this->owns($paper) && $this->allows($user, 'manage');
    }

    /**
     * The confidentiality rule, in one place.
     *
     * Every ability above runs through it rather than each re-deriving it,
     * because a rule spelled out six times is a rule that will eventually be
     * spelled out five times. A restricted document is open to the person who
     * owns it and to whoever may administer documents; to everybody else it
     * does not exist, however much `papers.view` they hold.
     *
     * This is the enforcement point. A workspace that leaves a restricted
     * document out of a list has hidden it, which is not the same as refusing
     * it — the URL, the API and the id all still work.
     */
    protected function readable(User $user, Model $paper): bool
    {
        if (! $paper instanceof BusinessDocument || ! $paper->isRestricted()) {
            return true;
        }

        return $paper->owner_id === $user->id || $this->allows($user, 'manage');
    }
}
