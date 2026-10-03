import { useEffect, useRef } from 'react';

import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X, ChevronLeft, ChevronRight, Download } from 'lucide-react';

import { Dialog, DialogOverlay, DialogPortal, DialogTitle } from '@/components/ui/dialog';
import type { GalleryPhoto } from '@/types';

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
    const hasMultiple = photos.length > 1;
    const goPrev = () => onIndexChange((index - 1 + photos.length) % photos.length);
    const goNext = () => onIndexChange((index + 1) % photos.length);
    const touchStartX = useRef<number | null>(null);

    // Radix Dialog owns focus-trap, scroll-lock, and focus-restore.
    // Handle ArrowLeft/ArrowRight for prev/next navigation.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowLeft') goPrev();
            if (e.key === 'ArrowRight') goNext();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [index, photos.length]);

    // Task 28: Escape key closes the viewer
    useEffect(() => {
        const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [onClose]);

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
            goNext(); // swipe left -> next
        } else {
            goPrev(); // swipe right -> prev
        }
    };

    // Persistent dark, blurred background keeps the controls visible over any image.
    const controlBase =
        'z-[60] flex items-center justify-center rounded-full bg-black/50 text-white shadow-lg backdrop-blur-sm transition-colors hover:bg-black/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white';

    return (
        <Dialog open onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogPortal>
                {/* Task 28: Add transition-opacity duration-200 to overlay */}
                <DialogOverlay className="bg-black/95 transition-opacity duration-200" />

                {/*
                  A full-screen, unstyled Radix content (no default centered "card"
                  box). We position the image and controls against the viewport so
                  the backdrop truly fills the screen and the arrows never get
                  clipped by a smaller dialog box.
                  Task 28: Explicit role="dialog" and aria-modal="true" added.
                */}
                <DialogPrimitive.Content
                    role="dialog"
                    aria-modal="true"
                    aria-describedby={undefined}
                    onTouchStart={handleTouchStart}
                    onTouchEnd={handleTouchEnd}
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 outline-none"
                >
                    <DialogTitle className="sr-only">{photo.filename ?? 'Photo'}</DialogTitle>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className={`absolute top-4 right-4 h-11 w-11 ${controlBase}`}
                    >
                        <X className="h-6 w-6" />
                    </button>

                    {hasMultiple && (
                        <button
                            type="button"
                            onClick={goPrev}
                            aria-label="Previous photo"
                            className={`absolute top-1/2 left-3 h-12 w-12 -translate-y-1/2 sm:left-6 ${controlBase}`}
                        >
                            <ChevronLeft className="h-7 w-7" />
                        </button>
                    )}

                    <img
                        src={photo.optimizedUrl}
                        alt={photo.filename}
                        className="max-h-[85vh] max-w-[90vw] object-contain select-none"
                    />

                    {hasMultiple && (
                        <button
                            type="button"
                            onClick={goNext}
                            aria-label="Next photo"
                            className={`absolute top-1/2 right-3 h-12 w-12 -translate-y-1/2 sm:right-6 ${controlBase}`}
                        >
                            <ChevronRight className="h-7 w-7" />
                        </button>
                    )}

                    <a
                        href={`/e/${slug}/photos/${photo.uuid}/download`}
                        className={`absolute bottom-6 min-h-11 gap-2 px-4 py-2 ${controlBase}`}
                    >
                        <Download className="h-5 w-5" />
                        Download
                    </a>
                </DialogPrimitive.Content>
            </DialogPortal>
        </Dialog>
    );
}
