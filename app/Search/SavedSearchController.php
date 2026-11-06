<?php

namespace BookStack\Search;

use BookStack\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function __construct(
        protected SavedSearchRepo $savedSearchRepo,
    ) {
    }

    /**
     * Save a new search for the current user.
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $this->preventGuestAccess();
        $user = user();

        $input = $this->validate($request, $this->savedSearchRepo->validationRules($user));
        $search = $this->savedSearchRepo->create($user, $input['name'], $input['query']);

        $this->showSuccessNotification(trans('entities.search_saved_create_success', ['name' => $search->name]));

        return redirect($search->getUrl());
    }

    /**
     * Delete a saved search belonging to the current user.
     * @return RedirectResponse
     */
    public function destroy(int $id): RedirectResponse
    {
        $this->preventGuestAccess();

        $search = $this->savedSearchRepo->findForUserOrFail(user(), $id);
        $this->savedSearchRepo->destroy($search);

        $this->showSuccessNotification(trans('entities.search_saved_delete_success', ['name' => $search->name]));

        return redirect()->back(302, [], url('/search'));
    }
}
