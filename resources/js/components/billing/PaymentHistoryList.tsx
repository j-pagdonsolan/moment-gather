import { Receipt } from 'lucide-react';

import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { money } from '@/lib/format';
import type { BillingPayment } from '@/types/billing';

interface PaymentHistoryListProps {
    payments: BillingPayment[];
    currency: string;
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    const normalized = status.toLowerCase();

    if (normalized === 'succeeded' || normalized === 'paid') {
        return 'default';
    }
    if (normalized === 'failed') {
        return 'destructive';
    }
    return 'secondary';
}

function formatDate(value: string): string {
    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function PaymentHistoryList({
    payments,
    currency,
}: PaymentHistoryListProps) {
    if (payments.length === 0) {
        return (
            <EmptyState
                icon={Receipt}
                title="No payments yet"
                description="Your payment history will appear here once you upgrade."
            />
        );
    }

    return (
        <ul className="divide-y divide-border rounded-lg border">
            {payments.map((payment) => (
                <li
                    key={payment.id}
                    className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div className="flex flex-col gap-0.5">
                        <span className="font-medium text-foreground">
                            {money(payment.amount, payment.currency || currency)}
                            <span className="ml-1 text-xs text-muted-foreground">
                                {payment.currency || currency}
                            </span>
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {formatDate(payment.date)}
                        </span>
                        {payment.reference && (
                            <span className="text-xs text-muted-foreground">
                                Ref: {payment.reference}
                            </span>
                        )}
                    </div>
                    <Badge
                        variant={statusVariant(payment.status)}
                        className="self-start capitalize sm:self-center"
                    >
                        {payment.status}
                    </Badge>
                </li>
            ))}
        </ul>
    );
}
