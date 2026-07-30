<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ListNewsletterSubscribersAction;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NewsletterSubscriberController extends Controller
{
    private const FILTER_KEYS = ['q', 'status'];

    public function index(Request $request, ListNewsletterSubscribersAction $action): Response
    {
        $filters = $request->only(self::FILTER_KEYS);
        $subscribers = $action->handle($filters);

        return Inertia::render('admin/newsletter-subscribers/index', [
            'filters' => $filters,
            'subscribers' => [
                'data' => collect($subscribers->items())->map(fn (NewsletterSubscriber $s) => $this->summarize($s))->all(),
                'current_page' => $subscribers->currentPage(),
                'last_page' => $subscribers->lastPage(),
                'total' => $subscribers->total(),
            ],
            'active_count' => NewsletterSubscriber::query()->whereNull('unsubscribed_at')->count(),
        ]);
    }

    public function exportCsv(Request $request, ListNewsletterSubscribersAction $action): StreamedResponse
    {
        $filters = $request->only(self::FILTER_KEYS);
        $subscribers = $action->all($filters);

        return response()->streamDownload(function () use ($subscribers) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['Email', 'Status', 'Subscribed at', 'Unsubscribed at']);

            foreach ($subscribers as $subscriber) {
                /** @var NewsletterSubscriber $subscriber */
                fputcsv($handle, [
                    $subscriber->email,
                    $subscriber->unsubscribed_at === null ? 'subscribed' : 'unsubscribed',
                    $subscriber->subscribed_at?->toDateTimeString(),
                    $subscriber->unsubscribed_at?->toDateTimeString(),
                ]);
            }

            fclose($handle);
        }, 'newsletter-subscribers.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(NewsletterSubscriber $subscriber): array
    {
        return [
            'id' => $subscriber->id,
            'email' => $subscriber->email,
            'subscribed_at' => $subscriber->subscribed_at,
            'unsubscribed_at' => $subscriber->unsubscribed_at,
        ];
    }
}
