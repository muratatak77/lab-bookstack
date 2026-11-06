<?php

declare(strict_types=1);

namespace BookStack\Search;

use BookStack\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Saved searches are named search queries which a user has stored for quick re-use.
 * They are personal to each user, so these endpoints will only ever provide and act upon
 * the saved searches of the user that the API token belongs to.
 *
 * The 'query' property takes the same format as the main BookStack search bar, and as used on
 * the search API endpoint. Queries are normalised when saved, so the stored value may differ in
 * format from that provided. Single-item responses (create, read and update) also include a 'url'
 * property which provides a link to run the search within the BookStack interface.
 */
class SavedSearchApiController extends ApiController
{
    public function __construct(
        protected SavedSearchRepo $savedSearchRepo,
    ) {
    }

    protected function rules(?int $searchId = null): array
    {
        $user = user();

        return [
            'create' => $this->savedSearchRepo->validationRules($user),
            'update' => $this->savedSearchRepo->validationRules($user, $searchId, false),
        ];
    }

    /**
     * Get a listing of the saved searches for the current user.
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        $query = $this->savedSearchRepo->queryForUser(user());

        return $this->apiListingResponse($query, [
            'id', 'name', 'query', 'created_at', 'updated_at',
        ]);
    }

    /**
     * Save a new search for the current user.
     * Search names must be unique across the saved searches of the user.
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $input = $this->validate($request, $this->rules()['create']);
        $search = $this->savedSearchRepo->create(user(), $input['name'], $input['query']);

        return response()->json($this->formatForDisplay($search));
    }

    /**
     * Read the details of a single saved search.
     * @return JsonResponse
     */
    public function read(string $id): JsonResponse
    {
        $search = $this->savedSearchRepo->findForUserOrFail(user(), intval($id));

        return response()->json($this->formatForDisplay($search));
    }

    /**
     * Update the name and/or query of a saved search.
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $search = $this->savedSearchRepo->findForUserOrFail(user(), intval($id));
        $input = $this->validate($request, $this->rules($search->id)['update']);

        $search = $this->savedSearchRepo->update($search, $input);

        return response()->json($this->formatForDisplay($search));
    }

    /**
     * Delete a saved search.
     * @return Response
     */
    public function delete(string $id): Response
    {
        $search = $this->savedSearchRepo->findForUserOrFail(user(), intval($id));
        $this->savedSearchRepo->destroy($search);

        return response('', 204);
    }

    protected function formatForDisplay(SavedSearch $search): SavedSearch
    {
        $search->makeHidden(['user_id']);
        $search->setAttribute('url', $search->getUrl());

        return $search;
    }
}
