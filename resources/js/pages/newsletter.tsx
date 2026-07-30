import { Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

import MarketingLayout from '@/layouts/marketing-layout';

export default function Newsletter() {
    const [email, setEmail] = useState('');
    const [status, setStatus] = useState<'idle' | 'submitting' | 'done'>('idle');
    const [error, setError] = useState<string | null>(null);

    const submit = () => {
        setStatus('submitting');
        setError(null);

        router.post(
            '/newsletter/subscribe',
            { email },
            {
                onSuccess: () => {
                    setStatus('done');
                    setEmail('');
                },
                onError: (errors) => {
                    setStatus('idle');
                    setError(errors.email ?? 'Something went wrong — please try again.');
                },
            },
        );
    };

    return (
        <>
            <Head title="Newsletter — StockBeat" />

            <div className="mb-2 font-mono text-xs font-medium tracking-wide text-[#757872] uppercase">Stay in the loop</div>
            <h1
                className="mb-4 text-3xl font-semibold text-[#191C18]"
                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
            >
                Subscribe to StockBeat updates
            </h1>
            <p className="mb-8 text-[15px] leading-relaxed text-[#454843]">
                Product updates, new features, and the occasional tip for multi-channel sellers — no spam, and
                you can unsubscribe with one click at any time.
            </p>

            {status === 'done' ? (
                <div className="rounded-xl border border-[#D8DAD4] bg-[#EFF8D5] p-4 text-sm text-[#3A4D00]">
                    You&apos;re subscribed — check your inbox to confirm.
                </div>
            ) : (
                <div className="flex flex-col gap-3 sm:flex-row">
                    <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="you@example.com"
                        className="flex-1 rounded-lg border border-[#D8DAD4] bg-white px-4 py-3 text-sm text-[#191C18] placeholder:text-[#757872] focus:border-[#191C18] focus:outline-none"
                    />
                    <button
                        type="button"
                        onClick={submit}
                        disabled={status === 'submitting' || email.trim() === ''}
                        className="rounded-lg bg-[#191C18] px-6 py-3 text-sm font-medium text-white transition hover:bg-[#2e312d] disabled:opacity-50"
                    >
                        Subscribe
                    </button>
                </div>
            )}
            {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
        </>
    );
}

Newsletter.layout = (page: ReactNode) => <MarketingLayout>{page}</MarketingLayout>;
