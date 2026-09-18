import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { X } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface Props {
    slug: string;
}

interface Preview {
    file: File;
    url: string;
}

export default function PhotoUploader({ slug }: Props) {
    const [previews, setPreviews] = useState<Preview[]>([]);
    const [succeeded, setSucceeded] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const { setData, post, processing, progress, errors, reset } = useForm<{ photos: File[] }>({
        photos: [],
    });

    // Revoke all object URLs on unmount to avoid leaks.
    useEffect(() => {
        return () => previews.forEach((p) => URL.revokeObjectURL(p.url));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function syncForm(next: Preview[]) {
        setPreviews(next);
        setData(
            'photos',
            next.map((p) => p.file),
        );
    }

    function handleSelect(e: React.ChangeEvent<HTMLInputElement>) {
        const files = Array.from(e.target.files ?? []);
        const added = files.map((file) => ({ file, url: URL.createObjectURL(file) }));
        syncForm([...previews, ...added]);
        setSucceeded(false);
        e.target.value = ''; // allow re-selecting the same file
    }

    function removeAt(index: number) {
        URL.revokeObjectURL(previews[index].url);
        syncForm(previews.filter((_, i) => i !== index));
    }

    function submit() {
        post(`/e/${slug}/photos`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                previews.forEach((p) => URL.revokeObjectURL(p.url));
                setPreviews([]);
                reset('photos');
                setSucceeded(true);
            },
        });
    }

    const errorMessages = Object.values(errors).filter(
        (message): message is string => typeof message === 'string',
    );

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1 text-center">
                <h2 className="text-lg font-semibold text-foreground">Share Your Moments</h2>
                <p className="text-sm text-muted-foreground">
                    Select photos from your device and add them to this event.
                </p>
            </div>

            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={handleSelect}
            />

            <Button size="lg" variant="outline" className="w-full" onClick={() => inputRef.current?.click()}>
                Select Photos
            </Button>

            {previews.length > 0 && (
                <ul className="grid grid-cols-3 gap-2">
                    {previews.map((p, i) => (
                        <li key={p.url} className="relative">
                            <img src={p.url} alt="" className="aspect-square w-full rounded-md object-cover" />
                            <button
                                type="button"
                                aria-label="Remove photo"
                                onClick={() => removeAt(i)}
                                className="absolute -top-2 -right-2 rounded-full bg-background p-1 shadow"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {/* Friendly validation errors from the server error bag. */}
            {errorMessages.length > 0 && (
                <div className="text-center text-sm text-destructive">
                    {errorMessages.map((message, i) => (
                        <p key={i}>{message}</p>
                    ))}
                </div>
            )}

            {previews.length > 0 && (
                <Button size="lg" className="w-full" disabled={processing} onClick={submit}>
                    {processing
                        ? `Uploading${progress ? ` ${progress.percentage}%` : ''}…`
                        : 'Upload Photos'}
                </Button>
            )}

            {succeeded && (
                <p className="text-center text-sm text-foreground">
                    Your photos have been added to the event!
                </p>
            )}
        </div>
    );
}
