import type { LucideProps } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface StatCardProps {
    label: string;
    value: string | number;
    icon: React.ComponentType<LucideProps>;
    className?: string;
}

export default function StatCard({ label, value, icon: Icon, className }: StatCardProps) {
    return (
        <Card className={cn('transition-colors duration-150 hover:border-brand', className)}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {label}
                </CardTitle>
                <Icon className="h-5 w-5 text-muted-foreground" aria-hidden="true" />
            </CardHeader>
            <CardContent>
                <p className="text-3xl font-bold">{value}</p>
            </CardContent>
        </Card>
    );
}
