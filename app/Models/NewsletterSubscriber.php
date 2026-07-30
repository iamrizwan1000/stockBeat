<?php

namespace App\Models;

use Database\Factories\NewsletterSubscriberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A public-website newsletter signup — deliberately separate from `User`
 * (a visitor subscribing has no account at all) and from `marketing_opt_in`
 * (that column only ever governs marketing email to existing app users).
 *
 * @property int $id
 * @property string $email
 * @property string $unsubscribe_token
 * @property Carbon $subscribed_at
 * @property Carbon|null $unsubscribed_at
 */
#[Fillable(['email', 'unsubscribe_token', 'subscribed_at', 'unsubscribed_at'])]
class NewsletterSubscriber extends Model
{
    /** @use HasFactory<NewsletterSubscriberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}
