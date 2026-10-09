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
        Schema::create('sql_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sql_schema_id');
            $table->uuid('uid')->index();
            $table->string('name');
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->string('color', 32)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('sql_schema_id')->references('id')->on('sql_schemas')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_tables');
    }
};
