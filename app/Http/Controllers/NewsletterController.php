<?php

namespace App\Http\Controllers;

use App\Actions\Marketing\SubscribeToNewsletterAction;
use App\Http\Requests\SubscribeToNewsletterRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('newsletter');
    }

    public function store(SubscribeToNewsletterRequest $request, SubscribeToNewsletterAction $action): RedirectResponse
    {
        $action->handle($request->string('email')->toString());

        return back()->with('status', "You're subscribed — check your inbox to confirm.");
    }

    public function unsubscribe(string $token): View
    {
        $subscriber = NewsletterSubscriber::query()->where('unsubscribe_token', $token)->firstOrFail();

        if ($subscriber->unsubscribed_at === null) {
            $subscriber->update(['unsubscribed_at' => now()]);
        }

        return view('newsletter.unsubscribed');
    }
}
