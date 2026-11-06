<?php

namespace BookStack\Search;

use BookStack\App\Model;
use BookStack\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A search query that a user has saved, by name, for quick re-use.
 * Saved searches are personal and are only ever visible to the user that created them.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $name
 * @property string $query
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SavedSearch extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'query'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the URL to run this search within the main search interface.
     * @return string
     */
    public function getUrl(): string
    {
        return url('/search?term=' . urlencode($this->query));
    }

    /**
     * Check if this saved search represents the given search options.
     * @return bool
     */
    public function matchesOptions(SearchOptions $options): bool
    {
        return SearchOptions::fromString($this->query)->toString() === $options->toString();
    }
}
