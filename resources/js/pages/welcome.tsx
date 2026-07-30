import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/* Graphite Precision — this page's design system (see StockBeatApp's  */
/* DESIGN.md). Flat, high-contrast, industrial-elegant: near-black     */
/* "Graphite" as the primary anchor, "Signal Lime" reserved exclusively */
/* for active states / focus / functional highlights, no gradients or  */
/* drop shadows — depth comes from tonal layering and 1px borders.     */
/* ------------------------------------------------------------------ */

function useReveal<T extends HTMLElement>() {
    const ref = useRef<T | null>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' },
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    return { ref, visible };
}

function Reveal({
    children,
    className = '',
    delay = 0,
}: {
    children: ReactNode;
    className?: string;
    delay?: number;
}) {
    const { ref, visible } = useReveal<HTMLDivElement>();

    return (
        <div
            ref={ref}
            className={`transition-all duration-700 ease-out ${visible ? 'translate-y-0 opacity-100' : 'translate-y-8 opacity-0'} ${className}`}
            style={{ transitionDelay: `${delay}ms` }}
        >
            {children}
        </div>
    );
}

function useScrolled(threshold = 12) {
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > threshold);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, [threshold]);

    return scrolled;
}

/* --------------------------- Glyphs --------------------------- */

function AppleGlyph() {
    return (
        <svg viewBox="0 0 24 24" className="h-6 w-6" fill="currentColor">
            <path d="M16.365 1.43c0 1.14-.417 2.06-1.25 2.86-.916.83-1.994 1.29-3.14 1.2-.037-1.13.42-2.13 1.25-2.9C14.13 1.72 15.16 1.28 16.365 1.43ZM20.36 17.24c-.44 1.02-.98 1.98-1.63 2.87-.9 1.26-2.02 2.83-3.5 2.85-1.32.02-1.66-.86-3.46-.85-1.8.01-2.18.87-3.5.85-1.48-.02-2.53-1.44-3.44-2.7C2.36 17.7.85 13.4 2.35 10.4c.94-1.9 2.62-3.1 4.46-3.13 1.32-.02 2.16.9 3.44.9 1.24 0 1.9-.9 3.5-.87 1.68.03 3.15 1.02 4.08 2.6-3.58 2.14-3.02 6.85 1.53 7.34-.34.7-.72 1.37-1 2.0Z" />
        </svg>
    );
}

function AndroidGlyph() {
    return (
        <svg viewBox="0 0 24 24" className="h-6 w-6" fill="currentColor">
            <path d="M17.523 15.34a1.09 1.09 0 1 1-.001-2.18 1.09 1.09 0 0 1 .001 2.18Zm-11.046 0a1.09 1.09 0 1 1 0-2.18 1.09 1.09 0 0 1 0 2.18Zm11.36-6.03 1.9-3.3a.4.4 0 0 0-.7-.4l-1.93 3.33a8.8 8.8 0 0 0-7.2 0L7.97 5.6a.4.4 0 0 0-.7.4l1.9 3.3C6.03 10.9 4 13.98 4 17.5h16c0-3.52-2.03-6.6-5.16-8.2Z" />
        </svg>
    );
}

function BellGlyph() {
    return (
        <svg
            viewBox="0 0 20 20"
            className="h-4 w-4"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <path
                d="M10 2.5c-2.2 0-4 1.8-4 4v2.2c0 .8-.3 1.6-.9 2.2l-.6.6c-.6.6-.2 1.6.6 1.6h9.8c.8 0 1.2-1 .6-1.6l-.6-.6c-.6-.6-.9-1.4-.9-2.2V6.5c0-2.2-1.8-4-4-4Z"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path d="M8.2 15.8a1.8 1.8 0 0 0 3.6 0" strokeLinecap="round" />
        </svg>
    );
}

function MailGlyph() {
    return (
        <svg
            viewBox="0 0 20 20"
            className="h-4 w-4"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <rect x="2.5" y="4.5" width="15" height="11" rx="2" />
            <path
                d="M3.5 6 10 11l6.5-5"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function ChatGlyph() {
    return (
        <svg
            viewBox="0 0 20 20"
            className="h-4 w-4"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <path
                d="M3 5.5A2 2 0 0 1 5 3.5h10a2 2 0 0 1 2 2V12a2 2 0 0 1-2 2H8.5L5 17v-3H5a2 2 0 0 1-2-2V5.5Z"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function OtpGlyph() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <rect x="3" y="5" width="18" height="14" rx="2.5" />
            <path d="M3 9.5h18" strokeLinecap="round" />
            <path
                d="M7 14h.01M11 14h.01M15 14h.01"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function PlugGlyph() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <path
                d="M9 3v4M15 3v4M6 7h12l-1 5a5 5 0 0 1-10 0L6 7Z"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path d="M12 16v5" strokeLinecap="round" />
        </svg>
    );
}

function BoltGlyph() {
    return (
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor">
            <path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" />
        </svg>
    );
}

function HandTapGlyph() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
        >
            <path d="M9 12V6a1.5 1.5 0 0 1 3 0v5" strokeLinecap="round" />
            <path d="M12 11V4.5a1.5 1.5 0 0 1 3 0V11" strokeLinecap="round" />
            <path
                d="M15 11.5V6a1.5 1.5 0 0 1 3 0v9c0 3.5-2.5 6.5-6.5 6.5S5 18 5 15v-3.5a1.5 1.5 0 0 1 3-.2l.5 2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/* --------------------------- Content data --------------------------- */

const NOTIFICATION_CHANNELS = [
    {
        channel: 'Push',
        icon: <BellGlyph />,
        title: 'StockBeat',
        subtitle: 'High-value order',
        body: 'Order #1042 · $212.50 just came in from Shopify.',
        time: 'now',
    },
    {
        channel: 'Email',
        icon: <MailGlyph />,
        title: 'StockBeat Alerts',
        subtitle: 'New order needs your attention',
        body: '"High-value order" rule fired for order #1042 ($212.50) on Shopify.',
        time: '2m ago',
    },
    {
        channel: 'SMS',
        icon: <ChatGlyph />,
        title: 'StockBeat',
        subtitle: null,
        body: 'Order #1042 ($212.50) just came in. Tap to view →',
        time: 'now',
    },
];

function NotificationDemo() {
    const [step, setStep] = useState(0);
    const totalSteps = NOTIFICATION_CHANNELS.length + 1;

    useEffect(() => {
        const id = setInterval(
            () => setStep((s) => (s + 1) % totalSteps),
            1300,
        );

        return () => clearInterval(id);
    }, [totalSteps]);

    const revealed = Math.min(step, NOTIFICATION_CHANNELS.length);

    return (
        <div className="relative mx-auto max-w-md rounded-xl border border-[#D8DAD4] bg-white p-6">
            <div className="mb-5 flex items-center justify-between">
                <span className="font-mono text-[11px] font-medium tracking-widest text-[#757872] uppercase">
                    One rule fires
                </span>
                <span className="flex items-center gap-1.5 rounded-full bg-[#EFF8D5] px-2.5 py-0.5 font-mono text-[11px] font-medium text-[#3A4D00]">
                    <span className="h-1.5 w-1.5 rounded-full bg-[#4E6700]" />
                    Live
                </span>
            </div>

            <div className="space-y-3">
                {NOTIFICATION_CHANNELS.map((item, i) => {
                    const shown = i < revealed;

                    return (
                        <div
                            key={item.channel}
                            className={`flex items-start gap-3 rounded-lg border border-[#D8DAD4] bg-white p-3.5 transition-all duration-500 ease-out ${
                                shown
                                    ? 'translate-y-0 opacity-100'
                                    : 'pointer-events-none -translate-y-3 opacity-0'
                            }`}
                        >
                            <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-[#191C18] text-white">
                                {item.icon}
                            </span>
                            <div className="min-w-0 flex-1 text-left">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-semibold text-[#191C18]">
                                        {item.title}
                                    </span>
                                    <span className="flex-shrink-0 font-mono text-[10px] text-[#757872]">
                                        {item.time}
                                    </span>
                                </div>
                                {item.subtitle && (
                                    <div className="text-xs font-medium text-[#454843]">
                                        {item.subtitle}
                                    </div>
                                )}
                                <p className="mt-0.5 text-xs leading-snug text-[#454843]">
                                    {item.body}
                                </p>
                            </div>
                            <span className="mt-0.5 flex-shrink-0 font-mono text-[10px] font-semibold tracking-wide text-[#757872] uppercase">
                                {item.channel}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

const PLATFORMS = [
    { name: 'Shopify', live: true },
    { name: 'WooCommerce', live: false },
    { name: 'eBay', live: false },
    { name: 'Etsy', live: false },
    { name: 'Amazon', live: false },
    { name: 'TikTok Shop', live: false },
];

// The animated "how it works" walkthrough — auth → connect a store →
// orders/rules fire → act — the exact journey a new seller takes.
const JOURNEY = [
    {
        step: '01',
        icon: <OtpGlyph />,
        title: 'Sign in, no password',
        body: 'Email in, a one-time code out. No password to forget, no account to set up beyond that.',
    },
    {
        step: '02',
        icon: <PlugGlyph />,
        title: 'Connect your stores',
        body: 'Shopify is live today — link as many stores as your plan allows in under a minute. WooCommerce, eBay, Etsy, Amazon, and TikTok Shop are coming soon.',
    },
    {
        step: '03',
        icon: <BoltGlyph />,
        title: 'Orders flow in, rules fire',
        body: 'Every order lands in one feed the instant it happens. Your rules watch for what matters and reach you by push, email, or SMS.',
    },
    {
        step: '04',
        icon: <HandTapGlyph />,
        title: 'Act from your phone',
        body: 'Fulfill, refund, cancel, tag — one order or a whole batch — without ever opening a laptop.',
    },
];

const FEATURES = [
    {
        eyebrow: 'The dashboard',
        title: 'One feed. Every channel.',
        body: 'Shopify, WooCommerce, eBay, Etsy, Amazon, and TikTok Shop orders land in a single reverse-chronological feed — channel icon, customer, total in your base currency, fulfillment and payment status, a full chronological event timeline per order, all at a glance.',
        points: [
            'Real-time via webhooks where supported, polling everywhere else',
            'Ship-by deadline countdowns for eBay/Etsy/Amazon SLAs',
            'Saved filters, snooze, and global search across order #, customer, SKU',
        ],
    },
    {
        eyebrow: 'The core differentiator',
        title: 'Rules that actually know your business.',
        body: 'Compose rules in plain terms — WHEN a trigger fires, IF conditions match, THEN act. Fifteen triggers, from new orders to order/refund spikes, low stock, stale inventory, and review ratings — with AND/OR condition groups on total, SKU, country, shipping state, repeat-buyer status, and customer order count.',
        points: [
            'Push, email, or SMS — with a custom sound and priority per rule',
            'Quiet hours, cooldowns, and a one-tap test-fire',
            'Daily, weekly, or monthly digests — plus a full execution log',
        ],
    },
    {
        eyebrow: 'From your phone',
        title: 'Act without opening a laptop.',
        body: 'Every order supports the actions that actually move your day forward — mark fulfilled with tracking, issue a full or partial refund, cancel, tag, add an internal note, or message the customer. Batch it across dozens of orders at once when volume picks up.',
        points: [
            'Bulk cancel and tag, with a per-order result — never all-or-nothing',
            'Packing slips and priced invoices, generated server-side and shared instantly',
            "Every action logged to that order's own timeline, permanently",
        ],
    },
    {
        eyebrow: 'Business overview',
        title: 'Know where you stand, today.',
        body: 'Today, 7-day, and 30-day revenue, order count, and average order value — total and per channel — plus goal tracking against your best month and a monthly business report. A morning digest tells you what happened while you slept.',
        points: [
            'Per-channel comparison — "Etsy up 30% this week"',
            'Top products by units and revenue',
            "Home-screen widget for today's numbers at a glance",
        ],
    },
    {
        eyebrow: 'Stay stocked',
        title: 'Inventory that alerts itself.',
        body: 'Push real stock corrections straight to Shopify or WooCommerce, get notified the moment something drops below threshold, and catch dead stock before it becomes a write-off.',
        points: [
            'Low-stock and stale-inventory alerts, threshold and day-count both yours to set',
            'Real stock snapshots — see exactly how inventory trended over time',
            'Cost price tracking, so profit math is never a guess',
        ],
    },
    {
        eyebrow: 'Know your customers',
        title: 'Every buyer, one place.',
        body: 'A customer list grouped from your own order history — order count, total spent, last order date — with one tap into their full purchase history. Real payout reconciliation and review replies live right alongside it.',
        points: [
            'Payout tracking — what actually landed in your bank account',
            'Reply to reviews and negative feedback without leaving the app',
            'Repeat-buyer and order-milestone rule conditions, built on the same data',
        ],
    },
    {
        eyebrow: 'Ask anything',
        title: 'An AI Assistant that knows your data.',
        body: 'Ask it about your sales, your inventory, or your restock timing — or hand it a plain-English sentence and let it draft a rule for you to review before it ever saves.',
        points: [
            'Real answers from your real orders — never a guess dressed up as one',
            'Natural-language rule builder: describe it, review it, save it',
            'Profit summaries and restock recommendations, grounded in real sales velocity',
        ],
    },
    {
        eyebrow: 'Built for teams',
        title: 'Bring your whole team in.',
        body: 'Invite teammates with real roles — owner, manager, agent, viewer — and route specific alerts to specific people, with per-member store visibility when you need it.',
        points: [
            'Per-rule "notify a specific teammate" targeting',
            'Store-visibility restrictions for limited-access roles',
            'A shared, permission-aware view of everything above',
        ],
    },
];

const EVERY_PLAN_FEATURES = [
    'Unified order feed & search',
    'Quick actions — fulfill, track, refund',
    'Invoices & packing slips',
    'Order timeline',
    'Saved filters',
    'Bulk cost-price editing',
    'Inventory & customer view',
];

const PLANS = [
    {
        name: 'Free',
        price: '$0',
        cadence: '',
        highlight: false,
        features: [
            '1 connected store',
            'New-order push + daily digest',
            '25 email alerts/mo',
            '7 days of history',
        ],
    },
    {
        name: 'Starter',
        price: '$5.99',
        cadence: '/mo',
        highlight: false,
        features: [
            'Up to 3 stores',
            '5 custom rules — low stock, reviews, back-in-stock & more',
            'Notification priority per rule',
            'Bulk tag/cancel orders',
            '20 SMS + 250 email/mo · 30 days of history',
            'AI Assistant — 30 questions/mo',
        ],
    },
    {
        name: 'Pro',
        price: '$17.99',
        cadence: '/mo',
        highlight: true,
        features: [
            'Up to 10 stores',
            'Unlimited custom rules',
            'Unified inbox, payouts & review replies',
            'Full analytics + monthly business report',
            '100 SMS + 1,000 email/mo · 3 team seats',
            'AI Assistant — 150 questions/mo + rule builder',
            '7-day free trial',
        ],
    },
    {
        name: 'Premium',
        price: '$44.99',
        cadence: '/mo',
        highlight: false,
        features: [
            'Unlimited stores',
            'Order & refund spike alerts',
            '500 SMS + 5,000 email/mo · 10 team seats',
            'AI Assistant — 500 questions/mo + proactive insights',
            'Priority support',
        ],
    },
];

const TRUST_POINTS = [
    {
        title: 'Encrypted at rest',
        body: 'Every platform credential is encrypted in the database and never exposed in the admin panel.',
    },
    {
        title: 'Signed webhooks',
        body: 'Every inbound webhook is signature-verified before it touches your data.',
    },
    {
        title: 'GDPR-ready',
        body: 'Built-in data export and account deletion flows — your data, your control.',
    },
];

function AppBadge({
    icon,
    label,
    sublabel,
}: {
    icon: ReactNode;
    label: string;
    sublabel: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-[#D8DAD4] bg-white px-5 py-3 transition hover:border-[#191C18]">
            <span className="text-[#191C18]">{icon}</span>
            <div className="text-left leading-tight">
                <div className="font-mono text-[11px] tracking-wide text-[#757872] uppercase">
                    {sublabel}
                </div>
                <div className="text-sm font-semibold text-[#191C18]">
                    {label}
                </div>
            </div>
        </div>
    );
}

export default function Welcome() {
    const scrolled = useScrolled();

    return (
        <>
            <Head title="StockBeat — Multi-channel order monitoring, mission control for sellers">
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link
                    rel="preconnect"
                    href="https://fonts.gstatic.com"
                    crossOrigin="anonymous"
                />
                <link
                    href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap"
                    rel="stylesheet"
                />
            </Head>

            <div
                className="relative min-h-screen overflow-x-hidden bg-[#F8FAF3] text-[#191C18] selection:bg-[#EFF8D5]"
                style={{
                    fontFamily: "'Inter', ui-sans-serif, system-ui, sans-serif",
                }}
            >
                <nav
                    className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${
                        scrolled
                            ? 'border-b border-[#D8DAD4] bg-[#F8FAF3]/90 backdrop-blur'
                            : 'bg-transparent'
                    }`}
                >
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <div className="flex items-center gap-2.5">
                            <img
                                src="/assets/logo1.png"
                                alt="StockBeat"
                                className="h-8 w-8 rounded object-cover"
                            />
                            <span
                                className="text-base font-semibold tracking-tight"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                StockBeat
                            </span>
                        </div>
                        <div className="hidden items-center gap-8 text-sm font-medium text-[#454843] sm:flex">
                            <a
                                href="#features"
                                className="transition hover:text-[#191C18]"
                            >
                                Features
                            </a>
                            <a
                                href="#pricing"
                                className="transition hover:text-[#191C18]"
                            >
                                Pricing
                            </a>
                            <a
                                href="#security"
                                className="transition hover:text-[#191C18]"
                            >
                                Security
                            </a>
                        </div>
                        <Link
                            href="/admin/login"
                            className="rounded-xl border border-[#D8DAD4] bg-white px-4 py-2 text-sm font-medium text-[#252824] transition hover:border-[#191C18]"
                        >
                            Admin sign in
                        </Link>
                    </div>
                </nav>

                {/* Hero */}
                <section className="relative pt-40 pb-24">
                    <div className="relative mx-auto max-w-5xl px-6 text-center">
                        <Reveal>
                            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-[#D8DAD4] bg-white px-4 py-1.5 font-mono text-xs font-medium text-[#454843]">
                                <span className="h-1.5 w-1.5 rounded-full bg-[#4E6700]" />
                                Mission control for multi-channel sellers
                            </div>
                        </Reveal>

                        <Reveal delay={80}>
                            <h1
                                className="mx-auto max-w-3xl text-5xl leading-[1.08] font-semibold tracking-tight text-[#191C18] sm:text-6xl"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Every order.
                                <br />
                                <span className="relative inline-block">
                                    Instantly known.
                                    <span className="absolute inset-x-0 -bottom-1 -z-10 h-3 bg-[#C3F341]" />
                                </span>
                            </h1>
                        </Reveal>

                        <Reveal delay={160}>
                            <p className="mx-auto mt-6 max-w-xl text-lg text-[#454843]">
                                StockBeat aggregates every store into one feed,
                                alerts you the moment something matters, and
                                lets you act without opening a laptop.
                            </p>
                        </Reveal>

                        <Reveal delay={240}>
                            <div className="mt-10 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                                <a
                                    href="#pricing"
                                    className="rounded-xl bg-[#191C18] px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-[#2e312d]"
                                    style={{
                                        fontFamily:
                                            "'Hanken Grotesk', sans-serif",
                                    }}
                                >
                                    See pricing
                                </a>
                                <span className="font-mono text-sm text-[#757872]">
                                    7-day free trial · no card required
                                </span>
                            </div>
                        </Reveal>

                        <Reveal delay={320}>
                            <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                <AppBadge
                                    icon={<AppleGlyph />}
                                    sublabel="Coming soon to"
                                    label="the App Store"
                                />
                                <AppBadge
                                    icon={<AndroidGlyph />}
                                    sublabel="Coming soon on"
                                    label="Google Play"
                                />
                            </div>
                        </Reveal>

                        {/* Floating preview card */}
                        <Reveal delay={400} className="mt-20">
                            <div className="relative mx-auto max-w-3xl">
                                <div className="relative rounded-xl border border-[#D8DAD4] bg-white p-3">
                                    <div className="rounded-lg bg-[#F3F4EE] p-5">
                                        <div className="mb-4 flex items-center justify-between">
                                            <span className="text-sm font-semibold text-[#191C18]">
                                                Today's feed
                                            </span>
                                            <span className="rounded-full bg-[#EFF8D5] px-2.5 py-0.5 font-mono text-[11px] font-medium text-[#3A4D00]">
                                                Live
                                            </span>
                                        </div>
                                        <div className="space-y-2.5">
                                            {[
                                                {
                                                    store: 'Rivera Vintage Co · Woo',
                                                    order: '#1042',
                                                    total: '$84.00',
                                                    status: 'Unfulfilled',
                                                },
                                                {
                                                    store: 'Rivera Vintage Co · Shopify',
                                                    order: '#889',
                                                    total: '$212.50',
                                                    status: 'High value',
                                                },
                                                {
                                                    store: 'Rivera Vintage Co · Etsy',
                                                    order: '#331',
                                                    total: '$36.00',
                                                    status: 'Shipped',
                                                },
                                            ].map((row) => (
                                                <div
                                                    key={row.order}
                                                    className="flex items-center justify-between rounded-lg border border-[#D8DAD4] bg-white px-4 py-3"
                                                >
                                                    <div className="text-left">
                                                        <div className="font-mono text-sm font-medium text-[#191C18]">
                                                            {row.order}
                                                        </div>
                                                        <div className="text-xs text-[#757872]">
                                                            {row.store}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-xs font-medium text-[#454843]">
                                                            {row.status}
                                                        </span>
                                                        <span className="font-mono text-sm font-semibold text-[#191C18]">
                                                            {row.total}
                                                        </span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Reveal>
                    </div>
                </section>

                {/* Platform trust bar */}
                <section className="border-y border-[#D8DAD4] bg-[#F3F4EE] py-10">
                    <Reveal>
                        <div className="mx-auto max-w-5xl px-6">
                            <p className="mb-6 text-center font-mono text-xs font-medium tracking-widest text-[#757872] uppercase">
                                Built for sellers on
                            </p>
                            <div className="flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
                                {PLATFORMS.map((platform) => (
                                    <span
                                        key={platform.name}
                                        className="inline-flex items-center gap-2"
                                    >
                                        <span
                                            className={`text-lg font-semibold ${platform.live ? 'text-[#191C18]' : 'text-[#757872]'}`}
                                        >
                                            {platform.name}
                                        </span>
                                        {!platform.live && (
                                            <span className="rounded-full bg-[#EFF8D5] px-2 py-0.5 font-mono text-[10px] font-medium tracking-wide text-[#3A4D00] uppercase">
                                                Coming soon
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </Reveal>
                </section>

                {/* How it works — the real auth → connect → notify → act journey */}
                <section className="py-24">
                    <div className="mx-auto max-w-6xl px-6">
                        <Reveal className="mx-auto mb-16 max-w-2xl text-center">
                            <span className="font-mono text-xs font-semibold tracking-widest text-[#4E6700] uppercase">
                                From zero to live
                            </span>
                            <h2
                                className="mt-2 text-3xl font-semibold tracking-tight text-[#191C18] sm:text-4xl"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Four steps. A few minutes.
                            </h2>
                        </Reveal>

                        <div className="relative grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {/* Connecting line — desktop only, sits behind the step numbers */}
                            <div className="pointer-events-none absolute inset-x-16 top-[38px] hidden h-px bg-[#D8DAD4] lg:block" />

                            {JOURNEY.map((step, i) => (
                                <Reveal key={step.step} delay={i * 110}>
                                    <div className="relative flex h-full flex-col gap-4 rounded-xl border border-[#D8DAD4] bg-white p-6">
                                        <div className="flex items-center justify-between">
                                            <span className="flex h-9 w-9 items-center justify-center rounded-full border border-[#D8DAD4] bg-[#F8FAF3] text-[#191C18]">
                                                {step.icon}
                                            </span>
                                            <span className="font-mono text-xs font-medium text-[#757872]">
                                                {step.step}
                                            </span>
                                        </div>
                                        <h3 className="text-lg font-semibold text-[#191C18]">
                                            {step.title}
                                        </h3>
                                        <p className="text-sm leading-relaxed text-[#454843]">
                                            {step.body}
                                        </p>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Notification demo */}
                <section className="border-y border-[#D8DAD4] bg-[#F3F4EE] py-24">
                    <div className="mx-auto grid max-w-6xl grid-cols-1 items-center gap-16 px-6 lg:grid-cols-2">
                        <Reveal>
                            <span className="font-mono text-xs font-semibold tracking-widest text-[#4E6700] uppercase">
                                The core differentiator
                            </span>
                            <h2
                                className="mt-2 text-3xl font-semibold tracking-tight text-[#191C18] sm:text-4xl"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                One rule. Every channel.
                            </h2>
                            <p className="mt-4 text-lg text-[#454843]">
                                Set the condition once — a high-value order, a
                                spike in refunds, a low-stock alert, a run of
                                5-star reviews — and StockBeat reaches you
                                however you actually want to hear about it:
                                push, email, or SMS, the moment it happens.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'Push for the instant glance, email for the record, SMS for when you need to know now',
                                    'Critical/high/normal priority per rule, plus quiet hours and per-channel mute',
                                    'A full execution log — see exactly when and how every rule fired',
                                ].map((point) => (
                                    <li
                                        key={point}
                                        className="flex items-start gap-3 text-sm text-[#454843]"
                                    >
                                        <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[#4E6700]" />
                                        {point}
                                    </li>
                                ))}
                            </ul>
                        </Reveal>

                        <Reveal delay={120}>
                            <NotificationDemo />
                        </Reveal>
                    </div>
                </section>

                {/* Feature deep-dive */}
                <section id="features" className="py-24">
                    <div className="mx-auto max-w-5xl px-6">
                        <Reveal className="mx-auto mb-16 max-w-2xl text-center">
                            <h2
                                className="text-3xl font-semibold tracking-tight text-[#191C18] sm:text-4xl"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Everything a multi-channel seller actually needs
                            </h2>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                            {FEATURES.map((feature) => (
                                <Reveal key={feature.title}>
                                    <div className="flex h-full flex-col rounded-xl border border-[#D8DAD4] bg-white p-7">
                                        <span className="font-mono text-xs font-semibold tracking-widest text-[#4E6700] uppercase">
                                            {feature.eyebrow}
                                        </span>
                                        <h3 className="mt-2 text-xl font-semibold tracking-tight text-[#191C18]">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-3 text-sm text-[#454843]">
                                            {feature.body}
                                        </p>
                                        <ul className="mt-5 space-y-2.5">
                                            {feature.points.map((point) => (
                                                <li
                                                    key={point}
                                                    className="flex items-start gap-2.5 rounded-lg bg-[#F3F4EE] px-3.5 py-2.5 text-sm text-[#454843]"
                                                >
                                                    <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[#4E6700]" />
                                                    {point}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Security */}
                <section
                    id="security"
                    className="border-y border-[#D8DAD4] bg-[#F3F4EE] py-24"
                >
                    <div className="mx-auto max-w-5xl px-6">
                        <Reveal className="mx-auto mb-14 max-w-2xl text-center">
                            <h2
                                className="text-3xl font-semibold tracking-tight text-[#191C18]"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Built to be trusted with your data
                            </h2>
                            <p className="mt-3 text-[#454843]">
                                Your store credentials and customer data,
                                handled the way they should be.
                            </p>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                            {TRUST_POINTS.map((point, i) => (
                                <Reveal key={point.title} delay={i * 90}>
                                    <div className="h-full rounded-xl border border-[#D8DAD4] bg-white p-6 text-center">
                                        <h3 className="font-semibold text-[#191C18]">
                                            {point.title}
                                        </h3>
                                        <p className="mt-2 text-sm text-[#454843]">
                                            {point.body}
                                        </p>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Pricing */}
                <section id="pricing" className="py-24">
                    <div className="mx-auto max-w-6xl px-6">
                        <Reveal className="mb-14 text-center">
                            <h2
                                className="text-3xl font-semibold tracking-tight text-[#191C18]"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Simple, honest pricing
                            </h2>
                            <p className="mt-3 text-[#454843]">
                                Start free. Upgrade the moment a second store
                                makes it worth it.
                            </p>
                        </Reveal>

                        <Reveal className="mb-10">
                            <div className="rounded-xl border border-[#D8DAD4] bg-white p-6">
                                <p className="font-mono text-xs font-semibold tracking-wide text-[#757872] uppercase">
                                    Every plan includes
                                </p>
                                <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2">
                                    {EVERY_PLAN_FEATURES.map((feature) => (
                                        <span
                                            key={feature}
                                            className="flex items-center gap-2 text-sm text-[#454843]"
                                        >
                                            <span className="h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[#4E6700]" />
                                            {feature}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {PLANS.map((plan, i) => (
                                <Reveal key={plan.name} delay={i * 80}>
                                    <div
                                        className={`relative h-full rounded-xl border p-6 transition duration-300 ${
                                            plan.highlight
                                                ? 'border-[#191C18] bg-white'
                                                : 'border-[#D8DAD4] bg-white hover:border-[#191C18]'
                                        }`}
                                    >
                                        {plan.highlight && (
                                            <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-[#C3F341] px-3 py-1 font-mono text-[11px] font-semibold text-[#191C18]">
                                                Most popular
                                            </span>
                                        )}
                                        <h3 className="font-mono text-sm font-semibold tracking-wide text-[#757872] uppercase">
                                            {plan.name}
                                        </h3>
                                        <div className="mt-3 flex items-baseline gap-1">
                                            <span
                                                className="text-3xl font-semibold text-[#191C18]"
                                                style={{
                                                    fontFamily:
                                                        "'Hanken Grotesk', sans-serif",
                                                }}
                                            >
                                                {plan.price}
                                            </span>
                                            <span className="text-sm text-[#757872]">
                                                {plan.cadence}
                                            </span>
                                        </div>
                                        <ul className="mt-6 space-y-3">
                                            {plan.features.map((feature) => (
                                                <li
                                                    key={feature}
                                                    className="flex items-start gap-2 text-sm text-[#454843]"
                                                >
                                                    <span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-[#4E6700]" />
                                                    {feature}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Final CTA */}
                <section className="py-24">
                    <Reveal className="mx-auto max-w-3xl px-6 text-center">
                        <div className="relative overflow-hidden rounded-xl border border-[#191C18] bg-[#191C18] px-10 py-16">
                            <span
                                className="absolute top-0 right-0 h-24 w-24 bg-[#C3F341]"
                                style={{
                                    clipPath: 'polygon(100% 0, 0 0, 100% 100%)',
                                }}
                            />
                            <h2
                                className="relative text-3xl font-semibold tracking-tight text-white"
                                style={{
                                    fontFamily: "'Hanken Grotesk', sans-serif",
                                }}
                            >
                                Stop checking five apps to run one business.
                            </h2>
                            <p className="relative mt-3 text-[#c5c7c1]">
                                Start your 7-day free trial today — no card
                                required.
                            </p>
                            <div className="relative mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                <AppBadge
                                    icon={<AppleGlyph />}
                                    sublabel="Coming soon to"
                                    label="the App Store"
                                />
                                <AppBadge
                                    icon={<AndroidGlyph />}
                                    sublabel="Coming soon on"
                                    label="Google Play"
                                />
                            </div>
                        </div>
                    </Reveal>
                </section>

                <footer className="border-t border-[#D8DAD4] py-10">
                    <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-6 font-mono text-sm text-[#757872] sm:flex-row sm:justify-between">
                        <span>© {new Date().getFullYear()} StockBeat</span>
                        <div className="flex items-center gap-6">
                            <a href="/terms" className="transition hover:text-[#191C18]">
                                Terms
                            </a>
                            <a href="/privacy" className="transition hover:text-[#191C18]">
                                Privacy
                            </a>
                            <a href="/contact" className="transition hover:text-[#191C18]">
                                Contact
                            </a>
                            <a href="/newsletter" className="transition hover:text-[#191C18]">
                                Newsletter
                            </a>
                        </div>
                        <span>
                            Billed via Apple &amp; Google in-app purchases · no
                            external checkout
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}
