<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\BroadcastLogModel;
use App\Models\KendaraanModel;
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
                $this->formatPhoneNumber($this->recipient),
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
                $messageId = $response['data']['message_id'] ?? null;

                // Update status kendaraan
                $vehicle->update([
                    'status_broadcast' => 'antrian',
                    'message_id' => $messageId,
                    'tanggal_kirim' => null,
                    'keterangan_gagal' => null,
                ]);

                // Simpan log bahwa request sudah diterima gateway dan menunggu callback final.
                $this->saveBroadcastLog($vehicle, 'WA broadcast diterima gateway, menunggu callback', [
                    'status' => 'antrian',
                    'response' => $response
                ]);
            } else {
                $errorMsg = $response['message'] ?? 'Unknown error';

                // Update status gagal
                $vehicle->update([
                    'status_broadcast' => 'gagal',
                    'message_id' => null,
                    'keterangan_gagal' => $errorMsg,
                ]);

                // Simpan log gagal
                $this->saveBroadcastLog($vehicle, "Gagal mengirim WA broadcast: {$errorMsg}", [
                    'status' => 'gagal',
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
                    'message_id' => null,
                    'keterangan_gagal' => "Error after {$this->tries} attempts: " . $e->getMessage(),
                ]);
            } else {
                $vehicle->update([
                    'status_broadcast' => 'antrian', // Akan retry
                    'message_id' => null,
                    'keterangan_gagal' => "Retry {$this->attempts()}: " . $e->getMessage(),
                ]);
            }

            $this->saveBroadcastLog($vehicle, 'Error saat mengirim WA broadcast: ' . $e->getMessage(), [
                'status' => 'gagal',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function saveBroadcastLog(KendaraanModel $vehicle, string $message, array $result): void
    {
        $status = $result['status'] ?? 'gagal';

        BroadcastLogModel::create([
            'kendaraan_id' => $vehicle->id,
            'no_tujuan' => $vehicle->no_telepon,
            'pesan' => $message,
            'status' => $status,
            'response' => isset($result['response']) ? json_encode($result['response']) : ($result['error'] ?? null),
            'sent_at' => $status === 'terkirim' ? now() : null,
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
                'message_id' => null,
                'keterangan_gagal' => 'Permanent failure: ' . $exception->getMessage(),
            ]);
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }
}
