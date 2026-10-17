<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\WorkOrder;
use App\Services\LabelService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Yazdırılabilir etiket sayfaları (Aşama 12). Panel menüsü yoktur; sayfa açılınca yazdırma penceresi gelir.
 * Kaydı görmeye yetkisi olmayan kullanıcı 404 alır (operatör başkasının iş emrini panelde de göremez).
 */
class LabelController
{
    public function __construct(private readonly LabelService $labels) {}

    /** Seçili motorlar (?ids=1,2,3) veya bir iş emrinin tüm motorları (?work_order=5). */
    public function motors(Request $request): View
    {
        $workOrderId = $request->integer('work_order') ?: null;

        if ($workOrderId !== null) {
            $workOrder = WorkOrder::query()->findOrFail($workOrderId);
            abort_unless($request->user()->can('view', $workOrder), 404);
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));
        $units = $this->labels->motorsFor($ids, $workOrderId);

        abort_if($units->isEmpty(), 404, 'Etiketi yazdırılacak motor bulunamadı.');
        abort_if($units->contains(fn ($unit) => ! $request->user()->can('view', $unit)), 404);

        return $this->render($request, fn () => $this->labels->motorLabels($units, $request->user()), 'Motor etiketleri');
    }

    public function workOrder(Request $request, WorkOrder $workOrder): View
    {
        abort_unless($request->user()->can('view', $workOrder), 404);

        $copies = max(1, min(50, $request->integer('copies', 1)));

        return $this->render($request, fn () => $this->labels->workOrderLabels($workOrder, $request->user(), $copies), "İş emri etiketi {$workOrder->work_order_number}");
    }

    private function render(Request $request, callable $build, string $title): View
    {
        $layouts = config('labels.layouts');
        $layoutKey = array_key_exists((string) $request->query('layout'), $layouts) ? (string) $request->query('layout') : config('labels.default_layout');

        try {
            $labels = $build();
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }

        $layout = $layouts[$layoutKey];
        $perPage = $layout['columns'] * $layout['rows'];

        return view('labels.print', [
            'title' => $title,
            'company' => config('labels.company'),
            'layouts' => $layouts,
            'layoutKey' => $layoutKey,
            'layout' => $layout,
            'pages' => array_chunk($labels, $perPage),
            'count' => count($labels),
        ]);
    }
}
