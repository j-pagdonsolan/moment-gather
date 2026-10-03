import { Head, useForm } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import {
    create as eventsCreate,
    index as eventsIndex,
    store as eventsStore,
} from '@/routes/events';

type CreateEventForm = {
    name: string;
    description: string;
    event_date: string;
    location: string;
};

export default function EventsCreate() {
    const { data, setData, post, processing, errors } =
        useForm<CreateEventForm>({
            name: '',
            description: '',
            event_date: '',
            location: '',
        });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(eventsStore().url);
    };

    return (
        <>
            <Head title="Create Event" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {/* Branded page header — text-foreground is explicit to prevent color bleed */}
                <div className="flex items-center gap-3 rounded-xl border border-border bg-muted/50 p-4">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand text-brand-foreground">
                        <CalendarPlus className="size-5" aria-hidden="true" />
                    </div>
                    <div className="text-foreground">
                        <p className="font-semibold">Create a new event</p>
                        <p className="text-xs text-muted-foreground">Fill in the details below and get your QR code in seconds.</p>
                    </div>
                </div>

                {/* Form */}
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
                            placeholder="Event name"
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
                            placeholder="Event location"
                        />
                        <InputError message={errors.location} />
                    </div>

                    <div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="bg-brand text-brand-foreground hover:bg-brand/90"
                            data-test="create-event-button"
                        >
                            {processing ? 'Creating…' : 'Create Event'}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

EventsCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Events', href: eventsIndex() },
        { title: 'Create Event', href: eventsCreate() },
    ],
};
