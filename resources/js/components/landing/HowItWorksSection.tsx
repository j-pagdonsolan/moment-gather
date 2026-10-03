import { CalendarPlus, QrCode, Upload, Images } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useInView } from '@/hooks/use-in-view';

const steps = [
    {
        number: '1',
        icon: CalendarPlus,
        title: 'Create Your Event',
        description: 'Sign up and create an event in seconds. Give it a name, date, and description.',
    },
    {
        number: '2',
        icon: QrCode,
        title: 'Share Your QR Code',
        description: 'Display or print your unique QR code at your venue for guests to scan.',
    },
    {
        number: '3',
        icon: Upload,
        title: 'Guests Upload Photos',
        description: 'Guests scan the QR code and upload their photos directly — no account needed.',
    },
    {
        number: '4',
        icon: Images,
        title: 'Gather Every Moment',
        description: "All photos appear in one shared gallery. Download, share, and relive the event.",
    },
] as const;

export default function HowItWorksSection() {
    const { ref, inView } = useInView();

    return (
        <section ref={ref as React.RefObject<HTMLElement>} id="how-it-works" className="py-20 bg-muted/40">
            <div className="mx-auto max-w-5xl px-4 sm:px-6">
                <div className="mb-16 text-center">
                    <h2 className="text-2xl font-semibold text-foreground">How It Works</h2>
                    <p className="mt-2 text-sm text-muted-foreground">From setup to gallery in four simple steps.</p>
                </div>

                {/* Desktop */}
                <div className={cn('hidden md:grid md:grid-cols-4 gap-8 transition-all duration-700', inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8')}>
                    {steps.map((step, index) => (
                        <div key={step.number} className="relative flex flex-col items-center text-center">
                            {/* Connector line to the right (not on last item) */}
                            {index < steps.length - 1 && (
                                <div
                                    aria-hidden="true"
                                    className="absolute top-6 left-1/2 w-full h-px bg-border"
                                    style={{ left: '50%' }}
                                />
                            )}
                            {/* Circle — sits on top of the line */}
                            <div className="relative z-10 flex size-12 items-center justify-center rounded-full bg-brand text-brand-foreground text-lg font-bold shadow-sm mb-6 ring-4 ring-muted/40">
                                {step.number}
                            </div>
                            <step.icon className="mb-3 h-6 w-6 text-brand" aria-hidden="true" />
                            <h3 className="mb-2 font-semibold text-sm text-foreground">{step.title}</h3>
                            <p className="text-xs leading-relaxed text-muted-foreground">{step.description}</p>
                        </div>
                    ))}
                </div>

                {/* Mobile */}
                <ol className={cn('flex flex-col gap-6 md:hidden transition-all duration-700', inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8')}>
                    {steps.map((step, index) => (
                        <li key={step.number} className="relative flex gap-4">
                            {/* Vertical connector line */}
                            {index < steps.length - 1 && (
                                <div aria-hidden="true" className="absolute left-5 top-12 bottom-0 w-px bg-border -mb-6" />
                            )}
                            <div className="relative z-10 flex size-10 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground font-bold shadow-sm text-sm ring-4 ring-muted/40">
                                {step.number}
                            </div>
                            <div className="pb-6">
                                <div className="flex items-center gap-2 mb-1">
                                    <step.icon className="h-4 w-4 text-brand" aria-hidden="true" />
                                    <h3 className="font-semibold text-sm text-foreground">{step.title}</h3>
                                </div>
                                <p className="text-sm leading-relaxed text-muted-foreground">{step.description}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
