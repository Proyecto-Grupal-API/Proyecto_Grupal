<?php

namespace App\Domains\Identity\Authorization;

/** Versioned Module 1 policy. Catalog creation never assigns a role to a user. */
final class FinancialRoleCatalog
{
    public const VERSION = 2;
    public const SCOPES = ['business', 'association', 'service', 'council'];
    public const ROLES = [
        'estudiante' => ['Estudiante', null],
        'admin' => ['Administrador de plataforma', null],
        'auditor' => ['Auditor', null],
        'business_owner' => ['Propietario de negocio', 'business'],
        'business_manager' => ['Gerente de negocio', 'business'],
        'cashier' => ['Cajero de negocio', 'business'],
        'association_president' => ['Presidencia de asociación', 'association'],
        'association_treasurer' => ['Tesorería de asociación', 'association'],
        'cash_agent' => ['Agente de recarga y retiro', 'association'],
        'consejo_estudiantil' => ['Consejo Estudiantil', 'council'],
        'scholarship_committee' => ['Comité de becas', 'council'],
    ];
    public const NEW_ROLES = [
        'association_president', 'association_treasurer', 'cash_agent', 'scholarship_committee',
    ];
    public const NEW_ROLE_SCOPES = [
        'association_president' => 'association', 'association_treasurer' => 'association',
        'cash_agent' => 'association', 'scholarship_committee' => 'council',
    ];
    public const PERMISSIONS = [
        'identity.bonus_roles.manage' => ['Administrar asignaciones de roles de bonos', 'identity'],
        'bonus.view' => ['Consultar bonos del contexto autorizado', 'financial'],
        'bonus.cancel' => ['Cancelar saldo restante del contexto autorizado', 'financial'],
        'bonus.view.all' => ['Consultar bonos de todos los contextos', 'financial'],
        'bonus.cancel.all' => ['Cancelar bonos de todos los contextos', 'financial'],
        'bonus.issue.BECA' => ['Emitir bono de beca', 'financial'],
        'bonus.issue.CAMPANIA' => ['Emitir bono de campaña', 'financial'],
        'bonus.issue.BENEFICIO' => ['Emitir bono de beneficio', 'financial'],
        'bonus.issue.CATEGORIA' => ['Emitir bono por categoría', 'financial'],
        'bonus.issue.NEGOCIO' => ['Emitir bono de negocio', 'financial'],
    ];
    // This explicit initial matrix is a policy proposal, not an inferred wildcard hierarchy.
    public const MAPPINGS = [
        'admin' => ['bonus.issue.BECA', 'bonus.issue.CAMPANIA', 'bonus.issue.BENEFICIO', 'bonus.issue.CATEGORIA', 'bonus.issue.NEGOCIO', 'bonus.view', 'bonus.cancel', 'bonus.view.all', 'bonus.cancel.all', 'identity.bonus_roles.manage'],
        'consejo_estudiantil' => ['bonus.issue.BECA', 'bonus.issue.BENEFICIO', 'bonus.view', 'bonus.cancel'],
        'scholarship_committee' => ['bonus.issue.BECA', 'bonus.view', 'bonus.cancel'],
        'association_president' => ['bonus.issue.CAMPANIA', 'bonus.issue.BENEFICIO', 'bonus.view', 'bonus.cancel'],
        'business_owner' => ['bonus.issue.CAMPANIA', 'bonus.issue.CATEGORIA', 'bonus.issue.NEGOCIO', 'bonus.view', 'bonus.cancel'],
        'auditor' => ['bonus.view', 'bonus.view.all'],
    ];
}
