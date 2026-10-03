import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export default function CtaSection() {
    return (
        <section className="py-20 bg-brand">
            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6">
                <h2 className="text-3xl font-bold text-brand-foreground">
                    Ready to gather every moment?
                </h2>
                <p className="mt-4 text-lg text-brand-foreground/80">
                    Create your first event for free. No credit card required.
                </p>
                <div className="mt-8">
                    <Button asChild size="lg" variant="secondary" className="min-w-40">
                        <Link href="/register">Get Started Free</Link>
                    </Button>
                </div>
            </div>
        </section>
    );
}
