import * as pdfjsLib from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4/build/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdn.jsdelivr.net/npm/pdfjs-dist@4/build/pdf.worker.min.mjs';

async function renderPdfSpread(pdf, pageNum, scale) {
    const page     = await pdf.getPage(pageNum);
    const viewport = page.getViewport({ scale });
    const canvas   = document.createElement('canvas');
    canvas.width   = viewport.width;
    canvas.height  = viewport.height;
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    const halfW = Math.floor(viewport.width / 2);
    return [0, 1].map(i => {
        const half = document.createElement('canvas');
        half.width  = halfW;
        half.height = viewport.height;
        half.getContext('2d').drawImage(canvas, i * halfW, 0, halfW, viewport.height, 0, 0, halfW, viewport.height);
        const div = document.createElement('div');
        div.className = 'pf-page pf-page--pdf';
        const img = document.createElement('img');
        img.src = half.toDataURL('image/jpeg', 0.85);
        img.alt = `Page ${pageNum} ${i === 0 ? 'gauche' : 'droite'}`;
        div.appendChild(img);
        return div;
    });
}

async function renderPdfPage(pdf, pageNum, scale) {
    const page     = await pdf.getPage(pageNum);
    const viewport = page.getViewport({ scale });
    const canvas   = document.createElement('canvas');
    canvas.width   = viewport.width;
    canvas.height  = viewport.height;
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    const div = document.createElement('div');
    div.className = 'pf-page pf-page--pdf';
    const img = document.createElement('img');
    img.src = canvas.toDataURL('image/jpeg', 0.85);
    img.alt = `Page ${pageNum}`;
    div.appendChild(img);
    return div;
}

async function initFlipbook(container) {
    const coverEl = container.querySelector('.pf-page--cover');
    if (!coverEl) return;

    const width  = parseInt(container.dataset.width,  10) || 400;
    const height = parseInt(container.dataset.height, 10) || 570;
    const pdfUrl = container.dataset.pdf || '';

    let pdf = null;
    if (pdfUrl) {
        try {
            pdf = await pdfjsLib.getDocument(pdfUrl).promise;
        } catch (err) {
            console.error('[passiflore-pageflip] PDF load error', err);
        }
    }
    const pdfNumPages = pdf ? pdf.numPages : 1;
    const hasCover4   = pdfNumPages >= 2;
    const innerPdfEnd = hasCover4 ? pdfNumPages - 1 : pdfNumPages;

    const pageTypes = [];
    if (pdf && innerPdfEnd >= 2) {
        const coverRatio = width / height;
        for (let i = 2; i <= innerPdfEnd; i++) {
            const vp = (await pdf.getPage(i)).getViewport({ scale: 1 });
            pageTypes.push((vp.width / vp.height) / coverRatio > 1.5);
        }
    }
    const innerFlipCount = pageTypes.reduce((acc, s) => acc + (s ? 2 : 1), 0);
    const needsPad       = hasCover4 && innerFlipCount % 2 === 1;
    const flipPageCount  = 1 + innerFlipCount + (needsPad ? 1 : 0) + (hasCover4 ? 1 : 0);
    const lastIndex      = flipPageCount - 1;

    // ── Overlay elements ──────────────────────────────────────────
    const overlay      = document.querySelector('.bs-flipbook-overlay');
    const closeBtn     = overlay?.querySelector('.bs-flipbook-close');
    const scaleWrapper = overlay?.querySelector('.bs-flipbook-scale-wrapper');
    const innerEl      = overlay?.querySelector('.bs-flipbook-inner');
    const bsHero       = document.querySelector('.bs-hero');

    const viewport      = overlay?.querySelector('.bs-flipbook-viewport');
    const btnSinglePage = overlay?.querySelector('[data-action="single-page"]');
    const btnFirstPage  = overlay?.querySelector('[data-action="first-page"]');
    const btnPrevPage   = overlay?.querySelector('[data-action="prev-page"]');
    const btnZoomOut    = overlay?.querySelector('[data-action="zoom-out"]');
    const btnZoomIn     = overlay?.querySelector('[data-action="zoom-in"]');
    const btnNextPage   = overlay?.querySelector('[data-action="next-page"]');
    const btnLastPage   = overlay?.querySelector('[data-action="last-page"]');
    const btnDownload   = overlay?.querySelector('[data-action="download"]');

    // ── État ──────────────────────────────────────────────────────
    let flip              = null;
    let renderedRealPages = null;
    let pdfRenderPromise  = null;
    let shouldFlipOnCover = false;
    let flipState         = 'cover';
    let baseScale         = 1;
    let zoomFactor        = 1.0;
    let currentScale      = 1;
    let singlePageMode    = window.innerWidth <= 782 && width > height;
    let pdfReady          = !pdfUrl;

    const ZOOM_STEP = 0.1;
    const ZOOM_MIN  = 0.5;
    const ZOOM_MAX  = 2.0;

    // ── URL /extrait ──────────────────────────────────────────────
    const extraitUrl = overlay?.dataset.extraitUrl || '';
    const baseUrl    = extraitUrl ? extraitUrl.replace(/\/extrait\/?$/, '/') : '';

    // ── StPageFlip ────────────────────────────────────────────────
    function buildPlaceholders() {
        return Array.from({ length: flipPageCount - 1 }, () => {
            const ph = document.createElement('div');
            ph.className = 'pf-page pf-page--placeholder';
            ph.setAttribute('aria-hidden', 'true');
            return ph;
        });
    }

    function attachFlipHandlers() {
        flip.on('flip', (e) => {
            if (bsHero) {
                if (e.data === 0)              bsHero.dataset.state = 'closed';
                else if (e.data === lastIndex) bsHero.dataset.state = 'end';
                else                           bsHero.dataset.state = 'open';
            }
            if (e.data === 0 && shouldFlipOnCover) {
                shouldFlipOnCover = false;
                requestAnimationFrame(() => { if (flip) flip.flipNext(); });
            } else {
                flipState = e.data === 0 ? 'cover' : e.data === lastIndex ? 'end' : 'open';
                applyFlipOffset();
                updateToolbarState();
            }
        });
    }

    function createFlip() {
        const pagesToLoad = renderedRealPages
            ? [coverEl, ...renderedRealPages]
            : [coverEl, ...buildPlaceholders()];

        // StPageFlip déplace les éléments de page dans son propre .stf__wrapper.
        // On les rapatrie dans le container avant de supprimer l'ancienne instance,
        // sinon ils partiraient avec le wrapper.
        pagesToLoad.forEach(el => container.appendChild(el));
        container.querySelectorAll('.stf__wrapper, canvas').forEach(el => el.remove());

        flip = new St.PageFlip(container, {
            width, height,
            size: 'fixed',
            showCover: true,
            usePortrait: singlePageMode,
            maxShadowOpacity: 1,
            drawShadow: true,
            useMouseEvents: true,
        });
        flip.loadFromHTML(pagesToLoad);
        attachFlipHandlers();
    }

    function ensureFlipInit() {
        if (flip) return;
        createFlip();
    }

    // ── PDF rendering ─────────────────────────────────────────────
    const triggerPdfRender = () => {
        if (!pdf) return Promise.resolve();
        if (pdfRenderPromise) return pdfRenderPromise;
        container.classList.add('is-loading-pdf');
        let loadingShield = null;
        if (scaleWrapper) {
            loadingShield = document.createElement('div');
            loadingShield.style.cssText = 'position:absolute;inset:0;cursor:progress;';
            scaleWrapper.appendChild(loadingShield);
        }
        pdfRenderPromise = (async () => {
            try {
                const scale = Math.max(1.5, (window.devicePixelRatio || 1) * (height / 400));
                const pages = [];
                for (let i = 2; i <= innerPdfEnd; i++) {
                    if (pageTypes[i - 2]) {
                        pages.push(...(await renderPdfSpread(pdf, i, scale)));
                    } else {
                        pages.push(await renderPdfPage(pdf, i, scale));
                    }
                }
                if (needsPad) {
                    const blank = document.createElement('div');
                    blank.className = 'pf-page pf-page--blank';
                    blank.setAttribute('aria-hidden', 'true');
                    pages.push(blank);
                }
                if (hasCover4) {
                    pages.push(await renderPdfPage(pdf, pdfNumPages, scale));
                }
                renderedRealPages = pages;
                if (flip) flip.updateFromHtml([coverEl, ...pages]);
            } catch (err) {
                pdfRenderPromise = null;
                console.error('[passiflore-pageflip]', err);
                throw err;
            } finally {
                container.classList.remove('is-loading-pdf');
                loadingShield?.remove();
                pdfReady = true;
                updateToolbarState();
            }
        })();
        return pdfRenderPromise;
    };

    // ── Mise à l'échelle ──────────────────────────────────────────
    // En mode portrait (single), StPageFlip utilise width×height.
    // En mode paysage (double), le canvas fait (width×2)×height.
    function scaleToViewport() {
        if (!scaleWrapper || !innerEl || !viewport) return;
        const maxW  = viewport.clientWidth;
        const maxH  = viewport.clientHeight;
        const flipW = singlePageMode ? width : width * 2;
        baseScale    = Math.min(maxW / flipW, maxH / height);
        currentScale = baseScale * zoomFactor;
        scaleWrapper.style.width  = (flipW * currentScale) + 'px';
        scaleWrapper.style.height = (height * currentScale) + 'px';
        innerEl.style.width       = flipW + 'px';
        innerEl.style.height      = height + 'px';
        innerEl.style.transformOrigin = 'top left';
        applyFlipOffset();
    }

    // En mode paysage : la couverture (page 0) et la dernière page occupent
    // une seule moitié du canvas double-largeur → translateX pour centrer.
    // En mode portrait : StPageFlip gère le layout, aucun décalage manuel.
    function applyFlipOffset() {
        if (!scaleWrapper || !innerEl) return;
        innerEl.style.transform = `scale(${currentScale})`;
        innerEl.style.transformOrigin = 'top left';
        if (singlePageMode) {
            scaleWrapper.style.transform = '';
            return;
        }
        const half   = (width * currentScale) / 2;
        const offset = flipState === 'cover' ? -half
                     : flipState === 'end'   ?  half
                     : 0;
        scaleWrapper.style.transform = offset ? `translateX(${offset}px)` : '';
    }

    function adjustZoom(delta) {
        zoomFactor   = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, Math.round((zoomFactor + delta) * 10) / 10));
        currentScale = baseScale * zoomFactor;
        if (!scaleWrapper || !innerEl) return;
        const flipW = singlePageMode ? width : width * 2;
        scaleWrapper.style.width  = (flipW * currentScale) + 'px';
        scaleWrapper.style.height = (height * currentScale) + 'px';
        applyFlipOffset();
        updateToolbarState();
    }

    function toggleSinglePageMode() {
        const savedIdx = flip ? flip.getCurrentPageIndex() : 0;
        singlePageMode = !singlePageMode;
        // Mise à jour du flipState avant scaleToViewport (qui appelle applyFlipOffset)
        flipState = savedIdx === 0 ? 'cover' : savedIdx === lastIndex ? 'end' : 'open';

        // Réinitialisation de StPageFlip avec usePortrait mis à jour
        createFlip();
        if (savedIdx > 0) flip.turnToPage(savedIdx);

        if (scaleWrapper) scaleWrapper.style.transition = 'none';
        scaleToViewport();
        requestAnimationFrame(() => {
            if (scaleWrapper) scaleWrapper.style.transition = '';
        });
        updateToolbarState();
    }

    function updateToolbarState() {
        const idx     = flip ? flip.getCurrentPageIndex() : 0;
        const atCover = idx === 0;
        const atEnd   = idx === lastIndex;
        if (btnFirstPage) btnFirstPage.disabled = atCover;
        if (btnPrevPage)  btnPrevPage.disabled  = atCover;
        if (btnNextPage) {
            if (!pdfReady) {
                btnNextPage.classList.add('is-loading');
                btnNextPage.disabled = true;
            } else {
                btnNextPage.classList.remove('is-loading');
                btnNextPage.disabled = atEnd;
            }
        }
        if (btnLastPage)   btnLastPage.disabled  = !pdfReady || atEnd;
        if (btnZoomOut)    btnZoomOut.disabled    = zoomFactor <= ZOOM_MIN + 0.001;
        if (btnZoomIn)     btnZoomIn.disabled     = zoomFactor >= ZOOM_MAX - 0.001;
        if (btnSinglePage) btnSinglePage.setAttribute('aria-pressed', singlePageMode ? 'true' : 'false');
    }

    window.addEventListener('resize', () => {
        if (overlay?.classList.contains('is-open')) scaleToViewport();
    });

    window.addEventListener('popstate', () => {
        const atExtrait = window.location.pathname.endsWith('/extrait');
        if (atExtrait && overlay && !overlay.classList.contains('is-open')) {
            openAndFlip(true);
        } else if (!atExtrait && overlay && overlay.classList.contains('is-open')) {
            closeOverlay(true);
        }
    });

    // ── Overlay open / close ──────────────────────────────────────
    function openOverlay(skipHistory = false) {
        if (!overlay) return;
        flipState = 'cover';
        overlay.classList.add('is-open');
        overlay.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
        ensureFlipInit();
        updateToolbarState();
        if (scaleWrapper) scaleWrapper.style.transition = 'none';
        requestAnimationFrame(() => {
            scaleToViewport();
            requestAnimationFrame(() => {
                if (scaleWrapper) scaleWrapper.style.transition = '';
            });
        });
        if (!skipHistory && extraitUrl && !window.location.pathname.endsWith('/extrait')) {
            history.pushState({ pfFlipbook: true }, '', extraitUrl);
        }
    }

    function closeOverlay(skipHistory = false) {
        if (!overlay) return;
        shouldFlipOnCover = false;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (flip) flip.turnToPage(0);
        if (!skipHistory && extraitUrl && window.location.pathname.endsWith('/extrait')) {
            history.pushState({}, '', baseUrl);
        }
    }

    closeBtn?.addEventListener('click', () => closeOverlay());
    overlay?.addEventListener('click', (e) => { if (e.target === overlay) closeOverlay(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay?.classList.contains('is-open')) closeOverlay();
    });

    // ── Boutons toolbar ───────────────────────────────────────────
    btnSinglePage?.addEventListener('click', toggleSinglePageMode);
    btnFirstPage?.addEventListener('click',  () => { if (flip) flip.turnToPage(0); });
    btnPrevPage?.addEventListener('click',   () => { if (flip) flip.flipPrev(); });
    btnNextPage?.addEventListener('click',   () => { if (flip) flip.flipNext(); });
    btnLastPage?.addEventListener('click',   () => { if (flip) flip.turnToPage(lastIndex); });
    btnZoomOut?.addEventListener('click',    () => adjustZoom(-ZOOM_STEP));
    btnZoomIn?.addEventListener('click',     () => adjustZoom( ZOOM_STEP));
    btnDownload?.addEventListener('click',   () => { if (pdfUrl) window.open(pdfUrl, '_blank', 'noopener'); });

    // ── Bouton "Feuilleter l'extrait" → ouvre + flippe ───────────
    const flipBtn = document.querySelector('.pf-info__flip');
    if (flipBtn) {
        flipBtn.addEventListener('mouseenter', () => triggerPdfRender().catch(() => {}), { once: true });

        flipBtn.addEventListener('click', async () => {
            openOverlay();
            flipBtn.disabled = true;
            try {
                await triggerPdfRender();
                if (flip) {
                    if (flip.getCurrentPageIndex() === 0) {
                        flip.flipNext();
                    } else {
                        shouldFlipOnCover = true;
                        flip.turnToPage(0);
                    }
                }
            } catch (err) {
                console.error('[passiflore-pageflip]', err);
            } finally {
                flipBtn.disabled = false;
            }
        });
    }

    // ── Clic sur le livre dans l'étagère → ouvre + flippe ────────
    async function openAndFlip(skipHistory = false) {
        openOverlay(skipHistory);
        try {
            await triggerPdfRender();
            if (flip && pdfNumPages > 1) {
                if (flip.getCurrentPageIndex() === 0) {
                    flip.flipNext();
                } else {
                    shouldFlipOnCover = true;
                    flip.turnToPage(0);
                }
            }
        } catch (err) {
            console.error('[passiflore-pageflip]', err);
        }
    }

    document.querySelectorAll('[data-trigger-flipbook]').forEach(book => {
        book.addEventListener('mouseenter', () => triggerPdfRender().catch(() => {}), { once: true });
        book.addEventListener('click', () => openAndFlip());
        book.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAndFlip(); }
        });
    });

    // Auto-ouverture si l'URL se termine par /extrait (lien partagé)
    if (window.location.pathname.endsWith('/extrait')) {
        requestAnimationFrame(() => openAndFlip(true));
    }
}

function boot() {
    const container = document.getElementById('passiflore-flipbook');
    if (container) initFlipbook(container);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
