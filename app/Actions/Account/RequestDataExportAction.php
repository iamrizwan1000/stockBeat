<?php

namespace App\Actions\Account;

use App\Mail\DataExportMail;
use App\Models\Order;
use App\Models\Rule;
use App\Models\StoreConnection;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Compiles a real JSON export of the caller's account + team data and
 * emails it as an attachment (Plan §4.8 "data export request"). Relies on
 * `StoreConnection`'s own `#[Hidden(['credentials'])]` rather than manually
 * selecting columns, so credentials can never leak into an export by a
 * future field being added and forgotten here.
 */
class RequestDataExportAction
{
    public function handle(User $user): void
    {
        $team = $user->currentTeam();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'user' => $user->toArray(),
            'team' => $team?->only(['id', 'name']),
            'team_members' => $team === null
                ? []
                : TeamMember::query()->where('team_id', $team->id)->with('user:id,name,email')->get()->toArray(),
            'store_connections' => $team === null
                ? []
                : StoreConnection::query()->where('team_id', $team->id)->get()->toArray(),
            'orders' => $team === null
                ? []
                : Order::query()->where('team_id', $team->id)->with('items', 'notes')->get()->toArray(),
            'rules' => $team === null
                ? []
                : Rule::query()->where('team_id', $team->id)->get()->toArray(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        Mail::to($user->email)->queue(new DataExportMail($json));
    }
}
