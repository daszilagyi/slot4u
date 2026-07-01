import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
    type PropsWithChildren,
} from 'react';

export type Theme = 'light' | 'dark';

type ThemeContextValue = {
    theme: Theme;
    setTheme: (theme: Theme) => void;
    toggle: () => void;
};

const ThemeContext = createContext<ThemeContextValue | null>(null);

const STORAGE_KEY = 'theme';

/**
 * Initial theme = whatever the pre-paint script in app.blade.php already
 * resolved onto <html> (dark by default). Reading the class keeps the React
 * state in sync with the DOM and avoids a hydration mismatch.
 */
function initialTheme(): Theme {
    if (typeof document === 'undefined') {
        return 'dark';
    }

    return document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}

function apply(theme: Theme): void {
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

/**
 * App-wide theme, dark by default (branding). Persisted to localStorage and
 * mirrored onto the <html> `dark` class; kept in sync across tabs.
 */
export function ThemeProvider({ children }: PropsWithChildren) {
    const [theme, setThemeState] = useState<Theme>(initialTheme);

    const setTheme = useCallback((next: Theme) => {
        setThemeState(next);
        apply(next);
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Storage unavailable (private mode) — the choice just won't persist.
        }
    }, []);

    const toggle = useCallback(() => {
        setThemeState((current) => {
            const next: Theme = current === 'dark' ? 'light' : 'dark';
            apply(next);
            try {
                localStorage.setItem(STORAGE_KEY, next);
            } catch {
                // ignore
            }

            return next;
        });
    }, []);

    useEffect(() => {
        function onStorage(event: StorageEvent) {
            if (
                event.key === STORAGE_KEY &&
                (event.newValue === 'dark' || event.newValue === 'light')
            ) {
                setThemeState(event.newValue);
                apply(event.newValue);
            }
        }

        window.addEventListener('storage', onStorage);

        return () => window.removeEventListener('storage', onStorage);
    }, []);

    return (
        <ThemeContext.Provider value={{ theme, setTheme, toggle }}>
            {children}
        </ThemeContext.Provider>
    );
}

// eslint-disable-next-line react-refresh/only-export-components -- provider + its hook belong together
export function useTheme(): ThemeContextValue {
    const context = useContext(ThemeContext);

    if (context === null) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }

    return context;
}
