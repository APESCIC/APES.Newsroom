import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ThirdPartyAnalytics from './Components/Analytics/ThirdPartyAnalytics';
import RoleSwitcher from './Components/Dev/RoleSwitcher';

const appName = document.title || 'APES Newsroom';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: React.ComponentType }>('./Pages/**/*.tsx', { eager: true });
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Unknown Inertia page component: ${name}.tsx`);
        }

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <App {...props}>
                {({ Component, props: pageProps, key }) => (
                    <>
                        <Component key={key} {...pageProps} />
                        <ThirdPartyAnalytics />
                        <RoleSwitcher />
                    </>
                )}
            </App>,
        );
    },
});
