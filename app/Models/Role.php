<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;

/**
 * Role pengguna dari tbl_roles. ID role tidak pernah di-hardcode; pencocokan selalu memakai nama_role.
 *
 * @property int $id
 * @property string $nama_role
 * @property string|null $deskripsi
 */
#[Table('tbl_roles', timestamps: false)]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const string Hrd = 'HRD';

    public const string Operasional = 'Operasional';

    public const string Keuangan = 'Keuangan';

    public const string KaryawanLapangan = 'Karyawan Lapangan';

    public const string Ceo = 'CEO';

    /**
     * Nama route halaman awal tiap role setelah login.
     *
     * @var array<string, string>
     */
    private const array HOME_ROUTES = [
        self::Hrd => 'hrd.dashboard',
        self::Operasional => 'operasional.monitoring',
        self::Keuangan => 'keuangan.payroll.index',
        self::Ceo => 'ceo.dashboard',
        self::KaryawanLapangan => 'tenaga-kerja.absensi',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['nama_role', 'deskripsi'];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'id_role');
    }

    /**
     * Role untuk akun staff kantor (semua role selain Karyawan Lapangan).
     *
     * @param  Builder<Role>  $query
     */
    public function scopeInternal(Builder $query): void
    {
        $query->where('nama_role', '!=', self::KaryawanLapangan);
    }

    /**
     * Role yang boleh diberikan oleh $actor: hanya role internal, dan hanya CEO yang boleh memberikan role CEO.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAssignableBy(Builder $query, User $actor): void
    {
        $query->internal()
            ->when(! $actor->hasRole(self::Ceo), fn (Builder $roleQuery) => $roleQuery->where('nama_role', '!=', self::Ceo));
    }

    /**
     * URL halaman awal role ini. Jika modul role tersebut belum memiliki route, arahkan ke beranda umum.
     */
    public function homeUrl(): string
    {
        $routeName = self::HOME_ROUTES[$this->nama_role] ?? null;

        if ($routeName !== null && Route::has($routeName)) {
            return route($routeName);
        }

        return route('beranda');
    }
}
