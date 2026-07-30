import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import MarketingLayout from '@/layouts/marketing-layout';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="border-t border-[#D8DAD4] py-8 first:border-t-0 first:pt-0">
            <h2
                className="mb-3 text-lg font-semibold text-[#191C18]"
                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
            >
                {title}
            </h2>
            <div className="space-y-3 text-[15px] leading-relaxed text-[#454843]">{children}</div>
        </section>
    );
}

export default function Terms() {
    return (
        <>
            <Head title="Terms & Conditions — StockBeat" />

            <div className="mb-2 font-mono text-xs font-medium tracking-wide text-[#757872] uppercase">Legal</div>
            <h1
                className="mb-4 text-3xl font-semibold text-[#191C18]"
                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
            >
                Terms &amp; Conditions
            </h1>
            <p className="mb-8 text-sm text-[#757872]">Last updated: [DATE]</p>

            <div className="mb-10 rounded-xl border border-[#D8DAD4] bg-[#F3F4EE] p-4 text-sm text-[#454843]">
                <strong className="text-[#191C18]">Draft — pending legal review.</strong> This page is a
                starting draft written to reflect how StockBeat actually works today. It has not been reviewed
                by a lawyer and should not be treated as final or legally binding until it has. Bracketed items
                like <code className="rounded bg-white px-1 py-0.5">[Company Legal Name]</code> need to be
                filled in with real business details before this is published.
            </div>

            <Section title="1. Acceptance of these terms">
                <p>
                    By creating an account, connecting a store, or otherwise using StockBeat (the
                    &quot;Service&quot;, operated by <strong>[Company Legal Name]</strong>, &quot;we&quot;,
                    &quot;us&quot;), you agree to these Terms &amp; Conditions. If you don&apos;t agree, please
                    don&apos;t use the Service.
                </p>
            </Section>

            <Section title="2. What StockBeat does">
                <p>
                    StockBeat is a multi-channel order-monitoring and notification app for online sellers. You
                    connect one or more of your own store accounts (e.g. Shopify, WooCommerce, eBay, Etsy,
                    Amazon, TikTok Shop), and we read your order, inventory, and review data from those
                    platforms via their official APIs to power notifications, rules, analytics, and the other
                    features described on our site.
                </p>
                <p>
                    We are not a marketplace, payment processor, or fulfillment provider — we don&apos;t take
                    possession of your inventory, process your customers&apos; payments, or represent your
                    store to your customers in any way.
                </p>
            </Section>

            <Section title="3. Accounts and eligibility">
                <p>
                    You must provide accurate information when setting up your account and are responsible for
                    keeping your login credentials and connected-store access secure. You must be legally able
                    to enter into these terms and authorized to connect the store accounts you connect.
                </p>
            </Section>

            <Section title="4. Subscriptions and billing">
                <p>
                    StockBeat is billed entirely through Apple&apos;s App Store or Google Play&apos;s in-app
                    purchase systems — we do not process payments directly and never see or store your payment
                    card details. Subscription pricing, renewal, cancellation, and refund requests are governed
                    by the applicable app store&apos;s own terms and billing policies, not by us directly.
                </p>
                <p>
                    A free trial period, where offered, automatically converts to a paid subscription unless
                    cancelled before it ends, per the app store&apos;s standard subscription behavior.
                </p>
            </Section>

            <Section title="5. Acceptable use">
                <p>You agree not to:</p>
                <ul className="list-disc space-y-1 pl-5">
                    <li>Use the Service to access data you&apos;re not authorized to access;</li>
                    <li>Attempt to interfere with, disrupt, or reverse-engineer the Service;</li>
                    <li>Use the Service for any unlawful purpose or to violate any third party&apos;s rights;</li>
                    <li>
                        Share your account or connected-store credentials with anyone not authorized on your
                        team.
                    </li>
                </ul>
            </Section>

            <Section title="6. Your data and connected stores">
                <p>
                    You retain ownership of your business, order, and store data. Connecting a store grants us
                    only the API access needed to provide the Service, revocable at any time by disconnecting
                    the store from your account or from the platform&apos;s own app-permissions settings. See
                    our <a href="/privacy" className="underline hover:text-[#191C18]">Privacy Policy</a> for
                    details on what we collect and how it&apos;s used.
                </p>
            </Section>

            <Section title="7. Termination">
                <p>
                    You may stop using the Service and delete your account at any time from within the app. We
                    may suspend or terminate accounts that violate these terms, misuse the Service, or pose a
                    security risk to other users.
                </p>
            </Section>

            <Section title="8. Disclaimers and limitation of liability">
                <p>
                    The Service is provided &quot;as is&quot;. Notifications and analytics depend on data and
                    APIs provided by third-party platforms (your connected stores, SMS/push/email providers) —
                    we don&apos;t guarantee uninterrupted delivery or the accuracy of third-party data. To the
                    fullest extent permitted by law, [Company Legal Name] is not liable for indirect,
                    incidental, or consequential damages arising from your use of the Service.
                </p>
            </Section>

            <Section title="9. Changes to these terms">
                <p>
                    We may update these terms from time to time. Material changes will be reflected by an
                    updated &quot;Last updated&quot; date on this page; continued use of the Service after a
                    change means you accept the revised terms.
                </p>
            </Section>

            <Section title="10. Governing law">
                <p>These terms are governed by the laws of [Jurisdiction], without regard to conflict-of-law principles.</p>
            </Section>

            <Section title="11. Contact">
                <p>
                    Questions about these terms? Reach us via our{' '}
                    <a href="/contact" className="underline hover:text-[#191C18]">
                        contact page
                    </a>
                    .
                </p>
            </Section>
        </>
    );
}

Terms.layout = (page: ReactNode) => <MarketingLayout>{page}</MarketingLayout>;
