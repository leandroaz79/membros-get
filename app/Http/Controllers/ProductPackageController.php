<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductPackage\ProductExportService;
use App\Services\ProductPackage\ProductImportService;
use App\Services\TeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductPackageController extends Controller
{
    public function export(Request $request, Product $produto, ProductExportService $exportService): BinaryFileResponse|JsonResponse
    {
        $this->authorizeProduct($produto);

        $validated = $request->validate([
            'include_media' => ['sometimes', 'boolean'],
        ]);
        $includeMedia = array_key_exists('include_media', $validated)
            ? (bool) $validated['include_media']
            : true;

        try {
            $result = $exportService->export($produto, $includeMedia);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Falha ao exportar o produto.',
            ], 422);
        }

        return response()->download(
            $result['path'],
            $result['filename'],
            [
                'Content-Type' => 'application/zip',
                'X-Getfy-Package-Warnings' => implode(' | ', array_slice($result['warnings'], 0, 5)),
            ]
        )->deleteFileAfterSend(true);
    }

    public function preview(Request $request, ProductImportService $importService): JsonResponse
    {
        $request->validate([
            'package' => ['required', 'file', 'max:512000'],
        ]);

        $file = $request->file('package');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['zip', 'getfy-product'], true)
            && ! str_ends_with(strtolower($file->getClientOriginalName()), '.getfy-product')) {
            return response()->json([
                'success' => false,
                'message' => 'Envie um arquivo .getfy-product ou .zip.',
            ], 422);
        }

        $result = $importService->preview($file);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function import(Request $request, ProductImportService $importService): JsonResponse
    {
        $request->validate([
            'package' => ['required', 'file', 'max:512000'],
        ]);

        $file = $request->file('package');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($ext, ['zip', 'getfy-product'], true)
            && ! str_ends_with(strtolower($file->getClientOriginalName()), '.getfy-product')) {
            return response()->json([
                'success' => false,
                'message' => 'Envie um arquivo .getfy-product ou .zip.',
            ], 422);
        }

        $tenantId = (int) auth()->user()->tenant_id;
        $result = $importService->import($file, $tenantId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    private function authorizeProduct(Product $produto): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if (auth()->user()->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
