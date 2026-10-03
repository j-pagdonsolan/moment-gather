import { Head, Link, router } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/components/empty-state';
import PhotoGrid from '@/components/PhotoGrid';
import PhotoViewer from '@/components/PhotoViewer';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { GalleryPageProps, GalleryPhoto } from '@/types';

export default function GalleryPage({ event, photos, pagination }: GalleryPageProps) {
    const [items, setItems] = useState<GalleryPhoto[]>(photos);
    const [currentPage, setCurrentPage] = useState(pagination.current_page);
    const [lastPage, setLastPage] = useState(pagination.last_page);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [viewerIndex, setViewerIndex] = useState<number | null>(null);

    const hasMore = currentPage < lastPage;
    const photoCount = items.length;

    const loadMore = () => {
        if (loading || !hasMore) return;
        setLoading(true);
        setLoadError(false);
        router.get(
            `/e/${event.slug}/gallery`,
            { page: currentPage + 1 },
            {
                only: ['photos', 'pagination'],
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    const next = page.props.photos as GalleryPhoto[];
                    const meta = page.props.pagination as GalleryPageProps['pagination'];
                    setItems((prev) => {
                        const seen = new Set(prev.map((p) => p.uuid));
                        return [...prev, ...next.filter((p) => !seen.has(p.uuid))];
                    });
                    setCurrentPage(meta.current_page);
                    setLastPage(meta.last_page);
                },
                onError: () => {
                    setLoadError(true);
                    setLoading(false);
                },
                onFinish: () => setLoading(false),
            },
        );
    };

    return (
        <>
            <Head title={`${event.name} | Gallery | MomentGather`} />

            {/* Task 24: Sticky event header */}
            <header className="sticky top-0 z-10 bg-background/90 backdrop-blur-sm border-b border-border">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
                    <div>
                        <h1 className="font-semibold text-foreground">{event.name}</h1>
                        <p className="text-sm text-muted-foreground">{photoCount} photo{photoCount !== 1 ? 's' : ''}</p>
                    </div>
                    <a
                        href={`/e/${event.slug}`}
                        className="text-sm text-muted-foreground hover:text-foreground transition-colors duration-150"
                    >
                        ← Upload Photos
                    </a>
                </div>
            </header>

            <div className="mx-auto w-full max-w-5xl px-4 py-10">
                <header className="mb-8 text-center">
                    <h1 className="text-3xl font-semibold text-foreground">{event.name}</h1>
                    <p className="text-muted-foreground">Shared Photo Gallery</p>
                </header>

                {items.length === 0 ? (
                    <EmptyState
                        icon={ImageOff}
                        title="No photos yet"
                        description="Be the first to share a moment from this event."
                        action={
                            <Button asChild size="lg">
                                <Link href={`/e/${event.slug}`}>Upload Photos</Link>
                            </Button>
                        }
                    />
                ) : (
                    <>
                        <PhotoGrid photos={items} onSelect={(i) => setViewerIndex(i)} />

                        {/* Task 25: Skeleton grid — 8 items, correct columns, aria attrs */}
                        {loading && (
                            <div
                                className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4"
                                aria-live="polite"
                                aria-busy="true"
                            >
                                {Array.from({ length: 8 }).map((_, i) => (
                                    <Skeleton key={i} className="aspect-square w-full rounded-lg" />
                                ))}
                            </div>
                        )}

                        {/* Task 26: Load-more error state */}
                        {loadError && (
                            <div className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-6 text-center">
                                <p className="text-sm text-muted-foreground">Couldn't load more photos.</p>
                                <button
                                    onClick={() => { setLoadError(false); loadMore(); }}
                                    className="text-sm font-medium text-brand hover:underline"
                                >
                                    Try again
                                </button>
                            </div>
                        )}

                        {hasMore && !loadError && (
                            <div className="mt-8 flex justify-center">
                                <Button variant="outline" size="lg" onClick={loadMore} disabled={loading}>
                                    {loading ? 'Loading…' : 'Load More'}
                                </Button>
                            </div>
                        )}
                    </>
                )}

                {viewerIndex !== null && (
                    <PhotoViewer
                        photos={items}
                        index={viewerIndex}
                        slug={event.slug}
                        onIndexChange={setViewerIndex}
                        onClose={() => setViewerIndex(null)}
                    />
                )}
            </div>
        </>
    );
}
