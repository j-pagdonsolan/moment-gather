import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { useInView } from '@/hooks/use-in-view';

interface PlanData {
    slug: string;
    name: string;
    price: number;
    billing_interval: string | null;
    max_active_events: number;
    max_photos_per_event: number;
    max_storage_bytes: number;
}

interface Props {
    plans: PlanData[];
}

function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024 * 1024)).toFixed(0)} GB`;
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(0)} MB`;
    return `${(bytes / 1024).toFixed(0)} KB`;
}

function formatPrice(cents: number): string {
    if (cents === 0) return '$0';
    return `$${(cents / 100).toFixed(0)}`;
}

export default function PricingSection({ plans }: Props) {
    const { ref, inView } = useInView();

    return (
        <section ref={ref as React.RefObject<HTMLElement>} id="pricing" className="py-20 bg-muted/40">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="mb-12 text-center">
                    <h2 className="text-2xl font-semibold text-foreground">Simple, transparent pricing</h2>
                    <p className="mt-2 text-sm text-muted-foreground">Start free. Upgrade when you need more.</p>
                </div>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 max-w-3xl mx-auto">
                    {plans.map((plan, index) => {
                        const isPro = plan.slug === 'pro';
                        return (
                            <div
                                key={plan.slug}
                                className={cn(
                                    `relative rounded-2xl border bg-card p-8 flex flex-col transition-all duration-600 ${isPro ? 'ring-2 ring-brand border-brand/30' : 'border-border'}`,
                                    inView ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-8 scale-95'
                                )}
                                style={{ transitionDelay: inView ? `${index * 120}ms` : '0ms' }}
                            >
                                {isPro && (
                                    <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                                        <Badge className="bg-brand text-brand-foreground px-3 py-1">
                                            Most Popular
                                        </Badge>
                                    </div>
                                )}

                                <div className="mb-6">
                                    <h3 className="text-lg font-semibold text-foreground">{plan.name}</h3>
                                    <div className="mt-2 flex items-baseline gap-1">
                                        <span className="text-4xl font-bold text-foreground">
                                            {formatPrice(plan.price)}
                                        </span>
                                        {plan.billing_interval && (
                                            <span className="text-muted-foreground">/{plan.billing_interval}</span>
                                        )}
                                    </div>
                                </div>

                                <ul className="mb-8 flex-1 space-y-3 text-sm">
                                    {[
                                        `${plan.max_active_events} active event${plan.max_active_events !== 1 ? 's' : ''}`,
                                        `${plan.max_photos_per_event.toLocaleString()} photos per event`,
                                        `${formatBytes(plan.max_storage_bytes)} storage`,
                                        'QR code sharing',
                                        'Guest uploads (no account needed)',
                                        'Shared photo gallery',
                                    ].map((feature) => (
                                        <li key={feature} className="flex items-center gap-2 text-muted-foreground">
                                            <Check className="h-4 w-4 shrink-0 text-brand" aria-hidden="true" />
                                            {feature}
                                        </li>
                                    ))}
                                </ul>

                                <Button
                                    asChild
                                    className={isPro ? 'bg-brand text-brand-foreground hover:bg-brand/90 w-full' : 'w-full'}
                                    variant={isPro ? 'default' : 'outline'}
                                >
                                    <Link href="/register">
                                        {isPro ? 'Get Started with Pro' : 'Get Started Free'}
                                    </Link>
                                </Button>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
