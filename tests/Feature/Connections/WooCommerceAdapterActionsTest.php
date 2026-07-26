<?php

use App\Models\Review;
use App\Support\Connections\Adapters\WooCommerceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('replyToReview throws since the wc/v3 REST API has no reply mechanism at all', function () {
    $review = Review::factory()->create();

    app(WooCommerceAdapter::class)->replyToReview($review, 'Thanks for the feedback.');
})->throws(LogicException::class);
