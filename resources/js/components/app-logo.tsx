import { Camera } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-brand text-brand-foreground shadow-sm">
                <Camera className="size-4" aria-hidden="true" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">MomentGather</span>
                <span className="truncate text-xs text-muted-foreground">Event Photos</span>
            </div>
        </>
    );
}
