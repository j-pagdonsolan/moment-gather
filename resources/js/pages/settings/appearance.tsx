import { Head } from '@inertiajs/react';
import { Palette } from 'lucide-react';
import AppearanceTabs from '@/components/appearance-tabs';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-6">
                <div className="flex items-center gap-3 pb-2 border-b border-border">
                    <div className="flex size-9 items-center justify-center rounded-lg bg-brand-muted text-brand">
                        <Palette className="size-4" aria-hidden="true" />
                    </div>
                    <div>
                        <h2 className="font-semibold text-foreground">Appearance</h2>
                        <p className="text-xs text-muted-foreground">Update the appearance settings for your account</p>
                    </div>
                </div>
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
