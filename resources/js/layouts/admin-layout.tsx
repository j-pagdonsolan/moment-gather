import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/admin',
        icon: null,
    },
    {
        title: 'Users',
        href: '/admin/users',
        icon: null,
    },
    {
        title: 'Events',
        href: '/admin/events',
        icon: null,
    },
    {
        title: 'Photos',
        href: '/admin/photos',
        icon: null,
    },
    {
        title: 'Plans',
        href: '/admin/plans',
        icon: null,
    },
    {
        title: 'Subscriptions',
        href: '/admin/subscriptions',
        icon: null,
    },
    {
        title: 'Payments',
        href: '/admin/payments',
        icon: null,
    },
    {
        title: 'Audit Logs',
        href: '/admin/audit-logs',
        icon: null,
    },
];

export default function AdminLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-6">
            <Heading
                title="Admin"
                description="System administration and management"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label="Admin"
                    >
                        {adminNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>{item.title}</Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1">
                    <section className="space-y-6">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
