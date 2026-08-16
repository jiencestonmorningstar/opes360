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
 * A department: the org unit a person works in and a document is filed under.
 *
 * Core rather than a module. A business that switches HR off still has
 * departments, because Documents files by them and approval routing reads
 * them — tying them to HR would make a document's filing disappear the day
 * somebody turns off payroll.
 *
 * This replaces a free-text `employees.department` column. That column is
 * still there and still populated: it is what the business typed, and if the
 * backfill guessed wrong about two spellings being one department they have
 * to be able to see that.
 */
class Department extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /** Deeper org charts than this are a sign of a business needing a different tool. */
    public const MAX_DEPTH = 4;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "Operations / Logistics", root first. */
    public function path(): string
    {
        $names = [$this->name];

        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            array_unshift($names, $node->name);
        }

        return implode(' / ', $names);
    }

    public function depth(): int
    {
        $depth = 1;

        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            $depth++;
        }

        return $depth;
    }

    protected static function booted(): void
    {
        static::saving(function (self $department) {
            if ($department->parent_id === null) {
                return;
            }

            if ($department->parent_id === $department->id) {
                throw new RuntimeException('A department cannot be its own parent.');
            }

            $parent = self::find($department->parent_id);

            if ($parent === null) {
                return;
            }

            /*
             * Refused rather than tolerated. A cycle is not a strange-looking
             * org chart, it is an infinite loop in path() and in every tree
             * render built on it — the process dies rather than the page
             * merely looking odd.
             */
            for ($node = $parent; $node !== null; $node = $node->parent) {
                if ($node->id === $department->id) {
                    throw new RuntimeException('A department cannot be moved inside its own descendant.');
                }
            }

            if ($parent->depth() >= self::MAX_DEPTH) {
                throw new RuntimeException(
                    'Departments may be nested '.self::MAX_DEPTH.' levels deep at most.'
                );
            }
        });
    }
}
