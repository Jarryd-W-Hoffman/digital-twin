import type { SVGAttributes } from 'react';

/** Meridian's mark: a globe with one meridian, as in the viewer's top bar. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
            <circle
                cx="16"
                cy="16"
                r="12"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.6"
            />
            <ellipse
                cx="16"
                cy="16"
                rx="5.5"
                ry="12"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.6"
            />
        </svg>
    );
}
