<?php

namespace App\Services;

use App\Models\PointsAccount;
use App\Services\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PointsLedger\DTO\RedeemPointsRequest;
use App\Services\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PointsLedger\Exception\DailyCapExceededException;
use App\Services\PointsLedger\Exception\InsufficientPointsException;
use App\Services\PointsLedger\Exception\LedgerEntryNotFoundException;
use App\Services\PointsLedger\Exception\NoActiveEarningRuleException;
use App\Services\PointsLedger\Result\LedgerOperationResult;

interface PointsLedgerServiceInterface
{
    /**
     * Acumula puntos a partir de una venta confirmada.
     * Aplica la EarningRule vigente del negocio y el multiplicador de PointCampaign si aplica.
     *
     * @throws NoActiveEarningRuleException si el negocio no tiene regla vigente en la fecha
     * @throws DailyCapExceededException    si el tope diario ya está agotado (remaining <= 0)
     */
    public function earn(EarnPointsRequest $request): LedgerOperationResult;

    /**
     * Debita puntos para un canje confirmado. Valida el saldo disponible real
     * calculado desde el ledger, nunca desde el cache de PointsAccount.
     *
     * @throws InsufficientPointsException
     */
    public function redeem(RedeemPointsRequest $request): LedgerOperationResult;

    /**
     * Revierte un movimiento existente (típicamente un 'earn' cancelado por devolución).
     * Siempre crea el movimiento de reverso completo en el ledger; si el saldo disponible
     * no alcanza para cubrirlo (porque ya fue canjeado), el faltante se registra en
     * pending_balance y se genera un FraudFlag para revisión de Gobierno de Puntos.
     *
     * @throws LedgerEntryNotFoundException
     */
    public function reverse(ReversePointsRequest $request): LedgerOperationResult;

    /**
     * Recalcula y persiste el cache de PointsAccount desde el ledger completo.
     * Uso: reconciliación manual o programada; no forma parte del flujo normal de earn/redeem.
     */
    public function reconcileAccount(string $studentId): PointsAccount;

    /**
     * Saldo disponible real, calculado agregando el ledger (no el cache).
     */
    public function getAvailableBalance(string $studentId): int;
}
