import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}

export default function EmptyState({
    icon,
    title,
    description,
    action,
    className,
}: EmptyStateProps) {
    const Icon = icon;

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 py-16 text-center',
                className,
            )}
        >
            {Icon && (
                <Icon
                    className="h-10 w-10 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
            <h2 className="text-base font-semibold text-foreground">{title}</h2>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
