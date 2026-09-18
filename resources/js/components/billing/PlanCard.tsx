import { Calendar, HardDrive, Image } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { bytes, money } from '@/lib/format';
import type { BillingPlan } from '@/types/billing';

interface PlanCardProps {
    plan: BillingPlan;
    current: boolean;
    currency: string;
    onUpgrade?: () => void;
    upgrading?: boolean;
}

export default function PlanCard({
    plan,
    current,
    currency,
    onUpgrade,
    upgrading = false,
}: PlanCardProps) {
    const isFree = plan.price === 0;
    const priceLabel = isFree ? 'Free' : money(plan.price, currency);

    const limits = [
        {
            icon: Calendar,
            label: `${plan.maxActiveEvents.toLocaleString()} active ${
                plan.maxActiveEvents === 1 ? 'event' : 'events'
            }`,
        },
        {
            icon: Image,
            label: `${plan.maxPhotosPerEvent.toLocaleString()} photos per event`,
        },
        {
            icon: HardDrive,
            label: `${bytes(plan.maxStorageBytes)} storage`,
        },
    ];

    return (
        <Card className="h-full">
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-base">{plan.name}</CardTitle>
                    {current && <Badge variant="secondary">Current plan</Badge>}
                </div>
                <CardDescription>
                    <span className="text-2xl font-semibold text-foreground">
                        {priceLabel}
                    </span>
                    {!isFree && plan.billingInterval && (
                        <span className="ml-1 text-sm text-muted-foreground">
                            / {plan.billingInterval}
                        </span>
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <ul className="flex flex-col gap-2">
                    {limits.map(({ icon: Icon, label }) => (
                        <li
                            key={label}
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Icon
                                className="h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{label}</span>
                        </li>
                    ))}
                </ul>
            </CardContent>

            {!current && !isFree && onUpgrade && (
                <CardFooter>
                    <Button
                        className="w-full"
                        onClick={onUpgrade}
                        disabled={upgrading}
                    >
                        {upgrading ? 'Redirecting…' : 'Upgrade to Pro'}
                    </Button>
                </CardFooter>
            )}
        </Card>
    );
}
