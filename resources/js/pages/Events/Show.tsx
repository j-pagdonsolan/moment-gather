import { Head, Link, router } from '@inertiajs/react';
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
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : 'No date set';
}

const comingSoonSections = ['QR Code', 'Photo Gallery', 'Uploads'] as const;

export default function EventsShow({ event }: Props) {
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
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold">{event.name}</h1>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={eventsEdit({ event: event.uuid })}>Edit</Link>
                        </Button>
                        <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                            Delete
                        </Button>
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

                <div className="grid gap-4 md:grid-cols-3">
                    {comingSoonSections.map((section) => (
                        <Card key={section}>
                            <CardHeader>
                                <CardTitle>{section}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Badge variant="secondary">Coming Soon</Badge>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription>
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

EventsShow.layout = (props: { event: Event }) => ({
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
