<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class CheckBonusRoleIntegration extends Command
{
    protected $signature = 'financial:bonus-roles-check';
    protected $description = 'Comprueba el esquema y las dependencias para administrar roles de bonos sin modificar datos.';
    public function handle(): int
    {
        try {
            foreach (['issuer_scope_type','issuer_scope_id'] as $column) {
                if (!Schema::connection('sqlsrv')->hasColumn('bonuses',$column)) { throw new \RuntimeException('Falta la migración SQL del contexto de bonos.'); }
            }
            $mongo=DB::connection('mongodb');
            $hello=$mongo->getMongoClient()->selectDatabase('admin')->command(['hello'=>1])->toArray()[0];
            if (empty($hello->setName)) { throw new \RuntimeException('La administración transaccional requiere un replica set MongoDB.'); }
            $required=[
                'roles'=>['role_catalog_name_unique'], 'permissions'=>['permission_key_unique'],
                'role_permissions'=>['role_permission_unique'],
                'role_assignments'=>['current_identity_unique','identity_generation_unique'],
                'business_memberships'=>['current_identity_unique','identity_generation_unique'],
            ];
            foreach ($required as $collection=>$names) {
                $indexes=[];
                foreach ($mongo->getCollection($collection)->listIndexes() as $index) { $indexes[]=$index->getName(); }
                if (array_diff($names,$indexes)) { throw new \RuntimeException('Faltan índices de autorización en '.$collection.'.'); }
            }
            $authorizer=config('financial.bonus_roles.authorizer');
            if (!is_subclass_of($authorizer,\App\Domains\Identity\Contracts\ScopedCapabilityAuthorization::class)) { throw new \RuntimeException('El adaptador configurado no implementa el contrato contextual.'); }
            $this->info('Esquema y replica set disponibles. Esta comprobación no otorga permisos ni certifica el contrato oficial.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('No se puede activar roles de bonos: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
