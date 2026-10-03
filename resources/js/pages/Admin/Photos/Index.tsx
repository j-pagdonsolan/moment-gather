import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Image as ImageIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes } from '@/lib/utils';
import { show as adminPhotosShow } from '@/routes/admin/photos';
import type { AdminPhoto, PaginationMeta } from '@/types/admin';

interface Props {
    photos: {
        data: AdminPhoto[];
    } & PaginationMeta;
    filters: {
        event?: string;
        status?: string;
        owner?: string;
    };
}

export default function AdminPhotosIndex({ photos, filters }: Props) {
    const [eventFilter, setEventFilter] = useState(filters.event ?? '');
    const [statusFilter, setStatusFilter] = useState(filters.status ?? 'all');
    const [ownerFilter, setOwnerFilter] = useState(filters.owner ?? '');

    const applyFilters = () => {
        router.get(
            '/admin/photos',
            {
                event: eventFilter || undefined,
                status: statusFilter !== 'all' ? statusFilter : undefined,
                owner: ownerFilter || undefined,
            },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        setEventFilter('');
        setStatusFilter('all');
        setOwnerFilter('');
        router.get('/admin/photos', {}, { preserveState: true });
    };

    const handlePageChange = (page: number) => {
        router.get(
            '/admin/photos',
            {
                page,
                event: eventFilter || undefined,
                status: statusFilter !== 'all' ? statusFilter : undefined,
                owner: ownerFilter || undefined,
            },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Photos" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <ImageIcon className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Photos</h1>
                        <p className="text-xs text-muted-foreground">All photos across all events</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-4">
                        <div>
                            <Label htmlFor="event-filter">Event (UUID/ID)</Label>
                            <Input
                                id="event-filter"
                                value={eventFilter}
                                onChange={(e) => setEventFilter(e.target.value)}
                                placeholder="Event UUID or ID"
                            />
                        </div>
                        <div>
                            <Label htmlFor="status-filter">Status</Label>
                            <Select value={statusFilter} onValueChange={setStatusFilter}>
                                <SelectTrigger id="status-filter">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All statuses</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="ready">Ready</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="owner-filter">Owner (ID/Email)</Label>
                            <Input
                                id="owner-filter"
                                value={ownerFilter}
                                onChange={(e) => setOwnerFilter(e.target.value)}
                                placeholder="Owner ID or Email"
                            />
                        </div>
                        <div className="flex items-end gap-2">
                            <Button onClick={applyFilters} className="bg-brand text-brand-foreground hover:bg-brand/90">Apply</Button>
                            <Button variant="outline" onClick={clearFilters}>
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4">
                    {photos.data.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center">
                                <p className="text-muted-foreground">No photos found.</p>
                            </CardContent>
                        </Card>
                    ) : (
                        photos.data.map((photo) => (
                            <Card key={photo.id}>
                                <CardContent className="flex flex-col gap-4 p-6">
                                    <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
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
                                        <div>
                                            <p className="text-muted-foreground text-sm">Event</p>
                                            <p>{photo.event.name}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-sm">Owner</p>
                                            <p>{photo.owner.name}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-sm">Owner Email</p>
                                            <p className="break-all text-sm">{photo.owner.email}</p>
                                        </div>
                                        <div className="flex items-end">
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={adminPhotosShow({ photo: photo.uuid }).url}>
                                                    View Details
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>

                {photos.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        <Button
                            variant="outline"
                            onClick={() => handlePageChange(photos.current_page - 1)}
                            disabled={photos.current_page === 1}
                        >
                            Previous
                        </Button>
                        <span className="text-sm">
                            Page {photos.current_page} of {photos.last_page}
                        </span>
                        <Button
                            variant="outline"
                            onClick={() => handlePageChange(photos.current_page + 1)}
                            disabled={photos.current_page === photos.last_page}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
