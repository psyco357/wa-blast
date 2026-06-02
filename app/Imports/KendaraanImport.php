<?php
// app/Imports/KendaraanImport.php

namespace App\Imports;

use App\Models\KendaraanModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class KendaraanImport
{
    private $successCount = 0;
    private $failCount = 0;
    private $errors = [];

    public function import(UploadedFile $file): void
    {
        $this->importFromPath($file->getPathname());
    }

    public function importFromPath(string $path): void
    {
        $this->successCount = 0;
        $this->failCount = 0;
        $this->errors = [];

        $worksheet = IOFactory::load($path)->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, false);

        if ($rows === []) {
            return;
        }

        $headers = array_map(function ($header) {
            return Str::of((string) $header)->trim()->lower()->snake()->toString();
        }, array_shift($rows));

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            try {
                $rowData = $this->mapRow($headers, $row);

                // Validasi data
                $validator = validator($rowData, [
                    'kode_wilayah' => 'required|string|max:20',
                    'jenis_roda' => 'required|in:r2,r4,r6',
                    'nomor_polisi' => 'required|string|max:20|unique:kendaraan,nomor_polisi',
                    'nama_pemilik' => 'required|string|max:255',
                    'tanggal_akhir_pajak' => 'required|date',
                    'no_telepon' => 'required|string|max:20',
                    'jumlah_tagihan' => 'required|numeric|min:0',
                ]);

                if ($validator->fails()) {
                    $this->failCount++;
                    $this->errors[] = [
                        'row' => $index + 2, // +2 karena mulai dari baris 2 (header baris 1)
                        'errors' => $validator->errors()->all()
                    ];
                    continue;
                }

                // Simpan data
                KendaraanModel::create([
                    'kode_wilayah' => $rowData['kode_wilayah'],
                    'jenis_roda' => $rowData['jenis_roda'],
                    'nomor_polisi' => $rowData['nomor_polisi'],
                    'nama_pemilik' => $rowData['nama_pemilik'],
                    'tanggal_akhir_pajak' => $rowData['tanggal_akhir_pajak'],
                    'no_telepon' => $rowData['no_telepon'],
                    'jumlah_tagihan' => $rowData['jumlah_tagihan'],
                    'status_broadcast' => 'pending'
                ]);

                $this->successCount++;
            } catch (\Exception $e) {
                $this->failCount++;
                $this->errors[] = [
                    'row' => $index + 2,
                    'errors' => [$e->getMessage()]
                ];
            }
        }
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapRow(array $headers, array $row): array
    {
        $row = array_pad($row, count($headers), null);

        return array_combine($headers, array_slice($row, 0, count($headers)));
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getFailCount()
    {
        return $this->failCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
