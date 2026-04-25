<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerKycFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_routes_require_a_bearer_token(): void
    {
        $this->postJson('/api/v1/customers', [])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_customer_can_create_profile_without_exposing_pan_or_aadhaar(): void
    {
        $this->fakeVerifiedUser('customer-uuid-1', 'customer');

        $response = $this->withBearerToken()->postJson('/api/v1/customers', [
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'dob' => '1995-05-12',
            'pan_number' => 'ABCDE1234F',
            'aadhaar_number' => '123456789012',
            'address' => 'Mumbai, Maharashtra',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.pan_number')
            ->assertJsonMissingPath('data.aadhaar_number');

        $customer = Customer::query()->where('user_id', 'customer-uuid-1')->firstOrFail();
        $raw = DB::table('customers')->where('id', $customer->id)->first();

        $this->assertNotSame('ABCDE1234F', $raw->pan_number);
        $this->assertNotSame('123456789012', $raw->aadhaar_number);
    }

    public function test_customer_cannot_approve_or_reject_kyc(): void
    {
        $this->fakeVerifiedUser('customer-uuid-1', 'customer');

        $customer = $this->createCustomer([
            'user_id' => 'target-customer',
            'kyc_status' => 'under_review',
        ]);

        $this->withBearerToken()->patchJson("/api/v1/kyc/{$customer->id}/status", [
            'status' => 'approved',
            'remarks' => 'Looks valid',
        ])->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame('under_review', $customer->fresh()->kyc_status);
    }

    public function test_officer_can_review_under_review_kyc_queue(): void
    {
        $this->fakeVerifiedUser('officer-uuid-1', 'bank_officer');

        $underReview = $this->createCustomer([
            'user_id' => 'customer-under-review',
            'first_name' => 'Under',
            'kyc_status' => 'under_review',
        ]);

        $this->createCustomer([
            'user_id' => 'customer-pending',
            'first_name' => 'Pending',
            'kyc_status' => 'pending',
        ]);

        KycDocument::query()->create([
            'customer_id' => $underReview->id,
            'document_type' => 'pan',
            'file_path' => 'kyc_documents/test/pan.pdf',
            'status' => 'uploaded',
        ]);

        $this->withBearerToken()->getJson('/api/v1/kyc/reviews?status=under_review')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $underReview->id)
            ->assertJsonMissingPath('data.0.pan_number')
            ->assertJsonMissingPath('data.0.aadhaar_number');
    }

    public function test_kyc_can_only_be_approved_or_rejected_from_under_review(): void
    {
        $this->fakeVerifiedUser('officer-uuid-1', 'bank_officer');

        $customer = $this->createCustomer([
            'user_id' => 'customer-pending',
            'kyc_status' => 'pending',
        ]);

        $this->withBearerToken()->patchJson("/api/v1/kyc/{$customer->id}/status", [
            'status' => 'approved',
            'remarks' => 'Premature approval',
        ])->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $customer->fresh()->kyc_status);
        $this->assertDatabaseCount('kyc_audit_logs', 0);
    }

    public function test_customer_can_submit_kyc_and_audit_log_is_created(): void
    {
        $this->fakeVerifiedUser('customer-uuid-1', 'customer');

        $customer = $this->createCustomer([
            'user_id' => 'customer-uuid-1',
            'kyc_status' => 'pending',
        ]);

        $this->withBearerToken()->postJson('/api/v1/kyc/submit')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kyc_status', 'under_review');

        $this->assertSame('under_review', $customer->fresh()->kyc_status);
        $this->assertDatabaseHas('kyc_audit_logs', [
            'customer_id' => $customer->id,
            'action' => 'STATUS_CHANGE',
            'old_value' => 'pending',
            'new_value' => 'under_review',
            'performed_by' => 'customer-uuid-1',
        ]);
    }

    public function test_customer_can_upload_a_valid_kyc_document(): void
    {
        Storage::fake('public');
        $this->fakeVerifiedUser('customer-uuid-1', 'customer');

        $customer = $this->createCustomer([
            'user_id' => 'customer-uuid-1',
        ]);

        $response = $this->withBearerToken()->postJson('/api/v1/kyc/documents', [
            'document_type' => 'pan',
            'file' => UploadedFile::fake()->image('pan.jpg')->size(512),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.document_type', 'pan');

        $document = KycDocument::query()->where('customer_id', $customer->id)->firstOrFail();
        Storage::disk('public')->assertExists($document->file_path);
    }

    private function fakeVerifiedUser(string $uuid, string $role): void
    {
        Http::fake([
            '*/api/v1/internal/auth/verify' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'uuid' => $uuid,
                        'email' => "{$uuid}@bankcore.test",
                        'role' => $role,
                    ],
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 200),
        ]);
    }

    private function withBearerToken(): self
    {
        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    /**
     * Create a customer without relying on a factory that does not exist yet.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'user_id' => 'customer-uuid-1',
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'dob' => '1995-05-12',
            'pan_number' => 'ABCDE1234F',
            'aadhaar_number' => '123456789012',
            'address' => 'Mumbai, Maharashtra',
            'kyc_status' => 'pending',
        ], $overrides));
    }
}
