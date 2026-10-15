<?php

use App\Enums\Perfil;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Login por nome de usuário: nem todo caseiro tem e-mail.
            $table->string('usuario')->nullable()->unique()->after('name');
            $table->enum('perfil', Perfil::values())->default(Perfil::CASEIRO->value)->after('usuario');
            $table->boolean('ativo')->default(true)->after('perfil');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['usuario']);
            $table->dropColumn(['usuario', 'perfil', 'ativo']);
        });
    }
};
