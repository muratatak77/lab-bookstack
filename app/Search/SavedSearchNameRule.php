<?php

namespace BookStack\Search;

use BookStack\Users\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a saved search name is not already in use
 * across the other saved searches of the given user.
 */
class SavedSearchNameRule implements ValidationRule, \Stringable
{
    public function __construct(
        protected User $user,
        protected ?int $ignoreId = null,
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $existsQuery = SavedSearch::query()
            ->where('user_id', '=', $this->user->id)
            ->where('name', '=', is_string($value) ? trim($value) : $value);

        if ($this->ignoreId !== null) {
            $existsQuery->where('id', '!=', $this->ignoreId);
        }

        if ($existsQuery->exists()) {
            $fail('validation.unique')->translate();
        }
    }

    public function __toString(): string
    {
        return 'unique';
    }
}
