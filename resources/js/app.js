import './bootstrap';
import '@fontsource-variable/inter/index.css';
import { SyncEngine } from './offline/sync.js';
import documentForm from './forms/document.js';

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
/*
 * Offline sync. Booted only when the page names a company, so the login and
 * public verification pages never open an IndexedDB database they cannot use.
 */
const companyId = document.querySelector('meta[name=opes-company]')?.content;

if (companyId) {
    window.opesSync = new SyncEngine(companyId);
    window.opesSync.start();
}

document.addEventListener('alpine:init', () => {
    /* Forms that own their own state so they keep working with no connection. */
    window.Alpine.data('opesDocumentForm', documentForm);

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

    /* Live sync state for the header indicator. */
    window.Alpine.data('opesSyncStatus', () => ({
        online: navigator.onLine,
        pending: 0,
        failed: 0,
        running: false,

        init() {
            const update = (state) => Object.assign(this, state);

            window.opesSync?.onChange(update);
            window.opesSync?.announce();

            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));
        },

        syncNow() {
            window.opesSync?.sync();
        },
    }));
});
