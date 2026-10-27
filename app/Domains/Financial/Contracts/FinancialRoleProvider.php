<?php

namespace App\Domains\Financial\Contracts;

use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;

/**
 * Contrato del Módulo 2 para conocer los roles del dueño de una wallet.
 * El dominio financiero no consulta tablas/colecciones de identidad
 * directamente; lo hace a través de un adaptador.
 */
interface FinancialRoleProvider
{
    /**
     * Nombres de rol del dueño identificado por sus IDs externos.
     *
     * @return array<int, string>
     *
     * @throws FinancialDependencyUnavailableException
     */
    public function rolesOf(string $ownerType, string $ownerId): array;
}
