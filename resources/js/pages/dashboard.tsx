import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import { create as eventsCreate } from '@/routes/events';
import type { DashboardStats, Event } from '@/types';

interface Props {
    stats: DashboardStats;
    recentEvents: Pick<Event, 'uuid' | 'name' | 'status' | 'event_date' | 'created_at'>[];
}

export default function Dashboard({ stats, recentEvents }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                {/* Stat cards */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Total Events</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{stats.totalEvents}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Active Events</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{stats.activeEvents}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Archived Events</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{stats.archivedEvents}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent events */}
                <Card>
                    <CardHeader>
                        <CardTitle>Recent Events</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentEvents.length === 0 ? (
                            <p className="text-muted-foreground">
                                You have no events yet.{' '}
                                <Link href={eventsCreate()} className="underline">
                                    Create your first event.
                                </Link>
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {recentEvents.map((event) => (
                                    <li key={event.uuid} className="flex items-center justify-between py-3">
                                        <span className="font-medium">{event.name}</span>
                                        <div className="flex items-center gap-3">
                                            <Badge variant={event.status === 'active' ? 'default' : 'secondary'}>
                                                {event.status}
                                            </Badge>
                                            <span className="text-muted-foreground text-sm">
                                                {event.event_date
                                                    ? new Date(event.event_date).toLocaleDateString()
                                                    : 'No date set'}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                {/* Always-visible Create Event link */}
                <div>
                    <Link
                        href={eventsCreate()}
                        className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex items-center rounded-md px-4 py-2 text-sm font-medium transition-colors"
                    >
                        Create Event
                    </Link>
                </div>
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
