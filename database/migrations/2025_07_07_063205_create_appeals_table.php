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
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete(); // foydalanuvchi o‘chirilsa, appeal ham o‘chadi

            $table->string('role'); // employee, parent, other
            $table->text('message');
            $table->unsignedBigInteger('telegram_message_id')->nullable(); // Telegram xabarning IDsi
            $table->boolean('is_reviewed')->default(false);

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
