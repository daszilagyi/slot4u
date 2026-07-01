import { MoonIcon, SunIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';

/** Light/dark toggle button (dark is the default theme). */
export default function ThemeToggle() {
    const t = useTranslations();
    const { theme, toggle } = useTheme();
    const label = t('admin.topbar.toggle_theme');

    return (
        <Button
            variant="ghost"
            size="icon"
            onClick={toggle}
            aria-label={label}
            title={label}
        >
            {theme === 'dark' ? <SunIcon /> : <MoonIcon />}
        </Button>
    );
}
