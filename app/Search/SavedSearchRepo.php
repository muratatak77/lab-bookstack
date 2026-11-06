<?php

namespace BookStack\Search;

use BookStack\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class SavedSearchRepo
{
    /**
     * Start a query for the saved searches belonging to the given user.
     * @return Builder<SavedSearch>
     */
    public function queryForUser(User $user): Builder
    {
        return SavedSearch::query()->where('user_id', '=', $user->id);
    }

    /**
     * Get all saved searches for the given user, ordered by name.
     * @return Collection<int, SavedSearch>
     */
    public function getAllForUser(User $user): Collection
    {
        return $this->queryForUser($user)
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Find a saved search, by ID, which belongs to the given user.
     * Throws a not-found exception if not found or not owned by the user.
     * @return SavedSearch
     */
    public function findForUserOrFail(User $user, int $id): SavedSearch
    {
        return $this->queryForUser($user)->findOrFail($id);
    }

    /**
     * Create a new saved search for the given user.
     * @return SavedSearch
     */
    public function create(User $user, string $name, string $query): SavedSearch
    {
        $search = new SavedSearch();
        $search->user_id = $user->id;
        $search->name = trim($name);
        $search->query = $this->normaliseQuery($query);
        $this->saveHandlingDuplicateName($search);

        return $search;
    }

    /**
     * Update the name and/or query of an existing saved search.
     * @param array{name?: string, query?: string} $input
     * @return SavedSearch
     */
    public function update(SavedSearch $search, array $input): SavedSearch
    {
        if (isset($input['name'])) {
            $search->name = trim($input['name']);
        }

        if (isset($input['query'])) {
            $search->query = $this->normaliseQuery($input['query']);
        }

        $this->saveHandlingDuplicateName($search);

        return $search;
    }

    /**
     * Delete the given saved search.
     */
    public function destroy(SavedSearch $search): void
    {
        $search->delete();
    }

    /**
     * Get the validation rules for saved search input.
     * When updating, the ID of the existing search should be provided
     * so that its own name is ignored for uniqueness checks.
     * When not required (partial updates) each field may be omitted, but if
     * provided it must not be empty, since empty strings skip non-implicit rules.
     * @return array<string, array<int, mixed>>
     */
    public function validationRules(User $user, ?int $ignoreId = null, bool $required = true): array
    {
        $presence = $required ? ['required'] : ['sometimes', 'required'];

        return [
            'name'  => [...$presence, 'string', 'min:1', 'max:180', (new Unique('saved_searches', 'name'))->where('user_id', $user->id)->ignore($ignoreId)],
            'query' => [...$presence, 'string', 'max:2000', new SearchQueryRule()],
        ];
    }

    /**
     * Save the given search, converting a unique name constraint failure into a validation error.
     * Validation should prevent duplicate names, but concurrent saves (like a double-submitted form)
     * can both pass validation before either is written.
     * @throws ValidationException
     */
    protected function saveHandlingDuplicateName(SavedSearch $search): void
    {
        try {
            $search->save();
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'name' => [trans('validation.unique', ['attribute' => 'name'])],
            ]);
        }
    }

    /**
     * Normalise the given query string to a consistent format, as would be
     * used by the search system, so that equivalent searches can be matched.
     */
    protected function normaliseQuery(string $query): string
    {
        return SearchOptions::fromString($query)->toString();
    }
}
