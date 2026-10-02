<?php

namespace App\Domains\Financial\Enums;

enum BonusType: string
{
    case BECA = 'BECA';
    case CAMPANIA = 'CAMPANIA';
    case BENEFICIO = 'BENEFICIO';
    case CATEGORIA = 'CATEGORIA';
    case NEGOCIO = 'NEGOCIO';
}