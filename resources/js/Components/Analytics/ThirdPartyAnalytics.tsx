import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type AnalyticsShared = {
    provider: 'none' | 'plausible' | 'gtm-stub';
    plausibleDomain: string | null;
    gtmContainerId: string | null;
    enabled: boolean;
    consent: boolean;
};

function loadPlausible(domain: string): void {
    if (document.querySelector('script[data-newsroom-analytics="plausible"]')) {
        return;
    }
    const script = document.createElement('script');
    script.defer = true;
    script.setAttribute('data-domain', domain);
    script.setAttribute('data-newsroom-analytics', 'plausible');
    script.src = 'https://plausible.io/js/script.js';
    document.head.appendChild(script);
}

function loadGtmStub(containerId: string): void {
    if (document.querySelector('script[data-newsroom-analytics="gtm-stub"]')) {
        return;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js', 'gtm.uniqueEventId': 0 });
    const script = document.createElement('script');
    script.async = true;
    script.setAttribute('data-newsroom-analytics', 'gtm-stub');
    script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(containerId)}`;
    document.head.appendChild(script);
}

declare global {
    interface Window {
        dataLayer?: Record<string, unknown>[];
    }
}

export default function ThirdPartyAnalytics() {
    const { analytics } = usePage().props as { analytics?: AnalyticsShared };
    const [dismissed, setDismissed] = useState(false);

    useEffect(() => {
        if (!analytics?.enabled || !analytics.consent) {
            return;
        }
        if (analytics.provider === 'plausible' && analytics.plausibleDomain) {
            loadPlausible(analytics.plausibleDomain);
        }
        if (analytics.provider === 'gtm-stub' && analytics.gtmContainerId) {
            loadGtmStub(analytics.gtmContainerId);
        }
    }, [analytics]);

    if (!analytics?.enabled || analytics.consent || dismissed) {
        return null;
    }

    function setConsent(consent: boolean) {
        router.post(
            '/analytics/consent',
            { consent },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (!consent) {
                        setDismissed(true);
                    }
                },
            },
        );
    }

    return (
        <div
            className="fixed inset-x-0 bottom-0 z-50 border-t border-edge/40 bg-surface px-4 py-3 text-body shadow-lg"
            role="dialog"
            aria-label="Analytics cookie consent"
        >
            <div className="mx-auto flex max-w-public flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm">
                    Optional analytics helps us understand traffic. First-party reading metrics work without this.
                    See the{' '}
                    <a href="/legal/cookies" className="underline">
                        cookie notice
                    </a>
                    .
                </p>
                <div className="flex shrink-0 gap-2">
                    <button type="button" className="button-secondary" onClick={() => setConsent(false)}>
                        Decline
                    </button>
                    <button type="button" className="button-primary" onClick={() => setConsent(true)}>
                        Accept analytics
                    </button>
                </div>
            </div>
        </div>
    );
}
