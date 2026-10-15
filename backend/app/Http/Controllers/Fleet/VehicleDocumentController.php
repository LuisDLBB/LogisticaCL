<?php

namespace App\Http\Controllers\Fleet;

use App\Fleet\FleetAccess;
use App\Fleet\VehicleCatalog;
use App\Fleet\VehicleCertificateParser;
use App\Fleet\VehicleCertificateReader;
use App\Fleet\VehicleCertificateUpload;
use App\Fleet\VehicleDocuments;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VehicleDocumentController extends Controller
{
    public function store(Request $request, Vehicle $vehicle, FleetAccess $access, VehicleCatalog $catalog, VehicleCertificateUpload $upload, VehicleCertificateReader $reader, VehicleCertificateParser $parser): RedirectResponse
    {
        $tenant = $access->tenant($request);
        $owner = $catalog->owner($vehicle, Tenant::all());
        abort_unless($owner && $catalog->canView($owner, $tenant, $catalog->canSeeSharedPool($request->user(), $tenant, $access), false), 404);
        abort_unless($access->allows($request->user(), $tenant, 'operations.fleet', 2) && $access->allows($request->user(), $owner, 'operations.fleet', 2), 403);
        $request->validate(['document' => ['required', 'file', 'mimes:pdf', 'max:10240']]);
        $message = $upload->save($request->file('document'), $vehicle, $owner, $request->user(), $reader, $parser);

        return redirect()->route('fleet.vehicles.show', $vehicle)->with('status', $message);
    }

    public function show(Request $request, Vehicle $vehicle, int $document, FleetAccess $access, VehicleCatalog $catalog, VehicleDocuments $documents): BinaryFileResponse
    {
        $tenant = $access->tenant($request);
        $owner = $catalog->owner($vehicle, Tenant::all());
        abort_unless($owner && $catalog->canView($owner, $tenant, $catalog->canSeeSharedPool($request->user(), $tenant, $access), false), 404);
        abort_unless($access->allows($request->user(), $owner, 'operations.fleet'), 403);
        $record = $documents->query($vehicle, $owner)->where('documents.id', $document)->first();
        abort_unless($record && preg_match('/^[a-f0-9]{64}$/', $record->sha256) && $record->storage_path === 'fleet/documents/'.$record->sha256.'.pdf', 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($record->storage_path), 404);

        return response()->file($disk->path($record->storage_path), ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
