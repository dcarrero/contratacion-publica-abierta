import './bootstrap';

/**
 * Importe abreviado, misma escala que el helper PHP `formatImporteCorto`.
 *
 * Una sola unidad grande —millones— en vez de los tres escalones anteriores (K€, M€,
 * Md€/MM€): en una misma gráfica convivían "5,7 M€" y "11,3 MM€", que se distinguen en un
 * carácter pero se diferencian en un factor de mil.
 */
window.formatImporteCorto = function (valor) {
    if (valor === null || valor === undefined || Number.isNaN(valor)) {
        return '—';
    }

    const absoluto = Math.abs(valor);

    if (absoluto >= 1e9) {
        // A partir de mil millones los decimales sobran: "11.300 M€".
        return Math.round(valor / 1e6).toLocaleString('es-ES') + ' M€';
    }

    if (absoluto >= 1e6) {
        return (valor / 1e6).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' M€';
    }

    return Math.round(valor).toLocaleString('es-ES') + ' €';
};
