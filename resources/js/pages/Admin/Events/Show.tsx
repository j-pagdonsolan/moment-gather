import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays } from 'lucide-react';
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
import { archive as adminEventsArchive, destroy as adminEventsDestroy } from '@/routes/admin/events';
import type { AdminEvent } from '@/types/admin';

interface Props {
    event: AdminEvent;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : 'No date set';
}

export default function AdminEventsShow({ event }: Props) {
    const [confirmingArchive, setConfirmingArchive] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const archiveForm = useForm({});
    const deleteForm = useForm({});

    const handleArchive = () => {
        archiveForm.post(adminEventsArchive({ event: event.uuid }).url, {
            onSuccess: () => setConfirmingArchive(false),
        });
    };

    const handleDelete = () => {
        deleteForm.delete(adminEventsDestroy({ event: event.uuid }).url);
    };

    return (
        <>
            <Head title={`Event: ${event.name}`} />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                            <CalendarDays className="size-4" aria-hidden="true" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-foreground">{event.name}</h1>
                            <p className="text-xs text-muted-foreground">Event details</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setConfirmingArchive(true)}
                            disabled={event.status === 'archived'}
                        >
                            Archive
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
                            <p className="text-muted-foreground text-sm">Name</p>
                            <p>{event.name}</p>
                        </div>
                        <div className="sm:col-span-2">
                            <p className="text-muted-foreground text-sm">Slug</p>
                            <p>{event.slug}</p>
                        </div>
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
                            <Badge
                                variant={
                                    event.status === 'active'
                                        ? 'default'
                                        : event.status === 'archived'
                                          ? 'secondary'
                                          : 'outline'
                                }
                            >
                                {event.status}
                            </Badge>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Uploads</p>
                            <Badge variant={event.upload_enabled ? 'default' : 'secondary'}>
                                {event.upload_enabled ? 'Enabled' : 'Disabled'}
                            </Badge>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Photo Count</p>
                            <p>{event.photo_count}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Created</p>
                            <p>{new Date(event.created_at).toLocaleDateString()}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Owner Information</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p className="text-muted-foreground text-sm">Name</p>
                            <p>{event.owner.name}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Email</p>
                            <p>{event.owner.email}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={confirmingArchive} onOpenChange={setConfirmingArchive}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Archive event</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to archive "{event.name}"? This will set the event status to
                            archived.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button onClick={handleArchive} disabled={archiveForm.processing}>
                            Archive
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete "{event.name}"? This is destructive and cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={handleDelete} disabled={deleteForm.processing}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
