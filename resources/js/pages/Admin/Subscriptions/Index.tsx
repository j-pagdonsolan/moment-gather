import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Receipt } from 'lucide-react';
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
import { index as adminSubscriptionsIndex } from '@/routes/admin/subscriptions';
import type { AdminSubscription, PaginationMeta } from '@/types/admin';

interface Props {
    subscriptions: {
        data: AdminSubscription[];
    } & PaginationMeta;
    filters: {
        status?: string;
        plan?: string;
        user?: string;
    };
}

function formatDate(value: string | null): string {
    if (!value) return 'N/A';
    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function StatusBadge({ status }: { status: string }) {
    const variant = status === 'active' ? 'default' : 'secondary';
    return <Badge variant={variant}>{status}</Badge>;
}

export default function SubscriptionsIndex({ subscriptions, filters }: Props) {
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [planFilter, setPlanFilter] = useState(filters.plan || 'all');
    const [userFilter, setUserFilter] = useState(filters.user || '');

    const applyFilters = () => {
        router.get(
            adminSubscriptionsIndex().url,
            {
                status: statusFilter !== 'all' ? statusFilter : undefined,
                plan: planFilter !== 'all' ? planFilter : undefined,
                user: userFilter || undefined,
            },
            { preserveState: true }
        );
    };

    const clearFilters = () => {
        setStatusFilter('all');
        setPlanFilter('all');
        setUserFilter('');
        router.get(adminSubscriptionsIndex().url);
    };

    return (
        <>
            <Head title="Subscriptions" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <Receipt className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Subscriptions</h1>
                        <p className="text-xs text-muted-foreground">All user subscriptions</p>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <Label htmlFor="status">Status</Label>
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger id="status" className="w-full">
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All statuses</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="canceled">Canceled</SelectItem>
                                        <SelectItem value="past_due">Past Due</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="plan">Plan</Label>
                                <Select value={planFilter} onValueChange={setPlanFilter}>
                                    <SelectTrigger id="plan" className="w-full">
                                        <SelectValue placeholder="All plans" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All plans</SelectItem>
                                        <SelectItem value="free">Free</SelectItem>
                                        <SelectItem value="pro">Pro</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="user">User (ID or Email)</Label>
                                <Input
                                    id="user"
                                    type="text"
                                    value={userFilter}
                                    onChange={(e) => setUserFilter(e.target.value)}
                                    placeholder="Search user..."
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button onClick={applyFilters} className="flex-1 bg-brand text-brand-foreground hover:bg-brand/90">
                                    Apply
                                </Button>
                                <Button onClick={clearFilters} variant="outline">
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Subscriptions List */}
                {subscriptions.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No subscriptions found.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-4">
                            {subscriptions.data.map((subscription) => (
                                <Card key={subscription.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle className="text-base">
                                                    {subscription.user.name}
                                                </CardTitle>
                                                <p className="text-sm text-muted-foreground">
                                                    {subscription.user.email}
                                                </p>
                                            </div>
                                            <StatusBadge status={subscription.status} />
                                        </div>
                                    </CardHeader>
                                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-muted-foreground">Plan</p>
                                            <Badge variant="outline">{subscription.plan}</Badge>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Provider</p>
                                            <p>{subscription.provider}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Period End</p>
                                            <p>{formatDate(subscription.current_period_end)}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Created</p>
                                            <p>{formatDate(subscription.created_at)}</p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        {/* Pagination */}
                        {subscriptions.last_page > 1 && (
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing {subscriptions.from} to {subscriptions.to} of{' '}
                                    {subscriptions.total} subscriptions
                                </p>
                                <div className="flex gap-2">
                                    {subscriptions.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminSubscriptionsIndex({
                                                        query: {
                                                            page: subscriptions.current_page - 1,
                                                            ...filters,
                                                        },
                                                    }).url
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {subscriptions.current_page < subscriptions.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminSubscriptionsIndex({
                                                        query: {
                                                            page: subscriptions.current_page + 1,
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
