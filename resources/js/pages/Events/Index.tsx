import { Head, Link, router } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    create as eventsCreate,
    destroy as eventsDestroy,
    edit as eventsEdit,
    index as eventsIndex,
    show as eventsShow,
} from '@/routes/events';
import type { Event } from '@/types';

interface Props {
    events: Event[];
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : 'No date set';
}

export default function EventsIndex({ events }: Props) {
    const [pendingDelete, setPendingDelete] = useState<Event | null>(null);

    const confirmDelete = () => {
        if (!pendingDelete) {
            return;
        }

        router.delete(eventsDestroy({ event: pendingDelete.uuid }).url, {
            onFinish: () => setPendingDelete(null),
        });
    };

    return (
        <>
            <Head title="Events" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold">Events</h1>
                    <Button asChild>
                        <Link href={eventsCreate()}>Create Event</Link>
                    </Button>
                </div>

                {events.length === 0 ? (
                    <EmptyState
                        icon={CalendarPlus}
                        title="No events yet"
                        description="Create your first event to start collecting photos."
                        action={
                            <Button asChild>
                                <Link href={eventsCreate()}>Create Event</Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {events.map((event) => (
                            <Card key={event.uuid} className="flex flex-col">
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-3">
                                        <CardTitle className="text-lg">
                                            <Link
                                                href={eventsShow({ event: event.uuid })}
                                                className="hover:underline"
                                            >
                                                {event.name}
                                            </Link>
                                        </CardTitle>
                                        <Badge
                                            variant={
                                                event.status === 'active'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {event.status}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-1 flex-col gap-3 text-sm">
                                    <div>
                                        <p className="text-muted-foreground">Event Date</p>
                                        <p>{formatDate(event.event_date)}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground">Location</p>
                                        <p>{event.location ?? 'No location'}</p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground">Uploads</p>
                                        <p>
                                            {event.upload_enabled
                                                ? 'Uploads on'
                                                : 'Uploads off'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-muted-foreground">Created</p>
                                        <p>
                                            {new Date(
                                                event.created_at,
                                            ).toLocaleDateString()}
                                        </p>
                                    </div>
                                </CardContent>
                                <CardFooter className="flex flex-wrap gap-2">
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11 flex-1"
                                    >
                                        <Link href={eventsShow({ event: event.uuid })}>
                                            View
                                        </Link>
                                    </Button>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11 flex-1"
                                    >
                                        <Link href={eventsEdit({ event: event.uuid })}>
                                            Edit
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        className="min-h-11 flex-1"
                                        onClick={() => setPendingDelete(event)}
                                    >
                                        Delete
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <Dialog open={pendingDelete !== null} onOpenChange={(open) => !open && setPendingDelete(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete
                            {pendingDelete ? ` "${pendingDelete.name}"` : ' this event'}? This action cannot be undone.
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

EventsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Events',
            href: eventsIndex(),
        },
    ],
};
