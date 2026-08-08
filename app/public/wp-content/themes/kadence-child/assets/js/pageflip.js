let pdfjsLibPromise = null;

// Chargé à la demande : un livre sans extrait ne doit jamais tirer pdf.js du CDN.
function loadPdfjs() {
    if (!pdfjsLibPromise) {
        pdfjsLibPromise = import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4/build/pdf.min.mjs')
            .then((lib) => {
                lib.GlobalWorkerOptions.workerSrc =
                    'https://cdn.jsdelivr.net/npm/pdfjs-dist@4/build/pdf.worker.min.mjs';
                return lib;
            });
    }
    return pdfjsLibPromise;
}

let zoomIndicatorTimer = null;
function showZoomIndicator(overlay, zoomFactor) {
    const el = overlay?.querySelector('.bs-zoom-indicator');
    if (!el) return;
    el.textContent = Math.round(zoomFactor * 100) + ' %';
    el.classList.add('is-visible');
    clearTimeout(zoomIndicatorTimer);
    zoomIndicatorTimer = setTimeout(() => el.classList.remove('is-visible'), 1000);
}

// À ≤100%, le contenu tient toujours dans le viewport par construction
// (baseScale l'y a calé) : overflow:auto ne sert alors qu'à rogner les
// débords purement visuels (ombre StPageFlip au coin qui tourne...) sans
// jamais permettre un vrai scroll. On ne le repasse en scrollable que
// lorsqu'un zoom > 100% peut effectivement dépasser le viewport.
function syncViewportOverflow(viewport, zoomFactor) {
    if (viewport) viewport.style.overflow = zoomFactor <= 1 ? 'visible' : 'auto';
}

// Pinch-to-zoom tactile — pilote le même `setZoom` continu que les boutons
// (mêmes bornes, même rendu, même indicateur), pas de geste concurrent maison.
// `onSettle` (optionnel) : rappelé au relâchement des doigts — pour le
// flipbook PDF, c'est là que la taille réelle de StPageFlip est recalée
// (voir commitZoom) ; pendant le pincement lui-même, seul un transform CSS
// bouge, jamais de reconstruction StPageFlip (trop coûteux à 60fps).
function attachPinchZoom(target, { getZoom, setZoom, onSettle, minZoom, maxZoom }) {
    if (!target) return;
    let startDist = 0;
    let startZoom = 1;

    function distance(touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.hypot(dx, dy);
    }

    // Capture (pas bubble) : StPageFlip a son propre listener de drag-page
    // sur le conteneur interne et coupe la propagation en bubble avant qu'elle
    // ne remonte jusqu'ici — en capture on l'intercepte avant qu'il le voie.
    // Un seul doigt : return immédiat, rien n'est intercepté, son drag marche toujours.
    target.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 2) return;
        const d = distance(e.touches);
        if (d > 0) {
            startDist = d;
            startZoom = getZoom();
            e.stopPropagation();
        }
    }, { passive: true, capture: true });

    target.addEventListener('touchmove', (e) => {
        if (e.touches.length !== 2 || startDist === 0) return;
        e.preventDefault();  // laisse le scroll tactile à un seul doigt intact
        e.stopPropagation();
        const ratio = distance(e.touches) / startDist;
        setZoom(Math.min(maxZoom, Math.max(minZoom, startZoom * ratio)));
    }, { passive: false, capture: true });

    const reset = (e) => {
        if (e.touches.length < 2 && startDist !== 0) {
            startDist = 0;
            onSettle?.();
        }
    };
    target.addEventListener('touchend', reset, { passive: true, capture: true });
    target.addEventListener('touchcancel', reset, { passive: true, capture: true });
}

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

// Pas d'extrait PDF : la couverture s'affiche seule dans l'overlay, en
// image zoomable — jamais dans StPageFlip (pas de "livre" avec une seule
// page à feuilleter, pas de chargement de StPageFlip/pdf.js pour rien).
function initCoverOnly(container, coverEl) {
    const width  = parseInt(container.dataset.width,  10) || 400;
    const height = parseInt(container.dataset.height, 10) || 570;

    const overlay      = document.querySelector('.bs-flipbook-overlay');
    const closeBtn     = overlay?.querySelector('.bs-flipbook-close');
    const scaleWrapper = overlay?.querySelector('.bs-flipbook-scale-wrapper');
    const innerEl      = overlay?.querySelector('.bs-flipbook-inner');
    const viewport      = overlay?.querySelector('.bs-flipbook-viewport');
    const btnZoomOut    = overlay?.querySelector('[data-action="zoom-out"]');
    const btnZoomIn     = overlay?.querySelector('[data-action="zoom-in"]');

    coverEl.style.width  = width + 'px';
    coverEl.style.height = height + 'px';

    const ZOOM_STEP = 0.1;
    const ZOOM_MIN  = 0.5;
    const ZOOM_MAX  = 2.0;
    let zoomFactor  = 1.0;

    const extraitUrl = overlay?.dataset.extraitUrl || '';
    const baseUrl    = extraitUrl ? extraitUrl.replace(/\/extrait\/?$/, '/') : '';

    function scaleToViewport() {
        if (!scaleWrapper || !innerEl || !viewport) return;
        const baseScale = Math.min(viewport.clientWidth / width, viewport.clientHeight / height);
        const scale     = baseScale * zoomFactor;
        scaleWrapper.style.width  = (width * scale) + 'px';
        scaleWrapper.style.height = (height * scale) + 'px';
        innerEl.style.width  = width + 'px';
        innerEl.style.height = height + 'px';
        innerEl.style.transformOrigin = 'top left';
        innerEl.style.transform = `scale(${scale})`;
        syncViewportOverflow(viewport, zoomFactor);
    }

    function updateZoomButtons() {
        if (btnZoomOut) btnZoomOut.disabled = zoomFactor <= ZOOM_MIN + 0.001;
        if (btnZoomIn)  btnZoomIn.disabled  = zoomFactor >= ZOOM_MAX - 0.001;
    }

    // Cible continue (pinch tactile) — les boutons passent par adjustZoom,
    // qui arrondit au pas de 0.1 avant de retomber ici.
    function setZoom(factor) {
        zoomFactor = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, factor));
        showZoomIndicator(overlay, zoomFactor);
        scaleToViewport();
        updateZoomButtons();
    }

    function adjustZoom(delta) {
        setZoom(Math.round((zoomFactor + delta) * 10) / 10);
    }

    function openOverlay(skipHistory = false) {
        if (!overlay) return;
        overlay.classList.add('is-open');
        overlay.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
        zoomFactor = 1.0;
        updateZoomButtons();
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
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (!skipHistory && extraitUrl && window.location.pathname.endsWith('/extrait')) {
            history.pushState({}, '', baseUrl);
        }
    }

    closeBtn?.addEventListener('click', () => closeOverlay());
    overlay?.addEventListener('click', (e) => { if (e.target === overlay || e.target === viewport) closeOverlay(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay?.classList.contains('is-open')) closeOverlay();
    });
    btnZoomOut?.addEventListener('click', () => adjustZoom(-ZOOM_STEP));
    btnZoomIn?.addEventListener('click',  () => adjustZoom( ZOOM_STEP));
    attachPinchZoom(viewport, { getZoom: () => zoomFactor, setZoom, minZoom: ZOOM_MIN, maxZoom: ZOOM_MAX });

    window.addEventListener('resize', () => {
        if (overlay?.classList.contains('is-open')) scaleToViewport();
    });

    window.addEventListener('popstate', () => {
        const atExtrait = window.location.pathname.endsWith('/extrait');
        if (atExtrait && overlay && !overlay.classList.contains('is-open')) {
            openOverlay(true);
        } else if (!atExtrait && overlay && overlay.classList.contains('is-open')) {
            closeOverlay(true);
        }
    });

    document.querySelectorAll('[data-trigger-flipbook]').forEach(book => {
        book.addEventListener('click', () => openOverlay());
        book.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openOverlay(); }
        });
    });

    // Auto-ouverture si l'URL se termine par /extrait (lien partagé)
    if (window.location.pathname.endsWith('/extrait')) {
        requestAnimationFrame(() => openOverlay(true));
    }
}

async function initFlipbook(container) {
    const coverEl = container.querySelector('.pf-page--cover');
    if (!coverEl) return;

    const pdfUrl = container.dataset.pdf || '';
    if (!pdfUrl) {
        initCoverOnly(container, coverEl);
        return;
    }

    const width  = parseInt(container.dataset.width,  10) || 400;
    const height = parseInt(container.dataset.height, 10) || 570;

    let pdf = null;
    try {
        const pdfjsLib = await loadPdfjs();
        pdf = await pdfjsLib.getDocument(pdfUrl).promise;
    } catch (err) {
        console.error('[passiflore-pageflip] PDF load error', err);
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
    let zoomFactor        = 1.0;
    // Taille RÉELLE (px déjà à l'échelle) à laquelle StPageFlip est
    // actuellement construit — cf. createFlip()/computeNativeScale().
    let nativePageW           = width;
    let nativePageH           = height;
    let currentNativeScale    = 1;
    let lastSettledZoomFactor = 1;
    // Page d'affichage : ≤480 (téléphone) toujours simple — un roman portrait
    // y est illisible en double page ; 481-1024 (tablette) simple seulement
    // pour un livre au format paysage ; >1024 toujours double. Uniquement le
    // mode de DÉPART : le bouton de la toolbar reste libre de le changer.
    let singlePageMode    = window.innerWidth <= 480
        ? true
        : window.innerWidth <= 1024
            ? width > height
            : false;
    let pdfReady          = false;

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
                applyFlipOffset(nativePageW);
                updateToolbarState();
            }
        });
    }

    // Taille réelle (fit viewport × zoom) à laquelle StPageFlip doit être
    // construit pour que SES propres zones de clic (calculées sur son
    // width/height de config) tombent au même endroit que le clic/tap réel
    // — sinon, dès que cette taille ne vaut plus 1:1 avec le rendu visuel
    // (cas courant : double page qui déborde un écran étroit), StPageFlip
    // se trompe de moitié en silence (page précédente au lieu de suivante).
    function computeNativeScale() {
        if (!viewport || !viewport.clientWidth || !viewport.clientHeight) return zoomFactor;
        const maxW  = viewport.clientWidth;
        const maxH  = viewport.clientHeight;
        const flipW = singlePageMode ? width : width * 2;
        return Math.min(maxW / flipW, maxH / height) * zoomFactor;
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

        currentNativeScale    = computeNativeScale();
        nativePageW            = Math.max(1, Math.round(width  * currentNativeScale));
        nativePageH            = Math.max(1, Math.round(height * currentNativeScale));
        lastSettledZoomFactor = zoomFactor;

        flip = new St.PageFlip(container, {
            width: nativePageW, height: nativePageH,
            size: 'fixed',
            showCover: true,
            usePortrait: singlePageMode,
            maxShadowOpacity: 1,
            drawShadow: true,
            useMouseEvents: true,
        });
        flip.loadFromHTML(pagesToLoad);
        attachFlipHandlers();
        applySizing();
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
    // Pose scaleWrapper/innerEl à la taille NATIVE actuelle (nativePageW/H,
    // déjà à l'échelle par createFlip) — aucun transform CSS ici : StPageFlip
    // rend déjà à la bonne taille, l'affichage et ses coordonnées internes
    // coïncident. Le transform ne sert plus qu'à l'aperçu live du pincement
    // (previewZoom), jamais à l'état posé.
    function applySizing() {
        if (!scaleWrapper || !innerEl) return;
        const flipMult = singlePageMode ? 1 : 2;
        scaleWrapper.style.width  = (nativePageW * flipMult) + 'px';
        scaleWrapper.style.height = nativePageH + 'px';
        innerEl.style.width       = (nativePageW * flipMult) + 'px';
        innerEl.style.height      = nativePageH + 'px';
        innerEl.style.transformOrigin = 'top left';
        innerEl.style.transform  = '';
        applyFlipOffset(nativePageW);
        syncViewportOverflow(viewport, zoomFactor);
    }

    // En mode paysage : la couverture (page 0) et la dernière page occupent
    // une seule moitié du canvas double-largeur → translateX pour centrer.
    // En mode portrait : StPageFlip gère le layout, aucun décalage manuel.
    // `visualPageW` : largeur d'UNE page telle qu'actuellement affichée
    // (nativePageW à l'état posé, ou nativePageW×ratio pendant un pincement).
    function applyFlipOffset(visualPageW) {
        if (!scaleWrapper) return;
        if (singlePageMode) {
            scaleWrapper.style.transform = '';
            return;
        }
        const half   = visualPageW / 2;
        const offset = flipState === 'cover' ? -half
                     : flipState === 'end'   ?  half
                     : 0;
        scaleWrapper.style.transform = offset ? `translateX(${offset}px)` : '';
    }

    // Aperçu live (pincement en cours) : un simple transform CSS relatif à
    // la dernière taille posée — pas touche à StPageFlip, ~free niveau perf,
    // contrairement à un re-init (voir commitZoom) qu'on ne peut pas se
    // permettre à chaque touchmove (60×/s pendant un pincement).
    function previewZoom(factor) {
        zoomFactor = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, factor));
        showZoomIndicator(overlay, zoomFactor);
        if (!scaleWrapper || !innerEl) return;
        const liveRatio = zoomFactor / lastSettledZoomFactor;
        const flipMult  = singlePageMode ? 1 : 2;
        scaleWrapper.style.width  = (nativePageW * flipMult * liveRatio) + 'px';
        scaleWrapper.style.height = (nativePageH * liveRatio) + 'px';
        innerEl.style.transformOrigin = 'top left';
        innerEl.style.transform  = liveRatio === 1 ? '' : `scale(${liveRatio})`;
        applyFlipOffset(nativePageW * liveRatio);
        syncViewportOverflow(viewport, zoomFactor);
        updateToolbarState();
    }

    // Fige le zoom courant dans StPageFlip lui-même (re-init à la taille
    // réelle) — remet le transform CSS à neutre du même coup. Appelé au
    // clic sur un bouton zoom (immédiat) et au relâchement d'un pincement
    // (jamais pendant, cf. previewZoom).
    function commitZoom() {
        const savedIdx = flip ? flip.getCurrentPageIndex() : 0;
        createFlip();
        if (savedIdx > 0) flip.turnToPage(savedIdx);
        updateToolbarState();
    }

    function adjustZoom(delta) {
        previewZoom(Math.round((zoomFactor + delta) * 10) / 10);
        commitZoom();
    }

    // Resize/orientation : ne re-crée StPageFlip que si l'échelle de calage
    // a réellement changé (évite un re-init pour un resize vertical seul,
    // sans effet sur une largeur déjà contrainte par la largeur).
    function resyncNativeSize() {
        if (!flip) return;
        if (Math.abs(computeNativeScale() - currentNativeScale) < 0.001) return;
        const savedIdx = flip.getCurrentPageIndex();
        createFlip();
        if (savedIdx > 0) flip.turnToPage(savedIdx);
        updateToolbarState();
    }

    function toggleSinglePageMode() {
        const savedIdx = flip ? flip.getCurrentPageIndex() : 0;
        singlePageMode = !singlePageMode;
        // Mise à jour du flipState avant createFlip (qui appelle applyFlipOffset)
        flipState = savedIdx === 0 ? 'cover' : savedIdx === lastIndex ? 'end' : 'open';

        // Réinitialisation de StPageFlip avec usePortrait mis à jour
        createFlip();
        if (savedIdx > 0) flip.turnToPage(savedIdx);

        if (scaleWrapper) scaleWrapper.style.transition = 'none';
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

    // Débounce : un resize/rotation peut émettre en rafale, et chaque
    // resync potentiel est un re-init StPageFlip complet (loadFromHTML...).
    let resizeDebounceTimer = null;
    window.addEventListener('resize', () => {
        if (!overlay?.classList.contains('is-open')) return;
        clearTimeout(resizeDebounceTimer);
        resizeDebounceTimer = setTimeout(resyncNativeSize, 150);
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
            resyncNativeSize();
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
    overlay?.addEventListener('click', (e) => {
        if (e.target === overlay || e.target === viewport) { closeOverlay(); return; }
        // Double page : la couverture/dernière page n'occupe qu'une moitié
        // du canvas (l'autre reste vide, cf. applyFlipOffset) — un clic dans
        // cette moitié vide doit fermer comme un clic à côté du livre.
        if (!singlePageMode && (flipState === 'cover' || flipState === 'end') && scaleWrapper?.contains(e.target)) {
            const rect       = scaleWrapper.getBoundingClientRect();
            const onLeftHalf = e.clientX < rect.left + rect.width / 2;
            if (flipState === 'cover' ? onLeftHalf : !onLeftHalf) closeOverlay();
        }
    });
    // Le tourne-page au clic/tap reste 100% natif (StPageFlip) : depuis que
    // createFlip() le construit toujours à sa taille RÉELLEMENT affichée
    // (cf. computeNativeScale), ses zones de clic internes tombent juste
    // sans qu'on ait à les recalculer ou à intercepter quoi que ce soit.
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
    attachPinchZoom(viewport, { getZoom: () => zoomFactor, setZoom: previewZoom, onSettle: commitZoom, minZoom: ZOOM_MIN, maxZoom: ZOOM_MAX });
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
