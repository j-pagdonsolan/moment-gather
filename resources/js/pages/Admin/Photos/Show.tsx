import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Image as ImageIcon } from 'lucide-react';
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
import { formatBytes } from '@/lib/utils';
import { show as adminEventsShow } from '@/routes/admin/events';
import { destroy as adminPhotosDestroy } from '@/routes/admin/photos';
import type { AdminPhoto } from '@/types/admin';

interface Props {
    photo: AdminPhoto;
}

export default function AdminPhotosShow({ photo }: Props) {
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const deleteForm = useForm({});

    const handleDelete = () => {
        deleteForm.delete(adminPhotosDestroy({ photo: photo.uuid }).url);
    };

    return (
        <>
            <Head title={`Photo: ${photo.original_filename}`} />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                            <ImageIcon className="size-4" aria-hidden="true" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-foreground">Photo Details</h1>
                            <p className="text-xs text-muted-foreground">Photo detail</p>
                        </div>
                    </div>
                    <Button variant="destructive" onClick={() => setConfirmingDelete(true)}>
                        Delete
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>File Information</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <p className="text-muted-foreground text-sm">UUID</p>
                            <p className="break-all font-mono text-sm">{photo.uuid}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Filename</p>
                            <p className="break-all font-medium">{photo.original_filename}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">MIME Type</p>
                            <p>{photo.mime_type}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">File Size</p>
                            <p>{formatBytes(photo.file_size)}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Dimensions</p>
                            <p>
                                {photo.width && photo.height
                                    ? `${photo.width} x ${photo.height}`
                                    : 'Not available'}
                            </p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Status</p>
                            <Badge
                                variant={
                                    photo.status === 'ready'
                                        ? 'default'
                                        : photo.status === 'failed'
                                          ? 'destructive'
                                          : 'secondary'
                                }
                            >
                                {photo.status}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Event Information</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p className="text-muted-foreground text-sm">Event Name</p>
                            <Link
                                href={adminEventsShow({ event: photo.event.uuid }).url}
                                className="text-primary hover:underline"
                            >
                                {photo.event.name}
                            </Link>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Event UUID</p>
                            <p className="break-all font-mono text-sm">{photo.event.uuid}</p>
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
                            <p>{photo.owner.name}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Email</p>
                            <p className="break-all">{photo.owner.email}</p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Timestamps</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p className="text-muted-foreground text-sm">Created</p>
                            <p>{new Date(photo.created_at).toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground text-sm">Updated</p>
                            <p>{new Date(photo.updated_at).toLocaleString()}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete photo</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete "{photo.original_filename}"? This action cannot be
                            undone.
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
