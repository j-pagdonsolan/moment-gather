import { cn } from '@/lib/utils';

interface UsageMeterProps {
    label: string;
    used: number;
    limit: number;
    format?: (n: number) => string;
}

type UsageState = 'normal' | 'approaching' | 'reached';

function usageState(percent: number): UsageState {
    if (percent >= 100) {
        return 'reached';
    }
    if (percent >= 75) {
        return 'approaching';
    }
    return 'normal';
}

const barColors: Record<UsageState, string> = {
    normal: 'bg-primary',
    approaching: 'bg-amber-500',
    reached: 'bg-destructive',
};

export default function UsageMeter({
    label,
    used,
    limit,
    format = (n) => n.toLocaleString(),
}: UsageMeterProps) {
    const rawPercent = limit > 0 ? (used / limit) * 100 : used > 0 ? 100 : 0;
    const width = Math.min(100, Math.max(0, rawPercent));
    const state = usageState(rawPercent);
    const valueText = `${format(used)} / ${format(limit)}`;

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-center justify-between gap-2 text-sm">
                <span className="font-medium text-foreground">{label}</span>
                <span className="text-muted-foreground">{valueText}</span>
            </div>
            <div
                role="progressbar"
                aria-label={`${label} usage`}
                aria-valuenow={used}
                aria-valuemin={0}
                aria-valuemax={limit}
                aria-valuetext={valueText}
                className="h-2 w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        barColors[state],
                    )}
                    style={{ width: `${width}%` }}
                />
            </div>
        </div>
    );
}
