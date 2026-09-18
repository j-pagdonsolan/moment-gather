import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
                    <p className="text-muted-foreground">
                        You have no events yet.{' '}
                        <Link href={eventsCreate()} className="underline">
                            Create your first event.
                        </Link>
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="px-4 py-3 font-medium">Name</th>
                                    <th className="px-4 py-3 font-medium">Event Date</th>
                                    <th className="px-4 py-3 font-medium">Location</th>
                                    <th className="px-4 py-3 font-medium">Status</th>
                                    <th className="px-4 py-3 font-medium">Uploads</th>
                                    <th className="px-4 py-3 font-medium">Created</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr key={event.uuid} className="border-b last:border-0">
                                        <td className="px-4 py-3 font-medium">{event.name}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{formatDate(event.event_date)}</td>
                                        <td className="px-4 py-3 text-muted-foreground">{event.location ?? 'No location'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={event.status === 'active' ? 'default' : 'secondary'}>
                                                {event.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {event.upload_enabled ? 'Uploads on' : 'Uploads off'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {new Date(event.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={eventsShow({ event: event.uuid })}>View</Link>
                                                </Button>
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={eventsEdit({ event: event.uuid })}>Edit</Link>
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    onClick={() => setPendingDelete(event)}
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
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
