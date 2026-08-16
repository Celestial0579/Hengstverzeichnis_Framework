let currentZoom = 1.0;

function zoomPedigree(delta) {
    currentZoom = Math.min(Math.max(0.5, currentZoom + delta), 2.0);
    applyZoom();
}

function resetZoom() {
    currentZoom = 1.0;
    applyZoom();
}

function applyZoom() {
    const canvas = document.getElementById('pedigreeCanvas');
    const text = document.getElementById('zoomLevelText');
    canvas.style.transform = `scale(${currentZoom})`;
    text.innerText = `${Math.round(currentZoom * 100)}%`;
}

function setGenerations(levels) {
    const tree = document.getElementById('pedigreeTree');
    tree.className = `pedigree-grid gen-view-${levels}`;
}
