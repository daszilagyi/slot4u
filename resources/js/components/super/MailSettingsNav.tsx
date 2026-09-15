import { Link } from '@inertiajs/react';
import { ArrowLeftIcon } from 'lucide-react';

import { useTranslations } from '@/lib/i18n';

/**
 * The way out of, and across, the two email pages (SLO-258): back to the
 * dashboard, and a switch between the frame (design) and the words (texts).
 * Without it the pages were a dead end, and the design page — one frame for
 * every mail — read as if it were the only template there is.
 */
export default function MailSettingsNav({
    current,
    mailCount,
}: {
    current: 'design' | 'texts';
    mailCount: number;
}) {
    const t = useTranslations();

    const tabs = [
        {
            key: 'design',
            href: '/emails/design',
            label: t('super.mail_nav.design'),
        },
        {
            key: 'texts',
            href: '/emails/templates',
            label: t('super.mail_nav.texts', { count: mailCount }),
        },
    ] as const;

    return (
        <div className="flex flex-col gap-4">
            <Link
                href="/"
                className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeftIcon className="size-4" />
                {t('super.mail_nav.back')}
            </Link>

            <nav
                aria-label={t('super.mail_nav.label')}
                className="flex flex-wrap gap-1 border-b border-border"
            >
                {tabs.map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        aria-current={tab.key === current ? 'page' : undefined}
                        className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                            tab.key === current
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
