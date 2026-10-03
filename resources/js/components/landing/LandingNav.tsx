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
                    <SheetContent side="right" className="w-72">
                        <div className="flex flex-col gap-4 pt-4">
                            {navLinks.map((link) => (
                                <a key={link.href} href={link.href}
                                   className="text-base text-foreground hover:text-brand"
                                   onClick={() => setOpen(false)}>
                                    {link.label}
                                </a>
                            ))}
                            <div className="border-t border-border pt-4 flex flex-col gap-2">
                                {user ? (
                                    <Button asChild className="bg-brand text-brand-foreground hover:bg-brand/90">
                                        <Link href="/dashboard">Go to Dashboard</Link>
                                    </Button>
                                ) : (
                                    <>
                                        <Button asChild variant="outline">
                                            <Link href="/login">Log in</Link>
                                        </Button>
                                        <Button asChild className="bg-brand text-brand-foreground hover:bg-brand/90">
                                            <Link href="/register">Get Started Free</Link>
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </nav>
    );
}
