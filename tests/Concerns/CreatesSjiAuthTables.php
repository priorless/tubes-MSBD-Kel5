<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat tbl_roles & tbl_users versi minimal di database tes (SQLite), meniru sql/schema_sji.sql
 * ditambah kolom dari migrasi add_auth_columns_to_tbl_users_table.
 */
trait CreatesSjiAuthTables
{
    protected function setUpSjiAuthTables(): void
    {
        Schema::create('tbl_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nama_role', 50)->unique();
            $table->string('deskripsi')->nullable();
        });

        Schema::create('tbl_users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_karyawan')->nullable()->unique();
            $table->string('nama', 100)->nullable();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->unsignedInteger('id_role');
            $table->string('status')->default('Aktif');
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
