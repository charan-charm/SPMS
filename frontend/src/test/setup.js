import '@testing-library/jest-dom/vitest'

/**
 * jsdom capability stubs for the shared SPMS foundations:
 * - matchMedia: needed by ThemeProvider (prefers-color-scheme) —
 *   defaults to "no match" (light).
 * - IntersectionObserver: needed by Motion's whileInView reveals on
 *   the landing page — reports every element as immediately visible
 *   so entrance states settle deterministically in tests.
 */
if (typeof window !== 'undefined') {
    if (!window.matchMedia) {
        window.matchMedia = (query) => ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
            dispatchEvent: () => false,
        })
    }

    if (!window.IntersectionObserver) {
        class ObservingStub {
            constructor(callback) {
                this.callback = callback
            }

            observe(target) {
                queueMicrotask(() => this.callback([{ isIntersecting: true, target }], this))
            }

            unobserve() {}

            disconnect() {}

            takeRecords() {
                return []
            }
        }
        window.IntersectionObserver = ObservingStub
    }
}
