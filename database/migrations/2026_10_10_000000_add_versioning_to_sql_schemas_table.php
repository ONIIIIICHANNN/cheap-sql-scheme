<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sql_schemas', function (Blueprint $table) {
            // database — схема, собранная из базы самого проекта.
            $table->string('source', 32)->nullable()->after('dialect');
            // Снимок структуры на момент последней синхронизации или коммита —
            // от него считаются изменения для новой миграции.
            $table->json('baseline')->nullable()->after('canvas');
            $table->timestamp('committed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sql_schemas', function (Blueprint $table) {
            $table->dropColumn(['source', 'baseline', 'committed_at']);
        });
    }
};
