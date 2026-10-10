<?php
require dirname(__DIR__).'/Kernel.php';
use CampusDigital\Authorization\{Store,Directory,Kernel};
final class MemoryStore implements Store {
 public array $rows=[];
 public function transaction(callable $work): mixed { $old=$this->rows; try { return $work(); } catch (Throwable $e) { $this->rows=$old; throw $e; } }
 public function get(string $key): ?array { return $this->rows[$key]??null; }
 public function put(string $key,array $value): void { $this->rows[$key]=$value; }
}
final class Accounts implements Directory {
 public array $inactive=[];
 public function account(string $id): ?array { return ['identity'=>$id,'active'=>!in_array($id,$this->inactive,true),'verified'=>true]; }
 public function scopeExists(string $type,string $id): bool { return $type==='business' && $id==='real'; }
}
$count=0;
function check(bool $condition,string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
function rejects(callable $work,string $label): void { try { $work(); } catch (DomainException|InvalidArgumentException|LogicException $e) { check(true,$label); return; } throw new RuntimeException($label); }
$s=new MemoryStore; $d=new Accounts;
$catalog=['campus.roles.manage'=>['module'=>1],'bonus.issue.BECA'=>['module'=>2],'bonus.cancel'=>['module'=>2],'identity.read'=>['module'=>1]];
$k=new Kernel($s,$d,$catalog,true);
$ids=[]; foreach(range(1,8) as $i) $ids[]=$k->register('opaque-'.$i);
check($k->register('opaque-1')===$ids[0],'Stable opaque identity');
$k->seat(null,1,$ids[0],'Initial administrator',true);
rejects(fn()=>$k->seat(null,2,$ids[1],'Repeat bootstrap',true),'Bootstrap closes forever');
foreach(range(2,7) as $m) $k->seat($ids[0],$m,$ids[$m-1],'Explicit seat');
check(count($k->snapshot()['seats'])===7,'Seven occupied seats');
$before=$k->snapshot(); rejects(fn()=>$k->seat($ids[0],7,$ids[7],'Eighth'),'Cannot occupy eighth seat');
check($before===$k->snapshot(),'Failure leaves no event');
rejects(fn()=>$k->seat($ids[0],8,$ids[7],'Invalid module'),'No eighth module');
check($k->check($ids[0],'bonus.cancel')->allowed(),'Global cross-module authorization');
$g=$k->grant($ids[0],$ids[7],2,['bonus.issue.BECA'],['type'=>'business','id'=>'real'],false,null,'Scoped issuance');
check($k->check($ids[7],'bonus.issue.BECA',['type'=>'business','id'=>'real'])->allowed(),'Exact context');
check(!$k->check($ids[7],'bonus.issue.BECA')->allowed(),'No implicit global context');
check(!$k->check($ids[7],'bonus.cancel')->allowed(),'No implicit cancellation');
rejects(fn()=>$k->grant($ids[0],$ids[7],2,['identity.read'],null,false,null,'Cross module'),'Module isolation');
rejects(fn()=>$k->grant($ids[0],$ids[7],2,['bonus.cancel'],['type'=>'business','id'=>'invented'],false,null,'Fake'),'Trusted scope required');
rejects(fn()=>$k->grant($ids[7],$ids[7],2,['bonus.cancel'],null,true,null,'Escalate'),'Modular cannot delegate');
$k->revoke($ids[0],$g,'Revoke'); check(!$k->check($ids[7],'bonus.issue.BECA',['type'=>'business','id'=>'real'])->allowed(),'Revocation immediate');
$d->inactive[]='opaque-1'; check(!$k->check($ids[0],'bonus.cancel')->allowed(),'Fresh account state'); $d->inactive=[];
$k->retire($ids[1],$ids[0],'Retire account'); check(!$k->check($ids[0],'bonus.cancel')->allowed(),'Retired principal denied');
check($k->register('opaque-1')===$ids[0],'Retired ID never replaced');
check(count($k->snapshot()['events'])===10,'Audit survives retirement');
check(!(new Kernel($s,$d,$catalog,false))->check($ids[1],'bonus.cancel')->allowed(),'Disabled fails closed');

$version=$k->snapshot()['version'];
$g=$k->administration($version,['channel'=>'WEB'],fn()=> $k->grant($ids[1],$ids[7],2,['bonus.cancel'],null,true,null,'Future permission',time()+3600));
check(!$k->check($ids[7],'bonus.cancel')->allowed(),'Future start not effective');
$before=$k->snapshot();
rejects(fn()=> $k->administration($version,[],fn()=> $k->revoke($ids[1],$g,'Stale form')),'Concurrent stale revision rejected');
check($before===$k->snapshot(),'Stale revision preserves history');
$all=$s->rows;
rejects(fn()=> $k->administration($k->snapshot()['version'],[],function() use($k,$ids) {
 $p=$k->register('new-rollback-account'); $k->seat($ids[1],2,$p,'Occupied seat');
}),'Failed combined registration rollback');
check($all===$s->rows,'New principal rollback atomic');
rejects(fn()=> $k->releaseSeat($ids[1],2,'Self release'),'Cannot release own seat');
$k->releaseSeat($ids[1],3,'Release another');
check(!isset($k->snapshot()['seats'][3]),'Seat released');
$k->seat($ids[1],3,$ids[2],'Reassign');
check(isset($k->snapshot()['seats'][3]),'Vacant seat reusable');
check($k->snapshot()['events'][10]['audit']['channel']==='WEB','Transport audit recorded');
echo "PASS: $count independent checks\n";

