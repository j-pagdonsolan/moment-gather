import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, X } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface Props {
    slug: string;
}

interface Preview {
    file: File;
    url: string;
}

// Accepted image MIME types — mirrors the hidden file input's `accept` and the
// backend validation. Used to filter files dropped via drag-and-drop.
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export default function PhotoUploader({ slug }: Props) {
    const [previews, setPreviews] = useState<Preview[]>([]);
    const [succeeded, setSucceeded] = useState(false);
    const [rateLimited, setRateLimited] = useState(false);
    const [dragging, setDragging] = useState(false);
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

    // Append a batch of files (from the picker or a drop) to the selection.
    function addFiles(files: File[]) {
        if (files.length === 0) {
            return;
        }
        const added = files.map((file) => ({ file, url: URL.createObjectURL(file) }));
        syncForm([...previews, ...added]);
        setSucceeded(false);
    }

    function handleSelect(e: React.ChangeEvent<HTMLInputElement>) {
        addFiles(Array.from(e.target.files ?? []));
        e.target.value = ''; // allow re-selecting the same file
    }

    // Progressive enhancement: desktop drag-and-drop. The hidden file input
    // remains the primary path; dropped files are filtered to accepted image
    // types and appended via the same syncForm path used by the picker.
    function handleDragOver(e: React.DragEvent) {
        e.preventDefault();
        setDragging(true);
    }

    function handleDragLeave(e: React.DragEvent) {
        e.preventDefault();
        setDragging(false);
    }

    function handleDrop(e: React.DragEvent) {
        e.preventDefault();
        setDragging(false);
        const files = Array.from(e.dataTransfer.files).filter((file) =>
            ACCEPTED_TYPES.includes(file.type),
        );
        addFiles(files);
    }

    function removeAt(index: number) {
        URL.revokeObjectURL(previews[index].url);
        syncForm(previews.filter((_, i) => i !== index));
    }

    function submit() {
        setRateLimited(false);
        post(`/e/${slug}/photos`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                previews.forEach((p) => URL.revokeObjectURL(p.url));
                setPreviews([]);
                reset('photos');
                setSucceeded(true);
            },
            // A 429 (rate limit) is a non-Inertia response and surfaces via the
            // httpException event. Show friendly copy and return false to
            // suppress Inertia's default error modal.
            onHttpException: (response) => {
                if (response.status === 429) {
                    setRateLimited(true);
                    return false;
                }
            },
        });
    }

    const errorMessages = Object.values(errors).filter(
        (message): message is string => typeof message === 'string',
    );

    // A failed submit (validation errors or a 429) leaves the pending selection
    // intact, so the button acts as a retry affordance.
    const hasError = errorMessages.length > 0 || rateLimited;

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

            <div
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                className={
                    dragging
                        ? 'rounded-lg ring-2 ring-ring ring-offset-2'
                        : undefined
                }
            >
                <Button
                    size="lg"
                    variant="outline"
                    className="min-h-11 w-full"
                    onClick={() => inputRef.current?.click()}
                >
                    Select Photos
                </Button>

                {previews.length > 0 && (
                    <ul className="mt-4 grid grid-cols-3 gap-2">
                        {previews.map((p, i) => (
                            <li key={p.url} className="relative">
                                <img
                                    src={p.url}
                                    alt=""
                                    className="aspect-square w-full rounded-md object-cover"
                                />
                                <button
                                    type="button"
                                    aria-label="Remove photo"
                                    onClick={() => removeAt(i)}
                                    className="absolute -top-3 -right-3 flex h-11 w-11 items-center justify-center rounded-full text-foreground"
                                >
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-background shadow">
                                        <X className="h-4 w-4" />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Live region so screen readers announce upload state changes. */}
            <div aria-live="polite" className="flex flex-col gap-4">
                {/* Friendly validation errors from the server error bag. */}
                {errorMessages.length > 0 && (
                    <div className="text-center text-sm text-destructive">
                        {errorMessages.map((message, i) => (
                            <p key={i}>{message}</p>
                        ))}
                    </div>
                )}

                {/* Friendly rate-limit (HTTP 429) message. */}
                {rateLimited && (
                    <div className="text-center text-sm text-destructive">
                        <p>You've uploaded too many photos in a short period. Please wait a moment and try again.</p>
                    </div>
                )}

                {succeeded && (
                    <p className="flex items-center justify-center gap-2 text-center text-sm font-medium text-foreground">
                        <CheckCircle2 className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <span>
                            Your photos have been uploaded and are being processed. They may take a moment to appear.
                        </span>
                    </p>
                )}
            </div>

            {previews.length > 0 && (
                <Button
                    size="lg"
                    className="min-h-11 w-full disabled:opacity-60"
                    disabled={processing}
                    aria-busy={processing}
                    onClick={submit}
                >
                    {processing
                        ? `Uploading${progress ? ` ${progress.percentage}%` : ''}…`
                        : hasError
                          ? 'Try Again'
                          : 'Upload Photos'}
                </Button>
            )}
        </div>
    );
}
