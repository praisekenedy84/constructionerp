import { Button } from '@/Components/ui/button';
import { getTheme, setTheme, type Theme } from '@/lib/theme';
import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ThemeToggleProps {
    className?: string;
}

export default function ThemeToggle({ className }: ThemeToggleProps) {
    const [theme, setThemeState] = useState<Theme>('light');

    useEffect(() => {
        setThemeState(getTheme());
    }, []);

    function toggle() {
        const next = theme === 'dark' ? 'light' : 'dark';
        setTheme(next);
        setThemeState(next);
    }

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={toggle}
            className={className}
            aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
        >
            {theme === 'dark' ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
        </Button>
    );
}
