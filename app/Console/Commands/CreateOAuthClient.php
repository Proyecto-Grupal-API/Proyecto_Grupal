<?php

namespace App\Console\Commands;

use App\Models\ServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateOAuthClient extends Command
{
    protected $signature = 'oauth:client {name} {--scope=* : Allowed scopes}';
    protected $description = 'Create an OAuth 2.0 client for service-to-service authentication';

    public function handle(): int
    {
        $secret = Str::random(64);
        $client = ServiceClient::create([
            'name' => $this->argument('name'),
            'client_id' => 'svc_'.Str::lower(Str::random(24)),
            'secret_hash' => Hash::make($secret),
            'scopes' => $this->option('scope') ?: ['students:read'],
            'active' => true,
        ]);

        $this->info('OAuth client created. Store the secret securely; it will not be shown again.');
        $this->line('client_id='.$client->client_id);
        $this->line('client_secret='.$secret);
        return self::SUCCESS;
    }
}
