<?php

namespace App\Actions\Orders;

use App\Models\SavedOrderFilter;

class DeleteSavedOrderFilterAction
{
    public function handle(SavedOrderFilter $savedOrderFilter): void
    {
        $savedOrderFilter->delete();
    }
}
