import { Head, useForm } from '@inertiajs/react';
import { PencilLine } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import {
    edit as eventsEdit,
    index as eventsIndex,
    show as eventsShow,
    update as eventsUpdate,
} from '@/routes/events';
import type { Event } from '@/types';

interface EventsEditProps {
    event: Event;
}

export default function EventsEdit({ event }: EventsEditProps) {
    const { data, setData, put, processing, errors } = useForm({
        name: event.name,
        description: event.description ?? '',
        event_date: event.event_date ?? '',
        location: event.location ?? '',
        status: event.status,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(eventsUpdate({ event: event.uuid }).url);
    };

    return (
        <>
            <Head title={`Edit ${event.name}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {/* Branded page header — text-foreground is explicit to prevent color bleed */}
                <div className="flex items-center gap-3 rounded-xl border border-border bg-muted/50 p-4">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
                        <PencilLine className="size-5" aria-hidden="true" />
                    </div>
                    <div className="text-foreground">
                        <p className="font-semibold">Edit event</p>
                        <p className="text-xs text-muted-foreground">{event.name}</p>
                    </div>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-5 max-w-xl">
                    <div className="grid gap-1.5">
                        <Label htmlFor="name" className="text-foreground">
                            Name <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="name"
                            name="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            maxLength={255}
                            autoComplete="off"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="description" className="text-foreground">Description</Label>
                        <Textarea
                            id="description"
                            name="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            maxLength={5000}
                            rows={5}
                            placeholder="Describe your event"
                        />
                        <p className="text-xs text-muted-foreground text-right">{data.description?.length ?? 0}/500</p>
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="event_date" className="text-foreground">Event Date</Label>
                        <Input
                            id="event_date"
                            name="event_date"
                            type="date"
                            value={data.event_date}
                            onChange={(e) => setData('event_date', e.target.value)}
                        />
                        <InputError message={errors.event_date} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="location" className="text-foreground">Location</Label>
                        <Input
                            id="location"
                            name="location"
                            value={data.location}
                            onChange={(e) => setData('location', e.target.value)}
                            maxLength={255}
                            autoComplete="off"
                        />
                        <InputError message={errors.location} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="status" className="text-foreground">Status</Label>
                        <select
                            id="status"
                            name="status"
                            value={data.status}
                            onChange={(e) =>
                                setData(
                                    'status',
                                    e.target.value as Event['status'],
                                )
                            }
                            className="border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive flex h-9 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                        >
                            <option value="active">active</option>
                            <option value="archived">archived</option>
                        </select>
                        <InputError message={errors.status} />
                    </div>

                    <div>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

EventsEdit.layout = (props: { event: Event }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Events', href: eventsIndex() },
        {
            title: props.event.name,
            href: eventsShow({ event: props.event.uuid }),
        },
        { title: 'Edit', href: eventsEdit({ event: props.event.uuid }) },
    ],
});
