import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Users as UsersIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
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
import type { AdminUser, PaginationMeta } from '@/types';

interface Props {
    users: {
        data: AdminUser[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        role?: string;
        is_active?: string;
    };
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString();
}

export default function AdminUsersIndex({ users, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [role, setRole] = useState(filters.role || 'all');
    const [isActive, setIsActive] = useState(filters.is_active || 'all');

    const handleFilterChange = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (role && role !== 'all') params.role = role;
        if (isActive && isActive !== 'all') params.is_active = isActive;

        router.get('/admin/users', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handlePageChange = (page: number) => {
        const params: Record<string, string | number> = { page };
        if (search) params.search = search;
        if (role && role !== 'all') params.role = role;
        if (isActive && isActive !== 'all') params.is_active = isActive;

        router.get('/admin/users', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Users" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <div className="flex items-center gap-3">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <UsersIcon className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Users</h1>
                        <p className="text-xs text-muted-foreground">Manage platform users</p>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle>Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="search">Search</Label>
                                <Input
                                    id="search"
                                    placeholder="Name or email..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            handleFilterChange();
                                        }
                                    }}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <Select value={role} onValueChange={setRole}>
                                    <SelectTrigger id="role">
                                        <SelectValue placeholder="All roles" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All roles</SelectItem>
                                        <SelectItem value="super_admin">Super Admin</SelectItem>
                                        <SelectItem value="organizer">Organizer</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="is_active">Status</Label>
                                <Select value={isActive} onValueChange={setIsActive}>
                                    <SelectTrigger id="is_active">
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All statuses</SelectItem>
                                        <SelectItem value="true">Active</SelectItem>
                                        <SelectItem value="false">Inactive</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter>
                    <Button onClick={handleFilterChange} className="bg-brand text-brand-foreground hover:bg-brand/90">Apply Filters</Button>
                    </CardFooter>
                </Card>

                {/* User List */}
                <div className="grid gap-4 sm:grid-cols-1 lg:grid-cols-2 xl:grid-cols-3">
                    {users.data.map((user) => (
                        <Card key={user.id} className="flex flex-col">
                            <CardHeader>
                                <div className="flex items-start justify-between gap-3">
                                    <CardTitle className="text-lg">
                                        <Link
                                            href={`/admin/users/${user.id}`}
                                            className="hover:underline"
                                        >
                                            {user.name}
                                        </Link>
                                    </CardTitle>
                                    <Badge variant={user.is_active ? 'default' : 'secondary'}>
                                        {user.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </div>
                            </CardHeader>
                            <CardContent className="flex flex-1 flex-col gap-3 text-sm">
                                <div>
                                    <p className="text-muted-foreground">Email</p>
                                    <p className="break-words">{user.email}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Roles</p>
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        {user.roles.length > 0 ? (
                                            user.roles.map((role) => (
                                                <Badge key={role} variant="outline">
                                                    {role === 'super_admin' ? 'Super Admin' : 'Organizer'}
                                                </Badge>
                                            ))
                                        ) : (
                                            <span className="text-muted-foreground">No roles</span>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Plan</p>
                                    <p className="capitalize">{user.plan}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Events</p>
                                    <p>{user.event_count}</p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">Created</p>
                                    <p>{formatDate(user.created_at)}</p>
                                </div>
                            </CardContent>
                            <CardFooter>
                                <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                >
                                    <Link href={`/admin/users/${user.id}`}>
                                        View Details
                                    </Link>
                                </Button>
                            </CardFooter>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {users.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handlePageChange(users.current_page - 1)}
                            disabled={users.current_page === 1}
                        >
                            Previous
                        </Button>
                        <span className="text-sm">
                            Page {users.current_page} of {users.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handlePageChange(users.current_page + 1)}
                            disabled={users.current_page === users.last_page}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
