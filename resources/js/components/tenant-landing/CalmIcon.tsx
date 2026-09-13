import type { LandingIcon } from '@/types';

/**
 * The calm template's line icons, drawn the way its design draws them (thin,
 * round, sage). Five, because TenantLanding accepts five; an unknown name has
 * already been turned into the leaf on the server.
 */
export default function CalmIcon({
    icon,
    size = 34,
}: {
    icon: LandingIcon;
    size?: number;
}) {
    const common = {
        width: size,
        height: size,
        viewBox: '0 0 40 40',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
    };

    switch (icon) {
        case 'heart':
            return (
                <svg {...common}>
                    <path d="M20 34 L7 21 A7 7 0 0 1 20 12 A7 7 0 0 1 33 21 Z" />
                </svg>
            );
        case 'people':
            return (
                <svg {...common}>
                    <circle cx="14" cy="13" r="4.5" />
                    <circle cx="27" cy="13" r="4.5" />
                    <path d="M5 33c0-6 4-9 9-9s9 3 9 9M20 29c1-3 4-5 7-5 5 0 8 3 8 9" />
                </svg>
            );
        case 'shield':
            return (
                <svg {...common}>
                    <path d="M20 4 L33 9 V19 C33 27 27 33 20 36 C13 33 7 27 7 19 V9Z" />
                    <path d="M14 20l4 4 8-8" />
                </svg>
            );
        case 'calendar':
            return (
                <svg {...common}>
                    <rect x="6" y="9" width="28" height="26" rx="4" />
                    <path d="M6 17h28M14 5v7M26 5v7M13 24h4M20 24h4M27 24h4M13 30h4M20 30h4" />
                </svg>
            );
        case 'leaf':
        default:
            return (
                <svg {...common}>
                    <path d="M20 35 C9 28 7 15 20 5 C33 15 31 28 20 35Z" />
                    <path d="M20 12v22" />
                </svg>
            );
    }
}
