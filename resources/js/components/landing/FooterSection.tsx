import { Link } from '@inertiajs/react';
import { Camera } from 'lucide-react';

export default function FooterSection() {
    return (
        <footer className="border-t border-border bg-background py-10">
            <div className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="flex flex-col items-center justify-between gap-6 sm:flex-row">
                    <div className="flex items-center gap-2">
                        <Camera className="h-4 w-4 text-brand" aria-hidden="true" />
                        <span className="font-semibold text-foreground">MomentGather</span>
                    </div>

                    <nav className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm text-muted-foreground">
                        <a href="#features" className="hover:text-foreground transition-colors duration-150">Features</a>
                        <a href="#pricing" className="hover:text-foreground transition-colors duration-150">Pricing</a>
                        <a href="#faq" className="hover:text-foreground transition-colors duration-150">FAQ</a>
                        <Link href="/login" className="hover:text-foreground transition-colors duration-150">Log in</Link>
                        <Link href="/register" className="hover:text-foreground transition-colors duration-150">Sign up</Link>
                    </nav>

                    <p className="text-sm text-muted-foreground">
                        © {new Date().getFullYear()} MomentGather. All rights reserved.
                    </p>
                </div>
            </div>
        </footer>
    );
}
