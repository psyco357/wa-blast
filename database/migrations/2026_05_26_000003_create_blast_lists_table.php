<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blast_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->string('phone_number', 30);
            $table->text('message');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // $table->index('user_id');
            // $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blast_lists');
    }
};
