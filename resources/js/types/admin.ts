export interface AdminUser {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    is_active: boolean;
    roles: string[];
    plan: 'free' | 'pro';
    event_count: number;
    photo_count?: number;
    storage_used_bytes?: number;
    two_fa_enabled?: boolean;
    created_at: string;
    updated_at: string;
    // NEVER include password, two_factor_secret, remember_token
}

export interface AdminEvent {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    description: string | null;
    event_date: string | null;
    location: string | null;
    status: 'active' | 'draft' | 'archived';
    upload_enabled: boolean;
    photo_count: number;
    owner: { id: number; name: string; email: string };
    created_at: string;
    updated_at: string;
}

export interface AdminPhoto {
    id: number;
    uuid: string;
    original_filename: string;
    mime_type: string;
    file_size: number;
    width: number | null;
    height: number | null;
    status: 'pending' | 'processing' | 'ready' | 'failed';
    event: { id: number; uuid: string; name: string };
    owner: { id: number; name: string; email: string };
    created_at: string;
    updated_at: string;
    // NEVER include original_path, optimized_path, thumbnail_path
}

export interface AdminPlan {
    slug: string;
    name: string;
    price: number;
    price_source: 'override' | 'config';
    max_active_events: number;
    max_active_events_source: 'override' | 'config';
    max_photos_per_event: number;
    max_photos_per_event_source: 'override' | 'config';
    max_storage_bytes: number;
    max_storage_bytes_source: 'override' | 'config';
    is_active: boolean;
}

export interface AdminSubscription {
    id: number;
    user: { id: number; name: string; email: string };
    plan: string;
    provider: string;
    status: string;
    current_period_start: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    canceled_at: string | null;
    created_at: string;
    updated_at: string;
    // NEVER include provider_subscription_id
}

export interface AdminPayment {
    id: number;
    user: { id: number; name: string; email: string };
    amount: number;
    currency: string;
    status: string;
    provider: string;
    paid_at: string | null;
    subscription_id: number | null;
    subscription_plan?: string;
    created_at: string;
    // NEVER include provider_payment_id or metadata
}

export interface AuditLogEntry {
    id: number;
    actor: { id: number; name: string; email: string } | null;
    action: string;
    target_type: string | null;
    target_id: number | null;
    description: string;
    ip_address: string | null;
    user_agent?: string;
    metadata?: Record<string, unknown>;
    created_at: string;
}

export interface AdminDashboardStats {
    users: {
        total: number;
        active: number;
        inactive: number;
        super_admin_count: number;
    };
    events: {
        total: number;
        active: number;
        draft: number;
        archived: number;
    };
    photos: {
        total: number;
        by_status: {
            pending: number;
            processing: number;
            ready: number;
            failed: number;
        };
    };
    subscriptions: {
        free_plan_users: number;
        pro_plan_users: number;
        active: number;
        canceled: number;
    };
    payments: {
        succeeded: number;
        failed_count: number;
        recent: AdminPayment[];
    };
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}
