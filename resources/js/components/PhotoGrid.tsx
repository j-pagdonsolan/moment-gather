import PhotoCard from '@/components/PhotoCard';
import type { GalleryPhoto } from '@/types';

export default function PhotoGrid({
    photos,
    onSelect,
}: {
    photos: GalleryPhoto[];
    onSelect: (index: number) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            {photos.map((photo, i) => (
                <PhotoCard key={photo.uuid} photo={photo} onClick={() => onSelect(i)} />
            ))}
        </div>
    );
}
