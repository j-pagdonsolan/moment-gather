import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ScrollText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
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
import { index as adminAuditLogsIndex } from '@/routes/admin/audit-logs';
import type { AuditLogEntry, PaginationMeta } from '@/types/admin';

interface Props {
    logs: {
        data: AuditLogEntry[];
    } & PaginationMeta;
    filters: {
        action?: string;
        actor?: string;
        from?: string;
        to?: string;
        target_type?: string;
    };
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function ActionBadge({ action }: { action: string }) {
    const variants: Record<string, 'default' | 'secondary' | 'destructive'> = {
        user_activated: 'default',
        user_deactivated: 'secondary',
        role_assigned: 'default',
        role_removed: 'destructive',
        event_archived: 'secondary',
        event_deleted: 'destructive',
        photo_deleted: 'destructive',
        plan_limit_updated: 'default',
        plan_deactivated: 'secondary',
    };
    return (
        <Badge variant={variants[action] || 'secondary'}>
            {action.replace(/_/g, ' ')}
        </Badge>
    );
}

export default function AuditLogsIndex({ logs, filters }: Props) {
    const [actionFilter, setActionFilter] = useState(filters.action || '');
    const [actorFilter, setActorFilter] = useState(filters.actor || '');
    const [fromFilter, setFromFilter] = useState(filters.from || '');
    const [toFilter, setToFilter] = useState(filters.to || '');
    const [targetTypeFilter, setTargetTypeFilter] = useState(filters.target_type || 'all');

    const applyFilters = () => {
        router.get(
            adminAuditLogsIndex().url,
            {
                action: actionFilter || undefined,
                actor: actorFilter || undefined,
                from: fromFilter || undefined,
                to: toFilter || undefined,
                target_type: targetTypeFilter !== 'all' ? targetTypeFilter : undefined,
            },
            { preserveState: true }
        );
    };

    const clearFilters = () => {
        setActionFilter('');
        setActorFilter('');
        setFromFilter('');
        setToFilter('');
        setTargetTypeFilter('all');
        router.get(adminAuditLogsIndex().url);
    };

    return (
        <>
            <Head title="Audit Logs" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <ScrollText className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Audit Logs</h1>
                        <p className="text-xs text-muted-foreground">All admin activity</p>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <Label htmlFor="action">Action</Label>
                                <Input
                                    id="action"
                                    type="text"
                                    value={actionFilter}
                                    onChange={(e) => setActionFilter(e.target.value)}
                                    placeholder="e.g. user_activated"
                                />
                            </div>
                            <div>
                                <Label htmlFor="actor">Actor</Label>
                                <Input
                                    id="actor"
                                    type="text"
                                    value={actorFilter}
                                    onChange={(e) => setActorFilter(e.target.value)}
                                    placeholder="Name or email"
                                />
                            </div>
                            <div>
                                <Label htmlFor="target_type">Target Type</Label>
                                <Select value={targetTypeFilter} onValueChange={setTargetTypeFilter}>
                                    <SelectTrigger id="target_type" className="w-full">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All types</SelectItem>
                                        <SelectItem value="User">User</SelectItem>
                                        <SelectItem value="Event">Event</SelectItem>
                                        <SelectItem value="Photo">Photo</SelectItem>
                                        <SelectItem value="PlanOverride">PlanOverride</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="from">From Date</Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={fromFilter}
                                    onChange={(e) => setFromFilter(e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="to">To Date</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={toFilter}
                                    onChange={(e) => setToFilter(e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex gap-2">
                            <Button onClick={applyFilters} className="bg-brand text-brand-foreground hover:bg-brand/90">Apply</Button>
                            <Button onClick={clearFilters} variant="outline">
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Audit Logs List */}
                {logs.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No audit logs found.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-4">
                            {logs.data.map((log) => (
                                <Card key={log.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle className="text-base">
                                                    {log.actor ? log.actor.name : 'System'}
                                                </CardTitle>
                                                {log.actor && (
                                                    <p className="text-sm text-muted-foreground">
                                                        {log.actor.email}
                                                    </p>
                                                )}
                                            </div>
                                            <ActionBadge action={log.action} />
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {log.target_type && (
                                                <div>
                                                    <p className="text-muted-foreground">Target</p>
                                                    <p>
                                                        {log.target_type}
                                                        {log.target_id && ` #${log.target_id}`}
                                                    </p>
                                                </div>
                                            )}
                                            {log.ip_address && (
                                                <div>
                                                    <p className="text-muted-foreground">IP Address</p>
                                                    <p className="font-mono">{log.ip_address}</p>
                                                </div>
                                            )}
                                            <div>
                                                <p className="text-muted-foreground">Time</p>
                                                <p>{formatDate(log.created_at)}</p>
                                            </div>
                                        </div>
                                        {log.description && (
                                            <div>
                                                <p className="text-muted-foreground">Description</p>
                                                <p>{log.description}</p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        {/* Pagination */}
                        {logs.last_page > 1 && (
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing {logs.from} to {logs.to} of {logs.total} logs
                                </p>
                                <div className="flex gap-2">
                                    {logs.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminAuditLogsIndex({
                                                        query: {
                                                            page: logs.current_page - 1,
                                                            ...filters,
                                                        },
                                                    }).url
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {logs.current_page < logs.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminAuditLogsIndex({
                                                        query: {
                                                            page: logs.current_page + 1,
                                                            ...filters,
                                                        },
                                                    }).url
                                                )
                                            }
                                        >
                                            Next
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
