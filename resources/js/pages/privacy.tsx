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

export default function Privacy() {
    return (
        <>
            <Head title="Privacy Policy — StockBeat" />

            <div className="mb-2 font-mono text-xs font-medium tracking-wide text-[#757872] uppercase">Legal</div>
            <h1
                className="mb-4 text-3xl font-semibold text-[#191C18]"
                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
            >
                Privacy Policy
            </h1>
            <p className="mb-8 text-sm text-[#757872]">Last updated: [DATE]</p>

            <div className="mb-10 rounded-xl border border-[#D8DAD4] bg-[#F3F4EE] p-4 text-sm text-[#454843]">
                <strong className="text-[#191C18]">Draft — pending legal review.</strong> This page is a
                starting draft written to reflect what StockBeat actually collects and does today. It has not
                been reviewed by a lawyer and should not be treated as final until it has, particularly for
                which data-protection regime(s) (GDPR, CCPA, etc.) actually apply to your business and users.
            </div>

            <Section title="1. What we collect">
                <p>
                    <strong className="text-[#191C18]">Account &amp; profile:</strong> name, email, business
                    name, base currency, timezone, and phone number (only if you provide one, used solely to
                    deliver SMS alerts you&apos;ve configured).
                </p>
                <p>
                    <strong className="text-[#191C18]">Connected store data:</strong> when you connect a store
                    (Shopify, WooCommerce, eBay, Etsy, Amazon, TikTok Shop), we access order, inventory, and
                    review data via that platform&apos;s official API, using an access token scoped to what the
                    Service needs. We don&apos;t request access to your customers&apos; payment details.
                </p>
                <p>
                    <strong className="text-[#191C18]">Device &amp; usage data:</strong> push-notification
                    device tokens, sign-in activity, and basic usage/diagnostic data to keep the Service
                    working and secure.
                </p>
                <p>
                    <strong className="text-[#191C18]">Contact &amp; newsletter:</strong> if you use our contact
                    form or subscribe to our newsletter, we store the name/email and message you provide.
                </p>
            </Section>

            <Section title="2. How we use it">
                <ul className="list-disc space-y-1 pl-5">
                    <li>To provide the Service — order feeds, notifications, rules, and analytics you configure;</li>
                    <li>To send transactional messages (order alerts, account/billing notices, support replies);</li>
                    <li>To send marketing/newsletter emails, only if you&apos;ve opted in, with an unsubscribe link in every send;</li>
                    <li>To maintain security, prevent abuse, and comply with legal obligations.</li>
                </ul>
                <p>We do not sell your personal data or your store/order data to third parties.</p>
            </Section>

            <Section title="3. Who we share it with">
                <p>We share data only with the service providers that help us run StockBeat, each acting under their own privacy/security terms:</p>
                <ul className="list-disc space-y-1 pl-5">
                    <li><strong className="text-[#191C18]">Apple / Google</strong> — subscription billing (in-app purchases), we never see your card details;</li>
                    <li><strong className="text-[#191C18]">RevenueCat</strong> — subscription/entitlement management on top of Apple &amp; Google billing;</li>
                    <li><strong className="text-[#191C18]">Twilio</strong> — SMS delivery, if you&apos;ve enabled SMS alerts and provided a phone number;</li>
                    <li><strong className="text-[#191C18]">Firebase Cloud Messaging</strong> — push-notification delivery to your devices;</li>
                    <li><strong className="text-[#191C18]">Resend</strong> — transactional and, where opted in, marketing email delivery;</li>
                    <li>
                        <strong className="text-[#191C18]">Your connected store platforms</strong> (Shopify,
                        WooCommerce host, eBay, Etsy, Amazon, TikTok Shop) — we read data from them via their
                        APIs; we don&apos;t send your customers&apos; data anywhere beyond what those platforms
                        already have.
                    </li>
                </ul>
            </Section>

            <Section title="4. Your rights and choices">
                <p>
                    From inside the app, you can request a full export of your data or request account
                    deletion at any time — deletion is processed as a scheduled removal after a short grace
                    period, giving you a window to change your mind before data is permanently purged.
                </p>
                <p>
                    You can disconnect any connected store at any time, which revokes our API access to it. You
                    can opt out of marketing emails via the unsubscribe link in any marketing email, and manage
                    push/email/SMS notification preferences from within the app&apos;s settings.
                </p>
            </Section>

            <Section title="5. Data retention">
                <p>
                    We retain your data for as long as your account is active. Order/notification history is
                    kept per your plan&apos;s history window; on account deletion, data is retained only through
                    the grace period described above before being permanently removed, except where we&apos;re
                    legally required to retain records longer (e.g. billing records).
                </p>
            </Section>

            <Section title="6. Children's privacy">
                <p>StockBeat is a business tool intended for adult sellers and is not directed at children. We do not knowingly collect data from anyone under 16.</p>
            </Section>

            <Section title="7. Changes to this policy">
                <p>We may update this policy from time to time; material changes will be reflected by an updated &quot;Last updated&quot; date above.</p>
            </Section>

            <Section title="8. Contact">
                <p>
                    Questions about this policy or a data request? Reach us via our{' '}
                    <a href="/contact" className="underline hover:text-[#191C18]">
                        contact page
                    </a>
                    .
                </p>
            </Section>
        </>
    );
}

Privacy.layout = (page: ReactNode) => <MarketingLayout>{page}</MarketingLayout>;
