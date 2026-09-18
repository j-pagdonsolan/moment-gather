import { Head } from '@inertiajs/react';

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

export default function PublicEventPage({ event }: Props) {
    const friendlyDate = formatEventDate(event.event_date);

    return (
        <>
            <Head title={`${event.name} | MomentGather`} />

            <div className="mx-auto flex min-h-screen w-full max-w-md flex-col gap-8 px-4 py-10 sm:max-w-lg">
                {/* Cover placeholder — static, no image data */}
                <div
                    aria-hidden="true"
                    className="flex aspect-video w-full items-center justify-center rounded-xl bg-muted text-muted-foreground"
                />

                <header className="flex flex-col gap-2 text-center">
                    <p className="text-sm font-medium tracking-wide text-muted-foreground uppercase">
                        Share Your Moments
                    </p>
                    <h1 className="text-3xl font-semibold text-foreground">{event.name}</h1>
                    {friendlyDate && (
                        <p className="text-muted-foreground">{friendlyDate}</p>
                    )}
                    {event.location && (
                        <p className="text-muted-foreground">{event.location}</p>
                    )}
                </header>

                {event.description && (
                    <p className="text-center text-sm leading-relaxed text-foreground">
                        {event.description}
                    </p>
                )}

                <div className="mt-2 flex flex-col gap-3">
                    {event.upload_enabled ? (
                        <Button size="lg" className="w-full">
                            Upload Photos
                        </Button>
                    ) : (
                        <p className="text-center text-sm text-muted-foreground">
                            Photo uploads are currently closed.
                        </p>
                    )}

                    <Button size="lg" variant="outline" className="w-full">
                        View Gallery
                    </Button>
                </div>
            </div>
        </>
    );
}
