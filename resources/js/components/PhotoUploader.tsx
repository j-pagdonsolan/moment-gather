import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, UploadCloud, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

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

    // Success state: full-panel replacement card.
    if (succeeded) {
        return (
            <div className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-8 text-center">
                <CheckCircle2 className="size-12 text-green-500" aria-hidden="true" />
                <div>
                    <p className="font-semibold text-foreground">Photos uploaded!</p>
                    <p className="mt-1 text-sm text-muted-foreground">Your photos have been added to the gallery.</p>
                </div>
                <Button asChild variant="outline">
                    <a href={`/e/${slug}/gallery`}>View Gallery</a>
                </Button>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={handleSelect}
            />

            {/* Drag-drop upload zone */}
            <div
                role="button"
                tabIndex={0}
                aria-label="Upload photos — press Enter or Space to browse files"
                onClick={() => inputRef.current?.click()}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={cn(
                    'border-2 border-dashed rounded-xl p-8 flex flex-col items-center gap-3 cursor-pointer transition-colors duration-200 select-none',
                    dragging ? 'border-brand bg-brand-muted' : 'border-border hover:border-brand/50 hover:bg-muted/40',
                )}
            >
                <UploadCloud className="size-10 text-muted-foreground" aria-hidden="true" />
                <div className="text-center">
                    <p className="font-medium text-foreground">Drag photos here</p>
                    <p className="text-sm text-muted-foreground">or click to browse</p>
                </div>
                <p className="text-xs text-muted-foreground">JPG, PNG, WEBP · Max 10 MB each</p>
            </div>

            {/* Preview grid */}
            {previews.length > 0 && (
                <ul className="grid grid-cols-3 gap-2">
                    {previews.map((p, i) => (
                        <li key={p.url} className="relative animate-in fade-in zoom-in-95 duration-150">
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

            {/* Upload progress bar */}
            {processing && (
                <div className="space-y-1">
                    <div className="h-1.5 rounded-full bg-muted overflow-hidden">
                        <div
                            className="h-full bg-brand transition-[width] duration-300 ease-out"
                            style={{ width: `${progress?.percentage ?? 0}%` }}
                        />
                    </div>
                    <p className="text-xs text-center text-muted-foreground">
                        {progress?.percentage ?? 0}%
                    </p>
                </div>
            )}

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
                        ? 'Uploading…'
                        : hasError
                          ? 'Try Again'
                          : 'Upload Photos'}
                </Button>
            )}
        </div>
    );
}
