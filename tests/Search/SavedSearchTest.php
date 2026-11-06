<?php

namespace Tests\Search;

use BookStack\Search\SavedSearch;
use BookStack\Search\SavedSearchRepo;
use BookStack\Users\Models\User;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    public function test_search_page_shows_save_form_for_current_search()
    {
        $resp = $this->asEditor()->get('/search?term=' . urlencode('cats {type:page}'));

        $html = $this->withHtml($resp);
        $html->assertElementExists('form[method="POST"][action$="/search/saved"] input[name="name"]');
        $html->assertFieldHasValue('query', 'cats {type:page}');
        $html->assertElementContains('h5', 'Saved Searches');
        $html->assertElementContains('p', 'You have no saved searches.');
    }

    public function test_save_form_not_shown_without_a_search_term()
    {
        $resp = $this->asEditor()->get('/search');

        $this->withHtml($resp)->assertElementNotExists('form[action$="/search/saved"]');
    }

    public function test_saving_a_search()
    {
        $editor = $this->users->editor();

        $resp = $this->actingAs($editor)->post('/search/saved', [
            'name' => '  Cat pages  ',
            'query' => '{type:page}   cats [animal=cat]',
        ]);

        $expectedQuery = 'cats [animal=cat] {type:page}';
        $resp->assertRedirect('/search?term=' . urlencode($expectedQuery));
        $resp->assertSessionHas('success', 'Search saved as "Cat pages"');

        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $editor->id,
            'name' => 'Cat pages',
            'query' => $expectedQuery,
        ]);
    }

    public function test_saved_searches_listed_on_search_page_and_current_search_indicated()
    {
        $editor = $this->users->editor();
        $catSearch = SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Cat pages', 'query' => 'cats {type:page}']);
        $dogSearch = SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Dog books', 'query' => 'dogs {type:book}']);

        $resp = $this->actingAs($editor)->get('/search?term=' . urlencode('{type:page} cats'));

        $html = $this->withHtml($resp);
        $html->assertElementContains('.saved-search-list a[href="' . $catSearch->getUrl() . '"]', 'Cat pages');
        $html->assertElementContains('.saved-search-list a[href="' . $dogSearch->getUrl() . '"]', 'Dog books');
        $html->assertElementContains('.saved-search-list a.text-primary', 'Cat pages');
        $html->assertElementNotContains('.saved-search-list a.text-primary', 'Dog books');
        $html->assertElementContains('p', 'Saved as "Cat pages"');
        $html->assertElementNotExists('form[action$="/search/saved"]');
        $html->assertElementExists('form[action$="/search/saved/' . $dogSearch->id . '"] input[name="_method"][value="DELETE"]');
    }

    public function test_saved_searches_of_other_users_are_not_shown()
    {
        $otherSearch = SavedSearch::factory()->create([
            'user_id' => $this->users->admin()->id,
            'name' => 'Admin only search',
        ]);

        $resp = $this->asEditor()->get('/search?term=cats');

        $resp->assertDontSee('Admin only search');
        $this->withHtml($resp)->assertElementNotExists('form[action$="/search/saved/' . $otherSearch->id . '"]');
    }

    public function test_saving_requires_unique_name_per_user()
    {
        $editor = $this->users->editor();
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'My search']);
        SavedSearch::factory()->create(['user_id' => $this->users->admin()->id, 'name' => 'Admin search']);

        $resp = $this->actingAs($editor)->from('/search?term=cats')->post('/search/saved', [
            'name' => 'My search',
            'query' => 'cats',
        ]);
        $resp->assertRedirect('/search?term=cats');
        $resp->assertSessionHasErrors(['name' => 'The name has already been taken.']);

        $resp = $this->post('/search/saved', [
            'name' => 'Admin search',
            'query' => 'cats',
        ]);
        $resp->assertSessionHasNoErrors();
        $this->assertDatabaseHas('saved_searches', ['user_id' => $editor->id, 'name' => 'Admin search']);
    }

    public function test_duplicate_name_constraint_failure_redirects_back_with_name_error()
    {
        $editor = $this->users->editor();
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'My search']);

        // Drop the uniqueness rule to simulate a concurrent save which passed validation
        // before the existing search was written, so the database constraint is what fails.
        $this->app->instance(SavedSearchRepo::class, new class () extends SavedSearchRepo {
            public function validationRules(User $user, ?int $ignoreId = null, bool $required = true): array
            {
                $rules = parent::validationRules($user, $ignoreId, $required);
                $rules['name'] = array_values(array_filter($rules['name'], 'is_string'));

                return $rules;
            }
        });

        $resp = $this->actingAs($editor)->from('/search?term=cats')->post('/search/saved', [
            'name' => 'My search',
            'query' => 'cats',
        ]);

        $resp->assertRedirect('/search?term=cats');
        $resp->assertSessionHasErrors(['name' => 'The name has already been taken.']);
        $this->assertEquals(1, SavedSearch::query()->where('user_id', '=', $editor->id)->count());
    }

    public function test_saving_requires_a_usable_query()
    {
        $resp = $this->asEditor()->from('/search?term=cats')->post('/search/saved', [
            'name' => 'Empty search',
            'query' => '""',
        ]);

        $resp->assertSessionHasErrors(['query' => 'The query must contain at least one search term or filter.']);
        $this->assertDatabaseMissing('saved_searches', ['name' => 'Empty search']);
    }

    public function test_validation_errors_shown_on_save_form()
    {
        $editor = $this->users->editor();
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'My search']);

        $this->actingAs($editor)->from('/search?term=cats')->post('/search/saved', [
            'name' => 'My search',
            'query' => 'cats',
        ]);
        $resp = $this->get('/search?term=cats');

        $this->withHtml($resp)->assertElementContains('.saved-search-form .text-neg', 'The name has already been taken.');
        $this->withHtml($resp)->assertFieldHasValue('name', 'My search');
    }

    public function test_deleting_a_saved_search()
    {
        $editor = $this->users->editor();
        $search = SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Old search']);

        $resp = $this->actingAs($editor)->from('/search?term=cats')->delete("/search/saved/{$search->id}");

        $resp->assertRedirect('/search?term=cats');
        $resp->assertSessionHas('success', 'Saved search "Old search" deleted');
        $this->assertDatabaseMissing('saved_searches', ['id' => $search->id]);
    }

    public function test_cannot_delete_saved_search_of_another_user()
    {
        $search = SavedSearch::factory()->create(['user_id' => $this->users->admin()->id]);

        $resp = $this->asEditor()->delete("/search/saved/{$search->id}");

        $resp->assertNotFound();
        $this->assertDatabaseHas('saved_searches', ['id' => $search->id]);
    }

    public function test_guests_cannot_use_saved_searches()
    {
        $this->setSettings(['app-public' => 'true']);
        $guestSearch = SavedSearch::factory()->create(['user_id' => $this->users->guest()->id]);

        $resp = $this->get('/search?term=cats');
        $resp->assertOk();
        $this->withHtml($resp)->assertElementNotExists('form[action$="/search/saved"]');
        $this->withHtml($resp)->assertElementNotExists('.saved-search-list');

        $resp = $this->post('/search/saved', ['name' => 'Guest search', 'query' => 'cats']);
        $this->assertPermissionError($resp);

        $resp = $this->delete("/search/saved/{$guestSearch->id}");
        $this->assertPermissionError($resp);

        $this->assertDatabaseMissing('saved_searches', ['name' => 'Guest search']);
        $this->assertDatabaseHas('saved_searches', ['id' => $guestSearch->id]);
    }
}
