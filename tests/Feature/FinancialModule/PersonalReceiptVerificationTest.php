<?php

namespace Tests\Feature\FinancialModule;

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\PersonalReceiptQueryService;
use App\Domains\Financial\Services\ReceiptPdfService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Exercises the real receipt routes with in-memory identity and financial read fixtures. */
final class PersonalReceiptVerificationTest extends TestCase
{
    private const OWNER = '000000000000000000000001';

    private const OPERATION = 'f3ca664d-44db-498a-8f76-1c2895de8250';

    private const FOLIO = 'CAJ-20261107-0123456789ABCDEF0123456789ABCDEF';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false]);
        $this->withoutMiddleware([
            EnsureRequiredTwoFactorAuthentication::class,
            HandleInertiaRequests::class,
            TrackDeviceSession::class,
        ]);
    }

    private function ownerWallet(?Wallet $wallet): void
    {
        $user = new User;
        $user->forceFill(['_id' => self::OWNER, 'email_verified_at' => now()]);
        $this->actingAs($user);

        $this->mock(WalletService::class)->shouldReceive('findByOwner')
            ->with('USER', self::OWNER, WalletType::USUARIO)->andReturn($wallet);
    }

    private function detail(string $source = 'transaction'): string
    {
        return route('financial.receipts.show', ['source' => $source, 'operationId' => self::OPERATION]);
    }

    public function test_detail_checks_the_presented_values_against_the_owner_projection(): void
    {
        $wallet = new Wallet;
        $this->ownerWallet($wallet);
        $receipt = ['id' => self::OPERATION, 'cash_receipts' => [['folio' => self::FOLIO]]];
        $this->mock(PersonalReceiptQueryService::class)->shouldReceive('transaction')
            ->with($wallet, self::OPERATION)->andReturn($receipt);

        $url = $this->detail();
        $headers = ['X-Inertia' => 'true', 'Accept' => 'application/json'];
        foreach (['?folio=WRONG', '?folio=', '?folio[]=x', '?reference[]=x', '?reference=bad',
            '?folio='.self::FOLIO.'&reference=9bf743a0-4b16-414d-aeac-bb1fd1111c3b'] as $query) {
            $this->get($url.$query, $headers)->assertNotFound()
                ->assertExactJson(['message' => 'Recurso no encontrado.']);
        }

        foreach (['', '?folio='.self::FOLIO, '?reference='.strtoupper(self::OPERATION),
            '?folio='.self::FOLIO.'&reference='.self::OPERATION] as $query) {
            $this->get($url.$query, $headers)->assertOk()->assertJsonPath('props.receipt', $receipt)
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_a_purchase_can_verify_its_reference_but_has_no_cash_folio(): void
    {
        $wallet = new Wallet;
        $this->ownerWallet($wallet);
        $receipt = ['id' => self::OPERATION, 'cash_receipts' => []];
        $this->mock(PersonalReceiptQueryService::class)->shouldReceive('purchase')
            ->with($wallet, self::OPERATION)->andReturn($receipt);

        $headers = ['X-Inertia' => 'true', 'Accept' => 'application/json'];
        $this->get($this->detail('purchase').'?reference='.self::OPERATION, $headers)
            ->assertOk()->assertJsonPath('props.receipt', $receipt);
        $this->get($this->detail('purchase').'?folio='.self::FOLIO, $headers)->assertNotFound();
    }

    public function test_guest_and_absent_wallet_cannot_verify(): void
    {
        $this->mock(PersonalReceiptQueryService::class)->shouldNotReceive('transaction');
        $url = $this->detail().'?folio='.self::FOLIO;

        $this->getJson($url)->assertUnauthorized();
        $this->ownerWallet(null);
        $this->getJson($url)->assertNotFound();
    }

    public function test_verification_preserves_the_query_service_ownership_and_completion_checks(): void
    {
        $wallet = new Wallet;
        $this->ownerWallet($wallet);
        $query = $this->mock(PersonalReceiptQueryService::class);
        $query->shouldReceive('transaction')->once()->with($wallet, self::OPERATION)
            ->andThrow(new ModelNotFoundException);
        $query->shouldReceive('transaction')->once()->with($wallet, self::OPERATION)
            ->andThrow(new ConflictHttpException);

        $url = $this->detail().'?reference='.self::OPERATION;
        $this->getJson($url)->assertNotFound()->assertExactJson(['message' => 'Recurso no encontrado.']);
        $this->getJson($url)->assertConflict();
    }

    public function test_pdf_is_generated_only_after_a_match_when_verification_is_requested(): void
    {
        $wallet = new Wallet;
        $this->ownerWallet($wallet);
        $receipt = ['id' => self::OPERATION, 'cash_receipts' => [['folio' => self::FOLIO]]];
        $this->mock(PersonalReceiptQueryService::class)->shouldReceive('transaction')
            ->with($wallet, self::OPERATION)->andReturn($receipt);
        $this->mock(ReceiptPdfService::class)->shouldReceive('download')->once()->with($receipt)
            ->andReturn(response('fixture PDF', 200, ['Content-Type' => 'application/pdf']));

        $url = route('financial.receipts.pdf', ['source' => 'transaction', 'operationId' => self::OPERATION]);
        $this->getJson($url.'?folio=WRONG')->assertNotFound();
        $this->get($url.'?folio='.self::FOLIO)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
