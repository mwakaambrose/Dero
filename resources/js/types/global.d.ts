import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            currentTeam: Team | null;
            teams: Team[];
            [key: string]: unknown;
        };

        /**
         * Inertia::flash() data.
         *
         * It arrives on the page object, NOT among the props — read it as
         * usePage().flash, since usePage().props.flash is always undefined.
         */
        flashDataType: {
            toast?: { type: 'success' | 'error'; message: string };
            newAccessKey?: { accessKeyId: string; secretKey: string };
            objectLink?: {
                key: string;
                url: string;
                expiresAt: string | null;
            };
        };
    }
}
