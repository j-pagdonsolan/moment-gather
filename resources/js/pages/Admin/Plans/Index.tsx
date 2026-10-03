import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { CreditCard } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as adminPlansUpdate, deactivate as adminPlansDeactivate } from '@/routes/admin/plans';
import type { AdminPlan } from '@/types/admin';

interface Props {
    plans: AdminPlan[];
}

function formatCurrency(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`;
}

function SourceBadge({ source }: { source: 'override' | 'config' }) {
    return (
        <Badge variant={source === 'override' ? 'default' : 'secondary'}>
            {source}
        </Badge>
    );
}

function PlanCard({ plan }: { plan: AdminPlan }) {
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [isDeactivateOpen, setIsDeactivateOpen] = useState(false);

    const { data, setData, put, processing, reset } = useForm({
        max_active_events: plan.max_active_events,
        max_photos_per_event: plan.max_photos_per_event,
        max_storage_bytes: plan.max_storage_bytes,
        price: plan.price,
    });

    const handleEdit = (e: React.FormEvent) => {
        e.preventDefault();
        put(adminPlansUpdate(plan.slug).url, {
            onSuccess: () => {
                setIsEditOpen(false);
                reset();
            },
        });
    };

    const handleDeactivate = () => {
        router.post(adminPlansDeactivate(plan.slug).url, {}, {
            onSuccess: () => {
                setIsDeactivateOpen(false);
            },
        });
    };

    return (
        <>
            <Card>
                <CardHeader>
                    <div className="flex items-start justify-between gap-3">
                        <CardTitle className="text-lg">{plan.name}</CardTitle>
                        <Badge variant={plan.is_active ? 'default' : 'secondary'}>
                            {plan.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">{plan.slug}</p>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                    <div>
                        <div className="flex items-center justify-between">
                            <p className="text-muted-foreground">Price</p>
                            <SourceBadge source={plan.price_source} />
                        </div>
                        <p className="font-medium">{formatCurrency(plan.price)}</p>
                    </div>
                    <div>
                        <div className="flex items-center justify-between">
                            <p className="text-muted-foreground">Max Active Events</p>
                            <SourceBadge source={plan.max_active_events_source} />
                        </div>
                        <p className="font-medium">{plan.max_active_events}</p>
                    </div>
                    <div>
                        <div className="flex items-center justify-between">
                            <p className="text-muted-foreground">Max Photos per Event</p>
                            <SourceBadge source={plan.max_photos_per_event_source} />
                        </div>
                        <p className="font-medium">{plan.max_photos_per_event}</p>
                    </div>
                    <div>
                        <div className="flex items-center justify-between">
                            <p className="text-muted-foreground">Max Storage</p>
                            <SourceBadge source={plan.max_storage_bytes_source} />
                        </div>
                        <p className="font-medium">{formatBytes(plan.max_storage_bytes)}</p>
                    </div>
                </CardContent>
                <CardFooter className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="flex-1"
                        onClick={() => setIsEditOpen(true)}
                    >
                        Edit Limits
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        className="flex-1"
                        onClick={() => setIsDeactivateOpen(true)}
                        disabled={!plan.is_active}
                    >
                        Deactivate
                    </Button>
                </CardFooter>
            </Card>

            {/* Edit Dialog */}
            <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Plan Limits</DialogTitle>
                        <DialogDescription>
                            Update limits for {plan.name} ({plan.slug})
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleEdit}>
                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="price">Price (cents)</Label>
                                <Input
                                    id="price"
                                    type="number"
                                    value={data.price}
                                    onChange={(e) => setData('price', parseInt(e.target.value))}
                                    min="0"
                                />
                            </div>
                            <div>
                                <Label htmlFor="max_active_events">Max Active Events</Label>
                                <Input
                                    id="max_active_events"
                                    type="number"
                                    value={data.max_active_events}
                                    onChange={(e) => setData('max_active_events', parseInt(e.target.value))}
                                    min="0"
                                />
                            </div>
                            <div>
                                <Label htmlFor="max_photos_per_event">Max Photos per Event</Label>
                                <Input
                                    id="max_photos_per_event"
                                    type="number"
                                    value={data.max_photos_per_event}
                                    onChange={(e) => setData('max_photos_per_event', parseInt(e.target.value))}
                                    min="0"
                                />
                            </div>
                            <div>
                                <Label htmlFor="max_storage_bytes">Max Storage (bytes)</Label>
                                <Input
                                    id="max_storage_bytes"
                                    type="number"
                                    value={data.max_storage_bytes}
                                    onChange={(e) => setData('max_storage_bytes', parseInt(e.target.value))}
                                    min="0"
                                />
                            </div>
                        </div>
                        <DialogFooter className="mt-6">
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={processing}>
                                Update
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Deactivate Dialog */}
            <Dialog open={isDeactivateOpen} onOpenChange={setIsDeactivateOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Deactivate Plan</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to deactivate {plan.name}? This will prevent new subscriptions to this plan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button variant="destructive" onClick={handleDeactivate}>
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function PlansIndex({ plans }: Props) {
    return (
        <>
            <Head title="Plans" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <CreditCard className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Plans</h1>
                        <p className="text-xs text-muted-foreground">Manage subscription plans</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <PlanCard key={plan.slug} plan={plan} />
                    ))}
                </div>
            </div>
        </>
    );
}
