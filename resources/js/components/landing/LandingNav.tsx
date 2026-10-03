import { Link, usePage } from '@inertiajs/react';
import { Camera, Menu } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet';

const navLinks = [
    { label: 'Features', href: '#features' },
    { label: 'How It Works', href: '#how-it-works' },
    { label: 'Pricing', href: '#pricing' },
    { label: 'FAQ', href: '#faq' },
];

export default function LandingNav() {
    const page = usePage<{ auth: { user: { name: string } | null } }>();
    const user = page.props.auth?.user ?? null;
    const [open, setOpen] = useState(false);

    return (
        <nav className="sticky top-0 z-50 border-b border-border bg-background/80 backdrop-blur-md">
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link href="/" className="flex items-center gap-2 font-semibold text-foreground">
                    <Camera className="h-5 w-5 text-brand" aria-hidden="true" />
                    <span>MomentGather</span>
                </Link>

                <div className="hidden items-center gap-6 md:flex">
                    {navLinks.map((link) => (
                        <a key={link.href} href={link.href}
                           className="text-sm text-muted-foreground transition-colors duration-150 hover:text-foreground">
                            {link.label}
                        </a>
                    ))}
                </div>

                <div className="hidden items-center gap-3 md:flex">
                    {user ? (
                        <Button asChild size="sm" className="bg-brand text-brand-foreground hover:bg-brand/90">
                            <Link href="/dashboard">Go to Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/login">Log in</Link>
                            </Button>
                            <Button asChild size="sm" className="bg-brand text-brand-foreground hover:bg-brand/90">
                                <Link href="/register">Get Started</Link>
                            </Button>
                        </>
                    )}
                </div>

                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetTrigger asChild>
                        <Button variant="ghost" size="icon" className="md:hidden" aria-label="Open menu">
                            <Menu className="h-5 w-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" className="w-full max-w-xs p-0 flex flex-col">
                        {/* Header */}
                        <div className="flex items-center justify-between px-5 py-4 border-b border-border">
                            <Link href="/" className="flex items-center gap-2" onClick={() => setOpen(false)}>
                                <div className="flex size-7 items-center justify-center rounded-lg bg-brand text-brand-foreground">
                                    <Camera className="h-3.5 w-3.5" aria-hidden="true" />
                                </div>
                                <span className="font-semibold text-foreground text-sm">MomentGather</span>
                            </Link>
                        </div>

                        {/* Nav links */}
                        <div className="flex-1 overflow-y-auto px-3 py-4">
                            <nav className="flex flex-col gap-0.5">
                                {navLinks.map((link) => (
                                    <a
                                        key={link.href}
                                        href={link.href}
                                        className="flex items-center rounded-lg px-4 py-3 text-sm font-medium text-foreground hover:bg-muted hover:text-brand transition-colors duration-150"
                                        onClick={() => setOpen(false)}
                                    >
                                        {link.label}
                                    </a>
                                ))}
                            </nav>
                        </div>

                        {/* Auth buttons pinned to bottom */}
                        <div className="px-4 py-4 border-t border-border flex flex-col gap-2">
                            {user ? (
                                <Button asChild className="w-full bg-brand text-brand-foreground hover:bg-brand/90">
                                    <Link href="/dashboard" onClick={() => setOpen(false)}>Go to Dashboard</Link>
                                </Button>
                            ) : (
                                <>
                                    <Button asChild variant="outline" className="w-full">
                                        <Link href="/login" onClick={() => setOpen(false)}>Log in</Link>
                                    </Button>
                                    <Button asChild className="w-full bg-brand text-brand-foreground hover:bg-brand/90">
                                        <Link href="/register" onClick={() => setOpen(false)}>Get Started Free</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </nav>
    );
}
