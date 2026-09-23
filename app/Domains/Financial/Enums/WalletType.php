<?php

namespace App\Domains\Financial\Enums;

enum WalletType: string
{
    case USUARIO = 'WALLET_USUARIO';
    case ORGANIZACION = 'WALLET_ORGANIZACION';
    case NEGOCIO = 'WALLET_NEGOCIO';
}