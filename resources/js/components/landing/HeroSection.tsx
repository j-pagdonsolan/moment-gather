import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function HeroSection() {
    const page = usePage<{ auth: { user: { name: string } | null } }>();
    const user = page.props.auth?.user ?? null;

    return (
        <section className="relative overflow-hidden py-24 sm:py-32">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 -z-10"
                style={{ background: 'radial-gradient(ellipse 80% 50% at 50% -20%, var(--color-brand-muted), transparent)' }}
            />
            <div className="mx-auto max-w-4xl px-4 text-center sm:px-6">
                <p className="mb-4 text-sm font-medium uppercase tracking-widest text-brand">
                    Event Photo Sharing
                </p>
                <h1 className="text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                    Everyone captures the moment.{' '}
                    <span className="text-brand">We gather them.</span>
                </h1>
                <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                    Create an event, share a QR code, and collect everyone's photos in one
                    beautiful shared gallery — no app downloads, no accounts required for guests.
                </p>
                <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    {user ? (
                        <Button asChild size="lg" className="bg-brand text-brand-foreground hover:bg-brand/90 min-w-40">
                            <Link href="/dashboard">Go to Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild size="lg" className="bg-brand text-brand-foreground hover:bg-brand/90 min-w-40">
                                <Link href="/register">
                                    Get Started Free
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Link>
                            </Button>
                            <Button asChild size="lg" variant="outline" className="min-w-40">
                                <a href="#how-it-works">See How It Works</a>
                            </Button>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}
