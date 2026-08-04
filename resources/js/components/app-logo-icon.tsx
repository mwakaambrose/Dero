import type { SVGAttributes } from 'react';

/**
 * The Dero mark: a granary reduced to geometry.
 *
 * A conical thatched roof over a woven cylindrical body, standing on legs that
 * lift the grain clear of the ground. Single fill, no gradients, legible at
 * 16px as a favicon.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            viewBox="0 0 32 32"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            {/* Roof */}
            <path d="M16 2 3.5 12.2h25L16 2Z" fill="currentColor" />
            {/* Woven body */}
            <path
                d="M6 14.2h20v9.4a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-9.4Z"
                fill="currentColor"
                opacity="0.9"
            />
            {/* Weave bands, knocked out of the body */}
            <path
                d="M6 17.4h20M6 20.6h20"
                stroke="var(--background, #fff)"
                strokeWidth="1.4"
                strokeLinecap="round"
            />
            {/* Legs */}
            <path
                d="M9.5 26v3.6M22.5 26v3.6M16 26v3.6"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
        </svg>
    );
}
