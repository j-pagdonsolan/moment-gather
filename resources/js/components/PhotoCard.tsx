import { useState } from 'react';
import { ImageOff } from 'lucide-react';
import type { GalleryPhoto } from '@/types';

export default function PhotoCard({
    photo,
    onClick,
}: {
    photo: GalleryPhoto;
    onClick: () => void;
}) {
    const [broken, setBroken] = useState(false);

    return (
        <button
            type="button"
            onClick={onClick}
            className="group relative aspect-square overflow-hidden rounded-lg bg-muted"
        >
            {broken ? (
                <span className="flex h-full w-full flex-col items-center justify-center gap-1 text-muted-foreground">
                    <ImageOff className="h-6 w-6" />
                    <span className="text-xs">Photo unavailable</span>
                </span>
            ) : (
                <img
                    src={photo.url}
                    alt={photo.filename}
                    width={photo.width ?? undefined}
                    height={photo.height ?? undefined}
                    loading="lazy"
                    onError={() => setBroken(true)}
                    className="h-full w-full object-cover transition group-hover:scale-105"
                />
            )}
        </button>
    );
}
