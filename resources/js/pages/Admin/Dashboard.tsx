import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    CreditCard,
    DollarSign,
    Image,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import SectionHeader from '@/components/SectionHeader';
import StatCard from '@/components/StatCard';
import type { AdminDashboardStats } from '@/types';

interface Props {
    stats: AdminDashboardStats;
}

function formatCurrency(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(amount / 100);
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString();
}

export default function AdminDashboard({ stats }: Props) {
    return (
        <>
            <Head title="Admin Dashboard" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <LayoutDashboard className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Admin Dashboard</h1>
                        <p className="text-xs text-muted-foreground">Platform overview and statistics</p>
                    </div>
                </div>

                {/* Users Stats */}
                <div>
                    <div className="mb-4">
                        <SectionHeader title="Users" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Total Users" value={stats.users.total} icon={Users} />
                        <StatCard label="Active" value={stats.users.active} icon={Users} />
                        <StatCard label="Inactive" value={stats.users.inactive} icon={Users} />
                        <StatCard label="Super Admins" value={stats.users.super_admin_count} icon={Users} />
                    </div>
                </div>

                {/* Events Stats */}
                <div>
                    <div className="mb-4">
                        <SectionHeader title="Events" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Total Events" value={stats.events.total} icon={CalendarDays} />
                        <StatCard label="Active" value={stats.events.active} icon={CalendarDays} />
                        <StatCard label="Draft" value={stats.events.draft} icon={CalendarDays} />
                        <StatCard label="Archived" value={stats.events.archived} icon={CalendarDays} />
                    </div>
                </div>

                {/* Photos Stats */}
                <div>
                    <div className="mb-4">
                        <SectionHeader title="Photos" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <StatCard label="Total Photos" value={stats.photos.total} icon={Image} />
                        <StatCard label="Pending" value={stats.photos.by_status.pending} icon={Image} />
                        <StatCard label="Processing" value={stats.photos.by_status.processing} icon={Image} />
                        <StatCard label="Ready" value={stats.photos.by_status.ready} icon={Image} />
                        <StatCard label="Failed" value={stats.photos.by_status.failed} icon={Image} />
                    </div>
                </div>

                {/* Subscriptions Stats */}
                <div>
                    <div className="mb-4">
                        <SectionHeader title="Subscriptions" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Free Plan Users" value={stats.subscriptions.free_plan_users} icon={CreditCard} />
                        <StatCard label="Pro Plan Users" value={stats.subscriptions.pro_plan_users} icon={CreditCard} />
                        <StatCard label="Active" value={stats.subscriptions.active} icon={CreditCard} />
                        <StatCard label="Canceled" value={stats.subscriptions.canceled} icon={CreditCard} />
                    </div>
                </div>

                {/* Payments Stats and Recent Payments */}
                <div>
                    <div className="mb-4">
                        <SectionHeader title="Payments" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-2 mb-4">
                        <StatCard label="Succeeded" value={stats.payments.succeeded} icon={DollarSign} />
                        <StatCard label="Failed" value={stats.payments.failed_count} icon={DollarSign} />
                    </div>

                    {/* Recent Payments List */}
                    {stats.payments.recent.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Recent Payments</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {stats.payments.recent.map((payment) => (
                                        <div
                                            key={payment.id}
                                            className="flex flex-col gap-2 border-b pb-4 last:border-b-0 last:pb-0"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <p className="font-medium">{payment.user.name}</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {payment.user.email}
                                                    </p>
                                                </div>
                                                <Badge
                                                    variant={
                                                        payment.status === 'succeeded'
                                                            ? 'default'
                                                            : 'destructive'
                                                    }
                                                >
                                                    {payment.status}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center justify-between text-sm">
                                                <span className="font-semibold">
                                                    {formatCurrency(payment.amount, payment.currency)}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {formatDate(payment.created_at)}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
