import { usePage } from '@inertiajs/react';

import { useFeatures } from '@/lib/features';
import { useTranslations } from '@/lib/i18n';

/**
 * The signed-in visitor's own links on a tenant's public pages — or the login
 * link for a guest. Shared with the landing templates (SLO-238), which draw
 * their own header but must not lose the way to "my bookings".
 */
export function usePublicAccountLinks(): { href: string; label: string }[] {
    const t = useTranslations();
    const feature = useFeatures();
    const { auth, messages_unread } = usePage().props;

    const accountLinks: { href: string; label: string }[] =
        auth.user && !auth.user.is_staff
            ? [
                  { href: '/my/bookings', label: t('tenant.nav.my_bookings') },
                  ...(feature('feature_waitlist')
                      ? [
                            {
                                href: '/my/waitlist',
                                label: t('tenant.nav.my_waitlist'),
                            },
                        ]
                      : []),
                  ...(feature('feature_quote_request')
                      ? [
                            {
                                href: '/my/quotes',
                                label: t('tenant.nav.my_quotes'),
                            },
                        ]
                      : []),
                  ...(feature('feature_online_payment')
                      ? [
                            {
                                href: '/my/payments',
                                label: t('tenant.nav.my_payments'),
                            },
                        ]
                      : []),
                  ...(feature('feature_invoicing')
                      ? [
                            {
                                href: '/my/invoices',
                                label: t('tenant.nav.my_invoices'),
                            },
                        ]
                      : []),
                  ...(feature('feature_messages')
                      ? [
                            {
                                href: '/my/messages',
                                label:
                                    messages_unread && messages_unread > 0
                                        ? `${t('tenant.nav.my_messages')} (${messages_unread})`
                                        : t('tenant.nav.my_messages'),
                            },
                        ]
                      : []),
                  { href: '/my/profile', label: t('tenant.nav.my_profile') },
                  // Not feature-gated, unlike everything above it: the export
                  // and erasure rights are statutory, so no tenant setting may
                  // hide the way to exercise them (SLO-159).
                  { href: '/my/privacy', label: t('tenant.nav.my_privacy') },
              ]
            : auth.user
              ? []
              : [{ href: '/login', label: t('tenant.nav.login') }];

    return accountLinks;
}
