<?php
namespace App\Http\Controllers\Financial;
use App\Http\Controllers\Controller;
use App\Domains\Financial\Services\{InstitutionalBudgetReadAccess,InstitutionalBudgetHistoryQuery};
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

final class InstitutionalBudgetHistoryController extends Controller
{
    public function index(Request $request,string $budgetId,InstitutionalBudgetReadAccess $access,InstitutionalBudgetHistoryQuery $query)
    {
        abort_unless(\Illuminate\Support\Str::isUuid($budgetId),422,'Presupuesto no valido.');
        abort_if(array_diff(array_keys($request->query()),['action','per_page','before']),422,'Filtros no admitidos.');
        $filters=$request->validate(['action'=>['nullable',Rule::in(InstitutionalBudgetHistoryQuery::ACTIONS)],
            'per_page'=>['sometimes','regex:/\A[1-9][0-9]?\z/','integer','min:1','max:20'],
            'before'=>['nullable','regex:/\A[1-9][0-9]{0,17}\z/']]);
        $budgetId=strtolower($budgetId);
        try{$access->authorize($request,$budgetId);}
        catch(AuthorizationException $e){throw $e;}
        catch(Throwable){return response('La autorizacion de lectura no esta disponible.',503)->header('Cache-Control','no-store, private');}
        try{$history=$query->report($budgetId,$filters['action']??null,(int)($filters['per_page']??10),$filters['before']??null);}
        catch(ModelNotFoundException $e){throw $e;}
        catch(Throwable){return response('El historial presupuestario no esta disponible.',503)->header('Cache-Control','no-store, private');}
        $response=Inertia::render('Financial/InstitutionalBudgetHistory',['history'=>$history,
            'actions'=>InstitutionalBudgetHistoryQuery::ACTIONS,
            'filters'=>['action'=>$filters['action']??null,'per_page'=>(int)($filters['per_page']??10)]])->toResponse($request);
        $response->headers->set('Cache-Control','no-store, private');return $response;
    }
}
