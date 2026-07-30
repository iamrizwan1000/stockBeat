<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\SubmitContactMessageAction;
use App\Http\Requests\SubmitContactMessageRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('contact');
    }

    public function store(SubmitContactMessageRequest $request, SubmitContactMessageAction $action): RedirectResponse
    {
        $action->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->filled('subject') ? $request->string('subject')->toString() : null,
            $request->string('message')->toString(),
        );

        return back()->with('status', "Thanks — we'll get back to you by email.");
    }
}
