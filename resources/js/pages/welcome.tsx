import { Head } from '@inertiajs/react';
import CtaSection from '@/components/landing/CtaSection';
import FaqSection from '@/components/landing/FaqSection';
import FeaturesSection from '@/components/landing/FeaturesSection';
import FooterSection from '@/components/landing/FooterSection';
import HeroSection from '@/components/landing/HeroSection';
import HowItWorksSection from '@/components/landing/HowItWorksSection';
import LandingNav from '@/components/landing/LandingNav';
import PricingSection from '@/components/landing/PricingSection';

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

export default function Welcome({ plans }: Props) {
    return (
        <>
            <Head title="MomentGather — Gather Every Moment" />
            <div className="min-h-screen bg-background text-foreground">
                <LandingNav />
                <main role="main">
                    <HeroSection />
                    <HowItWorksSection />
                    <FeaturesSection />
                    <PricingSection plans={plans} />
                    <FaqSection />
                    <CtaSection />
                </main>
                <FooterSection />
            </div>
        </>
    );
}
