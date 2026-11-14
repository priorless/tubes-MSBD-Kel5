{{-- Notifikasi flash: success / warning / error (termasuk pesan SIGNAL SQLSTATE 45000 dari database). --}}
@php
    $pesanFlash = [
        'success' => ['kelas' => 'alert-success', 'ikon' => 'fa-check-circle', 'pesan' => session('success')],
        'warning' => ['kelas' => 'alert-warning', 'ikon' => 'fa-exclamation-triangle', 'pesan' => session('warning')],
        'error' => ['kelas' => 'alert-danger', 'ikon' => 'fa-times-circle', 'pesan' => session('error') ?? ($error ?? null)],
    ];
@endphp

@foreach ($pesanFlash as $flash)
    @if (filled($flash['pesan']))
        <div class="alert {{ $flash['kelas'] }} alert-dismissible fade show" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Tutup">
                <span aria-hidden="true">&times;</span>
            </button>
            <i class="icon fas {{ $flash['ikon'] }}"></i> {{ $flash['pesan'] }}
        </div>
    @endif
@endforeach
