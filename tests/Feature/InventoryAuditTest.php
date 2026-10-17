<?php

use App\Models\InventoryModule\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('audits model creation changes and deletion with the real actor and business', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);
    request()->setUserResolver(fn () => $actor);
    config(['team4.business_id' => 'other-default']);
    $product = Product::create(['business_id' => 'audit-business', 'sku' => 'AUDIT-1', 'name' => 'Antes', 'active' => true]);
    expect($product->getKey())->not->toBeNull();
    $product->update(['name' => 'Después']);
    $product->delete();
    $events = DB::connection('inventory')->getCollection('audit_events')->find(['entity_id' => (string) $product->getKey()])->toArray();
    expect($events)->toHaveCount(3)->and(array_column($events, 'event'))->toBe(['Product.created', 'Product.updated', 'Product.deleted']);
    foreach ($events as $event) {
        expect($event['business_id'])->toBe('audit-business')->and($event['actor_id'])->toBe((string) $actor->getKey());
    }
    expect($events[1]['before']['name'])->toBe('Antes')->and($events[1]['after']['name'])->toBe('Después');
});

it('keeps model audit inside inventory and does not observe identity models', function () {
    User::factory()->create();
    expect(DB::connection('inventory')->getCollection('audit_events')->countDocuments([]))->toBe(0);
    Product::create(['business_id' => 'audit-B', 'sku' => 'AUDIT-2', 'name' => 'Servicio', 'active' => true]);
    $event = DB::connection('inventory')->getCollection('audit_events')->findOne(['event' => 'Product.created']);
    expect($event)->not->toBeNull()->and($event['actor_id'])->toBe('SYSTEM')
        ->and(DB::connection('inventory')->getCollection('audit_events')->countDocuments([]))->toBe(1)
        ->and((new Product)->getConnectionName())->toBe('inventory');
});
