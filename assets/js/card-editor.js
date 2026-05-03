/**
 * CardEditor - Fabric.js 7.x based business card editor
 * Handles canvas rendering, text positioning, QR codes, and export
 * Compatible with Fabric.js 7.0.0+
 * 
 * IMPORTANT Fabric.js 7.x changes:
 * - originX/originY now default to 'center' (we explicitly set to 'left'/'top')
 * - Use setDimensions() instead of setWidth/setHeight
 * - Use getScenePoint/getViewportPoint instead of getPointer
 * - preserveObjectStacking defaults to true
 * - Image.fromURL returns a Promise
 */

// Arabic/Western numeral conversion utilities
const ArabicNumerals = {
    western: '0123456789',
    arabic: '٠١٢٣٤٥٦٧٨٩',
    
    /**
     * Convert Western numerals to Arabic numerals
     * @param {string} str - Input string
     * @returns {string} String with Arabic numerals
     */
    toArabic: function(str) {
        if (!str) return str;
        return str.split('').map(c => {
            const i = this.western.indexOf(c);
            return i >= 0 ? this.arabic[i] : c;
        }).join('');
    },
    
    /**
     * Convert Arabic numerals to Western numerals
     * @param {string} str - Input string
     * @returns {string} String with Western numerals
     */
    toWestern: function(str) {
        if (!str) return str;
        return str.split('').map(c => {
            const i = this.arabic.indexOf(c);
            return i >= 0 ? this.western[i] : c;
        }).join('');
    },
    
    /**
     * Check if string contains Arabic numerals
     * @param {string} str - Input string
     * @returns {boolean}
     */
    hasArabic: function(str) {
        if (!str) return false;
        return /[٠-٩]/.test(str);
    }
};

// Export for global use
if (typeof window !== 'undefined') {
    window.ArabicNumerals = ArabicNumerals;
}

// Get Fabric.js reference
function getFabric() {
    if (typeof fabric !== 'undefined' && fabric.Canvas) {
        return fabric;
    }
    if (typeof window !== 'undefined' && window.fabric && window.fabric.Canvas) {
        return window.fabric;
    }
    return null;
}

class CardEditor {
    constructor(canvasId, options = {}) {
        this.canvasId = canvasId;
        this.options = {
            width: options.width || 1050,
            height: options.height || 600,
            backgroundColor: options.backgroundColor || '#ffffff',
            onReady: options.onReady || null,
            onFieldMove: options.onFieldMove || null,
            onFieldSelect: options.onFieldSelect || null,
            ...options
        };
        
        this.canvas = null;
        this.backgroundImage = null;
        this.fields = {};
        this.qrCodeObject = null;
        this.selectedField = null;
        this.isReady = false;
        this.fabricRef = null;
        
        // Snapping settings
        this.snapEnabled = true;
        this.snapDistance = 15; // Pixels - distance to snap within
        this.alignmentLines = [];
        
        this._init();
    }
    
    async _init() {
        await this._waitForFabric();
        
        this.fabricRef = getFabric();
        if (!this.fabricRef) {
            console.error('Fabric.js not loaded!');
            return;
        }
        
        const canvasEl = document.getElementById(this.canvasId);
        if (!canvasEl) {
            console.error('Canvas element not found:', this.canvasId);
            return;
        }
        
        // Dispose existing canvas if any
        if (canvasEl.__canvas) {
            try {
                canvasEl.__canvas.dispose();
            } catch (e) {
                console.warn('Error disposing canvas:', e);
            }
        }
        
        // Create Fabric.js 7.x canvas
        try {
            this.canvas = new this.fabricRef.Canvas(this.canvasId, {
                width: this.options.width,
                height: this.options.height,
                backgroundColor: this.options.backgroundColor,
                selection: true,
                preserveObjectStacking: true, // Default in 7.x but explicit for clarity
                stopContextMenu: true,
                fireRightClick: true,
                fireMiddleClick: true
            });
            
            canvasEl.__canvas = this.canvas;
        } catch (e) {
            console.error('Error creating Fabric canvas:', e);
            return;
        }
        
        this._setupEventListeners();
        
        this.isReady = true;
        
        if (this.options.onReady) {
            this.options.onReady(this);
        }
    }
    
    async _waitForFabric(timeout = 5000) {
        const startTime = Date.now();
        while (!getFabric()) {
            if (Date.now() - startTime > timeout) {
                throw new Error('Fabric.js failed to load within timeout');
            }
            await new Promise(resolve => setTimeout(resolve, 50));
        }
    }
    
    _setupEventListeners() {
        // Object moving - apply snapping
        this.canvas.on('object:moving', (e) => {
            const obj = e.target;
            if (!obj) return;

            // Background can move freely (no snap, no bounds) so the user
            // can position artwork that intentionally overflows the card.
            if (obj.isBackground) return;

            if (this.snapEnabled) {
                this._snapToGuides(obj);
            }

            this._constrainToBounds(obj);

            if (obj.fieldKey && this.options.onFieldMove) {
                this.options.onFieldMove(obj.fieldKey, {
                    x: obj.left,
                    y: obj.top
                });
            }
        });

        // Object modified (after move/scale)
        this.canvas.on('object:modified', (e) => {
            this._clearAlignmentLines();
            const obj = e.target;
            if (!obj) return;

            if (obj.isBackground) {
                // Keep background at the bottom of the stack even after
                // drag/resize and push the change up so it can be saved.
                if (this.canvas.sendObjectToBack) {
                    this.canvas.sendObjectToBack(obj);
                }
                if (this.options.onBackgroundTransform) {
                    this.options.onBackgroundTransform(this.getBackgroundTransform());
                }
                return;
            }

            this._constrainToBounds(obj);
            if (obj.fieldKey && this.options.onFieldMove) {
                this.options.onFieldMove(obj.fieldKey, {
                    x: obj.left,
                    y: obj.top
                });
            }
        });
        
        // Clear guides on mouse up
        this.canvas.on('mouse:up', () => {
            this._clearAlignmentLines();
        });
        
        // Selection events
        this.canvas.on('selection:created', (e) => {
            const obj = e.selected?.[0];
            if (obj && obj.fieldKey) {
                this.selectedField = obj.fieldKey;
                if (this.options.onFieldSelect) {
                    this.options.onFieldSelect(obj.fieldKey);
                }
            }
        });
        
        this.canvas.on('selection:updated', (e) => {
            const obj = e.selected?.[0];
            if (obj && obj.fieldKey) {
                this.selectedField = obj.fieldKey;
                if (this.options.onFieldSelect) {
                    this.options.onFieldSelect(obj.fieldKey);
                }
            }
        });
        
        this.canvas.on('selection:cleared', () => {
            this.selectedField = null;
            if (this.options.onFieldSelect) {
                this.options.onFieldSelect(null);
            }
        });
    }
    
    /**
     * Snap object to alignment guides (canvas center, edges, other objects)
     */
    _snapToGuides(movingObj) {
        this._clearAlignmentLines();
        
        if (!movingObj) return;
        
        // Get object dimensions using Fabric.js 7.x methods
        const objWidth = movingObj.getScaledWidth ? movingObj.getScaledWidth() : (movingObj.width * (movingObj.scaleX || 1));
        const objHeight = movingObj.getScaledHeight ? movingObj.getScaledHeight() : (movingObj.height * (movingObj.scaleY || 1));
        
        // Current position (left/top origin)
        const objLeft = movingObj.left;
        const objTop = movingObj.top;
        const objRight = objLeft + objWidth;
        const objBottom = objTop + objHeight;
        const objCenterX = objLeft + objWidth / 2;
        const objCenterY = objTop + objHeight / 2;
        
        const canvasWidth = this.canvas.width;
        const canvasHeight = this.canvas.height;
        const centerX = canvasWidth / 2;
        const centerY = canvasHeight / 2;
        
        let snappedX = false;
        let snappedY = false;
        
        // 1. Snap to canvas center (highest priority)
        if (!snappedX && Math.abs(objCenterX - centerX) < this.snapDistance) {
            movingObj.set('left', centerX - objWidth / 2);
            this._drawGuide(centerX, 0, centerX, canvasHeight, '#10b981'); // Green
            snappedX = true;
        }
        if (!snappedY && Math.abs(objCenterY - centerY) < this.snapDistance) {
            movingObj.set('top', centerY - objHeight / 2);
            this._drawGuide(0, centerY, canvasWidth, centerY, '#10b981');
            snappedY = true;
        }
        
        // 2. Snap to canvas edges
        if (!snappedX) {
            if (Math.abs(objLeft) < this.snapDistance) {
                movingObj.set('left', 0);
                this._drawGuide(1, 0, 1, canvasHeight, '#f59e0b'); // Orange
                snappedX = true;
            } else if (Math.abs(objRight - canvasWidth) < this.snapDistance) {
                movingObj.set('left', canvasWidth - objWidth);
                this._drawGuide(canvasWidth - 1, 0, canvasWidth - 1, canvasHeight, '#f59e0b');
                snappedX = true;
            }
        }
        if (!snappedY) {
            if (Math.abs(objTop) < this.snapDistance) {
                movingObj.set('top', 0);
                this._drawGuide(0, 1, canvasWidth, 1, '#f59e0b');
                snappedY = true;
            } else if (Math.abs(objBottom - canvasHeight) < this.snapDistance) {
                movingObj.set('top', canvasHeight - objHeight);
                this._drawGuide(0, canvasHeight - 1, canvasWidth, canvasHeight - 1, '#f59e0b');
                snappedY = true;
            }
        }
        
        // 3. Snap to other objects
        const otherObjects = this.canvas.getObjects().filter(o => 
            o !== movingObj && 
            o.fieldKey && 
            !o._isAlignmentLine
        );
        
        for (const other of otherObjects) {
            if (snappedX && snappedY) break;
            
            const otherWidth = other.getScaledWidth ? other.getScaledWidth() : (other.width * (other.scaleX || 1));
            const otherHeight = other.getScaledHeight ? other.getScaledHeight() : (other.height * (other.scaleY || 1));
            const otherLeft = other.left;
            const otherTop = other.top;
            const otherRight = otherLeft + otherWidth;
            const otherBottom = otherTop + otherHeight;
            const otherCenterX = otherLeft + otherWidth / 2;
            const otherCenterY = otherTop + otherHeight / 2;
            
            // Recalculate moving object position for accurate comparison
            const currentLeft = movingObj.left;
            const currentTop = movingObj.top;
            const currentCenterX = currentLeft + objWidth / 2;
            const currentCenterY = currentTop + objHeight / 2;
            const currentRight = currentLeft + objWidth;
            const currentBottom = currentTop + objHeight;
            
            if (!snappedX) {
                // Centers aligned horizontally
                if (Math.abs(currentCenterX - otherCenterX) < this.snapDistance) {
                    movingObj.set('left', otherCenterX - objWidth / 2);
                    this._drawGuide(otherCenterX, 0, otherCenterX, canvasHeight, '#3b82f6'); // Blue
                    snappedX = true;
                }
                // Left edges aligned
                else if (Math.abs(currentLeft - otherLeft) < this.snapDistance) {
                    movingObj.set('left', otherLeft);
                    this._drawGuide(otherLeft, 0, otherLeft, canvasHeight, '#3b82f6');
                    snappedX = true;
                }
                // Right edges aligned
                else if (Math.abs(currentRight - otherRight) < this.snapDistance) {
                    movingObj.set('left', otherRight - objWidth);
                    this._drawGuide(otherRight, 0, otherRight, canvasHeight, '#3b82f6');
                    snappedX = true;
                }
            }
            
            if (!snappedY) {
                // Centers aligned vertically
                if (Math.abs(currentCenterY - otherCenterY) < this.snapDistance) {
                    movingObj.set('top', otherCenterY - objHeight / 2);
                    this._drawGuide(0, otherCenterY, canvasWidth, otherCenterY, '#ef4444'); // Red
                    snappedY = true;
                }
                // Top edges aligned
                else if (Math.abs(currentTop - otherTop) < this.snapDistance) {
                    movingObj.set('top', otherTop);
                    this._drawGuide(0, otherTop, canvasWidth, otherTop, '#ef4444');
                    snappedY = true;
                }
                // Bottom edges aligned
                else if (Math.abs(currentBottom - otherBottom) < this.snapDistance) {
                    movingObj.set('top', otherBottom - objHeight);
                    this._drawGuide(0, otherBottom, canvasWidth, otherBottom, '#ef4444');
                    snappedY = true;
                }
            }
        }
        
        // Render the canvas to show guide lines
        this.canvas.requestRenderAll();
    }
    
    /**
     * Draw an alignment guide line - Fabric.js 7.x
     */
    _drawGuide(x1, y1, x2, y2, color) {
        try {
            // Fabric.js 7.x - Line class
            const LineClass = this.fabricRef.Line || 
                              (typeof fabric !== 'undefined' ? fabric.Line : null);
            
            if (!LineClass) {
                console.warn('Fabric Line class not found');
                return;
            }
            
            const line = new LineClass([x1, y1, x2, y2], {
                stroke: color,
                strokeWidth: 2,
                strokeDashArray: [6, 3],
                selectable: false,
                evented: false,
                excludeFromExport: true,
                // Don't set originX/Y for Line - it uses the coords directly
                _isAlignmentLine: true,
                opacity: 1
            });
            
            this.alignmentLines.push(line);
            this.canvas.add(line);
            
            // Move to front - Fabric.js 7.x
            if (this.canvas.moveObjectTo) {
                // Fabric 7.x method
                const objects = this.canvas.getObjects();
                this.canvas.moveObjectTo(line, objects.length - 1);
            } else if (line.bringToFront) {
                line.bringToFront();
            }
            
            // Force immediate render
            this.canvas.requestRenderAll();
        } catch (e) {
            console.error('Error drawing guide:', e);
        }
    }
    
    /**
     * Clear all alignment guide lines
     */
    _clearAlignmentLines() {
        if (this.alignmentLines.length === 0) return;
        
        for (const line of this.alignmentLines) {
            this.canvas.remove(line);
        }
        this.alignmentLines = [];
    }
    
    /**
     * Constrain object to canvas bounds
     */
    _constrainToBounds(obj) {
        if (!obj) return;

        const objWidth = obj.getScaledWidth();
        const objHeight = obj.getScaledHeight();
        const cw = this.canvas.width;
        const ch = this.canvas.height;

        // Skip axis-constraint when the object is bigger than the canvas in
        // that axis: pinning to (canvas - obj) snaps it to a negative/fixed
        // position and the user can't drag it left/right any more. Better to
        // let long placeholder text (addresses, long names) overflow and stay
        // draggable. See BHD chat Apr 23 2026.
        if (objWidth < cw) {
            if (obj.left < 0) obj.set('left', 0);
            if (obj.left + objWidth > cw) obj.set('left', cw - objWidth);
        }
        if (objHeight < ch) {
            if (obj.top < 0) obj.set('top', 0);
            if (obj.top + objHeight > ch) obj.set('top', ch - objHeight);
        }
    }
    
    /**
     * Load background image - Fabric.js 7.x (fromURL returns Promise)
     */
    async loadBackground(imageUrl, transform) {
        if (!this.canvas || !imageUrl) return;

        try {
            const ImageClass = this.fabricRef.FabricImage ||
                               this.fabricRef.Image ||
                               (typeof fabric !== 'undefined' ? (fabric.FabricImage || fabric.Image) : null);

            if (!ImageClass) {
                throw new Error('Fabric Image class not found');
            }

            // SVGs: browser-rasterise at canvas size so gradients, clipPaths,
            // patterns and filters all render faithfully (Fabric's own SVG
            // parser drops a lot of those). Raster formats take the standard
            // Image.fromURL path.
            const isSvg = /\.svg(\?|$)/i.test(imageUrl);
            let img;
            if (isSvg) {
                img = await this._loadSvgAsRaster(imageUrl, ImageClass);
            } else {
                img = await ImageClass.fromURL(imageUrl, {
                    crossOrigin: 'anonymous'
                });
            }

            // Default transform: stretch artwork to exactly fill the canvas.
            // Matching aspects render pixel-perfect; mismatched aspects
            // stretch rather than crop. If a saved transform is supplied
            // (user previously moved/resized the background), restore it.
            //
            // Prefer the SVG's parsed viewBox width/height when we have one
            // (set by _loadSvgAsImage) over the Group's recomputed bbox,
            // because groupSVGElements can round/change width when children
            // sit inside the viewBox with padding.
            const naturalW = (img._svgWidth && img._svgWidth > 0) ? img._svgWidth
                : (img.width && img.width > 0 ? img.width : this.canvas.width);
            const naturalH = (img._svgHeight && img._svgHeight > 0) ? img._svgHeight
                : (img.height && img.height > 0 ? img.height : this.canvas.height);

            let t = transform;
            if (!t || typeof t !== 'object') {
                t = {
                    left: 0,
                    top: 0,
                    scaleX: this.canvas.width / naturalW,
                    scaleY: this.canvas.height / naturalH,
                    angle: 0
                };
            }

            img.set({
                left: t.left || 0,
                top: t.top || 0,
                scaleX: typeof t.scaleX === 'number' ? t.scaleX : (this.canvas.width / img.width),
                scaleY: typeof t.scaleY === 'number' ? t.scaleY : (this.canvas.height / img.height),
                angle: t.angle || 0,
                originX: 'left',
                originY: 'top',
                selectable: true,
                evented: true,
                hasControls: true,
                hasBorders: true,
                lockRotation: false,
                excludeFromExport: false
            });

            // Tag so snapping, bounds-constraint, and selection logic can
            // skip the background without special-casing by id.
            img.isBackground = true;

            if (this.backgroundImage) {
                this.canvas.remove(this.backgroundImage);
            }

            this.backgroundImage = img;
            this.canvas.add(img);
            this.canvas.sendObjectToBack(img);
            this.canvas.requestRenderAll();

            return img;
        } catch (e) {
            console.error('Background load error:', e);
            throw e;
        }
    }

    getBackgroundTransform() {
        const img = this.backgroundImage;
        if (!img) return null;
        return {
            left: img.left || 0,
            top: img.top || 0,
            scaleX: typeof img.scaleX === 'number' ? img.scaleX : 1,
            scaleY: typeof img.scaleY === 'number' ? img.scaleY : 1,
            angle: img.angle || 0
        };
    }

    async _loadSvgAsRaster(imageUrl, ImageClass) {
        // Step 1: load the raw SVG as an HTMLImageElement. We'll read its
        // intrinsic aspect from viewBox or explicit dimensions.
        const imgEl = await new Promise((resolve, reject) => {
            const el = new Image();
            el.crossOrigin = 'anonymous';
            el.onload = () => resolve(el);
            el.onerror = (e) => reject(new Error('SVG failed to load: ' + imageUrl));
            el.src = imageUrl;
        });

        // Step 2: pick a target size that matches the canvas backstore at
        // native resolution. Browsers rasterise SVG smoothly at any size.
        // If the SVG's intrinsic aspect differs from the canvas, letterbox
        // it (fit) rather than distort - the user can unlock and reposition
        // afterwards.
        const cw = this.canvas.width;
        const ch = this.canvas.height;
        const iw = imgEl.naturalWidth || imgEl.width || cw;
        const ih = imgEl.naturalHeight || imgEl.height || ch;
        const fit = Math.min(cw / iw, ch / ih);
        const drawW = Math.max(1, Math.round(iw * fit));
        const drawH = Math.max(1, Math.round(ih * fit));

        // Step 3: draw to an offscreen canvas at native canvas size with
        // the SVG centred. Fabric gets a pre-sized bitmap with no further
        // scaling needed, which also means no zoom/crop surprises.
        const offscreen = document.createElement('canvas');
        offscreen.width = cw;
        offscreen.height = ch;
        const ctx = offscreen.getContext('2d');
        ctx.clearRect(0, 0, cw, ch);
        ctx.drawImage(
            imgEl,
            Math.round((cw - drawW) / 2),
            Math.round((ch - drawH) / 2),
            drawW,
            drawH
        );

        // Step 4: construct a Fabric Image directly from the canvas
        // element. Fabric's Image constructor accepts any drawable source.
        const img = new ImageClass(offscreen);
        return img;
    }

    setBackgroundLocked(locked) {
        const img = this.backgroundImage;
        if (!img || !this.canvas) return;
        img.set({
            selectable: !locked,
            evented: !locked,
            hasControls: !locked,
            hasBorders: !locked,
            lockMovementX: !!locked,
            lockMovementY: !!locked,
            lockScalingX: !!locked,
            lockScalingY: !!locked,
            lockRotation: !!locked,
            hoverCursor: locked ? 'default' : 'move'
        });
        if (locked && this.canvas.getActiveObject() === img) {
            this.canvas.discardActiveObject();
        }
        img.setCoords();
        this.canvas.requestRenderAll();
    }

    centerBackground() {
        const img = this.backgroundImage;
        if (!img || !this.canvas) return;
        const w = img.getScaledWidth ? img.getScaledWidth() : img.width * (img.scaleX || 1);
        const h = img.getScaledHeight ? img.getScaledHeight() : img.height * (img.scaleY || 1);
        img.set({
            left: Math.round((this.canvas.width - w) / 2),
            top: Math.round((this.canvas.height - h) / 2)
        });
        img.setCoords();
        this.canvas.sendObjectToBack(img);
        this.canvas.requestRenderAll();
        if (this.options.onBackgroundTransform) {
            this.options.onBackgroundTransform(this.getBackgroundTransform());
        }
    }

    nudgeSelected(dx, dy) {
        if (!this.canvas) return;
        const active = this.canvas.getActiveObjects();
        if (!active || active.length === 0) return;
        active.forEach((obj) => {
            obj.set({
                left: (obj.left || 0) + dx,
                top: (obj.top || 0) + dy
            });
            obj.setCoords();
            if (!obj.isBackground) this._constrainToBounds(obj);
            if (obj.fieldKey && this.options.onFieldMove) {
                this.options.onFieldMove(obj.fieldKey, { x: obj.left, y: obj.top });
            }
            if (obj.isBackground && this.options.onBackgroundTransform) {
                this.options.onBackgroundTransform(this.getBackgroundTransform());
            }
        });
        this.canvas.requestRenderAll();
    }

    resetBackgroundTransform() {
        const img = this.backgroundImage;
        if (!img || !this.canvas) return;
        img.set({
            left: 0,
            top: 0,
            scaleX: this.canvas.width / img.width,
            scaleY: this.canvas.height / img.height,
            angle: 0
        });
        img.setCoords();
        this.canvas.sendObjectToBack(img);
        this.canvas.requestRenderAll();
        if (this.options.onBackgroundTransform) {
            this.options.onBackgroundTransform(this.getBackgroundTransform());
        }
    }
    
    /**
     * Add a text field - Fabric.js 7.x IText
     */
    addTextField(key, options = {}) {
        if (!this.canvas) return null;
        
        // Remove existing field with same key
        this.removeField(key);
        
        // Determine text alignment and corresponding origin
        const textAlign = options.textAlign || 'left';
        let originX = 'left';
        if (textAlign === 'center') {
            originX = 'center';
        } else if (textAlign === 'right') {
            originX = 'right';
        }
        
        // Use explicit originX from options if provided (for backward compatibility)
        if (options.originX) {
            originX = options.originX;
        }
        
        const fontFamily = options.fontFamily || 'Inter';
        
        const fieldOptions = {
            left: options.x || 50,
            top: options.y || 50,
            fontSize: options.fontSize || 16,
            fontFamily: fontFamily,
            fontWeight: options.fontWeight || 'normal',
            fontStyle: options.fontStyle || 'normal',
            fill: options.fill || options.color || '#333333',
            // Text alignment
            textAlign: textAlign,
            // Fabric.js 7.x: set origin based on text alignment
            originX: originX,
            originY: options.originY || 'top',
            // Interactive properties
            selectable: true,
            editable: false,
            hasControls: true,
            hasBorders: true,
            lockScalingX: true,
            lockScalingY: true,
            lockRotation: true
        };
        
        // Arabic / Hebrew etc. text needs RTL bidi + Canvas-native shaping.
        // Fabric 7.1.0's text engine (both IText AND Text) splits the
        // string per-character before rendering for cursor / per-char
        // styling support. That destroys Arabic contextual shaping —
        // every glyph renders in its isolated form (e.g. "علي" -> "ع ل ي"
        // disconnected). Setting direction='rtl' on the Fabric object
        // does not fix this; the per-character split happens before the
        // direction takes effect. Verified directly in browser console
        // 3 May 2026: only RAW canvas2D ctx.fillText() with direction='rtl'
        // produces correctly shaped Arabic; every Fabric text variant
        // (IText, Text, FabricText, Textbox) breaks the joining.
        //
        // Workaround: pre-render the RTL string to an offscreen canvas
        // with raw ctx.fillText(), then wrap it as a fabric.Image so we
        // get Fabric's positioning / hit-testing without its text engine.
        const _rtlRe = new RegExp('[\\u0590-\\u08FF\\u0750-\\u077F\\uFB50-\\uFDFF\\uFE70-\\uFEFF]');
        const isRtl = _rtlRe.test(String(options.text || ''));
        if (isRtl) {
            const rtlObj = this._buildRtlTextImage(options, fieldOptions, textAlign);
            if (rtlObj) {
                rtlObj.fieldKey = key;
                rtlObj.fieldType = 'text';
                rtlObj.textAlignValue = textAlign;
                this.fields[key] = rtlObj;
                this.canvas.add(rtlObj);
                this.canvas.requestRenderAll();
                // Schedule a re-render after fonts settle, in case the
                // initial measure used a fallback face.
                const canvas = this.canvas;
                const self = this;
                setTimeout(() => {
                    const rebuilt = self._buildRtlTextImage(options, fieldOptions, textAlign);
                    if (rebuilt) {
                        rebuilt.fieldKey = key;
                        rebuilt.fieldType = 'text';
                        rebuilt.textAlignValue = textAlign;
                        self.canvas.remove(self.fields[key]);
                        self.fields[key] = rebuilt;
                        self.canvas.add(rebuilt);
                        self.canvas.requestRenderAll();
                    }
                }, 80);
                return rtlObj;
            }
            // Fall through to Fabric Text if image building failed.
        }
        // Auto-shrink Latin text when it would overflow field.width. The
        // PDF parser bbox was sized for the source design's literal text;
        // a longer dynamic name (e.g. "Ali Adnan Haider Darwish" replacing
        // "Ali Al-Zaabi") would otherwise spill into adjacent elements
        // (logo, gold accent line). Step down 0.5pt at a time until it
        // fits or hits the per-field floor (default 70% of original).
        // Honours options.autoShrink === false to disable the behaviour
        // and options.shrinkFloorPct (40-100) to customize the floor.
        const autoShrinkEnabled = options.autoShrink !== false;
        if (autoShrinkEnabled && options.width && options.text) {
            const fieldWidth = Number(options.width);
            const originalSize = Number(fieldOptions.fontSize || 16);
            const floorPct = Math.max(40, Math.min(100, Number(options.shrinkFloorPct || 70)));
            const minSize = Math.max(6, originalSize * floorPct / 100);
            const measC = document.createElement('canvas');
            const mc = measC.getContext('2d');
            const buildSpec = (fs) => `${fieldOptions.fontStyle || 'normal'} ${fieldOptions.fontWeight || 'normal'} ${fs}px "${fieldOptions.fontFamily}", sans-serif`;
            let trial = originalSize;
            let attempts = 0;
            while (attempts < 80 && trial > minSize) {
                mc.font = buildSpec(trial);
                if (mc.measureText(String(options.text)).width <= fieldWidth) break;
                trial -= 0.5;
                attempts++;
            }
            if (trial < originalSize) {
                fieldOptions.fontSize = trial;
            }
        }
        const TextCtor = this.fabricRef.IText;
        const textObj = new TextCtor(options.text || key, fieldOptions);
        textObj.fieldKey = key;
        textObj.fieldType = 'text';
        textObj.textAlignValue = textAlign; // Store for later retrieval

        this.fields[key] = textObj;
        this.canvas.add(textObj);
        this.canvas.requestRenderAll();

        // Schedule a re-render to ensure font is applied after it loads
        const canvas = this.canvas;
        setTimeout(() => {
            textObj.set('dirty', true);
            textObj.setCoords();
            canvas.requestRenderAll();
        }, 50);

        return textObj;
    }

    /**
     * Pre-render RTL text to an offscreen canvas via raw ctx.fillText
     * (the only path that preserves Arabic contextual shaping in
     * Fabric 7.1.0), then return it wrapped as a fabric.Image positioned
     * to match the original field bbox. The image right edge anchors at
     * options.x + options.width when width is provided, otherwise at
     * options.x (the same anchor as a left-aligned Latin field).
     *
     * Returns null if Image class not available or rendering fails.
     */
    _buildRtlTextImage(options, fieldOptions, textAlign) {
        const ImageClass = this.fabricRef.FabricImage ||
                           this.fabricRef.Image ||
                           (typeof fabric !== 'undefined' ? (fabric.FabricImage || fabric.Image) : null);
        if (!ImageClass) return null;

        const text = String(options.text || '');
        if (!text) return null;

        let fontSize = Number(fieldOptions.fontSize || 16);
        const fontFamily = String(fieldOptions.fontFamily || 'Inter');
        const fontWeight = fieldOptions.fontWeight || 'normal';
        const fontStyle = fieldOptions.fontStyle || 'normal';
        const fill = String(fieldOptions.fill || '#333333');

        // Render at 2x DPR so card-export multiplier=4 doesn't softens
        // the glyphs back to today's blurry baseline.
        const dpr = 2;
        const buildFontSpec = (fs) => `${fontStyle} ${fontWeight} ${fs * dpr}px "${fontFamily}", "Cairo", "Tajawal", serif`;

        // Measure once + auto-shrink: when the dynamic text is wider than
        // the parser's bbox (longer name than the source design held), step
        // the font down 0.5pt at a time until it fits within field.width.
        // Floor at shrinkFloorPct (default 70%) of the original size.
        // Honours options.autoShrink === false (HR may want fixed sizing).
        const autoShrinkEnabled = options.autoShrink !== false;
        const fieldWidth = Number(options.width || 0);
        const floorPct = Math.max(40, Math.min(100, Number(options.shrinkFloorPct || 70)));
        const measureC = document.createElement('canvas');
        const mctx = measureC.getContext('2d');
        let measure;
        let attempts = 0;
        const minSize = Math.max(6, fontSize * floorPct / 100);
        while (true) {
            mctx.font = buildFontSpec(fontSize);
            mctx.direction = 'rtl';
            measure = mctx.measureText(text);
            const renderedLogicalW = measure.width / dpr;
            if (!autoShrinkEnabled || fieldWidth <= 0 || renderedLogicalW <= fieldWidth || fontSize <= minSize || attempts >= 80) break;
            fontSize -= 0.5;
            attempts++;
        }
        const fontSpec = buildFontSpec(fontSize);

        const padX = Math.ceil(fontSize * dpr * 0.15);
        const padY = Math.ceil(fontSize * dpr * 0.30);
        const ascent = measure.actualBoundingBoxAscent || (fontSize * dpr * 0.85);
        const descent = measure.actualBoundingBoxDescent || (fontSize * dpr * 0.30);
        const W = Math.max(8, Math.ceil(measure.width) + padX * 2);
        const H = Math.max(8, Math.ceil(ascent + descent) + padY * 2);

        const c = document.createElement('canvas');
        c.width = W;
        c.height = H;
        const ctx = c.getContext('2d');
        ctx.font = fontSpec;
        ctx.direction = 'rtl';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = fill;
        // Anchor right edge of glyph run at canvas right - padX.
        ctx.textAlign = 'right';
        ctx.fillText(text, W - padX, padY + ascent);

        // Place image into Fabric. Scale back to logical size (1x), so
        // a fontSize=67 input renders at 67px tall on the editor canvas.
        const x = Number(options.x || 0);
        const y = Number(options.y || 0);
        const width = Number(options.width || 0);
        const scale = 1 / dpr;
        const imgW = W * scale;
        const imgH = H * scale;

        // For RTL, anchor the image RIGHT edge at (x + width) when width
        // was provided (so the visually-rightmost glyph sits where the
        // PDF parser detected the right edge of the original text). When
        // width is missing, fall back to anchoring left at x.
        const left = width > 0 ? (x + width) - (W - padX) * scale : x - padX * scale;
        const top = y - padY * scale;

        const imgEl = new Image();
        const dataUrl = c.toDataURL('image/png');
        imgEl.src = dataUrl;
        // Construct via fabric.FabricImage (Fabric 7.x) directly with the
        // canvas element, since fromURL is async + we want a sync object.
        const img = new ImageClass(c, {
            left: left,
            top: top,
            originX: 'left',
            originY: 'top',
            scaleX: scale,
            scaleY: scale,
            selectable: !!fieldOptions.selectable,
            hasControls: !!fieldOptions.hasControls,
            hasBorders: !!fieldOptions.hasBorders,
            lockRotation: true,
            lockScalingX: true,
            lockScalingY: true,
        });
        img._isRtlText = true;
        img._rtlText = text;
        return img;
    }
    
    /**
     * Set text alignment for a field
     */
    setFieldAlignment(key, alignment) {
        const field = this.fields[key];
        if (!field || field.fieldType !== 'text') return;
        
        // Determine new originX based on alignment
        let originX = 'left';
        if (alignment === 'center') {
            originX = 'center';
        } else if (alignment === 'right') {
            originX = 'right';
        }
        
        // Get current position and dimensions
        const currentLeft = field.left;
        const currentWidth = field.getScaledWidth ? field.getScaledWidth() : field.width;
        
        // Calculate new position based on alignment change
        let newLeft = currentLeft;
        const oldOriginX = field.originX;
        
        // Adjust position when changing alignment
        if (oldOriginX !== originX) {
            if (oldOriginX === 'left' && originX === 'center') {
                newLeft = currentLeft + currentWidth / 2;
            } else if (oldOriginX === 'left' && originX === 'right') {
                newLeft = currentLeft + currentWidth;
            } else if (oldOriginX === 'center' && originX === 'left') {
                newLeft = currentLeft - currentWidth / 2;
            } else if (oldOriginX === 'center' && originX === 'right') {
                newLeft = currentLeft + currentWidth / 2;
            } else if (oldOriginX === 'right' && originX === 'left') {
                newLeft = currentLeft - currentWidth;
            } else if (oldOriginX === 'right' && originX === 'center') {
                newLeft = currentLeft - currentWidth / 2;
            }
        }
        
        field.set({
            textAlign: alignment,
            originX: originX,
            left: newLeft
        });
        field.textAlignValue = alignment;
        
        this.canvas.requestRenderAll();
        return field;
    }
    
    /**
     * Add QR code
     */
    async addQRCode(data, options = {}) {
        if (!this.canvas) return null;

        // Remove existing QR
        this.removeField('qr_code');

        const size = options.size || 100;

        try {
            // Generate QR code using QRCode library, applying any style
            // sampled from the source PDF (foreground colour, background,
            // border colour + width, corner radius).
            const qrDataUrl = await this._generateQRCode(data, size, options.style || null);
            
            // Fabric.js 7.x: Image class
            const ImageClass = this.fabricRef.FabricImage || 
                               this.fabricRef.Image || 
                               (typeof fabric !== 'undefined' ? (fabric.FabricImage || fabric.Image) : null);
            
            const img = await ImageClass.fromURL(qrDataUrl);
            
            img.set({
                left: options.x || 100,
                top: options.y || 100,
                // Fabric.js 7.x: explicit origin
                originX: 'left',
                originY: 'top',
                scaleX: size / img.width,
                scaleY: size / img.height,
                selectable: true,
                hasControls: true,
                hasBorders: true,
                lockRotation: true
            });
            
            img.fieldKey = 'qr_code';
            img.fieldType = 'qr';
            
            this.qrCodeObject = img;
            this.fields['qr_code'] = img;
            this.canvas.add(img);
            this.canvas.requestRenderAll();
            
            return img;
        } catch (e) {
            console.error('QR code error:', e);
            return null;
        }
    }
    
    async _generateQRCode(data, size, style) {
        return new Promise((resolve, reject) => {
            // Check for qrcode-generator library (global function named 'qrcode')
            if (typeof qrcode === 'undefined') {
                reject(new Error('QRCode library not loaded'));
                return;
            }

            try {
                // Use qrcode-generator library
                // Type 0 = auto-detect, 'M' = medium error correction
                const qr = qrcode(0, 'M');
                qr.addData(data);
                qr.make();

                // Resolve style with sensible defaults so a missing/partial
                // qr_style still renders a valid black-on-white code.
                //
                // mode classifier (set by parse_card_pdf.py sample_qr_style):
                //   'real_qr_styled'    -> paint sampled fg/bg (+ eye + border)
                //   'real_qr_plain'     -> paint sampled fg/bg (likely black/white)
                //   'empty_placeholder' -> sampled bg is the placeholder colour;
                //                          auto-pick a contrasting fg by luminance
                //                          so the dynamic QR sits cleanly inside
                //                          the box even when the template designer
                //                          left only an empty square.
                //   missing             -> classic black-on-white (legacy default)
                const s = style || {};
                const mode = s.mode || null;
                const _hexToRgb = (hex) => {
                    const m = /^#?([a-f0-9]{6})$/i.exec(String(hex || ''));
                    if (!m) return null;
                    const n = parseInt(m[1], 16);
                    return [(n >> 16) & 0xff, (n >> 8) & 0xff, n & 0xff];
                };
                const _lum = (rgb) => rgb ? (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) : 128;
                const _contrastFg = (bg) => {
                    const rgb = _hexToRgb(bg);
                    return _lum(rgb) > 140 ? '#111111' : '#f5f5f5';
                };

                let moduleColor = s.color || '#000000';
                const bgColor = s.bg_color || '#ffffff';
                if (mode === 'empty_placeholder') {
                    // Sampled "color" is just the luminance pick from Python;
                    // recompute on the JS side using the canonical bg so the
                    // contrast holds even if downstream code overrode bg_color.
                    moduleColor = _contrastFg(bgColor);
                }
                const eyeColor = s.eye_color || null;
                const hasBorder = !!s.has_border && !!s.border_color;
                const borderColor = hasBorder ? s.border_color : null;
                // border_width_px is in source-bg pixels (sampled at 1200 DPI).
                // Scale to the target size relative to the QR area; cap at
                // 8% of the QR side so it never eats the modules.
                const borderRatio = hasBorder
                    ? Math.min(0.08, Math.max(0.015, (s.border_width_px || 4) / Math.max(80, (s.qr_px_width || 600))))
                    : 0;
                const cornerRadiusPct = Math.max(0, Math.min(40, s.border_radius_pct || 0));

                // Panel padding: when the source design wraps the QR in a
                // rounded container of the same colour as bg_color, the
                // detector emits panel_padding_px (in 1200-DPI source-bg
                // pixels). The backfill grew the qr_code field by 2x that
                // padding so the canvas now covers the full panel; we
                // inset the modules by the same fraction so the QR sits
                // centred inside the panel with quiet zone all around.
                // qr_px_width is the ORIGINAL QR module width, so the
                // panel takes up panel_padding_px / (qr_px_width + 2*pad)
                // of the canvas on each side.
                const panelPaddingSrc = Math.max(0, s.panel_padding_px || 0);
                const qrPxOrig = Math.max(80, s.qr_px_width || 600);
                const panelRatio = panelPaddingSrc > 0
                    ? panelPaddingSrc / (qrPxOrig + 2 * panelPaddingSrc)
                    : 0;
                const panelRadiusPct = Math.max(0, Math.min(40, s.panel_radius_pct || 0));

                // Create canvas and draw QR code. The OUTER canvas is `size`
                // (matches what the editor expects). The QR modules paint
                // inside a smaller inset to leave room for the border.
                const canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                const ctx = canvas.getContext('2d');

                // Outer fill (border colour, or bg if no border so a single
                // flood paints the whole area cleanly).
                if (hasBorder) {
                    ctx.fillStyle = borderColor;
                    if (cornerRadiusPct > 0 && typeof ctx.roundRect === 'function') {
                        ctx.beginPath();
                        ctx.roundRect(0, 0, size, size, size * cornerRadiusPct / 100);
                        ctx.fill();
                    } else {
                        ctx.fillRect(0, 0, size, size);
                    }
                }
                // Inset rect for the QR + outer panel.
                const borderPx = hasBorder ? Math.round(size * borderRatio) : 0;
                const panelPx = Math.round(size * panelRatio);
                const innerSize = size - borderPx * 2;
                const moduleCount = qr.getModuleCount();
                const moduleAreaSize = innerSize - panelPx * 2;
                const cellSize = moduleAreaSize / moduleCount;

                // Panel: rounded rectangle of bg colour filling the area
                // between the (optional) outer border and the QR modules.
                // When panelRatio is 0, this fills the whole inner area
                // edge-to-edge (current behaviour).
                ctx.fillStyle = bgColor;
                if (panelRadiusPct > 0 && typeof ctx.roundRect === 'function') {
                    ctx.beginPath();
                    ctx.roundRect(borderPx, borderPx, innerSize, innerSize, innerSize * panelRadiusPct / 100);
                    ctx.fill();
                } else {
                    ctx.fillRect(borderPx, borderPx, innerSize, innerSize);
                }

                // Modules. Finder patterns are the 7x7 blocks in the top-left,
                // top-right, and bottom-left corners; they get the sampled
                // eye_color when present so brand-styled QRs that use a
                // distinct accent on the eyes stay faithful.
                const _inEye = (row, col) => {
                    if (!eyeColor) return false;
                    if (row < 7 && col < 7) return true;                                    // top-left
                    if (row < 7 && col >= moduleCount - 7) return true;                     // top-right
                    if (row >= moduleCount - 7 && col < 7) return true;                     // bottom-left
                    return false;
                };
                const moduleOriginX = borderPx + panelPx;
                const moduleOriginY = borderPx + panelPx;
                for (let row = 0; row < moduleCount; row++) {
                    for (let col = 0; col < moduleCount; col++) {
                        if (qr.isDark(row, col)) {
                            ctx.fillStyle = _inEye(row, col) ? eyeColor : moduleColor;
                            ctx.fillRect(
                                moduleOriginX + col * cellSize,
                                moduleOriginY + row * cellSize,
                                cellSize,
                                cellSize
                            );
                        }
                    }
                }

                resolve(canvas.toDataURL('image/png'));
            } catch (e) {
                reject(e);
            }
        });
    }
    
    /**
     * Update a text field's properties
     */
    updateField(key, properties) {
        const field = this.fields[key];
        if (!field) return;
        
        field.set(properties);

        // Any property that changes glyph metrics (font, weight, style,
        // size) needs setCoords + a dirty flag, otherwise the bounding
        // box and selection handles stay stuck on the old geometry.
        if (properties.fontFamily || properties.fontWeight ||
            properties.fontStyle || properties.fontSize) {
            field.setCoords();
            field.set('dirty', true);
        }

        this.canvas.requestRenderAll();
    }
    
    /**
     * Update QR code
     */
    updateQRCode(options) {
        if (!this.qrCodeObject) return;
        
        if (options.size) {
            const scale = options.size / (this.qrCodeObject.width || 100);
            this.qrCodeObject.set({
                scaleX: scale,
                scaleY: scale
            });
        }
        
        if (options.x !== undefined) this.qrCodeObject.set('left', options.x);
        if (options.y !== undefined) this.qrCodeObject.set('top', options.y);
        
        this.canvas.requestRenderAll();
    }
    
    /**
     * Remove a field from canvas
     */
    removeField(key) {
        const field = this.fields[key];
        if (field) {
            this.canvas.remove(field);
            delete this.fields[key];
            if (key === 'qr_code') {
                this.qrCodeObject = null;
            }
            this.canvas.requestRenderAll();
        }
    }
    
    /**
     * Get field position
     */
    getFieldPosition(key) {
        const field = this.fields[key];
        if (!field) return null;
        
        const result = {
            x: field.left,
            y: field.top
        };
        
        if (field.fieldType === 'qr') {
            result.size = field.getScaledWidth();
        } else {
            result.fontSize = field.fontSize;
        }
        
        return result;
    }
    
    /**
     * Clear all objects from canvas
     */
    clear() {
        if (!this.canvas) return;
        
        this.canvas.clear();
        this.canvas.backgroundColor = this.options.backgroundColor;
        this.backgroundImage = null;
        this.fields = {};
        this.qrCodeObject = null;
        this.alignmentLines = [];
        this.canvas.requestRenderAll();
    }
    
    /**
     * Export as PNG
     * @param {number} multiplier - Resolution multiplier (3 = 3150x1800px, ~300 DPI for business cards)
     */
    exportPNG(multiplier = 3) {
        if (!this.canvas) return null;
        
        // Deselect all before export
        this.canvas.discardActiveObject();
        this._clearAlignmentLines();
        this.canvas.requestRenderAll();
        
        return this.canvas.toDataURL({
            format: 'png',
            multiplier: multiplier,
            quality: 1
        });
    }
    
    /**
     * Export as PNG Blob (for batch generation)
     * @param {number} multiplier - Resolution multiplier (3 = 3150x1800px, ~300 DPI)
     */
    async exportPNGBlob(multiplier = 3) {
        const dataUrl = this.exportPNG(multiplier);
        if (!dataUrl) return null;
        
        // Convert data URL to blob
        const response = await fetch(dataUrl);
        return await response.blob();
    }
    
    /**
     * Export as PDF using jsPDF (rasterized version)
     * Uses 6x multiplier for high-quality print output (~600 DPI)
     */
    exportPDF(filename = 'card.pdf') {
        if (!this.canvas || typeof jspdf === 'undefined') {
            console.error('jsPDF not loaded');
            return;
        }
        
        this.canvas.discardActiveObject();
        this._clearAlignmentLines();
        this.canvas.requestRenderAll();
        
        // 6x multiplier for ~600 DPI print quality
        const dataUrl = this.canvas.toDataURL({
            format: 'png',
            multiplier: 6,
            quality: 1
        });
        
        // Calculate dimensions in mm (business card size)
        const widthMm = (this.canvas.width / 300) * 25.4;
        const heightMm = (this.canvas.height / 300) * 25.4;
        
        const { jsPDF } = jspdf;
        const pdf = new jsPDF({
            orientation: widthMm > heightMm ? 'landscape' : 'portrait',
            unit: 'mm',
            format: [widthMm, heightMm]
        });
        
        pdf.addImage(dataUrl, 'PNG', 0, 0, widthMm, heightMm);
        pdf.save(filename);
    }
    
    /**
     * Export as Vector PDF using PDF-lib (preserves original PDF background quality)
     * @param {string} originalPdfUrl - URL to the original PDF background
     * @param {string} filename - Output filename
     */
    async exportVectorPDF(originalPdfUrl, filename = 'card.pdf') {
        if (!this.canvas) {
            console.error('Canvas not initialized');
            return null;
        }
        
        // Check if PDF-lib is loaded
        if (typeof PDFLib === 'undefined') {
            console.warn('PDF-lib not loaded, falling back to rasterized PDF');
            return this.exportPDF(filename);
        }
        
        try {
            const { PDFDocument, rgb, StandardFonts } = PDFLib;
            
            let pdfDoc;
            
            // Load original PDF as background if provided
            if (originalPdfUrl) {
                const existingPdfBytes = await fetch(originalPdfUrl).then(res => res.arrayBuffer());
                pdfDoc = await PDFDocument.load(existingPdfBytes);
            } else {
                // Create new PDF if no background
                pdfDoc = await PDFDocument.create();
                const page = pdfDoc.addPage([this.canvas.width * 0.24, this.canvas.height * 0.24]); // Convert px to points (72 dpi)
            }
            
            const pages = pdfDoc.getPages();
            const page = pages[0];
            const { width, height } = page.getSize();
            
            // Embed standard fonts
            const helvetica = await pdfDoc.embedFont(StandardFonts.Helvetica);
            const helveticaBold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
            
            // Get scale factors (canvas to PDF points)
            const scaleX = width / this.canvas.width;
            const scaleY = height / this.canvas.height;
            
            // Add text fields from canvas
            const objects = this.canvas.getObjects();
            for (const obj of objects) {
                if (obj.type === 'textbox' || obj.type === 'text' || obj.type === 'i-text') {
                    const text = obj.text || '';
                    if (!text.trim()) continue;
                    
                    // Convert position (Fabric.js origin is top-left, PDF is bottom-left)
                    const x = obj.left * scaleX;
                    const y = height - (obj.top * scaleY) - (obj.fontSize * scaleY);
                    
                    // Parse color
                    let color = rgb(0, 0, 0);
                    if (obj.fill) {
                        const hex = obj.fill.replace('#', '');
                        if (hex.length === 6) {
                            color = rgb(
                                parseInt(hex.substr(0, 2), 16) / 255,
                                parseInt(hex.substr(2, 2), 16) / 255,
                                parseInt(hex.substr(4, 2), 16) / 255
                            );
                        }
                    }
                    
                    // Choose font
                    const font = (obj.fontWeight === 'bold' || obj.fontWeight >= 600) ? helveticaBold : helvetica;
                    
                    page.drawText(text, {
                        x: x,
                        y: y,
                        size: obj.fontSize * scaleY * 0.75, // Adjust for point size
                        font: font,
                        color: color
                    });
                }
            }
            
            // Add QR code as image if present
            for (const obj of objects) {
                if (obj.customType === 'qrcode' && obj._element) {
                    try {
                        const qrDataUrl = obj._element.src || obj.toDataURL();
                        const qrImageBytes = await fetch(qrDataUrl).then(res => res.arrayBuffer());
                        const qrImage = await pdfDoc.embedPng(qrImageBytes);
                        
                        const qrX = obj.left * scaleX;
                        const qrY = height - (obj.top * scaleY) - (obj.height * obj.scaleY * scaleY);
                        const qrWidth = obj.width * obj.scaleX * scaleX;
                        const qrHeight = obj.height * obj.scaleY * scaleY;
                        
                        page.drawImage(qrImage, {
                            x: qrX,
                            y: qrY,
                            width: qrWidth,
                            height: qrHeight
                        });
                    } catch (e) {
                        console.warn('Could not embed QR code:', e);
                    }
                }
            }
            
            // Save PDF
            const pdfBytes = await pdfDoc.save();
            
            // Download
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            link.click();
            URL.revokeObjectURL(link.href);
            
            return pdfBytes;
        } catch (error) {
            console.error('Vector PDF export error:', error);
            // Fallback to rasterized
            return this.exportPDF(filename);
        }
    }
    
    /**
     * Export text/fields as transparent PNG (without background)
     * Used for overlaying on PDF background
     */
    async exportTextOverlayPNG(multiplier = 3) {
        if (!this.canvas) return null;
        
        // Temporarily hide background
        const bgImage = this.canvas.backgroundImage;
        const bgColor = this.canvas.backgroundColor;
        this.canvas.backgroundImage = null;
        this.canvas.backgroundColor = 'transparent';
        this.canvas.requestRenderAll();
        
        // Export with transparency
        const dataUrl = this.canvas.toDataURL({
            format: 'png',
            multiplier: multiplier,
            quality: 1
        });
        
        // Restore background
        this.canvas.backgroundImage = bgImage;
        this.canvas.backgroundColor = bgColor;
        this.canvas.requestRenderAll();
        
        return dataUrl;
    }
    
    /**
     * Export as hybrid PDF: vector PDF background + high-quality raster text overlay
     * Best of both worlds: vector quality background, full language support for text
     * Uses 6x multiplier for print-quality output (~600 DPI equivalent)
     */
    async exportHybridPDFBlob(originalPdfUrl) {
        if (!this.canvas || typeof PDFLib === 'undefined') {
            return null;
        }
        
        try {
            const { PDFDocument } = PDFLib;
            
            let pdfDoc;
            
            // Load original PDF as background
            if (originalPdfUrl) {
                const existingPdfBytes = await fetch(originalPdfUrl).then(res => res.arrayBuffer());
                pdfDoc = await PDFDocument.load(existingPdfBytes);
            } else {
                // No PDF background - fall back to full PNG export
                return null;
            }
            
            const pages = pdfDoc.getPages();
            const page = pages[0];
            const { width, height } = page.getSize();
            
            // Export text overlay as transparent PNG at HIGH quality
            // 6x multiplier = ~600 DPI for crisp print quality
            const overlayDataUrl = await this.exportTextOverlayPNG(6);
            if (overlayDataUrl) {
                // Convert data URL to bytes
                const overlayBytes = await fetch(overlayDataUrl).then(res => res.arrayBuffer());
                
                // Embed the overlay image
                const overlayImage = await pdfDoc.embedPng(overlayBytes);
                
                // Draw overlay on top of the PDF page (full page coverage)
                page.drawImage(overlayImage, {
                    x: 0,
                    y: 0,
                    width: width,
                    height: height
                });
            }
            
            const pdfBytes = await pdfDoc.save();
            return new Blob([pdfBytes], { type: 'application/pdf' });
        } catch (error) {
            console.error('Hybrid PDF export error:', error);
            return null;
        }
    }
    
    /**
     * Resize canvas - Fabric.js 7.x uses setDimensions
     */
    setDimensions(width, height, displayWidth, displayHeight) {
        if (!this.canvas) return;

        this.options.width = width;
        this.options.height = height;

        // Fabric resets both the backstore (internal resolution, which we
        // keep at 300 DPI for export) and the CSS size together. That was
        // making the canvas CSS width ~1087px while its wrapper is only
        // 480px wide with overflow-hidden, visually cropping the right
        // third of every uploaded design. Set them independently so the
        // canvas renders full-resolution internally but displays fitted.
        this.canvas.setDimensions({ width: width, height: height }, { backstoreOnly: true });

        if (typeof displayWidth === 'number' && typeof displayHeight === 'number') {
            this.canvas.setDimensions(
                { width: displayWidth + 'px', height: displayHeight + 'px' },
                { cssOnly: true }
            );
        }

        this.canvas.requestRenderAll();
    }
    
    /**
     * Get all field objects
     */
    getFields() {
        return this.fields;
    }
    
    /**
     * Enable/disable snapping
     */
    setSnapping(enabled) {
        this.snapEnabled = enabled;
    }
    
    /**
     * Set snap distance
     */
    setSnapDistance(distance) {
        this.snapDistance = distance;
    }
    
    /**
     * Dispose canvas and cleanup
     */
    dispose() {
        if (this.canvas) {
            this.canvas.dispose();
            this.canvas = null;
        }
        this.fields = {};
        this.qrCodeObject = null;
        this.backgroundImage = null;
        this.alignmentLines = [];
        this.isReady = false;
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CardEditor;
} else if (typeof window !== 'undefined') {
    window.CardEditor = CardEditor;
}
