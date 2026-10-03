import { Link } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    {/* Brand logo */}
                    <div className="flex flex-col items-center gap-4">
                        <Link href={home()} className="flex items-center gap-2.5">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-brand text-brand-foreground shadow-sm">
                                <Camera className="size-5" aria-hidden="true" />
                            </div>
                            <span className="text-lg font-semibold text-foreground">MomentGather</span>
                        </Link>

                        <div className="space-y-1 text-center">
                            <h1 className="text-xl font-semibold text-foreground">{title}</h1>
                            <p className="text-sm text-muted-foreground">{description}</p>
                        </div>
                    </div>

                    {/* Form card */}
                    <div className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
