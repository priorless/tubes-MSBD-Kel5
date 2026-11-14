<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * CRUD akun staff internal (tbl_users). Akun karyawan lapangan tidak dikelola di sini.
 */
class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.User::StatusAktif.','.User::StatusNonaktif],
        ]);

        $cari = $request->string('cari')->trim()->toString();

        $users = User::query()
            ->internal()
            ->with('role')
            ->when($cari !== '', fn (Builder $query) => $query->where(
                fn (Builder $searchQuery) => $searchQuery
                    ->where('nama', 'like', "%{$cari}%")
                    ->orWhere('username', 'like', "%{$cari}%"),
            ))
            ->when($request->filled('role'), fn (Builder $query) => $query->where('id_role', $request->integer('role')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->orderBy('nama')
            ->orderBy('username')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::query()->internal()->orderBy('nama_role')->get(),
            'filters' => $request->only(['cari', 'role', 'status']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', [
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::query()->create([
            'nama' => $request->validated('nama'),
            'username' => $request->validated('username'),
            'id_role' => $request->validated('id_role'),
            'password' => Hash::make($request->validated('password')),
            'status' => User::StatusAktif,
            'must_change_password' => true,
        ]);

        return redirect()->route('users.index')
            ->with('success', "Akun {$user->username} berhasil dibuat. User wajib mengganti password saat login pertama.");
    }

    public function edit(Request $request, User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.edit', [
            'user' => $user->load('role'),
            'roles' => $this->assignableRoles($request->user()),
            'isEditingSelf' => $request->user()->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['nama', 'username', 'id_role', 'status']));

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
            $user->must_change_password = true;
        }

        $user->save();

        return redirect()->route('users.index')
            ->with('success', "Akun {$user->username} berhasil diperbarui.");
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        try {
            $user->delete();
        } catch (QueryException $exception) {
            // 23000 = akun masih direferensikan data lain (mis. tbl_penempatan.dibuat_oleh).
            if (($exception->errorInfo[0] ?? null) === '23000') {
                return back()->with('error', "Akun {$user->username} tidak dapat dihapus karena sudah tercatat di data transaksi. Nonaktifkan akun ini sebagai gantinya.");
            }

            if (DatabaseService::isPesanBisnis($exception)) {
                return back()->with('error', DatabaseService::pesanError($exception));
            }

            throw $exception;
        }

        return redirect()->route('users.index')
            ->with('success', "Akun {$user->username} berhasil dihapus.");
    }

    /**
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $actor): Collection
    {
        return Role::query()->assignableBy($actor)->orderBy('nama_role')->get();
    }
}
