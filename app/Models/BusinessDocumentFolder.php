<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A place to keep documents.
 *
 * A folder is an arrangement rather than a container: documents belong to the
 * business, not to the folder, and deleting one leaves its documents at the
 * root to be re-filed.
 */
class BusinessDocumentFolder extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const COMPANY = 'company';

    public const PERSONAL = 'personal';

    /**
     * How deep the tree may go.
     *
     * Five is not a technical limit — it is a usability one. Nobody navigates
     * a six-deep folder tree, and past that people start losing documents in
     * it, which is the opposite of what filing is for.
     */
    public const MAX_DEPTH = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class, 'folder_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('kind', self::COMPANY);
    }

    /** A personal folder is visible only to the user who owns it. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('kind', self::COMPANY)
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('kind', self::PERSONAL)
                    ->where('owner_id', $user->id));
        });
    }

    public function isPersonal(): bool
    {
        return $this->kind === self::PERSONAL;
    }

    /** How deep this folder sits. A root is 1. */
    public function depth(): int
    {
        $depth = 1;
        $node = $this;

        while ($node->parent_id !== null && $depth <= self::MAX_DEPTH + 1) {
            $node = $node->parent;

            if ($node === null) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    /** `Sales / Contracts / 2026`, for a breadcrumb or a list row. */
    public function path(string $separator = ' / '): string
    {
        $names = [$this->name];
        $node = $this;
        $guard = 0;

        while ($node->parent_id !== null && $guard++ <= self::MAX_DEPTH + 1) {
            $node = $node->parent;

            if ($node === null) {
                break;
            }

            array_unshift($names, $node->name);
        }

        return implode($separator, $names);
    }

    /**
     * Whether this folder is somewhere beneath the given one.
     *
     * Used to refuse a move that would detach a branch from the tree — putting
     * a folder inside its own descendant orphans everything between them, and
     * the rows survive while becoming unreachable.
     */
    public function isDescendantOf(self $possibleAncestor): bool
    {
        $node = $this->parent;
        $guard = 0;

        while ($node !== null && $guard++ <= self::MAX_DEPTH + 1) {
            if ($node->id === $possibleAncestor->id) {
                return true;
            }

            $node = $node->parent;
        }

        return false;
    }

    /**
     * Re-parent, refusing the two moves that would break the tree.
     */
    public function moveTo(?self $parent): self
    {
        if ($parent !== null) {
            if ($parent->id === $this->id) {
                throw new RuntimeException('A folder cannot be moved inside itself.');
            }

            if ($parent->isDescendantOf($this)) {
                throw new RuntimeException('A folder cannot be moved inside one of its own subfolders.');
            }

            if ($parent->depth() >= self::MAX_DEPTH) {
                throw new RuntimeException('Folders cannot be nested more than '.self::MAX_DEPTH.' deep.');
            }
        }

        $this->forceFill(['parent_id' => $parent?->id])->save();

        return $this;
    }
}
