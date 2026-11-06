<?php

namespace BookStack\Search;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a value is a search query string which contains
 * at least one usable search term, tag or filter once parsed.
 */
class SearchQueryRule implements ValidationRule, \Stringable
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || SearchOptions::fromString($value)->toString() === '') {
            $fail('validation.saved_search_query_empty')->translate();
        }
    }

    public function __toString(): string
    {
        return 'search_query';
    }
}
