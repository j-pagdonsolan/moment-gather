import { useEffect, useRef } from 'react';
import { X, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import type { GalleryPhoto } from '@/types';
import { Dialog, DialogClose, DialogContent, DialogTitle } from '@/components/ui/dialog';

const SWIPE_THRESHOLD = 50;

export default function PhotoViewer({
    photos,
    index,
    slug,
    onIndexChange,
    onClose,
}: {
    photos: GalleryPhoto[];
    index: number;
    slug: string;
    onIndexChange: (i: number) => void;
    onClose: () => void;
}) {
    const photo = photos[index];
    const goPrev = () => onIndexChange((index - 1 + photos.length) % photos.length);
    const goNext = () => onIndexChange((index + 1) % photos.length);
    const touchStartX = useRef<number | null>(null);

    // Radix Dialog owns Escape, focus-trap, scroll-lock, focus-restore and aria-modal.
    // We only handle ArrowLeft/ArrowRight here for prev/next navigation.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowLeft') goPrev();
            if (e.key === 'ArrowRight') goNext();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [index]);

    if (!photo) return null;

    const handleTouchStart = (e: React.TouchEvent) => {
        touchStartX.current = e.changedTouches[0]?.clientX ?? null;
    };

    const handleTouchEnd = (e: React.TouchEvent) => {
        if (touchStartX.current === null) return;
        const endX = e.changedTouches[0]?.clientX ?? touchStartX.current;
        const delta = endX - touchStartX.current;
        touchStartX.current = null;
        if (Math.abs(delta) <= SWIPE_THRESHOLD) return;
        if (delta < 0) {
            goNext(); // swipe left → next
        } else {
            goPrev(); // swipe right → prev
        }
    };

    return (
        <Dialog open onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogContent
                aria-describedby={undefined}
                className="flex h-screen w-screen max-w-none items-center justify-center border-0 bg-black/95 p-0 [&>button:last-child]:hidden"
                onTouchStart={handleTouchStart}
                onTouchEnd={handleTouchEnd}
            >
                <DialogTitle className="sr-only">{photo.filename ?? 'Photo'}</DialogTitle>

                <DialogClose
                    aria-label="Close"
                    className="absolute top-4 right-4 z-10 flex h-11 w-11 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                >
                    <X className="h-6 w-6" />
                </DialogClose>

                <button
                    type="button"
                    onClick={goPrev}
                    aria-label="Previous"
                    className="absolute left-2 z-10 flex h-11 w-11 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white sm:left-6"
                >
                    <ChevronLeft className="h-8 w-8" />
                </button>

                <img
                    src={photo.optimizedUrl}
                    alt={photo.filename}
                    className="max-h-[85vh] max-w-[90vw] object-contain"
                />

                <button
                    type="button"
                    onClick={goNext}
                    aria-label="Next"
                    className="absolute right-2 z-10 flex h-11 w-11 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white sm:right-6"
                >
                    <ChevronRight className="h-8 w-8" />
                </button>

                <a
                    href={`/e/${slug}/photos/${photo.uuid}/download`}
                    className="absolute bottom-6 flex min-h-11 items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-white transition-colors hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                >
                    <Download className="h-5 w-5" />
                    Download
                </a>
            </DialogContent>
        </Dialog>
    );
}
