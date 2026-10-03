import { QrCode, Images, Upload, Zap, Shield, BarChart3 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useInView } from '@/hooks/use-in-view';

const features = [
    {
        icon: QrCode,
        title: 'QR Code Sharing',
        description: 'Every event gets a unique QR code. Display it anywhere — screens, prints, badges.',
    },
    {
        icon: Upload,
        title: 'Guest Uploads',
        description: 'Guests upload photos directly from their phones with no app or account required.',
    },
    {
        icon: Images,
        title: 'Shared Gallery',
        description: 'All photos from all guests appear instantly in one beautiful shared gallery.',
    },
    {
        icon: Zap,
        title: 'Instant Processing',
        description: 'Photos are optimized and thumbnailed automatically in the background.',
    },
    {
        icon: Shield,
        title: 'Secure Storage',
        description: 'Your photos are stored securely with access controlled by your event settings.',
    },
    {
        icon: BarChart3,
        title: 'Usage Dashboard',
        description: 'Track your events, photo counts, and storage usage from your organizer dashboard.',
    },
] as const;

export default function FeaturesSection() {
    const { ref, inView } = useInView();

    return (
        <section ref={ref as React.RefObject<HTMLElement>} id="features" className="py-20">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mb-12 text-center">
                    <h2 className="text-2xl font-semibold text-foreground">Everything you need to gather moments</h2>
                    <p className="mt-2 text-sm text-muted-foreground">Built for event organizers who want to collect memories effortlessly.</p>
                </div>

                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature, index) => (
                        <div
                            key={feature.title}
                            className={cn(
                                'rounded-xl border border-border bg-card p-6 transition-all duration-500 hover:border-brand/50',
                                inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                            )}
                            style={{ transitionDelay: inView ? `${index * 80}ms` : '0ms' }}
                        >
                            <div className="mb-4 inline-flex rounded-lg bg-brand-muted p-3">
                                <feature.icon className="h-5 w-5 text-brand" aria-hidden="true" />
                            </div>
                            <h3 className="mb-2 font-semibold text-foreground">{feature.title}</h3>
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                {feature.description}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
