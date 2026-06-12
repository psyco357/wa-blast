<?php

namespace App\Jobs;

use App\Imports\KendaraanImport;
use App\Models\ImportHistoryModel;
use App\Models\KendaraanModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessKendaraanImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    private ?int $importHistoryId = null;

    public function __construct(
        private readonly string $path,
        private readonly string $mode,
        private readonly string $originalName,
        private readonly string $disk = 'local',
        ?int $importHistoryId = null,
    ) {
        $this->importHistoryId = $importHistoryId;
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $fullPath = Storage::disk($this->disk)->path($this->path);
        $importHistory = $this->importHistoryId !== null
            ? ImportHistoryModel::find($this->importHistoryId)
            : null;

        $importHistory?->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            if ($this->mode === 'csv') {
                $result = $this->importCsv($fullPath);
            } else {
                $import = new KendaraanImport();
                $import->importFromPath($fullPath);

                $result = [
                    'success_count' => $import->getSuccessCount(),
                    'fail_count' => $import->getFailCount(),
                    'errors' => $import->getErrors(),
                ];
            }

            $importHistory?->update([
                'status' => 'completed',
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count'],
                'errors' => $result['errors'],
                'finished_at' => now(),
            ]);

            Log::info('Kendaraan import selesai.', [
                'import_id' => $this->importHistoryId,
                'file' => $this->originalName,
                'mode' => $this->mode,
                'success_count' => $result['success_count'],
                'fail_count' => $result['fail_count'],
                'errors' => $result['errors'],
            ]);
        } catch (Throwable $exception) {
            $importHistory?->update([
                'status' => 'failed',
                'errors' => [[
                    'row' => null,
                    'errors' => [$exception->getMessage()],
                ]],
                'finished_at' => now(),
            ]);

            Log::error('Kendaraan import gagal.', [
                'import_id' => $this->importHistoryId,
                'file' => $this->originalName,
                'mode' => $this->mode,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->importHistoryId === null) {
            return;
        }

        $importHistory = ImportHistoryModel::find($this->importHistoryId);

        if ($importHistory === null || $importHistory->status === 'completed') {
            return;
        }

        $importHistory->update([
            'status' => 'failed',
            'finished_at' => now(),
            'errors' => $exception === null
                ? $importHistory->errors
                : [[
                    'row' => null,
                    'errors' => [$exception->getMessage()],
                ]],
        ]);
    }

    private function importCsv(string $fullPath): array
    {
        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('File CSV tidak bisa dibuka.');
        }

        fgetcsv($handle);

        $successCount = 0;
        $failCount = 0;
        $errors = [];
        $rowNumber = 2;

        while (($data = fgetcsv($handle)) !== false) {
            try {
                $vehicleData = [
                    'kode_wilayah' => $data[0] ?? null,
                    'jenis_roda' => $data[1] ?? null,
                    'nomor_polisi' => $data[2] ?? null,
                    'nama_pemilik' => $data[3] ?? null,
                    'tanggal_akhir_pajak' => $data[4] ?? null,
                    'no_telepon' => $data[5] ?? null,
                    'jumlah_tagihan' => $data[6] ?? null,
                ];

                $validator = validator($vehicleData, [
                    'kode_wilayah' => 'required|string|max:20',
                    'jenis_roda' => 'required|in:r2,r4,r6',
                    'nomor_polisi' => 'required|string|max:20|unique:kendaraan,nomor_polisi',
                    'nama_pemilik' => 'required|string|max:255',
                    'tanggal_akhir_pajak' => 'required|date',
                    'no_telepon' => 'required|string|max:20',
                    'jumlah_tagihan' => 'required|numeric|min:0',
                ]);

                if ($validator->fails()) {
                    $failCount++;
                    $errors[] = [
                        'row' => $rowNumber,
                        'errors' => $validator->errors()->all(),
                    ];
                    $rowNumber++;
                    continue;
                }

                KendaraanModel::create([
                    'kode_wilayah' => $vehicleData['kode_wilayah'],
                    'jenis_roda' => $vehicleData['jenis_roda'],
                    'nomor_polisi' => $vehicleData['nomor_polisi'],
                    'nama_pemilik' => $vehicleData['nama_pemilik'],
                    'tanggal_akhir_pajak' => $vehicleData['tanggal_akhir_pajak'],
                    'no_telepon' => $vehicleData['no_telepon'],
                    'jumlah_tagihan' => $vehicleData['jumlah_tagihan'],
                    'status_broadcast' => 'belum_dikirim',
                ]);

                $successCount++;
                $rowNumber++;
            } catch (\Exception $e) {
                $failCount++;
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => [$e->getMessage()],
                ];
                $rowNumber++;
            }
        }

        fclose($handle);

        return [
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'errors' => $errors,
        ];
    }
}
