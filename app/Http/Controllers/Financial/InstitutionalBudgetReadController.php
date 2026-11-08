<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\{InstitutionalBudgetReadAccess,InstitutionalObligationQueryService};
use App\Domains\Financial\Models\InstitutionalBonusBudget;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use Throwable;

final class InstitutionalBudgetReadController extends Controller
{
    public function index(Request $request, InstitutionalBudgetReadAccess $access, InstitutionalObligationQueryService $query)
    {
        abort_if(array_diff(array_keys($request->query()),['budget','business','page','per_page','view']),422,'Filtros no admitidos.');
        $data=$request->validate(['budget'=>['nullable','uuid'],'business'=>['nullable','string','max:100','regex:/\A[^\s\x00-\x1f\x7f]+\z/u'],
            'page'=>['sometimes','regex:/\A[1-9][0-9]{0,6}\z/','integer','max:1000000'],
            'per_page'=>['sometimes','regex:/\A[1-9][0-9]?\z/','integer','min:1','max:20'],
            'view'=>['sometimes','in:businesses,obligations,settlements']]);
        $budget=isset($data['budget']) && $data['budget']!=='' ? strtolower($data['budget']) : null;
        $business=isset($data['business']) && $data['business']!=='' ? $data['business'] : null;
        $view=$data['view'] ?? 'businesses';
        abort_if($business !== null && strlen($business)>100,422,'Identificador de negocio demasiado largo.');
        abort_if($budget===null && ($business!==null || $view!=='businesses'),422,'Selecciona un presupuesto para consultar su detalle.');
        try { $access->authorize($request,$budget); }
        catch (AuthorizationException $e) { throw $e; }
        catch (Throwable $e) { return response('La autorizacion de lectura no esta disponible.',503)->header('Cache-Control','no-store, private'); }
        try {
            $report=$query->report($budget,$business,(int)($data['page']??1),(int)($data['per_page']??10),$view==='obligations',$view==='settlements');
            $ids=array_column($report['budgets'],'budget_id');
            $labels=InstitutionalBonusBudget::whereIn('public_id',$ids)->get(['public_id','purpose','institution_reference'])
                ->map(fn($b)=>['budget_id'=>strtolower($b->public_id),'purpose'=>$b->purpose,'institution_reference'=>$b->institution_reference])->all();
        } catch (Throwable $e) { return response('La consulta de presupuestos no esta disponible. Intenta nuevamente mas tarde.',503)->header('Cache-Control','no-store, private'); }
        $response=Inertia::render('Financial/InstitutionalBudgets',['report'=>$report,'labels'=>$labels,'canListAll'=>$access->canList($request),
            'filters'=>['budget'=>$budget,'business'=>$business,'page'=>$report['page'],'per_page'=>$report['per_page'],'view'=>$view]])->toResponse($request);
        $response->headers->set('Cache-Control','no-store, private');
        return $response;
    }
}
