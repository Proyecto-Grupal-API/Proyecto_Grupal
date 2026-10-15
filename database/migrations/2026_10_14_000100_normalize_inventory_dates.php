<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void
    {
        $db=DB::connection('inventory');
        // Native driver writes do not serialize PHP DateTime objects as BSON dates.
        foreach ($db->getDatabase()->listCollections() as $info) {
            $collection=$db->getCollection($info->getName());
            foreach ($collection->find() as $row) {
                $updates=[];
                foreach ($row as $key=>$value) {
                    if (!str_ends_with($key,'_at') && !in_array($key,['expected_at','expires_at'])) { continue; }
                    if ($value instanceof \MongoDB\BSON\UTCDateTime) { continue; }
                    $date=is_array($value) || $value instanceof \ArrayObject ? (array)$value : null;
                    if ($date === [] && in_array($key, ['created_at', 'updated_at']) && $row['_id'] instanceof \MongoDB\BSON\ObjectId) {
                        // DateTime was stored as an empty document; catalogue creation time remains in ObjectId.
                        $updates[$key]=new \MongoDB\BSON\UTCDateTime($row['_id']->getTimestamp()*1000);
                        continue;
                    }
                    if (!$date || !isset($date['date'])) { continue; }
                    $updates[$key]=new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable($date['date'],new \DateTimeZone($date['timezone'] ?? 'UTC')));
                }
                if ($updates) { $collection->updateOne(['_id'=>$row['_id']],['$set'=>$updates]); }
            }
        }
    }
    public function down(): void {}
};
