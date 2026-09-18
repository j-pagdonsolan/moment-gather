export type EventStatus = 'active' | 'archived';

export interface Event {
    id: number;
    uuid: string;
    user_id: number;
    name: string;
    slug: string;
    description: string | null;
    event_date: string | null;
    location: string | null;
    status: EventStatus;
    upload_enabled: boolean;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface DashboardStats {
    totalEvents: number;
    activeEvents: number;
    archivedEvents: number;
}

export interface PublicEvent {
    name: string;
    description: string | null;
    event_date: string | null;
    location: string | null;
    upload_enabled: boolean;
}
