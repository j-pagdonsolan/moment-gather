import { Head, Link, usePage } from '@inertiajs/react';
import { Archive, CalendarCheck, CalendarDays, CalendarPlus, Plus, TrendingUp } from 'lucide-react';

import EmptyState from '@/components/empty-state';
import StatCard from '@/components/StatCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { create as eventsCreate, show as eventsShow } from '@/routes/events';
import type { Auth, DashboardStats, Event } from '@/types';

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
    const { auth } = usePage<{ auth: Auth }>().props;
    const firstName = auth.user.name.split(' ')[0];

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-8 p-4 sm:p-6">
                {/* Welcome header */}
                <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-foreground">
                            Welcome back, {firstName} 👋
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Here's what's happening with your events.
                        </p>
                    </div>
                    <Button asChild className="mt-3 sm:mt-0 bg-brand text-brand-foreground hover:bg-brand/90">
                        <Link href={eventsCreate()}>
                            <Plus className="size-4" />
                            Create Event
                        </Link>
                    </Button>
                </div>

                {/* Stat cards */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {statCards.map(({ key, label, icon: Icon }) => (
                        <StatCard key={key} label={label} value={stats[key]} icon={Icon} />
                    ))}
                </div>

                {/* Quick actions */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Link
                        href={eventsCreate()}
                        className="group flex items-center gap-4 rounded-xl border border-dashed border-border bg-card p-4 transition-colors duration-150 hover:border-brand hover:bg-brand-muted"
                    >
                        <div className="flex size-10 items-center justify-center rounded-lg bg-brand-muted text-brand group-hover:bg-brand group-hover:text-brand-foreground transition-colors duration-150">
                            <CalendarPlus className="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <p className="font-medium text-foreground">Create a new event</p>
                            <p className="text-xs text-muted-foreground">Get a QR code in seconds</p>
                        </div>
                    </Link>
                    <Link
                        href="/billing"
                        className="group flex items-center gap-4 rounded-xl border border-dashed border-border bg-card p-4 transition-colors duration-150 hover:border-brand hover:bg-brand-muted"
                    >
                        <div className="flex size-10 items-center justify-center rounded-lg bg-brand-muted text-brand group-hover:bg-brand group-hover:text-brand-foreground transition-colors duration-150">
                            <TrendingUp className="size-5" aria-hidden="true" />
                        </div>
                        <div>
                            <p className="font-medium text-foreground">View your plan</p>
                            <p className="text-xs text-muted-foreground">Check usage and limits</p>
                        </div>
                    </Link>
                </div>

                {/* Recent events */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <div>
                            <CardTitle className="text-base font-semibold">Recent Events</CardTitle>
                            <p className="text-xs text-muted-foreground mt-0.5">Your 5 most recent events</p>
                        </div>
                        <Button asChild variant="ghost" size="sm" className="text-xs text-muted-foreground">
                            <Link href="/events">View all →</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {recentEvents.length === 0 ? (
                            <EmptyState
                                icon={CalendarPlus}
                                title="No events yet"
                                description="Create your first event to start collecting photos."
                                action={
                                    <Button asChild className="bg-brand text-brand-foreground hover:bg-brand/90">
                                        <Link href={eventsCreate()}>Create Event</Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {recentEvents.map((event) => (
                                    <Link
                                        key={event.uuid}
                                        href={eventsShow({ event: event.uuid })}
                                        className="group flex flex-col gap-2 rounded-xl border border-border bg-muted/30 p-4 transition-all duration-150 hover:border-brand hover:bg-brand-muted hover:shadow-sm"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="font-medium text-foreground truncate group-hover:text-brand transition-colors duration-150">
                                                {event.name}
                                            </span>
                                            <Badge
                                                variant={event.status === 'active' ? 'default' : 'secondary'}
                                                className={`shrink-0 text-xs ${event.status === 'active' ? 'bg-brand text-brand-foreground' : ''}`}
                                            >
                                                {event.status}
                                            </Badge>
                                        </div>
                                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <CalendarDays className="size-3 shrink-0" aria-hidden="true" />
                                            <span>
                                                {event.event_date
                                                    ? new Date(event.event_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
                                                    : 'No date set'}
                                            </span>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
