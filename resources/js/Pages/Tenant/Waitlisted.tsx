import { Head, Link } from '@inertiajs/react';
import { ListOrderedIcon } from 'lucide-react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

type WaitlistStatus = 'waiting' | 'offered' | 'converted' | 'expired';

type WaitlistedProps = {
    waitlist: {
        code: string;
        position: number;
        status: WaitlistStatus;
        service: string | null;
        service_id: number | null;
        starts_local: string | null;
        party_size: number;
        offered_until_local: string | null;
    };
};

/**
 * A waitlist place (SLO-100) at its durable address `/waitlisted/{code}`
 * (SLO-103): reached right after joining, and again from a bookmark or a shared
 * link — so it shows the place as it is NOW (still waiting, offered a seat,
 * booked, expired), not as it was at the moment of joining.
 */
export default function Waitlisted({ waitlist }: WaitlistedProps) {
    const t = useTranslations();
    // "You're on the list" is only true while waiting; a revisited page of an
    // offered, booked or expired place gets a neutral heading.
    const waiting = waitlist.status === 'waiting';
    const bookHref = waitlist.service_id
        ? `/book?service=${waitlist.service_id}`
        : '/book';

    return (
        <PublicLayout>
            <Head
                title={
                    waiting
                        ? t('tenant.waitlisted.title')
                        : t('tenant.waitlisted.title_place')
                }
            />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 py-16 sm:px-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <ListOrderedIcon className="size-12 text-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {waiting
                            ? t('tenant.waitlisted.title')
                            : t('tenant.waitlisted.title_place')}
                    </h1>
                    {waiting ? (
                        <p className="text-muted-foreground">
                            {t('tenant.waitlisted.subtitle')}
                        </p>
                    ) : null}
                </div>

                {waitlist.status === 'offered' ? (
                    <div
                        role="status"
                        className="flex flex-col items-center gap-3 rounded-xl border border-primary/40 bg-card px-4 py-6 text-center"
                    >
                        <p className="font-semibold">
                            {t('tenant.waitlisted.offered_title')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {waitlist.offered_until_local
                                ? t('tenant.waitlisted.offered_body', {
                                      deadline: waitlist.offered_until_local,
                                  })
                                : t(
                                      'tenant.waitlisted.offered_body_no_deadline',
                                  )}
                        </p>
                        <Button asChild>
                            <Link href={bookHref}>
                                {t('tenant.waitlisted.offered_action')}
                            </Link>
                        </Button>
                    </div>
                ) : waitlist.status === 'converted' ||
                  waitlist.status === 'expired' ? (
                    <div
                        role="status"
                        className="flex flex-col items-center gap-2 rounded-xl border border-border bg-card px-4 py-6 text-center"
                    >
                        <p className="font-semibold">
                            {t(`tenant.waitlisted.${waitlist.status}_title`)}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(`tenant.waitlisted.${waitlist.status}_body`)}
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col items-center gap-1 rounded-xl border border-border bg-card px-4 py-6 text-center">
                        <span className="text-xs text-muted-foreground uppercase">
                            {t('tenant.waitlisted.position_label')}
                        </span>
                        <Badge
                            variant="outline"
                            className="font-mono text-base tracking-widest"
                        >
                            {waitlist.position}
                        </Badge>
                    </div>
                )}

                <dl className="grid gap-px overflow-hidden rounded-xl border border-border bg-border">
                    <div className="flex items-center justify-between gap-4 bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.waitlisted.event')}
                        </dt>
                        <dd className="text-right font-medium">
                            {waitlist.service}
                            {waitlist.starts_local
                                ? ` · ${waitlist.starts_local}`
                                : ''}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-4 bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.waitlisted.party_size')}
                        </dt>
                        <dd className="font-medium">{waitlist.party_size}</dd>
                    </div>
                    <div className="flex items-center justify-between gap-4 bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.waitlisted.code_label')}
                        </dt>
                        <dd className="font-mono font-medium tracking-widest">
                            {waitlist.code}
                        </dd>
                    </div>
                    <div className="flex items-center justify-between gap-4 bg-card px-4 py-3 text-sm">
                        <dt className="text-muted-foreground">
                            {t('tenant.waitlisted.status_label')}
                        </dt>
                        <dd className="font-medium">
                            {t(`tenant.waitlisted.status.${waitlist.status}`)}
                        </dd>
                    </div>
                </dl>

                {waitlist.status === 'waiting' ? (
                    <p className="text-center text-sm text-muted-foreground">
                        {t('tenant.waitlisted.keep_link')}
                    </p>
                ) : null}

                <div className="flex justify-center">
                    <Button asChild variant="outline">
                        <Link href="/">{t('tenant.waitlisted.back')}</Link>
                    </Button>
                </div>
            </div>
        </PublicLayout>
    );
}
