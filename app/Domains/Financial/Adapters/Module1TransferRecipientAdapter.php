<?php
namespace App\Domains\Financial\Adapters;
use App\Domains\Financial\Contracts\IdentityProvider;
use App\Domains\Financial\Contracts\TransferRecipientProvider;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Models\StudentProfile;
use App\Models\User;
use InvalidArgumentException;
use Throwable;
class Module1TransferRecipientAdapter implements TransferRecipientProvider
{
    public function __construct(private readonly IdentityProvider $identity) {}
    public function resolve(string $method, string $value, string $actorId, ?string $ip): array
    {
        try { return $this->resolveAvailable($method, $value, $actorId, $ip); }
        catch (InvalidArgumentException | FinancialDependencyUnavailableException $e) { throw $e; }
        catch (Throwable $e) { throw new FinancialDependencyUnavailableException('La consulta del destinatario no está disponible.', 0, $e); }
    }
    private function resolveAvailable(string $method, string $value, string $actorId, ?string $ip): array
    {
        $value = trim($value); $id = null;
        if ($method === 'USER_ID') {
            if (!preg_match('/^[a-f0-9]{24}$/i', $value)) { throw new InvalidArgumentException('Identificador de usuario no válido.'); }
            $id = strtolower($value);
        } elseif ($method === 'ENROLLMENT') {
            if ($value === '' || mb_strlen($value) > 100) { throw new InvalidArgumentException('Matrícula no válida.'); }
            $matches = StudentProfile::where('enrollment_number', $value)->limit(2)->get();
            if ($matches->count() !== 1) { throw new InvalidArgumentException('No se pudo identificar un destinatario único.'); }
            $id = (string) $matches->first()->user_id;
        } elseif ($method === 'QR') {
            if ($value === '' || strlen($value) > 500) { throw new InvalidArgumentException('Código QR no válido.'); }
            try { $result = $this->identity->validateQr($value, $actorId, 'FINANCIAL_TRANSFER_RECIPIENT', $ip); }
            catch (Throwable $e) { report($e); throw new FinancialDependencyUnavailableException('La validación QR de identidad no está disponible.'); }
            if (($result['ok'] ?? false) !== true) { throw new InvalidArgumentException('El QR no es válido o ya no está vigente.'); }
            $identity = $result['identity'] ?? null;
            $id = is_array($identity) ? ($identity['user_id'] ?? $identity['id'] ?? null) : null;
            if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/i', $id)) {
                throw new FinancialDependencyUnavailableException('El servicio de identidad no resolvió un identificador de usuario compatible.');
            }
        } else { throw new InvalidArgumentException('Método de identificación no válido.'); }
        if (!preg_match('/^[a-f0-9]{24}$/i', (string) $id)) { throw new InvalidArgumentException('No se pudo identificar el destinatario.'); }
        $u = User::find(strtolower($id));
        if (!$u || $u->account_activation_pending || !$u->email_verified_at) { throw new InvalidArgumentException('El destinatario no tiene una cuenta activa y verificada.'); }
        $profiles = StudentProfile::where('user_id', (string) $u->getKey())->limit(2)->get();
        if ($profiles->count() > 1) { throw new InvalidArgumentException('El destinatario tiene una identidad académica ambigua.'); }
        return ['user_id' => strtolower((string) $u->getKey()), 'name' => $u->name,
            'enrollment_number' => $profiles->first()?->enrollment_number];
    }
}
