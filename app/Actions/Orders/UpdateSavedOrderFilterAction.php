<?php

namespace App\Actions\Orders;

use App\Models\SavedOrderFilter;

class UpdateSavedOrderFilterAction
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(SavedOrderFilter $savedOrderFilter, string $name, array $filters): SavedOrderFilter
    {
        $savedOrderFilter->update(['name' => $name, 'filters' => $filters]);

        return $savedOrderFilter;
    }
}
