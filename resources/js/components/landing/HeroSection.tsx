import { Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useInView } from '@/hooks/use-in-view';

export default function HeroSection() {
    const page = usePage<{ auth: { user: { name: string } | null } }>();
    const user = page.props.auth?.user ?? null;
    const { ref, inView } = useInView(0.1);

    return (
        <section ref={ref as React.RefObject<HTMLElement>} className="relative py-24 sm:py-32 bg-background overflow-hidden">

            {/* Keyframe animations for orbs */}
            <style>{`
                @keyframes orb-float-1 {
                    0%, 100% { transform: translate(0px, 0px) scale(1); }
                    33%       { transform: translate(40px, -50px) scale(1.06); }
                    66%       { transform: translate(-25px, 25px) scale(0.95); }
                }
                @keyframes orb-float-2 {
                    0%, 100% { transform: translate(0px, 0px) scale(1); }
                    40%       { transform: translate(-50px, 35px) scale(1.08); }
                    70%       { transform: translate(30px, -30px) scale(0.96); }
                }
                @keyframes orb-float-3 {
                    0%, 100% { transform: translate(0px, 0px) scale(1); }
                    30%       { transform: translate(25px, 45px) scale(1.04); }
                    65%       { transform: translate(-35px, -20px) scale(0.97); }
                }
                @keyframes orb-float-4 {
                    0%, 100% { transform: translate(0px, 0px) scale(1); }
                    50%       { transform: translate(-30px, -40px) scale(1.07); }
                }
                .hero-orb-1 { animation: orb-float-1 14s ease-in-out infinite; }
                .hero-orb-2 { animation: orb-float-2 18s ease-in-out infinite; }
                .hero-orb-3 { animation: orb-float-3 16s ease-in-out infinite; }
                .hero-orb-4 { animation: orb-float-4 20s ease-in-out infinite; }
            `}</style>

            {/* White center vignette — keeps text area clean */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0"
                style={{ background: 'radial-gradient(ellipse 60% 70% at 50% 50%, var(--background) 0%, transparent 100%)' }}
            />

            {/* Subtle top gradient */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0"
                style={{ background: 'radial-gradient(ellipse 80% 40% at 50% -10%, oklch(0.72 0.16 280 / 0.15), transparent)' }}
            />

            {/* Orb 1 — large, top-left */}
            <div
                aria-hidden="true"
                className="hero-orb-1 pointer-events-none absolute"
                style={{
                    top: '-10%', left: '-5%',
                    width: '500px', height: '500px',
                    borderRadius: '50%',
                    background: 'oklch(0.65 0.14 280)',
                    filter: 'blur(100px)',
                    opacity: 0.3,
                }}
            />

            {/* Orb 2 — medium, top-right */}
            <div
                aria-hidden="true"
                className="hero-orb-2 pointer-events-none absolute"
                style={{
                    top: '-15%', right: '-8%',
                    width: '450px', height: '450px',
                    borderRadius: '50%',
                    background: 'oklch(0.62 0.16 280)',
                    filter: 'blur(100px)',
                    opacity: 0.25,
                }}
            />

            {/* Orb 3 — small, bottom-left */}
            <div
                aria-hidden="true"
                className="hero-orb-3 pointer-events-none absolute"
                style={{
                    bottom: '-10%', left: '5%',
                    width: '300px', height: '300px',
                    borderRadius: '50%',
                    background: 'oklch(0.68 0.12 280)',
                    filter: 'blur(80px)',
                    opacity: 0.2,
                }}
            />

            {/* Orb 4 — medium, bottom-right */}
            <div
                aria-hidden="true"
                className="hero-orb-4 pointer-events-none absolute"
                style={{
                    bottom: '-10%', right: '-5%',
                    width: '420px', height: '420px',
                    borderRadius: '50%',
                    background: 'oklch(0.64 0.14 280)',
                    filter: 'blur(100px)',
                    opacity: 0.2,
                }}
            />

            {/* Content */}
            <div className="relative z-10 mx-auto max-w-4xl px-4 text-center sm:px-6">
                <p className={cn(
                    'mb-4 text-sm font-medium uppercase tracking-widest text-brand transition-all duration-500',
                    inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'
                )}>
                    Event Photo Sharing
                </p>
                <h1 className={cn(
                    'text-4xl font-bold leading-tight tracking-tight sm:text-5xl lg:text-6xl transition-all duration-700 delay-100',
                    inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                )}>
                    Everyone captures the moment.{' '}
                    <span className="text-brand">We gather them.</span>
                </h1>
                <p className={cn(
                    'mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground transition-all duration-700 delay-200',
                    inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                )}>
                    Create an event, share a QR code, and collect everyone's photos in one
                    beautiful shared gallery — no app downloads, no accounts required for guests.
                </p>
                <div className={cn(
                    'mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row transition-all duration-700 delay-300',
                    inView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                )}>
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
