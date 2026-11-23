@extends('layouts.app')

@section('title', 'Manajemen User')

@section('content')
    <x-ui.page-header title="Akun Staff Internal"
        description="Akun karyawan lapangan dibuat otomatis oleh sistem dan tidak tampil di sini.">
        @can('create', \App\Models\User::class)
            <a href="{{ route('users.create') }}" class="btn-primary">+ Tambah User</a>
        @endcan
    </x-ui.page-header>

    <div class="card overflow-hidden">

        <form method="GET" action="{{ route('users.index') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="search" name="cari" value="{{ $filters['cari'] ?? '' }}" class="form-input md:col-span-5" placeholder="Cari nama atau username..." aria-label="Cari">
            <select name="role" class="form-input md:col-span-3" aria-label="Filter role">
                <option value="">Semua Role</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" @selected(($filters['role'] ?? '') == $role->id)>{{ $role->nama_role }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:col-span-2" aria-label="Filter status">
                <option value="">Semua Status</option>
                @foreach ([\App\Models\User::StatusAktif, \App\Models\User::StatusNonaktif] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary md:col-span-2">Cari</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Password</th>
                        <th>Dibuat</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td class="text-gray-500">{{ $users->firstItem() + $loop->index }}</td>
                            <td class="font-medium text-gray-800">
                                {{ $user->nama ?? '-' }}
                                @if (auth()->user()->is($user))
                                    <span class="badge badge-info ml-1">Anda</span>
                                @endif
                            </td>
                            <td class="font-mono text-gray-600">{{ $user->username }}</td>
                            <td>{{ $user->role?->nama_role }}</td>
                            <td>
                                <span @class(['badge', 'badge-success' => $user->isAktif(), 'badge-secondary' => ! $user->isAktif()])>{{ $user->status }}</span>
                            </td>
                            <td>
                                @if ($user->must_change_password)
                                    <span class="badge badge-warning">Belum diganti</span>
                                @else
                                    <span class="text-xs text-gray-400">Sudah diganti</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-gray-500">{{ $user->created_at?->translatedFormat('d M Y') }}</td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    @can('update', $user)
                                        <a href="{{ route('users.edit', $user) }}" class="btn-secondary btn-sm">Ubah</a>
                                    @endcan
                                    @can('delete', $user)
                                        <form method="POST" action="{{ route('users.destroy', $user) }}"
                                              onsubmit="return confirm(@js('Hapus akun '.$user->username.'? Tindakan ini tidak dapat dibatalkan.'))">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-outline-danger btn-sm">Hapus</button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="table-empty">Belum ada akun staff yang sesuai.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $users->firstItem() ?? 0 }}&ndash;{{ $users->lastItem() ?? 0 }} dari {{ $users->total() }} akun</span>
            @include('partials.pagination', ['paginator' => $users])
        </div>
    </div>
@endsection
