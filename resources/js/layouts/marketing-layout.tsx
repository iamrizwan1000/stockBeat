import { Head, Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

/**
 * Shared header/footer for the public marketing pages that AREN'T the
 * homepage (Terms/Privacy/Contact/Newsletter) — matches welcome.tsx's
 * "Graphite Precision" palette/fonts exactly, but with an always-solid
 * header (no scroll-transparency) since these are subpages, not a landing
 * page. welcome.tsx itself is deliberately left untouched/self-contained
 * to avoid regressing its homepage-specific nav behavior.
 */

const NAV_LINKS = [
    { label: 'Home', href: '/' },
    { label: 'Pricing', href: '/#pricing' },
    { label: 'Contact', href: '/contact' },
];

function NewsletterFooterForm() {
    const [email, setEmail] = useState('');
    const [status, setStatus] = useState<'idle' | 'submitting' | 'done'>('idle');

    const submit = () => {
        if (email.trim() === '') {
            return;
        }

        setStatus('submitting');
        router.post(
            '/newsletter/subscribe',
            { email },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setStatus('done');
                    setEmail('');
                },
                onError: () => setStatus('idle'),
            },
        );
    };

    if (status === 'done') {
        return (
            <span className="font-mono text-sm text-[#4E6700]">
                You&apos;re subscribed — check your inbox to confirm.
            </span>
        );
    }

    return (
        <div className="flex items-center gap-2">
            <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@example.com"
                className="rounded-lg border border-[#D8DAD4] bg-white px-3 py-2 text-sm text-[#191C18] placeholder:text-[#757872] focus:border-[#191C18] focus:outline-none"
            />
            <button
                type="button"
                onClick={submit}
                disabled={status === 'submitting'}
                className="rounded-lg bg-[#191C18] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#2e312d] disabled:opacity-50"
            >
                Subscribe
            </button>
        </div>
    );
}

export default function MarketingLayout({ children }: { children: ReactNode }) {
    return (
        <>
            <Head>
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
                <link
                    href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap"
                    rel="stylesheet"
                />
            </Head>

            <div
                className="relative min-h-screen bg-[#F8FAF3] text-[#191C18] selection:bg-[#EFF8D5]"
                style={{ fontFamily: "'Inter', ui-sans-serif, system-ui, sans-serif" }}
            >
                <nav className="border-b border-[#D8DAD4] bg-[#F8FAF3]">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <Link href="/" className="flex items-center gap-2.5">
                            <img
                                src="/assets/logo1.png"
                                alt="StockBeat"
                                className="h-8 w-8 rounded object-cover"
                            />
                            <span
                                className="text-base font-semibold tracking-tight"
                                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
                            >
                                StockBeat
                            </span>
                        </Link>
                        <div className="hidden items-center gap-8 text-sm font-medium text-[#454843] sm:flex">
                            {NAV_LINKS.map((link) => (
                                <a key={link.href} href={link.href} className="transition hover:text-[#191C18]">
                                    {link.label}
                                </a>
                            ))}
                        </div>
                        <Link
                            href="/admin/login"
                            className="rounded-xl border border-[#D8DAD4] bg-white px-4 py-2 text-sm font-medium text-[#252824] transition hover:border-[#191C18]"
                        >
                            Admin sign in
                        </Link>
                    </div>
                </nav>

                <main className="mx-auto max-w-3xl px-6 py-16">{children}</main>

                <footer className="border-t border-[#D8DAD4] py-10">
                    <div className="mx-auto flex max-w-6xl flex-col gap-6 px-6">
                        <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                            <div className="flex items-center gap-6 font-mono text-sm text-[#757872]">
                                <a href="/terms" className="transition hover:text-[#191C18]">
                                    Terms
                                </a>
                                <a href="/privacy" className="transition hover:text-[#191C18]">
                                    Privacy
                                </a>
                                <a href="/contact" className="transition hover:text-[#191C18]">
                                    Contact
                                </a>
                            </div>
                            <NewsletterFooterForm />
                        </div>
                        <div className="flex flex-col items-center gap-4 font-mono text-sm text-[#757872] sm:flex-row sm:justify-between">
                            <span>© {new Date().getFullYear()} StockBeat</span>
                            <span>Billed via Apple &amp; Google in-app purchases · no external checkout</span>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
