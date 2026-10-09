<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sql_schemas', function (Blueprint $table) {
            $table->id();
            // Автор, если он был залогинен. Без внешнего ключа: у приложения может
            // не быть таблицы users, а id бывают и числами, и UUID.
            $table->string('user_id', 64)->nullable()->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('dialect', 32)->default('laravel');
            $table->json('canvas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_schemas');
    }
};
