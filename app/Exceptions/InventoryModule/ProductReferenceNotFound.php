<?php

namespace App\Exceptions\InventoryModule;

/**
 * Se lanza cuando un product_reference o variant_sku de Eq.7 no se puede
 * resolver contra Mongo (SKU inexistente). El controller lo mapea a HTTP 404.
 */
class ProductReferenceNotFound extends \RuntimeException
{
}