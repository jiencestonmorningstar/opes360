<?php

namespace App\Support;

use App\Models\Company;

/**
 * Holds the company the current request is acting on.
 *
 * Registered as a singleton and populated by SetCurrentCompany middleware. Models
 * read it through the BelongsToCompany trait, so tenant scoping never has to be
 * remembered at the call site.
 */
class CurrentCompany
{
    protected ?Company $company = null;

    /**
     * When true, the company scope is suspended. Reserved for console commands,
     * sync workers and cross-company owner reporting — never for web requests.
     */
    protected bool $suspended = false;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?string
    {
        return $this->company?->id;
    }

    public function check(): bool
    {
        return $this->company !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Run a callback with tenant scoping suspended, restoring it afterwards even
     * if the callback throws.
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    /**
     * Run a callback as though a different company were current.
     */
    public function as(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }
}
