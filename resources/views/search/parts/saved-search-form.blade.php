{{--
$searchTerm - string
$currentSavedSearch - ?SavedSearch
--}}
@if($currentSavedSearch)
    <p class="text-muted text-small flex-container-row items-center gap-xs mb-m">
        @icon('bookmark')
        <span>{{ trans('entities.search_saved_as', ['name' => $currentSavedSearch->name]) }}</span>
    </p>
@else
    <form action="{{ url('/search/saved') }}" method="POST" class="saved-search-form mb-m">
        {{ csrf_field() }}
        <input type="hidden" name="query" value="{{ $searchTerm }}">
        <div class="flex-container-row items-center gap-s wrap">
            <label for="saved-search-name" class="text-small text-muted">{{ trans('entities.search_saved_save') }}</label>
            <input type="text"
                   id="saved-search-name"
                   name="name"
                   maxlength="180"
                   required
                   value="{{ old('name') }}"
                   placeholder="{{ trans('entities.search_saved_name_placeholder') }}"
                   class="input-base flex @if($errors->has('name')) text-neg @endif">
            <button type="submit" class="button outline small">@icon('bookmark'){{ trans('common.save') }}</button>
        </div>
        @include('form.errors', ['name' => 'name'])
        @include('form.errors', ['name' => 'query'])
    </form>
@endif
