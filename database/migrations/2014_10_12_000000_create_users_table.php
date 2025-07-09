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
        Schema::create('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // Telegram ID asosiy kalit sifatida
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('language_code')->nullable();
            $table->enum('role', ['user', 'admin'])->default('user'); // foydalanuvchi roli
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
