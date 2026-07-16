import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';

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

const NOTIFICATION_CHANNELS = [
    {
        channel: 'Push',
        icon: <BellGlyph />,
        accent: 'bg-sky-500',
        ring: 'ring-sky-200',
        title: 'StockBeat',
        subtitle: 'High-value order',
        body: 'Order #1042 · $212.50 just came in from Shopify.',
        time: 'now',
    },
    {
        channel: 'Email',
        icon: <MailGlyph />,
        accent: 'bg-violet-500',
        ring: 'ring-violet-200',
        title: 'StockBeat Alerts',
        subtitle: 'New order needs your attention',
        body: '"High-value order" rule fired for order #1042 ($212.50) on Shopify.',
        time: '2m ago',
    },
    {
        channel: 'SMS',
        icon: <ChatGlyph />,
        accent: 'bg-emerald-500',
        ring: 'ring-emerald-200',
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
        <div className="relative mx-auto max-w-md">
            <div className="absolute inset-x-10 -bottom-4 h-20 rounded-[2rem] bg-slate-900/10 blur-2xl" />
            <div className="relative rounded-[2rem] border border-slate-200 bg-white/90 p-6 shadow-2xl shadow-slate-400/20 backdrop-blur">
                <div className="mb-5 flex items-center justify-between">
                    <span className="text-xs font-semibold tracking-widest text-slate-400 uppercase">
                        One rule fires
                    </span>
                    <span className="flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        Live
                    </span>
                </div>

                <div className="space-y-3">
                    {NOTIFICATION_CHANNELS.map((item, i) => {
                        const shown = i < revealed;

                        return (
                            <div
                                key={item.channel}
                                className={`flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm ring-1 transition-all duration-500 ease-out ${item.ring} ${
                                    shown
                                        ? 'translate-y-0 opacity-100'
                                        : 'pointer-events-none -translate-y-3 opacity-0'
                                }`}
                            >
                                <span
                                    className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-white ${item.accent}`}
                                >
                                    {item.icon}
                                </span>
                                <div className="min-w-0 flex-1 text-left">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-semibold text-slate-800">
                                            {item.title}
                                        </span>
                                        <span className="flex-shrink-0 text-[11px] text-slate-400">
                                            {item.time}
                                        </span>
                                    </div>
                                    {item.subtitle && (
                                        <div className="text-xs font-medium text-slate-500">
                                            {item.subtitle}
                                        </div>
                                    )}
                                    <p className="mt-0.5 text-xs leading-snug text-slate-500">
                                        {item.body}
                                    </p>
                                </div>
                                <span className="mt-0.5 flex-shrink-0 text-[10px] font-semibold tracking-wide text-slate-300 uppercase">
                                    {item.channel}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

const PLATFORMS = ['Shopify', 'WooCommerce', 'eBay', 'Etsy', 'Amazon'];

const JOBS = [
    {
        title: 'See',
        body: 'Every order from every store, in one live feed, updated in real time.',
    },
    {
        title: 'Know',
        body: 'Custom rules alert you by push, email, or SMS the second something matters.',
    },
    {
        title: 'Act',
        body: 'Fulfill, add tracking, refund, cancel, tag — from your phone, no laptop required.',
    },
    {
        title: 'Talk',
        body: 'A unified customer inbox across every marketplace, in one screen.',
    },
];

const FEATURES = [
    {
        eyebrow: 'The dashboard',
        title: 'One feed. Every channel.',
        body: 'Shopify, WooCommerce, eBay, Etsy, and Amazon orders land in a single reverse-chronological feed — channel icon, customer, total in your base currency, fulfillment and payment status, all at a glance. Filter by channel, store, status, date, or value; search everything by order number, customer, email, product, or SKU.',
        points: [
            'Real-time via webhooks where supported, polling everywhere else',
            'Ship-by deadline countdowns for eBay/Etsy/Amazon SLAs',
            'Snooze any order to deal with it later',
        ],
    },
    {
        eyebrow: 'The core differentiator',
        title: 'Rules that actually know your business.',
        body: 'Compose rules in plain terms — WHEN a trigger fires, IF conditions match, THEN act. Twelve triggers from new orders to order and refund spikes, with AND/OR condition groups on total, SKU, country, repeat-buyer status, and more.',
        points: [
            'Push, email, or SMS — with a custom sound per rule',
            'Quiet hours, cooldowns, and a one-tap test-fire',
            'Full execution log — the last 50 firings per rule',
        ],
    },
    {
        eyebrow: 'From your phone',
        title: 'Act without opening a laptop.',
        body: 'Every order card supports the actions that actually move your day forward: mark fulfilled with tracking, issue a full or partial refund, cancel, tag, add an internal note, or message the customer — queued server-side with optimistic UI and automatic retry.',
        points: [
            'Packing slips generated server-side, shared instantly',
            'Message a customer straight into the unified inbox',
            'Every destructive action is logged and reversible in review',
        ],
    },
    {
        eyebrow: 'Business overview',
        title: 'Know where you stand, today.',
        body: 'Today, 7-day, and 30-day revenue, order count, and average order value — total and per channel — plus goal tracking against your best month. A morning digest tells you what happened while you slept.',
        points: [
            'Per-channel comparison — "Etsy up 30% this week"',
            'Top products by units and revenue',
            'Home-screen widget for today’s numbers at a glance',
        ],
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
        <div className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-3 shadow-sm shadow-slate-200/50 transition hover:-translate-y-0.5 hover:shadow-md hover:shadow-slate-300/50">
            <span className="text-slate-900">{icon}</span>
            <div className="text-left leading-tight">
                <div className="text-[11px] tracking-wide text-slate-500 uppercase">
                    {sublabel}
                </div>
                <div className="text-sm font-semibold text-slate-900">
                    {label}
                </div>
            </div>
        </div>
    );
}

const PLANS = [
    {
        name: 'Free',
        price: '$0',
        cadence: '',
        highlight: false,
        features: [
            '1 connected store',
            'Live order feed',
            'New-order push + daily summary',
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
            '5 custom alert rules',
            '20 SMS + 250 email/mo',
            '30 days of history',
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
            'Unified customer inbox',
            '100 SMS + 1,000 email/mo',
            '3 team seats',
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
            '500 SMS + 5,000 email/mo',
            '10 team seats',
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

function GradientBlob({ className }: { className: string }) {
    return (
        <div
            aria-hidden
            className={`pointer-events-none absolute rounded-full blur-3xl ${className}`}
        />
    );
}

export default function Welcome() {
    const scrolled = useScrolled();

    return (
        <>
            <Head title="StockBeat — Multi-channel order monitoring, mission control for sellers" />

            <div className="relative min-h-screen overflow-x-hidden bg-white text-slate-900 selection:bg-emerald-200/60">
                <nav
                    className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${
                        scrolled
                            ? 'border-b border-slate-200/80 bg-white/80 backdrop-blur-lg'
                            : 'bg-transparent'
                    }`}
                >
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <div className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 text-sm font-bold text-white shadow-md shadow-emerald-500/30">
                                S
                            </div>
                            <span className="text-base font-semibold tracking-tight">
                                StockBeat
                            </span>
                        </div>
                        <div className="hidden items-center gap-8 text-sm font-medium text-slate-600 sm:flex">
                            <a
                                href="#features"
                                className="transition hover:text-slate-900"
                            >
                                Features
                            </a>
                            <a
                                href="#pricing"
                                className="transition hover:text-slate-900"
                            >
                                Pricing
                            </a>
                            <a
                                href="#security"
                                className="transition hover:text-slate-900"
                            >
                                Security
                            </a>
                        </div>
                        <Link
                            href="/admin/login"
                            className="rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:shadow"
                        >
                            Admin sign in
                        </Link>
                    </div>
                </nav>

                {/* Hero */}
                <section className="relative pt-40 pb-28">
                    <GradientBlob className="-top-32 -left-32 h-[30rem] w-[30rem] bg-emerald-200/50" />
                    <GradientBlob className="-top-20 -right-40 h-[34rem] w-[34rem] bg-indigo-200/40" />
                    <GradientBlob className="top-96 left-1/3 h-[24rem] w-[24rem] bg-teal-100/60" />

                    <div className="relative mx-auto max-w-5xl px-6 text-center">
                        <Reveal>
                            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-1.5 text-xs font-semibold text-emerald-700">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                Mission control for multi-channel sellers
                            </div>
                        </Reveal>

                        <Reveal delay={80}>
                            <h1 className="mx-auto max-w-3xl text-5xl leading-[1.08] font-semibold tracking-tight text-slate-900 sm:text-6xl">
                                Every order.
                                <br />
                                <span className="bg-gradient-to-r from-emerald-500 via-teal-500 to-indigo-500 bg-clip-text text-transparent">
                                    Instantly known.
                                </span>
                            </h1>
                        </Reveal>

                        <Reveal delay={160}>
                            <p className="mx-auto mt-6 max-w-xl text-lg text-slate-500">
                                StockBeat aggregates every store into one feed,
                                alerts you the moment something matters, and
                                lets you act without opening a laptop.
                            </p>
                        </Reveal>

                        <Reveal delay={240}>
                            <div className="mt-10 flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                                <a
                                    href="#pricing"
                                    className="rounded-full bg-slate-900 px-7 py-3 text-sm font-semibold text-white shadow-xl shadow-slate-900/20 transition hover:-translate-y-0.5 hover:shadow-2xl hover:shadow-slate-900/30"
                                >
                                    See pricing
                                </a>
                                <span className="text-sm text-slate-400">
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
                                <div className="absolute inset-x-8 -bottom-6 h-24 rounded-[2rem] bg-slate-900/10 blur-2xl" />
                                <div className="relative rounded-[1.75rem] border border-slate-200 bg-white/90 p-3 shadow-2xl shadow-slate-400/20 backdrop-blur">
                                    <div className="rounded-[1.4rem] bg-slate-50 p-5">
                                        <div className="mb-4 flex items-center justify-between">
                                            <span className="text-sm font-semibold text-slate-700">
                                                Today's feed
                                            </span>
                                            <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
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
                                                    className="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm"
                                                >
                                                    <div className="text-left">
                                                        <div className="text-sm font-medium text-slate-800">
                                                            {row.order}
                                                        </div>
                                                        <div className="text-xs text-slate-400">
                                                            {row.store}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-xs font-medium text-slate-500">
                                                            {row.status}
                                                        </span>
                                                        <span className="text-sm font-semibold text-slate-800">
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
                <section className="border-y border-slate-100 bg-slate-50/60 py-10">
                    <Reveal>
                        <div className="mx-auto max-w-5xl px-6">
                            <p className="mb-6 text-center text-xs font-semibold tracking-widest text-slate-400 uppercase">
                                Built for sellers on
                            </p>
                            <div className="flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
                                {PLATFORMS.map((platform) => (
                                    <span
                                        key={platform}
                                        className="text-lg font-semibold text-slate-400"
                                    >
                                        {platform}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </Reveal>
                </section>

                {/* Notification demo */}
                <section className="py-28">
                    <div className="mx-auto grid max-w-6xl grid-cols-1 items-center gap-16 px-6 lg:grid-cols-2">
                        <Reveal>
                            <span className="text-xs font-semibold tracking-widest text-emerald-600 uppercase">
                                The core differentiator
                            </span>
                            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                                One rule. Every channel.
                            </h2>
                            <p className="mt-4 text-lg text-slate-500">
                                Set the condition once — a high-value order, a
                                spike in refunds, a low-stock alert — and
                                StockBeat reaches you however you actually want
                                to hear about it: push, email, or SMS, the
                                moment it happens.
                            </p>
                            <ul className="mt-6 space-y-3">
                                {[
                                    'Push for the instant glance, email for the record, SMS for when you need to know now',
                                    'Quiet hours and per-channel mute so nothing feels like spam',
                                    'A full execution log — see exactly when and how every rule fired',
                                ].map((point) => (
                                    <li
                                        key={point}
                                        className="flex items-start gap-3 text-sm text-slate-600"
                                    >
                                        <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-emerald-500" />
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

                {/* Four jobs */}
                <section className="bg-slate-50/60 py-28">
                    <div className="mx-auto max-w-6xl px-6">
                        <Reveal className="mx-auto mb-14 max-w-2xl text-center">
                            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
                                Four jobs. One app.
                            </h2>
                            <p className="mt-3 text-slate-500">
                                Not a full order management system —
                                deliberately. Just the mobile-first mission
                                control for sellers who need to see, know, act,
                                and talk, fast.
                            </p>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {JOBS.map((job, i) => (
                                <Reveal key={job.title} delay={i * 90}>
                                    <div className="group relative h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-200/60 transition duration-300 hover:-translate-y-1.5 hover:border-emerald-200 hover:shadow-xl hover:shadow-emerald-200/40">
                                        <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-100 to-teal-100 text-sm font-bold text-emerald-700">
                                            {i + 1}
                                        </div>
                                        <h3 className="mb-2 text-lg font-semibold text-slate-900">
                                            {job.title}
                                        </h3>
                                        <p className="text-sm leading-relaxed text-slate-500">
                                            {job.body}
                                        </p>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Feature deep-dive */}
                <section id="features" className="py-28">
                    <div className="mx-auto max-w-5xl px-6">
                        <Reveal className="mx-auto mb-16 max-w-2xl text-center">
                            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
                                Everything a multi-channel seller actually needs
                            </h2>
                        </Reveal>

                        <div className="space-y-6">
                            {FEATURES.map((feature) => (
                                <Reveal key={feature.title}>
                                    <div className="grid grid-cols-1 items-center gap-8 rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm shadow-slate-200/60 transition hover:shadow-lg hover:shadow-slate-300/40 md:grid-cols-2 md:p-10">
                                        <div>
                                            <span className="text-xs font-semibold tracking-widest text-emerald-600 uppercase">
                                                {feature.eyebrow}
                                            </span>
                                            <h3 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">
                                                {feature.title}
                                            </h3>
                                            <p className="mt-3 text-slate-500">
                                                {feature.body}
                                            </p>
                                        </div>
                                        <ul className="space-y-3">
                                            {feature.points.map((point) => (
                                                <li
                                                    key={point}
                                                    className="flex items-start gap-3 rounded-2xl border border-slate-100 bg-slate-50/80 px-4 py-3 text-sm text-slate-600"
                                                >
                                                    <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-emerald-500" />
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
                <section id="security" className="bg-slate-50/60 py-28">
                    <div className="mx-auto max-w-5xl px-6">
                        <Reveal className="mx-auto mb-14 max-w-2xl text-center">
                            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
                                Built to be trusted with your data
                            </h2>
                            <p className="mt-3 text-slate-500">
                                Your store credentials and customer data,
                                handled the way they should be.
                            </p>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                            {TRUST_POINTS.map((point, i) => (
                                <Reveal key={point.title} delay={i * 90}>
                                    <div className="h-full rounded-3xl border border-slate-200 bg-gradient-to-b from-white to-slate-50/60 p-6 text-center shadow-sm shadow-slate-200/50">
                                        <h3 className="font-semibold text-slate-900">
                                            {point.title}
                                        </h3>
                                        <p className="mt-2 text-sm text-slate-500">
                                            {point.body}
                                        </p>
                                    </div>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Pricing */}
                <section id="pricing" className="py-28">
                    <div className="mx-auto max-w-6xl px-6">
                        <Reveal className="mb-14 text-center">
                            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
                                Simple, honest pricing
                            </h2>
                            <p className="mt-3 text-slate-500">
                                Start free. Upgrade the moment a second store
                                makes it worth it.
                            </p>
                        </Reveal>

                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            {PLANS.map((plan, i) => (
                                <Reveal key={plan.name} delay={i * 80}>
                                    <div
                                        className={`relative h-full rounded-3xl border p-6 transition duration-300 hover:-translate-y-2 ${
                                            plan.highlight
                                                ? 'border-emerald-300 bg-gradient-to-b from-emerald-50 to-white shadow-2xl shadow-emerald-300/30'
                                                : 'border-slate-200 bg-white shadow-sm shadow-slate-200/50 hover:shadow-xl hover:shadow-slate-300/40'
                                        }`}
                                    >
                                        {plan.highlight && (
                                            <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-3 py-1 text-xs font-semibold text-white shadow-lg shadow-emerald-500/30">
                                                Most popular
                                            </span>
                                        )}
                                        <h3 className="text-sm font-semibold tracking-wide text-slate-500 uppercase">
                                            {plan.name}
                                        </h3>
                                        <div className="mt-3 flex items-baseline gap-1">
                                            <span className="text-3xl font-semibold text-slate-900">
                                                {plan.price}
                                            </span>
                                            <span className="text-sm text-slate-400">
                                                {plan.cadence}
                                            </span>
                                        </div>
                                        <ul className="mt-6 space-y-3">
                                            {plan.features.map((feature) => (
                                                <li
                                                    key={feature}
                                                    className="flex items-start gap-2 text-sm text-slate-500"
                                                >
                                                    <span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-emerald-500" />
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
                <section className="py-28">
                    <Reveal className="mx-auto max-w-3xl px-6 text-center">
                        <div className="relative overflow-hidden rounded-[2.5rem] border border-slate-200 bg-gradient-to-br from-slate-900 to-slate-800 px-10 py-16 shadow-2xl shadow-slate-400/30">
                            <GradientBlob className="-top-20 -right-20 h-72 w-72 bg-emerald-400/20" />
                            <h2 className="relative text-3xl font-semibold tracking-tight text-white">
                                Stop checking five apps to run one business.
                            </h2>
                            <p className="relative mt-3 text-slate-300">
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

                <footer className="border-t border-slate-100 py-10">
                    <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-6 text-sm text-slate-400 sm:flex-row sm:justify-between">
                        <span>© {new Date().getFullYear()} StockBeat</span>
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
