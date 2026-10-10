<?php
namespace App\Http\Controllers;
use App\Domains\CampusAuthorization\AuthorityConflict;
use App\Models\User;
use CampusDigital\Authorization\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
final class CampusAuthorizationController extends Controller {
    public function __construct(private Kernel $kernel) {}
    private function actor(Request $r,bool $write=false): string {
        abort_unless($r->user(),401);
        abort_unless(config('campus_authorization.enabled')===true,403);
        $id=$this->kernel->principalFor((string)$r->user()->getKey());
        abort_unless($id && $this->kernel->check($id,'campus.roles.manage')->allowed(),403);
        if ($write) abort_unless($r->user()->two_factor_secret && $r->user()->two_factor_confirmed_at,403,'Confirma el segundo factor para modificar autorizaciones.');
        return $id;
    }
    public function index(Request $r) {
        $this->actor($r);
        return Inertia::render('Authorization/Administration',['modules'=>Kernel::MODULES,'catalog'=>config('campus_authorization.catalog'),'twoFactorReady'=>(bool)($r->user()->two_factor_secret && $r->user()->two_factor_confirmed_at)]);
    }
    public function records(Request $r) {
        $this->actor($r); $s=$this->kernel->snapshot(); $people=[];
        $ids=array_values($s['seats']); foreach ($s['grants'] as $g) $ids[]=$g['principal'];
        foreach (array_unique($ids) as $id) {
            $p=$this->kernel->principalDetails($id); $u=$p ? User::find($p['identity']) : null;
            $people[$id]=['name'=>$u?->name ?? 'Cuenta retirada','email'=>$u?->email,'active'=>$p['active']??false];
        }
        return response()->json(['data'=>['seats'=>$s['seats'],'grants'=>$s['grants'],'version'=>$s['version'],'events'=>array_reverse(array_slice($s['events'],-50)),'people'=>$people]]);
    }
    public function lookup(Request $r) {
        $this->actor($r); $d=$r->validate(['email'=>['required','email','max:255']]);
        $u=User::where('email',$d['email'])->whereNull('deleted_at')->firstOrFail();
        return response()->json(['data'=>['identity'=>(string)$u->getKey(),'name'=>$u->name,'email'=>$u->email,'principal'=>$this->kernel->principalFor((string)$u->getKey())]]);
    }
    public function contexts(Request $r) {
        $this->actor($r); $d=$r->validate(['type'=>['required',Rule::in(['business','association','service','council'])],'search'=>['nullable','string','max:100']]);
        $q=DB::connection('mongodb')->table('campus_authorization_contexts')->where('type',$d['type'])->where('active',true);
        if (!empty($d['search'])) $q->where('reference',$d['search']);
        return response()->json(['data'=>$q->limit(100)->get(['type','reference','label'])->map(function($row) { $a=(array)$row; return ['type'=>$a['type'],'id'=>$a['reference'],'label'=>$a['label']??$a['reference']]; })->values()]);
    }
    public function seat(Request $r) {
        $actor=$this->actor($r,true); $d=$r->validate($this->base()+['identity'=>['required','regex:/\A[a-f0-9]{24}\z/i'],'module'=>['required','integer','between:1,7']]);
        return $this->change($r,$actor,$d,fn()=> $this->kernel->seat($actor,(int)$d['module'],$this->kernel->register($d['identity']),$d['reason']));
    }
    public function release(Request $r,int $module) {
        $actor=$this->actor($r,true); $d=$r->validate($this->base());
        return $this->change($r,$actor,$d,fn()=> $this->kernel->releaseSeat($actor,$module,$d['reason']));
    }
    public function grant(Request $r) {
        $actor=$this->actor($r,true); $d=$r->validate($this->base()+[
            'identity'=>['required','regex:/\A[a-f0-9]{24}\z/i'],'module'=>['required','integer','between:1,7'],
            'capabilities'=>['required','array','min:1','max:100'],'capabilities.*'=>['required','string','distinct',Rule::in(array_keys(config('campus_authorization.catalog')))],
            'all_contexts'=>['required','boolean'],'scope_type'=>['nullable',Rule::in(['business','association','service','council']),'required_with:scope_id'],
            'scope_id'=>['nullable','string','max:128','required_with:scope_type'],'starts_at'=>['nullable','date'],'ends_at'=>['nullable','date','after:now',Rule::when($r->filled('starts_at'),'after:starts_at')],
        ]);
        $scope=isset($d['scope_type'],$d['scope_id']) ? ['type'=>$d['scope_type'],'id'=>$d['scope_id']] : null;
        return $this->change($r,$actor,$d,fn()=> $this->kernel->grant($actor,$this->kernel->register($d['identity']),(int)$d['module'],$d['capabilities'],$scope,$d['all_contexts'],isset($d['ends_at'])?strtotime($d['ends_at']):null,$d['reason'],isset($d['starts_at'])?strtotime($d['starts_at']):null));
    }
    public function revoke(Request $r,string $grant) {
        $actor=$this->actor($r,true); $d=$r->validate($this->base());
        return $this->change($r,$actor,$d,fn()=> $this->kernel->revoke($actor,$grant,$d['reason']));
    }
    private function base(): array { return ['version'=>['required','integer','min:0'],'reason'=>['required','string','max:1000','regex:/\S/u'],'actor'=>['prohibited'],'principal'=>['prohibited']]; }
    private function change(Request $r,string $actor,array $d,callable $work) {
        $result=$this->kernel->administration($d['version'],['channel'=>'WEB','ip'=>$r->ip()],function() use($r,$work) { $this->actor($r,true); return $work(); });
        return response()->json(['data'=>$result,'version'=>$this->kernel->snapshot()['version']]);
    }
}
