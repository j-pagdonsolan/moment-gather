import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useInView } from '@/hooks/use-in-view';

const faqs = [
    {
        q: 'What is MomentGather?',
        a: 'MomentGather is an event photo-sharing platform. Organizers create events and share a QR code; guests scan the code and upload their photos to a shared gallery — no app downloads or accounts required.',
    },
    {
        q: 'Do guests need to create an account to upload photos?',
        a: 'No. Guests simply scan the QR code and upload photos directly from their phone browser. There is no sign-up, no login, and no app to install.',
    },
    {
        q: 'How do guests upload photos?',
        a: 'Guests scan the event QR code with their phone camera, which opens the event page in their browser. From there they can select or drag-and-drop photos to upload instantly.',
    },
    {
        q: 'Where do the photos go?',
        a: "All photos uploaded by guests appear in the event's shared gallery, which any guest can view by visiting the gallery link or scanning the QR code.",
    },
    {
        q: 'What happens if I reach my plan limit?',
        a: 'Uploads are paused once the photo limit for an event is reached. You can upgrade your plan at any time to increase limits — your existing photos and events are never deleted.',
    },
    {
        q: 'Can I upgrade my plan?',
        a: 'Yes. You can upgrade from the Free plan to the Pro plan at any time from your billing settings. Changes take effect immediately.',
    },
];

export default function FaqSection() {
    const [open, setOpen] = useState<number | null>(null);
    const { ref, inView } = useInView<HTMLDListElement>();

    return (
        <section id="faq" className="py-20">
            <div className="mx-auto max-w-3xl px-4 sm:px-6">
                <div className="mb-12 text-center">
                    <h2 className="text-2xl font-semibold text-foreground">Frequently asked questions</h2>
                    <p className="mt-2 text-sm text-muted-foreground">Everything you need to know about MomentGather.</p>
                </div>

                <dl
                    ref={ref}
                    className={cn('space-y-3 transition-all duration-700', inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6')}
                >
                    {faqs.map((faq, i) => (
                        <div key={i} className="rounded-xl border border-border bg-card overflow-hidden">
                            <dt>
                                <button
                                    onClick={() => setOpen(open === i ? null : i)}
                                    className="flex w-full items-center justify-between px-5 py-4 text-left text-sm font-medium text-foreground hover:bg-muted/50 transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                    aria-expanded={open === i}
                                >
                                    <span>{faq.q}</span>
                                    <ChevronDown
                                        className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200', {
                                            'rotate-180': open === i,
                                        })}
                                        aria-hidden="true"
                                    />
                                </button>
                            </dt>
                            {open === i && (
                                <dd className="px-5 pb-4 text-sm leading-relaxed text-muted-foreground border-t border-border pt-3">
                                    {faq.a}
                                </dd>
                            )}
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}
