{{--
$savedSearches - Collection<SavedSearch>
$currentSavedSearch - ?SavedSearch
--}}
<div class="mb-l">
    <h5>{{ trans('entities.search_saved') }}</h5>
    @if($savedSearches->isEmpty())
        <p class="text-muted text-small mb-none">{{ trans('entities.search_saved_none') }}</p>
    @else
        <ul class="saved-search-list">
            @foreach($savedSearches as $savedSearch)
                <li class="flex-container-row items-center gap-xs">
                    <a href="{{ $savedSearch->getUrl() }}"
                       title="{{ $savedSearch->query }}"
                       class="flex break-text py-xxs @if($currentSavedSearch && $currentSavedSearch->id === $savedSearch->id) text-primary bold @endif">
                        @icon('search') {{ $savedSearch->name }}
                    </a>
                    <form action="{{ url('/search/saved/' . $savedSearch->id) }}" method="POST" class="flex-none">
                        {{ csrf_field() }}
                        {{ method_field('DELETE') }}
                        <button type="submit"
                                class="text-neg text-button icon-button p-xs"
                                aria-label="{{ trans('entities.search_saved_delete_named', ['name' => $savedSearch->name]) }}"
                                title="{{ trans('entities.search_saved_delete_named', ['name' => $savedSearch->name]) }}">@icon('delete')</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</div>
