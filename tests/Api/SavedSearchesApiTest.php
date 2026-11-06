<?php

namespace Tests\Api;

use BookStack\Search\SavedSearch;
use Tests\TestCase;

class SavedSearchesApiTest extends TestCase
{
    use TestsApi;

    protected string $baseEndpoint = '/api/saved-searches';

    public function test_list_only_provides_searches_of_current_user()
    {
        $editor = $this->users->editor();
        $ownSearches = SavedSearch::factory()->count(3)->create(['user_id' => $editor->id]);
        $otherSearch = SavedSearch::factory()->create(['user_id' => $this->users->admin()->id]);

        $resp = $this->actingAsForApi($editor)->getJson($this->baseEndpoint . '?sort=id');

        $resp->assertOk();
        $resp->assertJsonCount(3, 'data');
        $resp->assertJson(['total' => 3]);
        $resp->assertJson(['data' => [
            [
                'id' => $ownSearches[0]->id,
                'name' => $ownSearches[0]->name,
                'query' => $ownSearches[0]->query,
            ],
        ]]);
        $resp->assertJsonMissing(['id' => $otherSearch->id]);
        $resp->assertJsonMissingPath('data.0.user_id');
    }

    public function test_list_can_be_filtered_by_name()
    {
        $editor = $this->users->editor();
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Release notes']);
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Onboarding']);

        $resp = $this->actingAsForApi($editor)->getJson($this->baseEndpoint . '?filter[name:like]=Release%25');

        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonPath('data.0.name', 'Release notes');
    }

    public function test_create()
    {
        $editor = $this->users->editor();
        $details = [
            'name' => 'Open deployment docs',
            'query' => 'deployment {type:page} [status=open]',
        ];

        $resp = $this->actingAsForApi($editor)->postJson($this->baseEndpoint, $details);

        $expectedQuery = 'deployment [status=open] {type:page}';
        $resp->assertOk();
        $resp->assertJson([
            'name' => 'Open deployment docs',
            'query' => $expectedQuery,
            'url' => url('/search?term=' . urlencode($expectedQuery)),
        ]);
        $resp->assertJsonMissingPath('user_id');
        $this->assertDatabaseHas('saved_searches', [
            'id' => $resp->json('id'),
            'user_id' => $editor->id,
            'name' => 'Open deployment docs',
            'query' => $expectedQuery,
        ]);
    }

    public function test_create_validation()
    {
        $editor = $this->users->editor();
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Existing']);
        $this->actingAsForApi($editor);

        $resp = $this->postJson($this->baseEndpoint, []);
        $resp->assertStatus(422);
        $resp->assertJson($this->validationResponse([
            'name' => ['The name field is required.'],
            'query' => ['The query field is required.'],
        ]));

        $resp = $this->postJson($this->baseEndpoint, ['name' => 'Existing', 'query' => '   {}  ']);
        $resp->assertStatus(422);
        $resp->assertJsonPath('error.validation.name', ['The name has already been taken.']);

        $resp = $this->postJson($this->baseEndpoint, ['name' => 'Blank', 'query' => '""']);
        $resp->assertStatus(422);
        $resp->assertJsonPath('error.validation.query', ['The query must contain at least one search term or filter.']);

        $resp = $this->postJson($this->baseEndpoint, ['name' => str_repeat('a', 181), 'query' => 'cats']);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['name'], 'error.validation');
    }

    public function test_create_allows_name_used_by_another_user()
    {
        SavedSearch::factory()->create(['user_id' => $this->users->admin()->id, 'name' => 'Shared name']);

        $resp = $this->actingAsApiEditor()->postJson($this->baseEndpoint, [
            'name' => 'Shared name',
            'query' => 'cats',
        ]);

        $resp->assertOk();
        $this->assertEquals(2, SavedSearch::query()->where('name', '=', 'Shared name')->count());
    }

    public function test_read()
    {
        $editor = $this->users->editor();
        $search = SavedSearch::factory()->create([
            'user_id' => $editor->id,
            'name' => 'Cat pages',
            'query' => 'cats {type:page}',
        ]);

        $resp = $this->actingAsForApi($editor)->getJson("{$this->baseEndpoint}/{$search->id}");

        $resp->assertOk();
        $resp->assertExactJson([
            'id' => $search->id,
            'name' => 'Cat pages',
            'query' => 'cats {type:page}',
            'url' => $search->getUrl(),
            'created_at' => $search->created_at->toJSON(),
            'updated_at' => $search->updated_at->toJSON(),
        ]);
    }

    public function test_update()
    {
        $editor = $this->users->editor();
        $search = SavedSearch::factory()->create([
            'user_id' => $editor->id,
            'name' => 'Cat pages',
            'query' => 'cats {type:page}',
        ]);

        $resp = $this->actingAsForApi($editor)->putJson("{$this->baseEndpoint}/{$search->id}", [
            'query' => '{type:chapter}   cats',
        ]);

        $resp->assertOk();
        $resp->assertJson([
            'id' => $search->id,
            'name' => 'Cat pages',
            'query' => 'cats {type:chapter}',
        ]);

        $resp = $this->putJson("{$this->baseEndpoint}/{$search->id}", ['name' => 'Cat chapters']);
        $resp->assertOk();
        $this->assertDatabaseHas('saved_searches', [
            'id' => $search->id,
            'name' => 'Cat chapters',
            'query' => 'cats {type:chapter}',
        ]);
    }

    public function test_update_name_uniqueness_ignores_own_search()
    {
        $editor = $this->users->editor();
        $search = SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Mine']);
        SavedSearch::factory()->create(['user_id' => $editor->id, 'name' => 'Taken']);
        $this->actingAsForApi($editor);

        $resp = $this->putJson("{$this->baseEndpoint}/{$search->id}", ['name' => 'Mine', 'query' => 'dogs']);
        $resp->assertOk();

        $resp = $this->putJson("{$this->baseEndpoint}/{$search->id}", ['name' => 'Taken']);
        $resp->assertStatus(422);
        $resp->assertJsonPath('error.validation.name', ['The name has already been taken.']);
    }

    public function test_delete()
    {
        $editor = $this->users->editor();
        $search = SavedSearch::factory()->create(['user_id' => $editor->id]);

        $resp = $this->actingAsForApi($editor)->deleteJson("{$this->baseEndpoint}/{$search->id}");

        $resp->assertStatus(204);
        $this->assertDatabaseMissing('saved_searches', ['id' => $search->id]);
    }

    public function test_searches_of_other_users_cannot_be_accessed()
    {
        $search = SavedSearch::factory()->create(['user_id' => $this->users->editor()->id]);
        $this->actingAsApiAdmin();

        $this->getJson("{$this->baseEndpoint}/{$search->id}")->assertNotFound();
        $this->putJson("{$this->baseEndpoint}/{$search->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("{$this->baseEndpoint}/{$search->id}")->assertNotFound();

        $this->assertDatabaseHas('saved_searches', ['id' => $search->id, 'name' => $search->name]);
    }
}
