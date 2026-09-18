import { Head, Link } from '@inertiajs/react';
import { Archive, CalendarCheck, CalendarDays, CalendarPlus } from 'lucide-react';

import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { create as eventsCreate, show as eventsShow } from '@/routes/events';
import type { DashboardStats, Event } from '@/types';

interface Props {
    stats: DashboardStats;
    recentEvents: Pick<Event, 'uuid' | 'name' | 'status' | 'event_date' | 'created_at'>[];
}

const statCards = [
    { key: 'totalEvents', label: 'Total Events', icon: CalendarDays },
    { key: 'activeEvents', label: 'Active Events', icon: CalendarCheck },
    { key: 'archivedEvents', label: 'Archived Events', icon: Archive },
] as const;

export default function Dashboard({ stats, recentEvents }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                {/* Top action */}
                <div className="flex justify-end">
                    <Button asChild>
                        <Link href={eventsCreate()}>Create Event</Link>
                    </Button>
                </div>

                {/* Stat cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    {statCards.map(({ key, label, icon: Icon }) => (
                        <Card key={key}>
                            <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {label}
                                </CardTitle>
                                <Icon className="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold">{stats[key]}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Recent events */}
                <Card>
                    <CardHeader>
                        <CardTitle>Recent Events</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentEvents.length === 0 ? (
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
                            <ul className="divide-y">
                                {recentEvents.map((event) => (
                                    <li key={event.uuid}>
                                        <Link
                                            href={eventsShow({ event: event.uuid })}
                                            className="-mx-2 flex min-h-[44px] items-center justify-between gap-3 rounded-md px-2 py-3 transition-colors hover:bg-accent hover:text-accent-foreground"
                                        >
                                            <span className="min-w-0 truncate font-medium">
                                                {event.name}
                                            </span>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <Badge
                                                    variant={
                                                        event.status === 'active'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {event.status}
                                                </Badge>
                                                <span className="text-sm text-muted-foreground">
                                                    {event.event_date
                                                        ? new Date(
                                                              event.event_date,
                                                          ).toLocaleDateString()
                                                        : 'No date set'}
                                                </span>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
