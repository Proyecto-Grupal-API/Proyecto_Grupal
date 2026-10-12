<?php

namespace App\Services\Integrations;

class MockTeam1IdentityGateway implements Team1IdentityGateway
{
    public function resolveUser(string $userId): array
    {
        return ['user_id'=>$userId,'status'=>'active','roles'=>['admin'],'provider'=>'team1-mock'];
    }
}
