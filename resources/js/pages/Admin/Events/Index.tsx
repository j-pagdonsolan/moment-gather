import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AdminEvent } from '@/types';

interface Props {
    events: {
        data: AdminEvent[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
    };
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString();
}

export default function AdminEventsIndex({ events, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const handleFilterChange = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (status && status !== 'all') params.status = status;

        router.get('/admin/events', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handlePageChange = (page: number) => {
        const params: Record<string, string | number> = { page };
        if (search) params.search = search;
        if (status && status !== 'all') params.status = status;

        router.get('/admin/events', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Events" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <CalendarDays className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Events</h1>
                        <p className="text-xs text-muted-foreground">All events across the platform</p>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="search">Search</Label>
                                <Input
                                    id="search"
                                    placeholder="Event name or slug..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            handleFilterChange();
                                        }
                                    }}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="status">Status</Label>
                                <Select value={status} onValueChange={setStatus}>
                                    <SelectTrigger id="status">
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All statuses</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="draft">Draft</SelectItem>
                                        <SelectItem value="archived">Archived</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button onClick={handleFilterChange} className="bg-brand text-brand-foreground hover:bg-brand/90">Apply Filters</Button>
                    </CardFooter>
                </Card>

                {/* Event List */}
                <div className="grid gap-4 sm:grid-cols-1 lg:grid-cols-2 xl:grid-cols-3">
                    {events.data.map((event) => (
                        <Card key={event.uuid} className="flex flex-col">
                            <CardHeader>
                                <div className="flex items-start justify-between gap-3">
                                    <CardTitle className="text-lg">
                                        <Link
                                            href={`/admin/events/${event.uuid}`}
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
                                    <p className="text-muted-foreground">Slug</p>
                                    <p className="font-mono text-xs break-all">{event.slug}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Owner</p>
                                    <p className="font-medium">{event.owner.name}</p>
                                    <p className="text-muted-foreground text-xs break-words">
                                        {event.owner.email}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Photos</p>
                                    <p>{event.photo_count}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Created</p>
                                    <p>{formatDate(event.created_at)}</p>
                                </div>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                >
                                    <Link href={`/admin/events/${event.uuid}`}>
                                        View Details
                                    </Link>
                                </Button>
                            </CardFooter>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {events.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handlePageChange(events.current_page - 1)}
                            disabled={events.current_page === 1}
                        >
                            Previous
                        </Button>
                        <span className="text-sm">
                            Page {events.current_page} of {events.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handlePageChange(events.current_page + 1)}
                            disabled={events.current_page === events.last_page}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
