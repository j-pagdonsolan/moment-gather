import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { User } from 'lucide-react';
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
import type { AdminUser } from '@/types';

interface Props {
    user: AdminUser;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString();
}

function formatBytes(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${Math.round((bytes / Math.pow(k, i)) * 100) / 100} ${sizes[i]}`;
}

export default function AdminUsersShow({ user }: Props) {
    const [showActivateDialog, setShowActivateDialog] = useState(false);
    const [showDeactivateDialog, setShowDeactivateDialog] = useState(false);

    const activateForm = useForm({});
    const deactivateForm = useForm({});

    const handleActivate = () => {
        activateForm.post(`/admin/users/${user.id}/activate`, {
            onSuccess: () => setShowActivateDialog(false),
        });
    };

    const handleDeactivate = () => {
        deactivateForm.post(`/admin/users/${user.id}/deactivate`, {
            onSuccess: () => setShowDeactivateDialog(false),
        });
    };

    return (
        <>
            <Head title={`User: ${user.name}`} />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                            <User className="size-4" aria-hidden="true" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-foreground">User Details</h1>
                            <p className="text-xs text-muted-foreground">{user.name}</p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        {!user.is_active && (
                            <Button
                                variant="default"
                                onClick={() => setShowActivateDialog(true)}
                            >
                                Activate
                            </Button>
                        )}
                        {user.is_active && (
                            <Button
                                variant="destructive"
                                onClick={() => setShowDeactivateDialog(true)}
                            >
                                Deactivate
                            </Button>
                        )}
                    </div>
                </div>

                {/* Account Information */}
                <Card>
                    <CardHeader>
                        <CardTitle>Account Information</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground text-sm">Name</p>
                                <p className="font-medium">{user.name}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-sm">Email</p>
                                <p className="font-medium break-words">{user.email}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-sm">Status</p>
                                <Badge variant={user.is_active ? 'default' : 'secondary'}>
                                    {user.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-sm">Email Verified</p>
                                <Badge variant={user.email_verified_at ? 'default' : 'secondary'}>
                                    {user.email_verified_at ? 'Verified' : 'Unverified'}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Roles & Permissions */}
                <Card>
                    <CardHeader>
                        <CardTitle>Roles & Permissions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            <div>
                                <p className="text-muted-foreground text-sm mb-2">Roles</p>
                                <div className="flex flex-wrap gap-2">
                                    {user.roles.length > 0 ? (
                                        user.roles.map((role) => (
                                            <Badge key={role} variant="outline">
                                                {role === 'super_admin' ? 'Super Admin' : 'Organizer'}
                                            </Badge>
                                        ))
                                    ) : (
                                        <span className="text-muted-foreground text-sm">No roles assigned</span>
                                    )}
                                </div>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                <p>Role management coming soon</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Subscription & Plan */}
                <Card>
                    <CardHeader>
                        <CardTitle>Subscription & Plan</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground text-sm">Current Plan</p>
                                <Badge variant="outline" className="capitalize">
                                    {user.plan}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Usage Statistics */}
                <Card>
                    <CardHeader>
                        <CardTitle>Usage Statistics</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-muted-foreground text-sm">Events Created</p>
                                <p className="text-2xl font-bold">{user.event_count}</p>
                            </div>
                            {user.photo_count !== undefined && (
                                <div>
                                    <p className="text-muted-foreground text-sm">Photos Uploaded</p>
                                    <p className="text-2xl font-bold">{user.photo_count}</p>
                                </div>
                            )}
                            {user.storage_used_bytes !== undefined && (
                                <div>
                                    <p className="text-muted-foreground text-sm">Storage Used</p>
                                    <p className="text-2xl font-bold">{formatBytes(user.storage_used_bytes)}</p>
                                </div>
                            )}
                            {user.two_fa_enabled !== undefined && (
                                <div>
                                    <p className="text-muted-foreground text-sm">Two-Factor Auth</p>
                                    <Badge variant={user.two_fa_enabled ? 'default' : 'secondary'}>
                                        {user.two_fa_enabled ? 'Enabled' : 'Disabled'}
                                    </Badge>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Timestamps */}
                <Card>
                    <CardHeader>
                        <CardTitle>Account History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground text-sm">Account Created</p>
                                <p className="font-medium">{formatDate(user.created_at)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-sm">Last Updated</p>
                                <p className="font-medium">{formatDate(user.updated_at)}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Activate Dialog */}
            <Dialog open={showActivateDialog} onOpenChange={setShowActivateDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Activate User</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to activate "{user.name}"? This will allow them to log in and access the platform.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            onClick={handleActivate}
                            disabled={activateForm.processing}
                        >
                            Activate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Deactivate Dialog */}
            <Dialog open={showDeactivateDialog} onOpenChange={setShowDeactivateDialog}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Deactivate User</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to deactivate "{user.name}"? This will prevent them from logging in and accessing the platform.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={handleDeactivate}
                            disabled={deactivateForm.processing}
                        >
                            Deactivate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
