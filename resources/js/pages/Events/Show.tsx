import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, Images } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import QrCode from '@/components/QrCode';
import { dashboard } from '@/routes';
import {
    destroy as eventsDestroy,
    edit as eventsEdit,
    index as eventsIndex,
    show as eventsShow,
} from '@/routes/events';
import type { Event } from '@/types';

interface Props {
    event: Event;
    publicUrl: string;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : 'No date set';
}

export default function EventsShow({ event, publicUrl }: Props) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const confirmDelete = () => {
        router.delete(eventsDestroy({ event: event.uuid }).url, {
            onSuccess: () => router.visit(eventsIndex().url),
        });
    };

    return (
        <>
            <Head title={event.name} />
            <div className="flex flex-col gap-6 p-4">
                {/* Page hero header */}
                <div className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-brand-muted via-card to-card p-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex flex-col gap-2">
                            <Badge
                                variant={event.status === 'active' ? 'default' : 'secondary'}
                                className={event.status === 'active' ? 'w-fit bg-brand text-brand-foreground' : 'w-fit'}
                            >
                                {event.status}
                            </Badge>
                            <h1 className="text-2xl font-bold text-foreground sm:text-3xl">{event.name}</h1>
                            {event.event_date && (
                                <p className="text-sm text-muted-foreground">
                                    📅 {new Date(event.event_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                                </p>
                            )}
                            {event.location && (
                                <p className="text-sm text-muted-foreground">📍 {event.location}</p>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={eventsEdit({ event: event.uuid })}>Edit</Link>
                            </Button>
                            <Button variant="destructive" size="sm" onClick={() => setConfirmingDelete(true)}>
                                Delete
                            </Button>
                        </div>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Event Details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <p className="text-muted-foreground text-sm">Description</p>
                            <p className="whitespace-pre-line">{event.description ?? 'No description'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Event Date</p>
                            <p>{formatDate(event.event_date)}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Location</p>
                            <p>{event.location ?? 'No location'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Status</p>
                            <Badge variant={event.status === 'active' ? 'default' : 'secondary'}>
                                {event.status}
                            </Badge>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Uploads</p>
                            <p>{event.upload_enabled ? 'Uploads enabled' : 'Uploads disabled'}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Created</p>
                            <p>{new Date(event.created_at).toLocaleDateString()}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card className="overflow-hidden">
                    <div className="bg-gradient-to-br from-brand-muted to-muted p-6">
                        <CardTitle className="mb-1 text-base font-semibold">Event QR Code</CardTitle>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Guests scan this to open <strong>{event.name}</strong> and upload their photos.
                        </p>
                        <div className="flex justify-center">
                            <QrCode url={publicUrl} fileName={`momentgather-${event.slug}-qr.png`} />
                        </div>
                        <p className="mt-3 text-center text-xs text-muted-foreground">
                            Point your phone camera at this code to open the upload page
                        </p>
                        <p className="mt-1 break-all text-center text-xs text-muted-foreground/70">{publicUrl}</p>
                    </div>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Share &amp; View</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 sm:flex-row">
                        <Button asChild variant="outline" className="min-h-11 w-full sm:w-auto">
                            <a href={publicUrl} target="_blank" rel="noopener noreferrer">
                                <ExternalLink className="size-4" />
                                Open Public Page
                            </a>
                        </Button>
                        <Button asChild variant="outline" className="min-h-11 w-full sm:w-auto">
                            <a href={`/e/${event.slug}/gallery`} target="_blank" rel="noopener noreferrer">
                                <Images className="size-4" />
                                View Gallery
                            </a>
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent aria-describedby="delete-event-description">
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription id="delete-event-description">
                            Are you sure you want to delete "{event.name}"? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmDelete}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

EventsShow.layout = (props: { event: Event; publicUrl: string }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Events',
            href: eventsIndex(),
        },
        {
            title: props.event.name,
            href: eventsShow({ event: props.event.uuid }),
        },
    ],
});
