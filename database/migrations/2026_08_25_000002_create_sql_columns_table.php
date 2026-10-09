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
        Schema::create('sql_columns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sql_table_id');
            $table->uuid('uid')->index();
            $table->integer('position')->default(0);
            $table->string('name');
            $table->string('type', 64)->default('string');
            $table->string('length', 32)->nullable();
            $table->boolean('nullable')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_index')->default(false);
            $table->boolean('is_auto_increment')->default(false);
            $table->string('default_value')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('sql_table_id')->references('id')->on('sql_tables')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_columns');
    }
};
