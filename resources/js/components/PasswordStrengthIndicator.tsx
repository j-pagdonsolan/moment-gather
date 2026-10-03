import { cn } from '@/lib/utils';

interface Props {
    password: string;
}

function getStrength(password: string): 0 | 1 | 2 | 3 | 4 {
    if (!password) return 0;
    if (password.length < 8) return 1;
    const hasNumber = /\d/.test(password);
    const hasSymbol = /[^a-zA-Z0-9]/.test(password);
    if (password.length >= 12 && hasNumber && hasSymbol) return 4;
    if (password.length >= 8 && (hasNumber || hasSymbol)) return 3;
    return 2;
}

const labels = ['', 'Too short', 'Weak', 'Fair', 'Strong'];
const colors = [
    '',
    'bg-destructive',
    'bg-orange-400',
    'bg-yellow-400',
    'bg-green-500',
];

export default function PasswordStrengthIndicator({ password }: Props) {
    const strength = getStrength(password);
    if (!password) return null;

    return (
        <div className="mt-2 space-y-1">
            <div className="flex gap-1">
                {[1, 2, 3, 4].map((level) => (
                    <div
                        key={level}
                        className={cn(
                            'h-1.5 flex-1 rounded-full transition-colors duration-200',
                            level <= strength ? colors[strength] : 'bg-muted',
                        )}
                    />
                ))}
            </div>
            <p className={cn('text-xs', strength >= 3 ? 'text-green-600 dark:text-green-400' : 'text-muted-foreground')}>
                {labels[strength]}
            </p>
        </div>
    );
}
