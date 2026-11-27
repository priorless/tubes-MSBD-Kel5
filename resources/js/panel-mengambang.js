/**
 * Panel mengambang: daftar dropdown, autocomplete, dan popover yang harus tampil di atas seluruh isi halaman.
 *
 * Panel dipindah ke <body> (atau ke <dialog> terbuka yang memuat jangkarnya, karena dialog modal berada di
 * top layer browser) lalu diposisikan `fixed` tepat di bawah jangkar, atau di atasnya bila ruang di bawah tidak
 * cukup. Dengan begitu panel tidak terpotong `overflow` kontainer dan tidak tertutup stacking context kartu atau
 * tabel di sekitarnya. Lapisannya mengikuti token --lapis-mengambang di resources/css/app.css.
 *
 * Pemakaian:
 *  - dari script: pasangPanelMengambang(panel, jangkar)
 *  - dari Blade:  <div data-panel-mengambang="id-elemen-jangkar" class="hidden ..."> (dipasang saat halaman dimuat)
 * Pemilik panel tetap menampilkan/menyembunyikannya lewat atribut `hidden` atau kelas `hidden`; posisi diperbarui
 * otomatis saat panel tampil, isinya berubah, halaman di-scroll, atau ukuran layar berubah.
 */
const JARAK = 4;
const TINGGI_MINIMUM = 120;
const terpasang = new WeakSet();

const tersembunyi = (panel) => panel.hidden || panel.classList.contains('hidden');

/**
 * @param {HTMLElement} panel
 * @param {HTMLElement} jangkar elemen yang menjadi acuan posisi dan lebar panel
 */
export function pasangPanelMengambang(panel, jangkar) {
    if (terpasang.has(panel)) {
        return;
    }
    terpasang.add(panel);
    panel.classList.add('panel-mengambang');

    const posisikan = () => {
        if (tersembunyi(panel)) {
            return;
        }
        if (!jangkar.isConnected) {
            panel.remove();
            return;
        }

        const wadah = jangkar.closest('dialog[open]') ?? document.body;
        if (panel.parentElement !== wadah) {
            wadah.append(panel);
        }

        const kotak = jangkar.getBoundingClientRect();
        panel.style.left = `${kotak.left}px`;
        panel.style.width = `${kotak.width}px`;
        panel.style.maxHeight = '';

        const tinggi = panel.offsetHeight;
        const ruangBawah = window.innerHeight - kotak.bottom - JARAK * 2;
        const ruangAtas = kotak.top - JARAK * 2;
        const keAtas = tinggi > ruangBawah && ruangAtas > ruangBawah;
        const ruang = keAtas ? ruangAtas : ruangBawah;
        if (tinggi > ruang) {
            panel.style.maxHeight = `${Math.max(ruang, TINGGI_MINIMUM)}px`;
        }

        panel.style.top = keAtas
            ? `${kotak.top - JARAK - panel.offsetHeight}px`
            : `${kotak.bottom + JARAK}px`;
    };

    let mengikutiLayar = false;
    const perbarui = () => {
        const tampil = !tersembunyi(panel);
        if (tampil) {
            posisikan();
        }
        if (tampil !== mengikutiLayar) {
            const aksi = tampil ? 'addEventListener' : 'removeEventListener';
            window[aksi]('scroll', posisikan, { capture: true, passive: true });
            window[aksi]('resize', posisikan, { passive: true });
            mengikutiLayar = tampil;
        }
    };

    if (!panel.isConnected) {
        document.body.append(panel);
    }
    new MutationObserver(perbarui).observe(panel, { attributes: true, attributeFilter: ['hidden', 'class'], childList: true });
    perbarui();
}

document.querySelectorAll('[data-panel-mengambang]').forEach((panel) => {
    const jangkar = document.getElementById(panel.dataset.panelMengambang);
    if (jangkar) {
        pasangPanelMengambang(panel, jangkar);
    }
});
