<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\OperationAccess;
use App\Modules\Operations\Services\OperationDashboardData;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationDashboardController extends Controller
{
    public function index(Request $request, OperationDashboardData $dashboard): View
    {
        $choices = [
            'period' => ['last', 'today', 'week'],
            'source' => ['all', 'system', 'excel'],
            'status' => ['all', 'approved', 'draft', 'reserve'],
            'group' => ['route', 'client'],
        ];
        $selected = [];
        foreach ($choices as $field => $allowed) {
            $requested = $request->query($field);
            $selected[$field] = is_string($requested) && in_array($requested, $allowed, true) ? $requested : $allowed[0];
        }

        return view('operations::dashboard', [
            'dashboard' => $dashboard->build(OperationAccess::tenant($request), $selected['period'], $selected['source'], $selected['status'], $selected['group']),
        ]);
    }
}
