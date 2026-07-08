import { MoonIcon, SunIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';

/** Light/dark toggle button (dark is the default theme). */
export default function ThemeToggle() {
    const t = useTranslations();
    const { toggle } = useTheme();
    const label = t('admin.topbar.toggle_theme');

    return (
        <Button
            variant="ghost"
            size="icon"
            onClick={toggle}
            aria-label={label}
            title={label}
        >
            {/* Both icons render identically on server and client; the <html>
                `dark` class (set pre-paint) picks which is visible via CSS, so
                the toggle is hydration-safe under SSR. */}
            <SunIcon className="hidden dark:block" />
            <MoonIcon className="block dark:hidden" />
        </Button>
    );
}
