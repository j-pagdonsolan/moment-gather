import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface CancelSubscriptionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing?: boolean;
    periodEnd?: string | null;
}

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

export default function CancelSubscriptionDialog({
    open,
    onOpenChange,
    onConfirm,
    processing = false,
    periodEnd,
}: CancelSubscriptionDialogProps) {
    const endLabel = periodEnd ? formatDate(periodEnd) : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel subscription</DialogTitle>
                    <DialogDescription>
                        {endLabel
                            ? `Your Pro plan stays active until ${endLabel}. After that, your account returns to the Free plan. Your events and photos are kept.`
                            : 'Your Pro plan stays active until the end of the current billing period. After that, your account returns to the Free plan. Your events and photos are kept.'}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline" disabled={processing}>
                            Keep Pro
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {processing ? 'Cancelling…' : 'Cancel subscription'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
