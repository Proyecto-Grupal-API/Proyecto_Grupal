<?php
namespace App\Http\Controllers\Financial;
use Illuminate\Http\Request;
class StudentTransferApiController extends StudentTransferJsonController
{
    protected function actor(Request $r): string
    {
        $actor = $r->attributes->get('transfer_delegated_user_id');
        abort_unless(is_string($actor) && preg_match('/^[a-f0-9]{24}$/', $actor), 403);
        return $actor;
    }
}
