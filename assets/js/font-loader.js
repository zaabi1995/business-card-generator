/**
 * FontLoader - Comprehensive Google Fonts loader
 * Organized by categories and language support
 * Loads ALL fonts for template designer
 */
const FontLoader = {
    // Comprehensive font configuration by category
    fonts: {
        // === ENGLISH / LATIN FONTS ===
        sansSerif: [
            'Inter:400,500,600,700',
            'Plus Jakarta Sans:400,500,600,700',
            'Montserrat:400,500,600,700',
            'Roboto:400,500,700',
            'Poppins:400,500,600,700',
            'Open Sans:400,600,700',
            'Lato:400,700',
            'Nunito:400,600,700',
            'Raleway:400,500,600,700',
            'Work Sans:400,500,600,700',
            'DM Sans:400,500,700',
            'Outfit:400,500,600,700',
            'Manrope:400,500,600,700,800',
            'Urbanist:400,500,600,700',
            'Lexend:400,500,600,700',
            'Sora:400,500,600,700',
            'Rubik:400,500,600,700',
            'Quicksand:400,500,600,700',
            'Ubuntu:400,500,700',
            'Barlow:400,500,600,700',
            'Jost:400,500,600,700'
        ],
        
        serif: [
            'Playfair Display:400,500,600,700',
            'Merriweather:400,700',
            'Lora:400,500,600,700',
            'PT Serif:400,700',
            'Libre Baskerville:400,700',
            'EB Garamond:400,500,600,700',
            'Cormorant Garamond:400,500,600,700',
            'Spectral:400,500,600,700',
            'Noto Serif:400,700',
            'Vollkorn:400,500,600,700',
            'Bodoni Moda:400,500,600,700'
        ],
        
        display: [
            'Bebas Neue:400',
            'Oswald:400,500,600,700',
            'Anton:400',
            'Archivo Black:400',
            'Righteous:400',
            'Teko:400,500,600,700',
            'Big Shoulders Display:400,500,600,700,800',
            'Fredoka:400,500,600,700'
        ],
        
        handwriting: [
            'Dancing Script:400,500,600,700',
            'Pacifico:400',
            'Great Vibes:400',
            'Sacramento:400',
            'Allura:400',
            'Lobster:400',
            'Caveat:400,500,600,700',
            'Kaushan Script:400'
        ],
        
        monospace: [
            'Roboto Mono:400,500,600,700',
            'JetBrains Mono:400,500,600,700',
            'Fira Code:400,500,600,700',
            'Source Code Pro:400,500,600,700',
            'Space Mono:400,700'
        ],
        
        // === ARABIC FONTS ===
        arabic: [
            'Cairo:400,500,600,700',
            'Tajawal:200,300,400,500,700,800,900',
            'Almarai:300,400,700,800',
            'Noto Kufi Arabic:400,500,600,700',
            'Amiri:400,700',
            'IBM Plex Sans Arabic:100,200,300,400,500,600,700',
            'Noto Sans Arabic:400,500,600,700',
            'Readex Pro:200,300,400,500,600,700',
            'El Messiri:400,500,600,700',
            'Changa:200,300,400,500,600,700,800',
            'Reem Kufi:400,500,600,700',
            'Lateef:200,300,400,500,600,700,800',
            'Scheherazade New:400,500,600,700',
            'Harmattan:400,500,600,700',
            'Markazi Text:400,500,600,700',
            'Mada:200,300,400,500,600,700,800,900',
            'Lalezar:400',
            'Lemonada:300,400,500,600,700',
            'Aref Ruqaa:400,700',
            'Mirza:400,500,600,700',
            'Rakkas:400',
            'Baloo Bhaijaan 2:400,500,600,700,800',
            'Noto Naskh Arabic:400,500,600,700',
            'Noto Nastaliq Urdu:400,500,600,700',
            'Gulzar:400'
        ]
    },
    
    // Category display names for UI
    categoryNames: {
        sansSerif: 'Sans-Serif',
        serif: 'Serif',
        display: 'Display',
        handwriting: 'Handwriting & Script',
        monospace: 'Monospace',
        arabic: 'Arabic (العربية)'
    },
    
    // Track loading state
    isLoaded: false,
    isLoading: false,
    loadedFonts: [],
    callbacks: [],
    
    /**
     * Get all font families
     */
    getAllFamilies() {
        const allFonts = [];
        for (const category in this.fonts) {
            allFonts.push(...this.fonts[category]);
        }
        // Remove duplicates
        return [...new Set(allFonts)];
    },
    
    /**
     * Get fonts by category
     */
    getFontsByCategory(category) {
        return this.fonts[category] || [];
    },
    
    /**
     * Get font families by language
     * @param {string} language - 'english', 'arabic', or 'all'
     */
    getFamilies(language = 'all') {
        switch (language) {
            case 'english':
                return [
                    ...this.fonts.sansSerif,
                    ...this.fonts.serif,
                    ...this.fonts.display,
                    ...this.fonts.handwriting,
                    ...this.fonts.monospace
                ];
            case 'arabic':
                return [...this.fonts.arabic];
            default:
                return this.getAllFamilies();
        }
    },
    
    /**
     * Get font options grouped by category for UI dropdowns
     */
    getFontOptions() {
        const extractName = (font) => font.split(':')[0];
        const result = {};
        
        for (const category in this.fonts) {
            result[category] = {
                label: this.categoryNames[category] || category,
                fonts: this.fonts[category].map(extractName)
            };
        }
        
        return result;
    },
    
    /**
     * Get flat font list with category info
     */
    getFontList() {
        const extractName = (font) => font.split(':')[0];
        const list = [];
        
        for (const category in this.fonts) {
            for (const font of this.fonts[category]) {
                list.push({
                    name: extractName(font),
                    fullSpec: font,
                    category: category,
                    categoryLabel: this.categoryNames[category] || category,
                    isArabic: category === 'arabic'
                });
            }
        }
        
        // Remove duplicates by name
        const seen = new Set();
        return list.filter(f => {
            if (seen.has(f.name)) return false;
            seen.add(f.name);
            return true;
        });
    },
    
    /**
     * Get essential fonts for initial load
     * Now loads ALL fonts for full designer support
     */
    getEssentialFonts() {
        // Return all fonts for template designer
        return this.getAllFamilies();
    },
    
    /**
     * Load fonts using WebFontLoader
     */
    load(options = {}) {
        return new Promise((resolve, reject) => {
            if (this.isLoaded) {
                resolve(this.loadedFonts);
                return;
            }
            
            if (this.isLoading) {
                this.callbacks.push({ resolve, reject });
                return;
            }
            
            if (typeof WebFont === 'undefined') {
                console.warn('WebFontLoader not available, falling back to CSS');
                this._loadFallbackCSS(options.families).then(resolve).catch(reject);
                return;
            }
            
            this.isLoading = true;
            
            // Load essential fonts first for faster initial render
            const families = options.families || (options.essentialOnly ? this.getEssentialFonts() : this.getAllFamilies());
            const timeout = options.timeout || 8000;
            
            WebFont.load({
                google: {
                    families: families
                },
                timeout: timeout,
                active: () => {
                    this.isLoaded = true;
                    this.isLoading = false;
                    this.loadedFonts = families;
                    
                    this.callbacks.forEach(cb => cb.resolve(families));
                    this.callbacks = [];
                    
                    if (options.onActive) options.onActive(families);
                    resolve(families);
                },
                inactive: () => {
                    this.isLoading = false;
                    const error = new Error('Fonts failed to load');
                    
                    this.callbacks.forEach(cb => cb.reject(error));
                    this.callbacks = [];
                    
                    if (options.onInactive) options.onInactive(error);
                    reject(error);
                },
                loading: () => {
                    if (options.onLoading) options.onLoading();
                },
                fontloading: (familyName, fvd) => {
                    if (options.onFontLoading) options.onFontLoading(familyName, fvd);
                },
                fontactive: (familyName, fvd) => {
                    if (options.onFontActive) options.onFontActive(familyName, fvd);
                }
            });
        });
    },
    
    /**
     * Load essential fonts only (for faster initial load)
     */
    loadEssential() {
        return this.load({ families: this.getEssentialFonts(), essentialOnly: true });
    },
    
    /**
     * Load additional fonts after essential ones
     */
    loadAll() {
        return this.load({ families: this.getAllFamilies() });
    },
    
    /**
     * Load fonts by category
     */
    loadCategory(category) {
        const families = this.getFontsByCategory(category);
        return this.load({ families });
    },
    
    /**
     * Load specific fonts only
     */
    loadSpecific(fontNames) {
        const families = [];
        const allFonts = this.getAllFamilies();
        
        for (const name of fontNames) {
            const found = allFonts.find(f => f.startsWith(name + ':'));
            if (found) {
                families.push(found);
            } else {
                families.push(name + ':400,700');
            }
        }
        
        return this.load({ families });
    },
    
    /**
     * Fallback: Load fonts via CSS link tags
     */
    _loadFallbackCSS(families = null) {
        return new Promise((resolve) => {
            const fontFamilies = families || this.getEssentialFonts();
            const encodedFamilies = fontFamilies.map(f => f.replace(/ /g, '+')).join('&family=');
            const cssUrl = `https://fonts.googleapis.com/css2?family=${encodedFamilies}&display=swap`;
            
            const existing = document.querySelector(`link[href*="fonts.googleapis.com"]`);
            if (existing) {
                this.isLoaded = true;
                resolve(fontFamilies);
                return;
            }
            
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cssUrl;
            link.onload = () => {
                this.isLoaded = true;
                this.loadedFonts = fontFamilies;
                resolve(fontFamilies);
            };
            link.onerror = () => {
                this.isLoaded = true;
                resolve([]);
            };
            
            document.head.appendChild(link);
        });
    },
    
    /**
     * Check if a specific font is loaded
     */
    isFontLoaded(fontName) {
        if (!this.isLoaded) return false;
        return this.loadedFonts.some(f => f.startsWith(fontName));
    },
    
    /**
     * Get CSS font-family value for a font
     */
    getFontFamily(fontName, fallback = 'sans-serif') {
        const isArabic = this.fonts.arabic.some(f => f.startsWith(fontName));
        const defaultFallback = isArabic ? "'Segoe UI', Tahoma, sans-serif" : fallback;
        return `'${fontName}', ${defaultFallback}`;
    },
    
    /**
     * Check if a font supports Arabic
     */
    isArabicFont(fontName) {
        return this.fonts.arabic.some(f => f.startsWith(fontName));
    },
    
    /**
     * Get recommended fonts for Arabic text
     */
    getArabicFonts() {
        return this.fonts.arabic.map(f => f.split(':')[0]);
    },
    
    /**
     * Check if a font is ready (already loaded via CSS)
     * Since fonts are pre-loaded via CSS links, this just verifies availability
     */
    async loadFont(fontName) {
        // Fonts are pre-loaded via CSS links in admin-layout.php
        // Just verify the font is available using the browser API
        if (document.fonts && document.fonts.check) {
            // Check if font is already available
            if (document.fonts.check('16px "' + fontName + '"')) {
                return Promise.resolve();
            }
            
            // Wait for fonts to be ready
            try {
                await document.fonts.ready;
                return Promise.resolve();
            } catch (e) {
                console.warn('Font ready check failed:', e);
            }
        }
        
        // Fallback: return immediately since fonts should be pre-loaded
        return Promise.resolve();
    },
    
    /**
     * Preload fonts (call early in page load)
     */
    preload() {
        const preconnects = [
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com'
        ];
        
        preconnects.forEach(url => {
            if (!document.querySelector(`link[href="${url}"]`)) {
                const link = document.createElement('link');
                link.rel = 'preconnect';
                link.href = url;
                if (url.includes('gstatic')) {
                    link.crossOrigin = 'anonymous';
                }
                document.head.appendChild(link);
            }
        });
        
        // Load all fonts
        return this.loadAll();
    },
    
    /**
     * Generate Google Fonts CSS URL
     */
    getGoogleFontsUrl(families = null) {
        const fontFamilies = families || this.getAllFamilies();
        const encodedFamilies = fontFamilies.map(f => 'family=' + f.replace(/ /g, '+')).join('&');
        return `https://fonts.googleapis.com/css2?${encodedFamilies}&display=swap`;
    },
    
    /**
     * Search fonts by name
     */
    searchFonts(query) {
        if (!query) return this.getFontList();
        
        const lowerQuery = query.toLowerCase();
        return this.getFontList().filter(f => 
            f.name.toLowerCase().includes(lowerQuery) ||
            f.categoryLabel.toLowerCase().includes(lowerQuery)
        );
    },
    
    /**
     * Wait for fonts to be ready
     */
    async waitForFonts() {
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }
        return this.isLoaded;
    }
};

// Auto-preload essential fonts
if (typeof window !== 'undefined') {
    FontLoader.preload();
}

// Export for modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FontLoader;
}
