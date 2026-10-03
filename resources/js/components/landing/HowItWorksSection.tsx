import { CalendarPlus, QrCode, Upload, Images } from 'lucide-react';
import SectionHeader from '@/components/SectionHeader';

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
    return (
        <section id="how-it-works" className="py-20 bg-muted/40">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mb-12 text-center">
                    <SectionHeader
                        title="How It Works"
                        description="From setup to gallery in four simple steps."
                    />
                </div>

                {/* Desktop: horizontal with connectors between circles */}
                <div className="hidden md:block">
                    {/* Top row: circles + connectors */}
                    <div className="flex items-center">
                        {steps.map((step, index) => (
                            <div key={step.number} className="flex flex-1 items-center">
                                {/* Circle */}
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground text-lg font-bold shadow-sm mx-auto">
                                    {step.number}
                                </div>
                                {/* Connector line after each circle except the last */}
                                {index < steps.length - 1 && (
                                    <div className="flex-1 h-px bg-border mx-2" aria-hidden="true" />
                                )}
                            </div>
                        ))}
                    </div>

                    {/* Bottom row: icons + titles + descriptions aligned under circles */}
                    <div className="mt-4 grid grid-cols-4 gap-6">
                        {steps.map((step) => (
                            <div key={step.number} className="flex flex-col items-center text-center">
                                <step.icon className="mb-3 h-7 w-7 text-brand" aria-hidden="true" />
                                <h3 className="mb-2 font-semibold text-foreground">{step.title}</h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {step.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Mobile: vertical stacked list */}
                <ol className="flex flex-col gap-8 md:hidden">
                    {steps.map((step) => (
                        <li key={step.number} className="flex items-start gap-4">
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground font-bold shadow-sm">
                                {step.number}
                            </div>
                            <div>
                                <h3 className="font-semibold text-foreground">{step.title}</h3>
                                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                                    {step.description}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
