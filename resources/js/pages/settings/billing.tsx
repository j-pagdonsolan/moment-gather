import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import CancelSubscriptionDialog from '@/components/billing/CancelSubscriptionDialog';
import PaymentHistoryList from '@/components/billing/PaymentHistoryList';
import PlanCard from '@/components/billing/PlanCard';
import UsageMeter from '@/components/billing/UsageMeter';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { bytes } from '@/lib/format';
import type { BillingPageProps } from '@/types/billing';

function formatDate(value: string): string {
    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

const formatCount = (n: number) => n.toLocaleString();

export default function Billing({
    plan,
    isPro,
    subscription,
    usage,
    payments,
    currency,
}: BillingPageProps) {
    const [upgrading, setUpgrading] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    const scheduledCancellation = Boolean(
        subscription &&
            (subscription.onGracePeriod || subscription.cancelAtPeriodEnd),
    );

    const canCancel =
        isPro &&
        subscription?.status === 'active' &&
        !scheduledCancellation;

    const handleUpgrade = () => {
        router.post(
            '/billing/checkout',
            { plan: 'pro' },
            {
                onStart: () => setUpgrading(true),
                onFinish: () => setUpgrading(false),
            },
        );
    };

    const handleCancel = () => {
        router.post(
            '/billing/cancel',
            {},
            {
                onStart: () => setCancelling(true),
                onFinish: () => {
                    setCancelling(false);
                    setCancelOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Billing" />

            <h1 className="sr-only">Billing</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Billing"
                    description="Manage your plan, usage, and payment history"
                />

                {/* Current plan summary */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between gap-2">
                            <CardTitle className="text-base">
                                {plan.name}
                            </CardTitle>
                            <Badge variant={isPro ? 'default' : 'secondary'}>
                                {isPro ? 'Pro' : 'Free'}
                            </Badge>
                        </div>
                        {subscription && (
                            <CardDescription>
                                {scheduledCancellation &&
                                subscription.currentPeriodEnd ? (
                                    <>
                                        Active until{' '}
                                        {formatDate(
                                            subscription.currentPeriodEnd,
                                        )}{' '}
                                        — cancellation scheduled
                                    </>
                                ) : (
                                    <span className="capitalize">
                                        {subscription.status}
                                    </span>
                                )}
                            </CardDescription>
                        )}
                    </CardHeader>
                </Card>

                {/* Plan cards / upgrade CTA */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <PlanCard plan={plan} current currency={currency} />

                    {!isPro && (
                        <Card className="flex h-full flex-col justify-between">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Upgrade to Pro
                                </CardTitle>
                                <CardDescription>
                                    Unlock more active events, higher photo
                                    limits, and more storage.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button
                                    className="w-full"
                                    onClick={handleUpgrade}
                                    disabled={upgrading}
                                >
                                    {upgrading
                                        ? 'Redirecting…'
                                        : 'Upgrade to Pro'}
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Usage */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Usage</CardTitle>
                        <CardDescription>
                            Your current usage against your plan limits.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <UsageMeter
                            label="Events"
                            used={usage.events.used}
                            limit={usage.events.limit}
                            format={formatCount}
                        />
                        <UsageMeter
                            label="Photos"
                            used={usage.photos.used}
                            limit={usage.photos.limit}
                            format={formatCount}
                        />
                        <UsageMeter
                            label="Storage"
                            used={usage.storage.used}
                            limit={usage.storage.limit}
                            format={bytes}
                        />
                    </CardContent>
                </Card>

                {/* Subscription management */}
                {isPro && subscription && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Subscription
                            </CardTitle>
                            <CardDescription>
                                {canCancel
                                    ? 'Cancel your subscription at any time. Your events and photos are kept.'
                                    : scheduledCancellation &&
                                        subscription.currentPeriodEnd
                                      ? `Your Pro plan stays active until ${formatDate(subscription.currentPeriodEnd)}. After that, your account returns to the Free plan.`
                                      : 'Manage your subscription.'}
                            </CardDescription>
                        </CardHeader>
                        {canCancel && (
                            <CardContent>
                                <Button
                                    variant="destructive"
                                    onClick={() => setCancelOpen(true)}
                                >
                                    Cancel subscription
                                </Button>
                            </CardContent>
                        )}
                    </Card>
                )}

                {/* Payment history */}
                <div className="space-y-3">
                    <Heading
                        variant="small"
                        title="Payment history"
                        description="A record of your past payments"
                    />
                    <PaymentHistoryList payments={payments} currency={currency} />
                </div>
            </div>

            <CancelSubscriptionDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                onConfirm={handleCancel}
                processing={cancelling}
                periodEnd={subscription?.currentPeriodEnd}
            />
        </>
    );
}

Billing.layout = {
    breadcrumbs: [
        {
            title: 'Billing',
            href: '/billing',
        },
    ],
};
