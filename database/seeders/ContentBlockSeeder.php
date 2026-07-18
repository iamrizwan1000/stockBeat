<?php

namespace Database\Seeders;

use App\Models\ContentBlock;
use Illuminate\Database\Seeder;

/**
 * Seeds the paywall & store-listing copy blocks quoted verbatim from Plan
 * §5.1 ("Customer-facing plan presentation") so the `content_blocks` table
 * isn't empty on a fresh install — an admin can edit any of these afterward
 * without an app release (Plan §8.7.3).
 */
class ContentBlockSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->blocks() as $key => [$title, $body]) {
            ContentBlock::query()->updateOrCreate(
                ['key' => $key, 'locale' => 'en'],
                ['title' => $title, 'body' => $body, 'active' => true],
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function blocks(): array
    {
        return [
            'paywall_free_headline' => ['Free — headline', 'Free'],
            'paywall_free_body' => ['Free — feature bullets', implode("\n", [
                '1 connected store (any platform)',
                'All your orders in one live feed',
                'New-order push alerts + daily summary',
                '25 email alerts /month',
                'Quick actions: fulfill, track, refund from your phone',
                'Last 7 days of orders',
            ])],

            'paywall_starter_headline' => ['Starter — headline', 'Starter — $5.99/month'],
            'paywall_starter_body' => ['Starter — feature bullets', implode("\n", [
                'Up to 3 connected stores',
                '5 custom alert rules (new order, high-value, unfulfilled, ship-by-deadline, cancelled, refunded, payment failed, low stock, negative review, digest)',
                '20 SMS + 250 email alerts /month',
                'Today + 7-day analytics',
                'Last 30 days of orders',
            ])],

            'paywall_pro_headline' => ['Pro — headline', 'Pro — $17.99/month'],
            'paywall_pro_subheadline' => ['Pro — intro offer note', 'first month intro offer'],
            'paywall_pro_body' => ['Pro — feature bullets', implode("\n", [
                'Up to 10 connected stores across Shopify, WooCommerce, eBay, Etsy & Amazon',
                'Unlimited custom alert rules',
                '100 SMS + 1,000 email alerts /month (top-ups available in-app)',
                'Custom notification sounds per rule',
                'Unified customer inbox — reply to any marketplace from one screen',
                '3 team members with per-person alert routing',
                'Full analytics + home-screen widget + 1 year of history',
                '7-day free trial, no card required',
            ])],

            'paywall_pro_yearly_headline' => ['Pro Yearly — headline', 'Pro Yearly — $172.99/year'],
            'paywall_pro_yearly_subheadline' => ['Pro Yearly — savings note', '(save 20%)'],
            'paywall_pro_yearly_body' => ['Pro Yearly — feature bullets', 'Everything in Pro, one payment'],

            'paywall_premium_headline' => ['Premium — headline', 'Premium — $44.99/month'],
            'paywall_premium_body' => ['Premium — feature bullets', implode("\n", [
                'Unlimited connected stores',
                'Everything in Pro, plus order spike & refund spike alerts — know the moment volume looks abnormal, not just order-by-order',
                '500 SMS + 5,000 email alerts /month',
                '10 team members',
                'Full analytics + multi-currency consolidation, unlimited history',
                'Priority email + phone/chat support',
            ])],

            'paywall_premium_yearly_headline' => ['Premium Yearly — headline', 'Premium Yearly — $429.99/year'],
            'paywall_premium_yearly_subheadline' => ['Premium Yearly — savings note', '(save 20%)'],
            'paywall_premium_yearly_body' => ['Premium Yearly — feature bullets', 'Everything in Premium, one payment'],

            'paywall_intro_note' => ['Paywall footnote', 'Introductory-offer pricing is a StoreKit/Play introductory offer on the relevant monthly product. Email alerts now carry a numeric quota at every paid tier (admin-tunable) so every channel has a visible number — quota consumption shown in Settings with an upsell at 80%.'],
        ];
    }
}
