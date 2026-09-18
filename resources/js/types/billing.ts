export interface BillingPlan {
    slug: string;
    name: string;
    /** Price in minor units (e.g. cents). */
    price: number;
    billingInterval: string | null;
    maxActiveEvents: number;
    maxPhotosPerEvent: number;
    maxStorageBytes: number;
}

export interface BillingSubscription {
    status: string;
    cancelAtPeriodEnd: boolean;
    currentPeriodEnd: string | null;
    onGracePeriod: boolean;
}

export interface UsageMetric {
    used: number;
    limit: number;
}

export interface BillingUsage {
    events: UsageMetric;
    photos: UsageMetric;
    storage: UsageMetric;
}

export interface BillingPayment {
    id: number;
    date: string;
    /** Amount in minor units (e.g. cents). */
    amount: number;
    currency: string;
    status: string;
    reference: string | null;
}

export interface BillingPageProps {
    plan: BillingPlan;
    isPro: boolean;
    subscription: BillingSubscription | null;
    usage: BillingUsage;
    payments: BillingPayment[];
    currency: string;
    publicKey: string | null;
}
