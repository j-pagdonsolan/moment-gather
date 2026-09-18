import { Head, router } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { useState } from 'react';

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

type Outcome = 'success' | 'fail' | 'cancel';

interface FakeCheckoutProps {
    token: string;
    plan: string;
}

/**
 * Billing/FakeCheckout — DEV/TEST-ONLY simulated hosted checkout.
 *
 * This is NOT a real payment page. It exists only when the fake billing provider is
 * active so the full upgrade flow can be exercised without external credentials. The
 * three buttons POST { token, outcome } to billing.fake-checkout.complete, which emits
 * the corresponding signed webhook and redirects to the matching return route.
 */
export default function FakeCheckout({ token, plan }: FakeCheckoutProps) {
    const [processing, setProcessing] = useState(false);

    const planLabel = plan ? plan.toUpperCase() : 'PRO';

    const submit = (outcome: Outcome) => {
        setProcessing(true);
        router.post(
            '/billing/fake-checkout/complete',
            { token, outcome },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <>
            <Head title="Checkout (Test)" />

            <div className="flex min-h-[60vh] w-full items-center justify-center p-4">
                <Card className="w-full max-w-md">
                    <CardHeader className="gap-3">
                        <Badge
                            variant="secondary"
                            className="gap-1.5 text-amber-700 dark:text-amber-400"
                        >
                            <FlaskConical className="size-3" />
                            Test mode — no real payment
                        </Badge>
                        <CardTitle>Simulated checkout</CardTitle>
                        <CardDescription>
                            You are purchasing the{' '}
                            <span className="font-semibold text-foreground">
                                {planLabel}
                            </span>{' '}
                            plan. Choose an outcome below to simulate the payment
                            provider. Nothing is charged.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            This sandbox page stands in for a real provider&apos;s hosted
                            checkout so the billing flow can be tested end to end.
                        </div>
                    </CardContent>

                    <CardFooter className="flex flex-col gap-2">
                        <Button
                            type="button"
                            className="w-full"
                            disabled={processing}
                            onClick={() => submit('success')}
                        >
                            Pay (simulate success)
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            disabled={processing}
                            onClick={() => submit('fail')}
                        >
                            Simulate failed payment
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full"
                            disabled={processing}
                            onClick={() => submit('cancel')}
                        >
                            Cancel
                        </Button>
                    </CardFooter>
                </Card>
            </div>
        </>
    );
}
