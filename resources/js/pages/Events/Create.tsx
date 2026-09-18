import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
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

            <div className="space-y-6">
                <Heading
                    title="Create Event"
                    description="Set up a new event to share with attendees"
                />

                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>

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

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>

                        <textarea
                            id="description"
                            name="description"
                            value={data.description}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            maxLength={5000}
                            rows={5}
                            placeholder="Describe your event"
                            className={cn(
                                'border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                            )}
                        />

                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="event_date">Event Date</Label>

                        <Input
                            id="event_date"
                            name="event_date"
                            type="date"
                            value={data.event_date}
                            onChange={(e) =>
                                setData('event_date', e.target.value)
                            }
                        />

                        <InputError message={errors.event_date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="location">Location</Label>

                        <Input
                            id="location"
                            name="location"
                            value={data.location}
                            onChange={(e) =>
                                setData('location', e.target.value)
                            }
                            maxLength={255}
                            autoComplete="off"
                            placeholder="Event location"
                        />

                        <InputError message={errors.location} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="create-event-button"
                        >
                            Create Event
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

EventsCreate.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Events',
            href: eventsIndex(),
        },
        {
            title: 'Create Event',
            href: eventsCreate(),
        },
    ],
};
