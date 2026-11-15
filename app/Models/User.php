<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;

/**
 * Akun login dari tbl_users.
 *
 * @property int $id
 * @property int|null $id_karyawan
 * @property string|null $nama
 * @property string $username
 * @property string $password
 * @property int $id_role
 * @property string $status
 * @property bool $must_change_password
 * @property-read Role $role
 */
#[Fillable(['id_karyawan', 'nama', 'username', 'password', 'id_role', 'status', 'must_change_password'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const string StatusAktif = 'Aktif';

    public const string StatusNonaktif = 'Nonaktif';

    /**
     * tbl_users hanya memiliki created_at.
     */
    public const UPDATED_AT = null;

    protected $table = 'tbl_users';

    /**
     * tbl_users tidak memiliki kolom remember_token, sehingga fitur "ingat saya" dinonaktifkan.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Kolom tambahan dari migrasi add_auth_columns_to_tbl_users_table.
     * Skema asli sql/schema_sji.sql tidak memilikinya, jadi kolom ini hanya ditulis bila memang ada.
     *
     * @var list<string>
     */
    public const array KOLOM_OPSIONAL = ['nama', 'must_change_password'];

    /**
     * Buang atribut kolom opsional yang belum ada di tbl_users sebelum INSERT/UPDATE,
     * agar login, ganti password, dan manajemen user tetap berjalan pada skema asli.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            foreach (self::KOLOM_OPSIONAL as $kolom) {
                if (! self::punyaKolom($kolom)) {
                    $user->offsetUnset($kolom);
                }
            }
        });
    }

    /**
     * Apakah tbl_users pada koneksi aktif memiliki kolom tersebut.
     */
    public static function punyaKolom(string $kolom): bool
    {
        return Schema::hasColumn((new self)->getTable(), $kolom);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'id_role');
    }

    /**
     * Akun staff kantor (bukan Karyawan Lapangan).
     *
     * @param  Builder<User>  $query
     */
    public function scopeInternal(Builder $query): void
    {
        $query->whereHas('role', fn (Builder $roleQuery) => $roleQuery->internal());
    }

    public function hasRole(string ...$namaRoles): bool
    {
        return in_array($this->role?->nama_role, $namaRoles, true);
    }

    public function isAktif(): bool
    {
        return $this->status === self::StatusAktif;
    }

    public function namaTampilan(): string
    {
        return $this->nama ?: $this->username;
    }

    public function homeUrl(): string
    {
        return $this->role?->homeUrl() ?? route('beranda');
    }
}
