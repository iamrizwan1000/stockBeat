import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';

import MarketingLayout from '@/layouts/marketing-layout';

const inputClasses =
    'w-full rounded-lg border border-[#D8DAD4] bg-white px-4 py-3 text-sm text-[#191C18] placeholder:text-[#757872] focus:border-[#191C18] focus:outline-none';

export default function Contact() {
    const { props } = usePage<{ flash: { status: string | null }; errors: Record<string, string> }>();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [subject, setSubject] = useState('');
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const submit = () => {
        setSubmitting(true);
        router.post(
            '/contact',
            { name, email, subject, message },
            {
                onFinish: () => setSubmitting(false),
                onSuccess: () => {
                    setName('');
                    setEmail('');
                    setSubject('');
                    setMessage('');
                },
            },
        );
    };

    return (
        <>
            <Head title="Contact — StockBeat" />

            <div className="mb-2 font-mono text-xs font-medium tracking-wide text-[#757872] uppercase">Get in touch</div>
            <h1
                className="mb-4 text-3xl font-semibold text-[#191C18]"
                style={{ fontFamily: "'Hanken Grotesk', sans-serif" }}
            >
                Contact us
            </h1>
            <p className="mb-8 text-[15px] leading-relaxed text-[#454843]">
                Questions about StockBeat, a billing issue, or feedback? Send us a message below and we&apos;ll
                reply by email.
            </p>

            {props.flash?.status && (
                <div className="mb-6 rounded-xl border border-[#D8DAD4] bg-[#EFF8D5] p-4 text-sm text-[#3A4D00]">
                    {props.flash.status}
                </div>
            )}

            <div className="space-y-4">
                <div>
                    <label className="mb-1 block text-sm font-medium text-[#191C18]">Name</label>
                    <input value={name} onChange={(e) => setName(e.target.value)} className={inputClasses} />
                    {props.errors?.name && <p className="mt-1 text-sm text-red-600">{props.errors.name}</p>}
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-[#191C18]">Email</label>
                    <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className={inputClasses}
                    />
                    {props.errors?.email && <p className="mt-1 text-sm text-red-600">{props.errors.email}</p>}
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-[#191C18]">Subject (optional)</label>
                    <input value={subject} onChange={(e) => setSubject(e.target.value)} className={inputClasses} />
                </div>
                <div>
                    <label className="mb-1 block text-sm font-medium text-[#191C18]">Message</label>
                    <textarea
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        rows={6}
                        className={inputClasses}
                    />
                    {props.errors?.message && <p className="mt-1 text-sm text-red-600">{props.errors.message}</p>}
                </div>
                <button
                    type="button"
                    onClick={submit}
                    disabled={submitting || name.trim() === '' || email.trim() === '' || message.trim() === ''}
                    className="rounded-lg bg-[#191C18] px-6 py-3 text-sm font-medium text-white transition hover:bg-[#2e312d] disabled:opacity-50"
                >
                    Send message
                </button>
            </div>
        </>
    );
}

Contact.layout = (page: ReactNode) => <MarketingLayout>{page}</MarketingLayout>;
