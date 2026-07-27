<?php

namespace App\Actions\Orders;

use App\Models\SavedOrderFilter;
use App\Models\Team;

class CreateSavedOrderFilterAction
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(Team $team, string $name, array $filters): SavedOrderFilter
    {
        return SavedOrderFilter::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'filters' => $filters,
        ]);
    }
}
