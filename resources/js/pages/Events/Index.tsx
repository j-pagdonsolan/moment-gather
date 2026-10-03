import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, CalendarPlus, MapPin, Plus, Search, Upload } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import {
    create as eventsCreate,
    destroy as eventsDestroy,
    edit as eventsEdit,
    index as eventsIndex,
    show as eventsShow,
} from '@/routes/events';
import type { Event } from '@/types';

interface PaginatedEvents {
    data: Event[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    events: PaginatedEvents;
    filters: {
        search: string;
        status: string;
    };
}

const STATUS_TABS = [
    { value: '', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
];

function formatDate(value: string | null): string {
    if (!value) return 'No date set';
    return new Date(value).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function EventsIndex({ events, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [pendingDelete, setPendingDelete] = useState<Event | null>(null);

    const navigate = (params: Record<string, string | number>) => {
        router.get('/events', { ...filters, search, ...params }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        navigate({ search, page: 1 });
    };

    const confirmDelete = () => {
        if (!pendingDelete) return;
        router.delete(eventsDestroy({ event: pendingDelete.uuid }).url, {
            onFinish: () => setPendingDelete(null),
        });
    };

    return (
        <>
            <Head title="Events" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">

                {/* Page header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-foreground">Your Events</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {events.total === 0
                                ? 'No events yet — create your first one.'
                                : `${events.total} event${events.total !== 1 ? 's' : ''} total`}
                        </p>
                    </div>
                    <Button asChild className="shrink-0 bg-brand text-brand-foreground hover:bg-brand/90">
                        <Link href={eventsCreate()}>
                            <Plus className="size-4" />
                            Create Event
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form onSubmit={handleSearch} className="relative flex-1 max-w-sm">
                        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search events..."
                            className="pl-9"
                        />
                    </form>

                    <div className="flex rounded-lg border border-border bg-muted/50 p-1 gap-1">
                        {STATUS_TABS.map((tab) => (
                            <button
                                key={tab.value}
                                onClick={() => navigate({ status: tab.value, page: 1 })}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors duration-150 ${
                                    filters.status === tab.value
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Cards */}
                {events.data.length === 0 ? (
                    <EmptyState
                        icon={CalendarPlus}
                        title={filters.search || filters.status ? 'No events match your filters' : 'No events yet'}
                        description={filters.search || filters.status ? 'Try adjusting your search or filter.' : 'Create your first event to start collecting photos.'}
                        action={
                            !filters.search && !filters.status ? (
                                <Button asChild className="bg-brand text-brand-foreground hover:bg-brand/90">
                                    <Link href={eventsCreate()}>Create Event</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {events.data.map((event) => (
                            <Card key={event.uuid} className="group flex flex-col overflow-hidden transition-shadow duration-150 hover:shadow-md">
                                <div
                                    className={`h-0.5 w-full ${event.status === 'active' ? 'bg-brand' : 'bg-border'}`}
                                    aria-hidden="true"
                                />
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="text-base font-semibold leading-snug text-foreground">
                                            <Link
                                                href={eventsShow({ event: event.uuid })}
                                                className="hover:text-brand transition-colors duration-150"
                                            >
                                                {event.name}
                                            </Link>
                                        </CardTitle>
                                        <Badge
                                            variant={event.status === 'active' ? 'default' : 'secondary'}
                                            className={`shrink-0 capitalize ${event.status === 'active' ? 'bg-brand text-brand-foreground' : ''}`}
                                        >
                                            {event.status}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="flex flex-1 flex-col gap-2 pb-4 text-sm text-muted-foreground">
                                    <div className="flex items-center gap-1.5">
                                        <CalendarDays className="size-3.5 shrink-0" aria-hidden="true" />
                                        <span>{formatDate(event.event_date)}</span>
                                    </div>
                                    {event.location && (
                                        <div className="flex items-center gap-1.5">
                                            <MapPin className="size-3.5 shrink-0" aria-hidden="true" />
                                            <span className="truncate">{event.location}</span>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-1.5">
                                        <Upload className="size-3.5 shrink-0" aria-hidden="true" />
                                        <span className={event.upload_enabled ? 'text-green-600 dark:text-green-400' : ''}>
                                            {event.upload_enabled ? 'Uploads open' : 'Uploads closed'}
                                        </span>
                                    </div>
                                </CardContent>
                                <CardFooter className="gap-2 border-t border-border pt-3">
                                    <Button asChild variant="outline" size="sm" className="flex-1">
                                        <Link href={eventsShow({ event: event.uuid })}>View</Link>
                                    </Button>
                                    <Button asChild variant="outline" size="sm" className="flex-1">
                                        <Link href={eventsEdit({ event: event.uuid })}>Edit</Link>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="flex-1 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        onClick={() => setPendingDelete(event)}
                                    >
                                        Delete
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {events.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Page {events.current_page} of {events.last_page} · {events.total} events
                        </p>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm"
                                disabled={events.current_page === 1}
                                onClick={() => navigate({ page: events.current_page - 1 })}
                            >
                                Previous
                            </Button>
                            <Button variant="outline" size="sm"
                                disabled={events.current_page === events.last_page}
                                onClick={() => navigate({ page: events.current_page + 1 })}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={pendingDelete !== null} onOpenChange={(open) => !open && setPendingDelete(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete event</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete{pendingDelete ? ` "${pendingDelete.name}"` : ' this event'}? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={confirmDelete}>Delete</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

EventsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Events', href: eventsIndex() },
    ],
};
