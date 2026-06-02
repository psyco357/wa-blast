<?php
// database/migrations/2024_01_01_create_kendaraan_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kendaraan', function (Blueprint $table) {
            $table->id();
            $table->string('kode_wilayah', 20);
            $table->string('jenis_roda', 5);
            $table->string('nomor_polisi', 20)->unique();
            $table->string('nama_pemilik', 255);
            $table->date('tanggal_akhir_pajak');
            $table->string('no_telepon', 20);
            $table->decimal('jumlah_tagihan', 15, 2);
            $table->enum('status_broadcast', ['pending', 'terkirim', 'gagal'])->default('pending');
            $table->text('pesan_blast')->nullable();
            $table->timestamp('tanggal_kirim')->nullable();
            $table->text('keterangan_gagal')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kendaraan_id')->constrained('kendaraan')->onDelete('cascade');
            $table->string('no_tujuan');
            $table->text('pesan');
            $table->enum('status', ['pending', 'terkirim', 'gagal']);
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kendaraan_id')->constrained('kendaraan')->onDelete('cascade');
            $table->string('nomor_wa');
            $table->text('pesan_masuk');
            $table->text('pesan_keluar')->nullable();
            $table->enum('jenis', ['incoming', 'outgoing']);
            $table->timestamp('waktu_pesan');
            $table->boolean('dibaca')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('broadcast_logs');
        Schema::dropIfExists('kendaraan');
    }
};
