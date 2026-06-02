<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\BroadcastLogModel;
use App\Models\KendaraanModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Helpers\TemplateWaBlast;
use Throwable;

class ProcessTemplateBlastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $recipient;
    public string $nama;
    public string $no_pol;
    public string $media_template;

    public int $tries = 3;
    public int $timeout = 120;
    public int $backoff = 60; // Delay retry 60 detik

    public function __construct(string $recipient, string $nama, string $no_pol, string $media_template)
    {
        $this->recipient = $recipient;
        $this->nama = $nama;
        $this->no_pol = $no_pol;
        $this->media_template = $media_template;
    }

    public function handle()
    {
        // Cari data kendaraan
        $vehicle = KendaraanModel::where('no_telepon', $this->recipient)
            ->where('nomor_polisi', $this->no_pol)
            ->first();

        if (!$vehicle) {
            Log::error('Kendaraan tidak ditemukan', [
                'recipient' => $this->recipient,
                'no_pol' => $this->no_pol
            ]);
            return;
        }

        try {
            // Kirim WA via helper
            $response = TemplateWaBlast::templateKuningan(
                $this->recipient,
                $this->nama,
                $this->no_pol,
                $this->media_template,
            );

            Log::info('WA broadcast response', [
                'recipient' => $this->recipient,
                'response' => $response,
            ]);

            // Cek response dari API
            if (isset($response['error']) && $response['error'] === false) {
                // Update status kendaraan
                $vehicle->update([
                    'status_broadcast' => 'terkirim',
                    'tanggal_kirim' => now(),
                    'keterangan_gagal' => null,
                ]);

                // Simpan log sukses
                $this->saveBroadcastLog($vehicle, 'WA broadcast berhasil dikirim', [
                    'success' => true,
                    'response' => $response
                ]);
            } else {
                $errorMsg = $response['message'] ?? 'Unknown error';

                // Update status gagal
                $vehicle->update([
                    'status_broadcast' => 'gagal',
                    'keterangan_gagal' => $errorMsg,
                ]);

                // Simpan log gagal
                $this->saveBroadcastLog($vehicle, "Gagal mengirim WA broadcast: {$errorMsg}", [
                    'success' => false,
                    'response' => $response
                ]);

                // Throw exception untuk retry jika masih ada attempt
                if ($this->attempts() < $this->tries) {
                    throw new \Exception($errorMsg);
                }
            }
        } catch (Throwable $e) {
            Log::error('SendWaJob failed', [
                'recipient' => $this->recipient,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Update status error jika sudah attempt terakhir
            if ($this->attempts() >= $this->tries) {
                $vehicle->update([
                    'status_broadcast' => 'gagal',
                    'keterangan_gagal' => "Error after {$this->tries} attempts: " . $e->getMessage(),
                ]);
            } else {
                $vehicle->update([
                    'status_broadcast' => 'pending', // Akan retry
                    'keterangan_gagal' => "Retry {$this->attempts()}: " . $e->getMessage(),
                ]);
            }

            $this->saveBroadcastLog($vehicle, 'Error saat mengirim WA broadcast: ' . $e->getMessage(), [
                'success' => false,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function saveBroadcastLog(KendaraanModel $vehicle, string $message, array $result): void
    {
        BroadcastLogModel::create([
            'kendaraan_id' => $vehicle->id,
            'no_tujuan' => $vehicle->no_telepon,
            'pesan' => $message,
            'status' => ($result['success'] ?? false) ? 'terkirim' : 'gagal',
            'response' => isset($result['response']) ? json_encode($result['response']) : ($result['error'] ?? null),
            'sent_at' => ($result['success'] ?? false) ? now() : null,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Broadcast job failed permanently', [
            'recipient' => $this->recipient,
            'no_pol' => $this->no_pol,
            'error' => $exception->getMessage(),
        ]);

        // Update final status
        $vehicle = KendaraanModel::where('no_telepon', $this->recipient)
            ->where('nomor_polisi', $this->no_pol)
            ->first();

        if ($vehicle) {
            $vehicle->update([
                'status_broadcast' => 'gagal',
                'keterangan_gagal' => 'Permanent failure: ' . $exception->getMessage(),
            ]);
        }
    }
}
