import { Head, Link } from '@inertiajs/react';
import { Check, QrCode, Upload } from 'lucide-react';

import PhotoUploader from '@/components/PhotoUploader';
import { Button } from '@/components/ui/button';
import type { PublicEvent } from '@/types';

interface Props {
    event: PublicEvent;
}

function formatEventDate(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return new Date(value).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

const steps = [
    { icon: QrCode, label: 'Scan' },
    { icon: Upload, label: 'Upload' },
    { icon: Check, label: 'Done' },
] as const;

export default function PublicEventPage({ event }: Props) {
    const friendlyDate = formatEventDate(event.event_date);

    return (
        <>
            <Head title={`${event.name} | MomentGather`} />

            <div className="mx-auto flex min-h-screen w-full max-w-md flex-col gap-8 px-4 py-10 sm:max-w-lg">
                {/* Hero band — decorative gradient panel carrying the event identity (no image data). */}
                <header className="flex flex-col items-center gap-3 overflow-hidden rounded-2xl bg-gradient-to-b from-primary/10 via-muted to-muted px-6 py-10 text-center">
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        Share Your Moments
                    </p>
                    <h1 className="text-3xl font-semibold text-balance text-foreground">
                        {event.name}
                    </h1>
                    {friendlyDate && (
                        <p className="text-base text-muted-foreground">{friendlyDate}</p>
                    )}
                    {event.location && (
                        <p className="text-base text-muted-foreground">{event.location}</p>
                    )}
                </header>

                {event.description && (
                    <p className="text-center text-base leading-relaxed text-foreground">
                        {event.description}
                    </p>
                )}

                {/* Guest flow: Scan → Upload → Done */}
                <ol className="flex flex-wrap items-center justify-center gap-x-2 gap-y-4">
                    {steps.map((step, index) => (
                        <li key={step.label} className="flex items-center gap-2">
                            <div className="flex min-w-[64px] flex-col items-center gap-1.5">
                                <span className="flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <step.icon className="size-5" aria-hidden="true" />
                                </span>
                                <span className="text-sm font-medium text-foreground">
                                    {step.label}
                                </span>
                            </div>
                            {index < steps.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className="h-px w-6 bg-border sm:w-10"
                                />
                            )}
                        </li>
                    ))}
                </ol>

                {/* Upload CTA */}
                <div className="flex flex-col gap-3">
                    {event.upload_enabled ? (
                        <div className="flex flex-col gap-3">
                            <h2 className="text-center text-lg font-semibold text-foreground">
                                Add your photos
                            </h2>
                            <PhotoUploader slug={event.slug} />
                        </div>
                    ) : (
                        <p className="text-center text-base text-muted-foreground">
                            Photo uploads are currently closed.
                        </p>
                    )}

                    <Button asChild size="lg" variant="outline" className="min-h-11 w-full">
                        <Link href={`/e/${event.slug}/gallery`}>
                            View Gallery{event.photoCount > 0 ? ` — ${event.photoCount} photos` : ''}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
