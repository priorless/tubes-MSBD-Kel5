/**
 * Semua <select class="form-input"> (kecuali multiple / data-tanpa-cari) diubah menjadi combobox:
 * bisa diketik untuk mencari DAN diklik untuk memilih dari daftar.
 * Select asli tetap ada (disembunyikan) sehingga nilai yang dikirim form serta handler change/onchange tidak berubah.
 * Perubahan dari script lain (select.value = ..., opsi disabled/ditambah, select disabled) ikut tersinkron.
 */
const PEMILIH = 'select.form-input:not([multiple]):not([data-tanpa-cari]), select[data-cari]';
const deskriptorValue = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
let urutan = 0;

function pasangPilihanCari(select) {
    if (select.dataset.pilihanCariTerpasang) {
        return;
    }
    select.dataset.pilihanCariTerpasang = '1';

    const idDaftar = `pilihan-cari-${++urutan}`;
    const nilaiSelect = () => deskriptorValue.get.call(select);
    const semuaOpsi = () => [...select.options]
        .filter((option) => !option.hidden)
        .map((option) => ({ value: option.value, text: option.text.trim(), disabled: option.disabled }));
    const opsiKosong = () => semuaOpsi().find((opsi) => opsi.value === '');
    const opsiPilihan = () => semuaOpsi().filter((opsi) => opsi.value !== '' && !opsi.disabled);
    const teksTerpilih = () => {
        const terpilih = select.selectedOptions[0];
        return terpilih && terpilih.value !== '' ? terpilih.text.trim() : '';
    };

    // Kelas tata letak (grid/lebar/margin/visibilitas) pindah ke pembungkus, kelas js-* tetap hanya di select.
    const kelas = [...select.classList].filter((nama) => !nama.startsWith('js-'));
    const kelasTataLetak = kelas.filter((nama) => /^(?:[\w-]+:)*(?:col-|row-|hidden$|block$|inline|w-|min-w-|max-w-|flex-|grow|shrink|order-|self-|m[trblxy]?-)/.test(nama));

    const wadah = document.createElement('div');
    wadah.className = ['pilihan-cari', ...kelasTataLetak].join(' ');

    const input = document.createElement('input');
    input.type = 'text';
    input.className = [...kelas.filter((nama) => !kelasTataLetak.includes(nama)), 'pilihan-cari-input'].join(' ');
    input.placeholder = select.dataset.cari || opsiKosong()?.text || 'Ketik untuk mencari atau pilih...';
    input.autocomplete = 'off';
    input.required = select.required;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', idDaftar);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-label', (select.id && document.querySelector(`label[for="${select.id}"]`)?.textContent.trim()) || select.getAttribute('aria-label') || select.name);
    if (select.id) {
        input.id = `${select.id}__cari`;
        document.querySelectorAll(`label[for="${select.id}"]`).forEach((label) => label.setAttribute('for', input.id));
    }
    select.required = false;

    const tombol = document.createElement('button');
    tombol.type = 'button';
    tombol.className = 'pilihan-cari-tombol';
    tombol.tabIndex = -1;
    tombol.setAttribute('aria-label', 'Tampilkan pilihan');
    tombol.innerHTML = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>';

    const daftar = document.createElement('ul');
    daftar.id = idDaftar;
    daftar.className = 'pilihan-cari-daftar';
    daftar.setAttribute('role', 'listbox');
    daftar.hidden = true;

    let aktif = -1;
    let tampil = [];
    let kataTerakhir = '';

    const sinkronTampilan = () => {
        input.disabled = select.disabled;
        tombol.disabled = select.disabled;
        if (document.activeElement !== input) {
            input.value = teksTerpilih();
        }
        input.setCustomValidity('');
    };

    const pilih = (nilai) => {
        input.setCustomValidity('');
        tutup();
        if (nilai !== nilaiSelect()) {
            deskriptorValue.set.call(select, nilai);
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        input.value = teksTerpilih();
    };

    const sorot = (indeks) => {
        aktif = indeks;
        [...daftar.children].forEach((li, i) => li.classList.toggle('is-aktif', i === indeks));
        daftar.children[indeks]?.scrollIntoView({ block: 'nearest' });
    };

    const render = (kata) => {
        kataTerakhir = kata;
        const kunci = kata.trim().toLowerCase();
        const kosong = opsiKosong();
        tampil = (kosong && kunci === '' ? [kosong] : []).concat(
            opsiPilihan().filter((item) => kunci === '' || item.text.toLowerCase().includes(kunci)),
        );
        daftar.replaceChildren(...(tampil.length === 0
            ? [Object.assign(document.createElement('li'), { className: 'pilihan-cari-kosong', textContent: 'Tidak ditemukan' })]
            : tampil.map((item, indeks) => {
                const li = document.createElement('li');
                li.textContent = item.text;
                li.setAttribute('role', 'option');
                li.className = 'pilihan-cari-opsi';
                li.classList.toggle('is-kosong', item.value === '');
                li.setAttribute('aria-selected', String(item.value === nilaiSelect()));
                li.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    pilih(item.value);
                });
                li.addEventListener('mousemove', () => sorot(indeks));
                return li;
            })));
        sorot(tampil.findIndex((item) => item.value === nilaiSelect() && item.value !== ''));
    };

    const buka = (kata = '') => {
        if (select.disabled) {
            return;
        }
        render(kata);
        daftar.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };

    function tutup() {
        daftar.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    // Script lain yang menulis select.value langsung tetap memperbarui teks combobox.
    Object.defineProperty(select, 'value', {
        configurable: true,
        get: nilaiSelect,
        set(nilai) {
            deskriptorValue.set.call(select, nilai);
            sinkronTampilan();
        },
    });
    select.addEventListener('change', sinkronTampilan);
    new MutationObserver(() => {
        sinkronTampilan();
        if (!daftar.hidden) {
            render(kataTerakhir);
        }
    }).observe(select, { attributes: true, attributeFilter: ['disabled'], childList: true, subtree: true, characterData: true });

    input.addEventListener('focus', () => {
        input.select();
        buka();
    });
    input.addEventListener('click', () => daftar.hidden && buka());
    input.addEventListener('input', () => {
        if (input.value.trim() === '') {
            pilih('');
            input.value = '';
        }
        buka(input.value);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (daftar.hidden) {
                buka();
            }
            const langkah = event.key === 'ArrowDown' ? 1 : -1;
            sorot(Math.max(0, Math.min(tampil.length - 1, aktif + langkah)));
        } else if (event.key === 'Enter' && !daftar.hidden) {
            event.preventDefault();
            const item = tampil[aktif] ?? (tampil.length === 1 ? tampil[0] : null);
            if (item) {
                pilih(item.value);
            }
        } else if (event.key === 'Escape' && !daftar.hidden) {
            event.preventDefault();
            tutup();
            input.value = teksTerpilih();
        } else if (event.key === 'Tab') {
            tutup();
        }
    });
    input.addEventListener('blur', () => {
        tutup();
        const kunci = input.value.trim().toLowerCase();
        if (kunci === '' || kunci === teksTerpilih().toLowerCase()) {
            input.value = teksTerpilih();
            return;
        }
        const pilihan = opsiPilihan();
        const persis = pilihan.find((item) => item.text.toLowerCase() === kunci);
        const mirip = pilihan.filter((item) => item.text.toLowerCase().includes(kunci));
        const cocok = persis ?? (mirip.length === 1 ? mirip[0] : null);
        if (cocok) {
            pilih(cocok.value);
        } else {
            input.value = teksTerpilih();
        }
    });
    tombol.addEventListener('mousedown', (event) => {
        event.preventDefault();
        if (daftar.hidden) {
            input.focus();
            buka();
        } else {
            tutup();
        }
    });

    select.form?.addEventListener('submit', (event) => {
        if (input.required && !select.disabled && nilaiSelect() === '') {
            event.preventDefault();
            event.stopImmediatePropagation();
            input.setCustomValidity('Pilih salah satu dari daftar.');
            input.reportValidity();
        }
    }, true);
    select.form?.addEventListener('reset', () => setTimeout(sinkronTampilan));

    select.hidden = true;
    select.after(wadah);
    wadah.append(input, tombol, daftar);
    sinkronTampilan();
}

const pasangSemua = (akar) => {
    if (akar.matches?.(PEMILIH)) {
        pasangPilihanCari(akar);
    }
    akar.querySelectorAll?.(PEMILIH).forEach(pasangPilihanCari);
};

pasangSemua(document);

// Select yang ditambahkan belakangan oleh script halaman juga ikut diubah.
new MutationObserver((perubahan) => perubahan.forEach((catatan) => catatan.addedNodes.forEach((node) => {
    if (node.nodeType === Node.ELEMENT_NODE && !node.closest('.pilihan-cari')) {
        pasangSemua(node);
    }
}))).observe(document.body, { childList: true, subtree: true });
