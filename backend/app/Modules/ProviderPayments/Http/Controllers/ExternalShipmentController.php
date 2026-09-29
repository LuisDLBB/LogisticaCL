<?php

namespace App\Modules\ProviderPayments\Http\Controllers;

use App\Models\ExternalShipment;
use App\Models\Tenant;
use App\Modules\ProviderPayments\Services\ExternalShipmentImporter;
use App\Modules\ProviderPayments\Services\ExternalShipmentIssueReport;
use App\Modules\ProviderPayments\Services\PaidExternalShipmentReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExternalShipmentController
{
    public function index(Request $request, PaidExternalShipmentReport $reports): View
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $base = ExternalShipment::query()->where('tenant_id', $tenant->id);
        $periods = (clone $base)->selectRaw("strftime('%Y-%m', fecha) AS period")
            ->distinct()->orderByDesc('period')->pluck('period');
        $period = trim((string) $request->query('period', $periods->first() ?? ''));
        $search = trim((string) $request->query('q', ''));
        $rows = (clone $base)
            ->when($period !== '', fn ($query) => $query->whereRaw("strftime('%Y-%m', fecha) = ?", [$period]))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('tracking_number', 'like', '%'.$search.'%')
                    ->orWhere('external_order_number', 'like', '%'.$search.'%')
                    ->orWhere('destination_locality_name', 'like', '%'.$search.'%')
                    ->orWhere('delivery_point', 'like', '%'.$search.'%')
                    ->orWhere('client_name_source', 'like', '%'.$search.'%')
                    ->orWhere('observacion', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('fecha')->orderByDesc('id')->paginate(100)->withQueryString();
        $paidExistingCount = $reports->countExisting($tenant->id);

        return view('provider-payments::external-shipments', compact('periods', 'period', 'search', 'rows', 'paidExistingCount'));
    }

    public function import(Request $request, ExternalShipmentImporter $importer, PaidExternalShipmentReport $reports, ExternalShipmentIssueReport $issueReports): RedirectResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:102400']])['file'];
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();
        $result = $importer->import($file->getRealPath(), $tenant->id);
        $reportToken = $reports->store($result['paid_rows'], $file->getClientOriginalName());
        $issueToken = $issueReports->store($result['issues'], $file->getClientOriginalName());
        if ($issueToken !== null) {
            $request->session()->push('external_issue_reports', $issueToken);
        }
        $summary = $result['created'] === 0 && $result['updated'] === 0
            ? ($result['issues'] === []
                ? sprintf('La planilla %s ya estaba cargada: %s envíos coinciden con los registrados. No se duplicaron ni se modificaron envíos.',
                    $file->getClientOriginalName(), number_format($result['unchanged'], 0, ',', '.'))
                : sprintf('La planilla %s se revisó: %s envíos ya estaban cargados sin cambios; %s filas quedaron pendientes. No hubo filas nuevas para cargar.',
                    $file->getClientOriginalName(), number_format($result['unchanged'], 0, ',', '.'),
                    number_format(count($result['issues']), 0, ',', '.')))
            : sprintf('La planilla %s se cargó: %s nuevos, %s actualizados y %s ya registrados sin cambios.',
                $file->getClientOriginalName(), number_format($result['created'], 0, ',', '.'),
                number_format($result['updated'], 0, ',', '.'), number_format($result['unchanged'], 0, ',', '.'));
        if ($result['issues'] !== [] && ($result['created'] > 0 || $result['updated'] > 0)) {
            $summary .= sprintf(' %s filas con problemas quedaron pendientes y no se guardaron.',
                number_format(count($result['issues']), 0, ',', '.'));
        }

        return redirect()->route('provider-payments.courier-movements.externos')
            ->with('paid_report_token', $reportToken)
            ->with('external_issue_report_token', $issueToken)
            ->with('external_import_issues', array_slice($result['issues'], 0, 10))
            ->with('status', $summary.sprintf(' Pagos activos marcados NO y Valor $ 0: %s. Atención: ya pagados: %s; con período cerrado: %s. Los pagos cerrados no se modificaron.',
                number_format($result['payments_excluded'], 0, ',', '.'), number_format($result['paid'], 0, ',', '.'),
                number_format($result['closed'], 0, ',', '.')));
    }

    public function downloadPaidReport(string $token, PaidExternalShipmentReport $reports): StreamedResponse
    {
        return $reports->download($token);
    }

    public function downloadIssueReport(Request $request, string $token, ExternalShipmentIssueReport $reports): StreamedResponse
    {
        abort_unless(in_array($token, $request->session()->get('external_issue_reports', []), true), 404);

        return $reports->download($token);
    }

    public function downloadExistingPaidReport(PaidExternalShipmentReport $reports): StreamedResponse
    {
        $tenant = Tenant::query()->where('code', '4N')->firstOrFail();

        return $reports->downloadExisting($tenant->id);
    }
}
