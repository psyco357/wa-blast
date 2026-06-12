<?php
// app/Http/Controllers/Api/KendaraanController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KendaraanModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KendaraanController extends Controller
{
    public function index(Request $request)
    {
        // dd($request->all());
        $query = KendaraanModel::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status_broadcast', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('tanggal_akhir_pajak', [$request->start_date, $request->end_date]);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nomor_polisi', 'LIKE', "%{$search}%")
                    ->orWhere('nama_pemilik', 'LIKE', "%{$search}%")
                    ->orWhere('no_telepon', 'LIKE', "%{$search}%");
            });
        }
        $perPage = $request->input('per_page', 15);
        $vehicles = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $vehicles
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'kode_wilayah' => 'required|string|max:20',
            'jenis_roda' => 'required|in:r2,r4,r6',
            'nomor_polisi' => 'required|string|max:20|unique:kendaraan',
            'nama_pemilik' => 'required|string|max:255',
            'tanggal_akhir_pajak' => 'required|date',
            'no_telepon' => 'required|string|max:20',
            'jumlah_tagihan' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $vehicle = KendaraanModel::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil ditambahkan',
            'data' => $vehicle
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $vehicle = KendaraanModel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'kode_wilayah' => 'string|max:20',
            'jenis_roda' => 'in:r2,r4,r6',
            'nomor_polisi' => 'string|max:20|unique:kendaraan_models,nomor_polisi,' . $id,
            'nama_pemilik' => 'string|max:255',
            'tanggal_akhir_pajak' => 'date',
            'no_telepon' => 'string|max:20',
            'jumlah_tagihan' => 'numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $vehicle->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diupdate',
            'data' => $vehicle
        ]);
    }

    public function destroy($id)
    {
        $vehicle = KendaraanModel::findOrFail($id);
        $vehicle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil dihapus'
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $vehicle = KendaraanModel::findOrFail($id);
        $vehicle->update([
            'status_broadcast' => $request->status,
            'keterangan_gagal' => $request->keterangan_gagal
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status berhasil diupdate'
        ]);
    }
}
