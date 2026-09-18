import { useEffect } from 'react';
import { X, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import type { GalleryPhoto } from '@/types';

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

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowLeft') goPrev();
            if (e.key === 'ArrowRight') goNext();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [index]);

    if (!photo) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/90">
            <button type="button" onClick={onClose} aria-label="Close" className="absolute top-4 right-4 text-white">
                <X className="h-8 w-8" />
            </button>
            <button type="button" onClick={goPrev} aria-label="Previous" className="absolute left-2 text-white sm:left-6">
                <ChevronLeft className="h-10 w-10" />
            </button>

            <img src={photo.url} alt={photo.filename} className="max-h-[85vh] max-w-[90vw] object-contain" />

            <button type="button" onClick={goNext} aria-label="Next" className="absolute right-2 text-white sm:right-6">
                <ChevronRight className="h-10 w-10" />
            </button>

            <a
                href={`/e/${slug}/photos/${photo.uuid}/download`}
                className="absolute bottom-6 flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-white hover:bg-white/20"
            >
                <Download className="h-5 w-5" />
                Download
            </a>
        </div>
    );
}
