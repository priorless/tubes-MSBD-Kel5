/*
 * Interaksi kecil tema Operasional.
 * Angka bertanda [data-hitung] berhitung naik dari 0 ke nilai aslinya saat halaman dibuka.
 * Nilai asli tetap ada di HTML (aman untuk pembaca layar dan bila JavaScript mati).
 */
(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    document.querySelectorAll('[data-hitung]').forEach((elemen, urutan) => {
        const target = parseInt(elemen.textContent.replace(/\D/g, ''), 10);

        if (!target) {
            return;
        }

        const mulai = performance.now() + 250 + urutan * 55;
        const durasi = 800 + Math.min(target, 60) * 14;
        elemen.textContent = '0';

        const langkah = (sekarang) => {
            const kemajuan = Math.min(Math.max((sekarang - mulai) / durasi, 0), 1);
            elemen.textContent = String(Math.round(target * (1 - Math.pow(1 - kemajuan, 3))));

            if (kemajuan < 1) {
                requestAnimationFrame(langkah);
            }
        };

        requestAnimationFrame(langkah);
    });
})();
