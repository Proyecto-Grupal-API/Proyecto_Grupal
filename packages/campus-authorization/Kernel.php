<?php
namespace CampusDigital\Authorization;

interface Store {
    public function transaction(callable $work): mixed;
    public function get(string $key): ?array;
    public function put(string $key, array $value): void;
}
interface Directory {
    /** Return a canonical identity, active and verified flags; null for absent accounts. */
    public function account(string $identity): ?array;
    public function scopeExists(string $type, string $id): bool;
}
final class Decision {
    public function __construct(public readonly string $status, public readonly string $reason) {}
    public function allowed(): bool { return $this->status === 'ALLOWED'; }
}
final class Kernel {
    public const VERSION = 1;
    public const MODULES = [1=>'Identidad',2=>'Finanzas',3=>'Marketplace',4=>'Abastecimiento',5=>'Servicios estudiantiles',6=>'Comunidad',7=>'Beneficios'];
    public function __construct(private Store $store, private Directory $directory, private array $catalog, private bool $enabled = false) {}
    private ?int $expectedVersion = null;
    private array $audit = [];
    public function principalFor(string $identity): ?string {
        $a=$this->directory->account($identity); if (!$a) return null;
        return ($this->store->get('identity:'.hash('sha256',$a['identity'])) ?? [])['principal'] ?? null;
    }
    public function principalDetails(string $principal): ?array { return $this->store->get('principal:'.$principal); }
    public function administration(int $version, array $audit, callable $work): mixed {
        return $this->store->transaction(function() use($version,$audit,$work) {
            if (($this->snapshot()['version']) !== $version) throw new \DomainException('AUTHORITY_VERSION_CONFLICT');
            $old=$this->expectedVersion; $previous=$this->audit; $this->expectedVersion=$version; $this->audit=$audit;
            try { return $work(); } finally { $this->expectedVersion=$old; $this->audit=$previous; }
        });
    }
    private function uuid(): string {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private function live(string $id): bool {
        $p=$this->store->get('principal:'.$id); if (!$p || !$p['active']) return false;
        $a=$this->directory->account($p['identity']);
        return $a !== null && ($a['active'] ?? false) === true && ($a['verified'] ?? false) === true;
    }
    public function check(string $principal, string $capability, ?array $scope=null): Decision {
        if (!$this->enabled) return new Decision('DENIED','INTEGRATION_DISABLED');
        try {
            if (!isset($this->catalog[$capability])) return new Decision('DENIED','UNKNOWN_CAPABILITY');
            if (!$this->live($principal)) return new Decision('DENIED','INACTIVE_ACCOUNT');
            if ($scope !== null) $this->validateScope($scope);
            $state=$this->store->get('authority') ?? ['seats'=>[], 'grants'=>[], 'events'=>[], 'version'=>0];
            if (in_array($principal,$state['seats'],true)) return new Decision('ALLOWED','GLOBAL_SEAT');
            if ($capability === 'campus.roles.manage') return new Decision('DENIED','GLOBAL_REQUIRED');
            foreach ($state['grants'] as $g) {
                if ($g['principal'] !== $principal || !$g['active'] || !in_array($capability,$g['capabilities'],true)) continue;
                if ($g['starts_at'] > time() || ($g['ends_at'] !== null && $g['ends_at'] <= time())) continue;
                if ($g['all_contexts'] || $g['scope'] === $scope) return new Decision('ALLOWED','EXPLICIT_GRANT');
            }
            return new Decision('DENIED','NO_GRANT');
        } catch (\InvalidArgumentException $e) { return new Decision('DENIED','INVALID_SCOPE'); }
    }
    private function validateScope(array $scope): void {
        if (array_keys($scope)!==['type','id'] || !in_array($scope['type'],['business','association','service','council'],true)
            || !is_string($scope['id']) || strlen($scope['id'])>128 || trim($scope['id'])==='' || preg_match('/[\x00-\x20]/',$scope['id'])
            || !$this->directory->scopeExists($scope['type'],$scope['id'])) throw new \InvalidArgumentException('Unknown or invalid context.');
    }
    public function register(string $identity): string {
        if (!$this->enabled) throw new \LogicException('Integration disabled.');
        $a=$this->directory->account($identity);
        if (!$a || ($a['active']??false)!==true || ($a['verified']??false)!==true) throw new \DomainException('Account must be active and verified.');
        $canonical=$a['identity'];
        return $this->store->transaction(function() use($canonical) {
            $key='identity:'.hash('sha256',$canonical); $existing=$this->store->get($key);
            if ($existing) return $existing['principal'];
            $id=$this->uuid(); $this->store->put('principal:'.$id,['identity'=>$canonical,'active'=>true]);
            $this->store->put($key,['principal'=>$id]); return $id;
        });
    }
    /** All authority changes serialize through one versioned document. */
    private function change(?string $actor, string $reason, callable $work, bool $bootstrap=false): mixed {
        if (!$this->enabled || trim($reason)==='' || strlen($reason)>1000) throw new \DomainException('Enabled integration and a reason are required.');
        return $this->store->transaction(function() use($actor,$reason,$work,$bootstrap) {
            $s=$this->store->get('authority') ?? ['seats'=>[], 'grants'=>[], 'events'=>[], 'version'=>0];
            if ($this->expectedVersion !== null && $s['version'] !== $this->expectedVersion) throw new \DomainException('AUTHORITY_VERSION_CONFLICT');
            if ($bootstrap) { if ($s['events']!==[]) throw new \DomainException('Bootstrap is permanently closed after the first authority change.'); }
            elseif (!$actor || !$this->check($actor,'campus.roles.manage')->allowed()) throw new \DomainException('Global administrator required.');
            $before=['seats'=>$s['seats'],'grants'=>$s['grants']]; $result=$work($s);
            $s['version']++; $s['events'][]=['id'=>$this->uuid(),'actor'=>$actor,'audit'=>$this->audit,'reason'=>$reason,'at'=>gmdate('c'),'version'=>$s['version'],'before'=>$before,'after'=>['seats'=>$s['seats'],'grants'=>$s['grants']]];
            $this->store->put('authority',$s); return $result;
        });
    }
    public function seat(?string $actor,int $module,string $principal,string $reason,bool $bootstrap=false): void {
        $this->change($actor,$reason,function(&$s) use($module,$principal) {
            if (!isset(self::MODULES[$module]) || !$this->live($principal)) throw new \DomainException('Invalid module or account.');
            if (isset($s['seats'][$module]) || in_array($principal,$s['seats'],true)) throw new \DomainException('Seat occupied or account already holds a global seat.');
            $s['seats'][$module]=$principal;
        },$bootstrap);
    }
    public function grant(string $actor,string $principal,int $module,array $capabilities,?array $scope,bool $allContexts,?int $endsAt,string $reason,?int $startsAt=null): string {
        return $this->change($actor,$reason,function(&$s) use($principal,$module,$capabilities,$scope,$allContexts,$endsAt,$startsAt) {
            if (!$this->live($principal) || !isset(self::MODULES[$module]) || $capabilities===[] || ($endsAt!==null && $endsAt<=time())) throw new \DomainException('Invalid grant.');
            if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) throw new \DomainException('Invalid validity interval.');
            if ($scope!==null) $this->validateScope($scope);
            if ($allContexts && $scope!==null) throw new \DomainException('Choose an exact scope or all contexts.');
            foreach ($capabilities as $c) if (!is_string($c) || !isset($this->catalog[$c]) || $c==='campus.roles.manage' || $this->catalog[$c]['module']!==$module) throw new \DomainException('Capability outside selected module.');
            $id=$this->uuid(); $s['grants'][$id]=['principal'=>$principal,'module'=>$module,'capabilities'=>array_values(array_unique($capabilities)),'scope'=>$scope,'all_contexts'=>$allContexts,'starts_at'=>$startsAt ?? time(),'ends_at'=>$endsAt,'active'=>true,'catalog_version'=>self::VERSION]; return $id;
        });
    }
    public function revoke(string $actor,string $grant,string $reason): void {
        $this->change($actor,$reason,function(&$s) use($grant) {
            if (!isset($s['grants'][$grant]) || !$s['grants'][$grant]['active']) throw new \DomainException('Active grant not found.');
            $s['grants'][$grant]['active']=false;
        });
    }
    public function releaseSeat(string $actor,int $module,string $reason): void {
        $this->change($actor,$reason,function(&$s) use($actor,$module) {
            if (!isset($s['seats'][$module])) throw new \DomainException('Occupied seat not found.');
            if ($s['seats'][$module] === $actor) throw new \DomainException('Another global administrator must release your seat.');
            if (count($s['seats']) <= 1) throw new \DomainException('Keep at least one global administrator.');
            unset($s['seats'][$module]);
        });
    }
    public function retire(string $actor,string $principal,string $reason): void {
        if ($actor===$principal) throw new \DomainException('Use another global administrator to retire this account.');
        $this->change($actor,$reason,function(&$s) use($principal) {
            $p=$this->store->get('principal:'.$principal); if (!$p || !$p['active']) throw new \DomainException('Active principal not found.');
            $p['active']=false; $this->store->put('principal:'.$principal,$p);
            foreach ($s['seats'] as $m=>$holder) if ($holder===$principal) unset($s['seats'][$m]);
            foreach ($s['grants'] as &$g) if ($g['principal']===$principal) $g['active']=false;
        });
    }
    public function snapshot(): array { return $this->store->get('authority') ?? ['seats'=>[], 'grants'=>[], 'events'=>[], 'version'=>0]; }
}
