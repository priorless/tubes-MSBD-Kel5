@extends('layouts.admin')

@section('title', 'Manajemen User')
@section('heading', 'Manajemen User')

@section('content')
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Akun Staff Internal</h3>
        <div class="card-tools">
            @can('create', \App\Models\User::class)
                <a href="{{ route('users.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-user-plus mr-1"></i> Tambah User
                </a>
            @endcan
        </div>
    </div>

    <div class="card-body border-bottom">
        <form method="GET" action="{{ route('users.index') }}" class="form-row">
            <div class="col-md-5 mb-2">
                <input type="search" name="cari" value="{{ $filters['cari'] ?? '' }}" class="form-control" placeholder="Cari nama atau username...">
            </div>
            <div class="col-md-3 mb-2">
                <select name="role" class="form-control" aria-label="Filter role">
                    <option value="">Semua Role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected(($filters['role'] ?? '') == $role->id)>{{ $role->nama_role }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <select name="status" class="form-control" aria-label="Filter status">
                    <option value="">Semua Status</option>
                    @foreach ([\App\Models\User::StatusAktif, \App\Models\User::StatusNonaktif] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-outline-primary btn-block"><i class="fas fa-search mr-1"></i> Cari</button>
            </div>
        </form>
    </div>

    <div class="card-body table-responsive p-0">
        <table class="table table-hover table-striped mb-0">
            <thead>
                <tr>
                    <th style="width: 50px">No</th>
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
                        <td>{{ $users->firstItem() + $loop->index }}</td>
                        <td>
                            {{ $user->nama ?? '-' }}
                            @if (auth()->user()->is($user))
                                <span class="badge badge-info ml-1">Anda</span>
                            @endif
                        </td>
                        <td><code>{{ $user->username }}</code></td>
                        <td>{{ $user->role?->nama_role }}</td>
                        <td>
                            <span class="badge {{ $user->isAktif() ? 'badge-success' : 'badge-secondary' }}">{{ $user->status }}</span>
                        </td>
                        <td>
                            @if ($user->must_change_password)
                                <span class="badge badge-warning">Belum diganti</span>
                            @else
                                <span class="badge badge-light">Sudah diganti</span>
                            @endif
                        </td>
                        <td>{{ $user->created_at?->translatedFormat('d M Y') }}</td>
                        <td class="text-right text-nowrap">
                            @can('update', $user)
                                <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-secondary" title="Ubah">
                                    <i class="fas fa-edit"></i> Ubah
                                </a>
                            @endcan
                            @can('delete', $user)
                                <form method="POST" action="{{ route('users.destroy', $user) }}" class="d-inline"
                                      onsubmit="return confirm('Hapus akun {{ $user->username }}? Tindakan ini tidak dapat dibatalkan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">Belum ada akun staff yang sesuai.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div class="card-footer clearfix">
            {{ $users->onEachSide(1)->links('pagination::bootstrap-4') }}
        </div>
    @endif
</div>
@endsection
