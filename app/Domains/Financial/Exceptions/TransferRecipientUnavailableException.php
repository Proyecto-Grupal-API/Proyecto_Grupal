<?php

namespace App\Domains\Financial\Exceptions;

use App\Exceptions\DirectoryFailure;

/**
 * FIN-07: el directorio de Identidad no pudo resolver al destinatario por una causa concreta. El mensaje orienta al
 * usuario sin datos sensibles; el código técnico y la correlación permiten investigarlo.
 */
class TransferRecipientUnavailableException extends FinancialDependencyUnavailableException
{
    public function __construct(string $message, public readonly string $reasonCode, public readonly string $correlationId)
    {
        parent::__construct($message);
    }

    public static function fromDirectory(DirectoryFailure $failure, string $correlationId): self
    {
        $message = match (true) {
            $failure->errorCode === 'directory_integrity_not_attested' => 'La búsqueda por matrícula no está habilitada en esta instalación: falta la acreditación de integridad del directorio de Identidad. Identifica al destinatario con su QR o su identificador de usuario.',
            $failure->status === 403 => 'Finanzas no tiene autorización para consultar el directorio de Identidad. Identifica al destinatario con su QR o avisa a soporte.',
            $failure->status === 429 || $failure->errorCode === 'directory_quota_unavailable' => 'Se alcanzó el límite de consultas al directorio de Identidad. Intenta más tarde o identifica al destinatario con su QR.',
            default => 'El directorio de Identidad no está disponible en este momento. Intenta más tarde o identifica al destinatario con su QR.',
        };

        return new self($message, $failure->errorCode, $correlationId);
    }
}
