import './bootstrap';
import '@fontsource-variable/inter/index.css';

/*
 * Register the service worker so the app shell is installable and survives a lost
 * connection. Registered after load so it never competes with first paint.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            /* Unsupported or blocked (private mode) — the app still works online. */
        });
    });
}

/**
 * Shell behaviour for the app layout.
 *
 * Registered on `alpine:init` because Livewire 3 boots Alpine itself — defining
 * the component before that event guarantees it exists when the shell renders.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('opesShell', () => ({
        drawer: false,
        collapsed: false,
        desktop: null,

        init() {
            try {
                this.collapsed = localStorage.getItem('opes-sidebar') === 'collapsed';
            } catch (e) {
                this.collapsed = false;
            }

            // Close the mobile drawer when the viewport grows into sidebar territory,
            // otherwise it stays mounted and traps focus off-screen.
            this.desktop = window.matchMedia('(min-width: 1024px)');
            this.desktop.addEventListener('change', (event) => {
                if (event.matches) {
                    this.drawer = false;
                }
            });

            // Follow the system theme only while the user has expressed no preference.
            window
                .matchMedia('(prefers-color-scheme: dark)')
                .addEventListener('change', (event) => {
                    if (! this.storedTheme()) {
                        this.applyTheme(event.matches);
                    }
                });
        },

        /** The hamburger opens the drawer on mobile and collapses the rail on desktop. */
        toggleSidebar() {
            if (this.desktop?.matches) {
                this.collapsed = ! this.collapsed;
                this.persistCollapsed();

                return;
            }

            this.drawer = ! this.drawer;
        },

        persistCollapsed() {
            try {
                localStorage.setItem('opes-sidebar', this.collapsed ? 'collapsed' : 'expanded');
            } catch (e) {
                /* Storage unavailable — the preference just won't survive a reload. */
            }
        },

        storedTheme() {
            try {
                return localStorage.getItem('opes-theme');
            } catch (e) {
                return null;
            }
        },

        toggleTheme() {
            const dark = ! document.documentElement.classList.contains('dark');

            this.applyTheme(dark);

            try {
                localStorage.setItem('opes-theme', dark ? 'dark' : 'light');
            } catch (e) {
                /* Preference not persisted; the toggle still works for this session. */
            }
        },

        applyTheme(dark) {
            document.documentElement.classList.toggle('dark', dark);
        },
    }));
});
