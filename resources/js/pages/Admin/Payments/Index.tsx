import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { DollarSign } from 'lucide-react';
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
import { index as adminPaymentsIndex } from '@/routes/admin/payments';
import type { AdminPayment, PaginationMeta } from '@/types/admin';

interface Props {
    payments: {
        data: AdminPayment[];
    } & PaginationMeta;
    filters: {
        status?: string;
        from?: string;
        to?: string;
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

function formatAmount(amount: number, currency: string): string {
    const dollars = (amount / 100).toFixed(2);
    return `${dollars} ${currency.toUpperCase()}`;
}

function StatusBadge({ status }: { status: string }) {
    const variants: Record<string, 'default' | 'secondary' | 'destructive'> = {
        succeeded: 'default',
        pending: 'secondary',
        failed: 'destructive',
    };
    return <Badge variant={variants[status] || 'secondary'}>{status}</Badge>;
}

export default function PaymentsIndex({ payments, filters }: Props) {
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [fromFilter, setFromFilter] = useState(filters.from || '');
    const [toFilter, setToFilter] = useState(filters.to || '');

    const applyFilters = () => {
        router.get(
            adminPaymentsIndex().url,
            {
                status: statusFilter !== 'all' ? statusFilter : undefined,
                from: fromFilter || undefined,
                to: toFilter || undefined,
            },
            { preserveState: true }
        );
    };

    const clearFilters = () => {
        setStatusFilter('all');
        setFromFilter('');
        setToFilter('');
        router.get(adminPaymentsIndex().url);
    };

    return (
        <>
            <Head title="Payments" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <DollarSign className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Payments</h1>
                        <p className="text-xs text-muted-foreground">Payment history</p>
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
                                        <SelectItem value="succeeded">Succeeded</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="failed">Failed</SelectItem>
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

                {/* Payments List */}
                {payments.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No payments found.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        <div className="grid gap-4">
                            {payments.data.map((payment) => (
                                <Card key={payment.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <CardTitle className="text-base">
                                                    {payment.user.name}
                                                </CardTitle>
                                                <p className="text-sm text-muted-foreground">
                                                    {payment.user.email}
                                                </p>
                                            </div>
                                            <StatusBadge status={payment.status} />
                                        </div>
                                    </CardHeader>
                                    <CardContent className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-muted-foreground">Amount</p>
                                            <p className="font-semibold">
                                                {formatAmount(payment.amount, payment.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Provider</p>
                                            <p>{payment.provider}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Paid At</p>
                                            <p>{formatDate(payment.paid_at)}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Created</p>
                                            <p>{formatDate(payment.created_at)}</p>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>

                        {/* Pagination */}
                        {payments.last_page > 1 && (
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Showing {payments.from} to {payments.to} of {payments.total}{' '}
                                    payments
                                </p>
                                <div className="flex gap-2">
                                    {payments.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminPaymentsIndex({
                                                        query: {
                                                            page: payments.current_page - 1,
                                                            ...filters,
                                                        },
                                                    }).url
                                                )
                                            }
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {payments.current_page < payments.last_page && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.get(
                                                    adminPaymentsIndex({
                                                        query: {
                                                            page: payments.current_page + 1,
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
