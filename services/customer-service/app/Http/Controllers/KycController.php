<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListKycReviewsRequest;
use App\Http\Requests\UpdateKycStatusRequest;
use App\Http\Requests\UploadKycDocumentRequest;
use App\Models\Customer;
use App\Models\KycAuditLog;
use App\Models\KycDocument;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class KycController extends Controller
{
    use ApiResponse;

    /**
     * Upload a KYC document.
     */
    public function uploadDocument(UploadKycDocumentRequest $request)
    {
        $userId = $request->attributes->get('user_id');
        $customer = Customer::where('user_id', $userId)->first();

        if (! $customer) {
            return $this->error('Customer profile not found', 404);
        }

        if ($customer->kyc_status === 'approved') {
            return $this->error('Approved KYC documents cannot be changed', 409);
        }

        try {
            $file = $request->file('file');

            $path = $file->storeAs(
                "kyc_documents/{$customer->id}",
                $file->hashName(),
                'public'
            );

            $document = KycDocument::create([
                'customer_id' => $customer->id,
                'document_type' => $request->validated('document_type'),
                'file_path' => $path,
                'status' => 'uploaded',
            ]);

            $uploadedTypes = KycDocument::where('customer_id', $customer->id)->distinct('document_type')->count('document_type');
            if ($uploadedTypes >= 5 && $customer->kyc_status === 'pending') {
                $customer->update(['kyc_status' => 'under_review']);
                KycAuditLog::create([
                    'customer_id' => $customer->id,
                    'action' => 'STATUS_CHANGE',
                    'old_value' => 'pending',
                    'new_value' => 'under_review',
                    'performed_by' => $userId,
                    'remarks' => 'Auto-submitted after all 5 documents uploaded',
                ]);
            }

            return $this->success($document, 'Document uploaded successfully', 201);
        } catch (Throwable) {
            return $this->error('Failed to upload document', 500);
        }
    }

    /**
     * Submit KYC for review by moving status from pending to under_review.
     */
    public function submitKyc(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        $customer = Customer::where('user_id', $userId)->first();

        if (! $customer) {
            return $this->error('Customer profile not found', 404);
        }

        if (! in_array($customer->kyc_status, ['pending', 'rejected'], true)) {
            return $this->error("Cannot submit KYC. Current status is {$customer->kyc_status}", 400);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $customer->kyc_status;

            KycAuditLog::create([
                'customer_id' => $customer->id,
                'action' => 'STATUS_CHANGE',
                'old_value' => $oldStatus,
                'new_value' => 'under_review',
                'performed_by' => $userId,
            ]);

            $customer->update(['kyc_status' => 'under_review']);

            DB::commit();

            return $this->success($customer, 'KYC submitted successfully for review');
        } catch (Throwable) {
            DB::rollBack();

            return $this->error('Failed to submit KYC', 500);
        }
    }

    /**
     * Admin/Officer approval of KYC statuses. Simulated workflow.
     */
    public function updateStatus(UpdateKycStatusRequest $request, string $id)
    {
        $adminId = $request->attributes->get('user_id');

        $customer = Customer::with(['kycDocuments', 'kycAuditLogs'])->find($id);

        if (! $customer) {
            return $this->error('Customer not found', 404);
        }

        if ($customer->kyc_status !== 'under_review') {
            return $this->error('KYC must be under review before approval or rejection', 409);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $customer->kyc_status;

            KycAuditLog::create([
                'customer_id' => $customer->id,
                'action' => 'STATUS_CHANGE',
                'old_value' => $oldStatus,
                'new_value' => $request->validated('status'),
                'performed_by' => $adminId,
                'remarks' => $request->validated('remarks') ?? 'Status updated by officer',
            ]);

            $customer->update(['kyc_status' => $request->validated('status')]);

            DB::commit();

            return $this->success(
                $customer->fresh(['kycDocuments', 'kycAuditLogs']),
                "KYC status updated to {$request->validated('status')}"
            );
        } catch (Throwable) {
            DB::rollBack();

            return $this->error('Failed to update KYC status', 500);
        }
    }

    public function reviewQueue(ListKycReviewsRequest $request)
    {
        $status = $request->validated('status', 'under_review');
        $perPage = (int) $request->validated('per_page', 50);

        $customers = Customer::query()
            ->with(['kycDocuments', 'kycAuditLogs'])
            ->where('kyc_status', $status)
            ->latest()
            ->limit($perPage)
            ->get();

        return $this->success($customers, 'KYC review queue retrieved');
    }

    public function reviewShow(string $id)
    {
        $customer = Customer::with(['kycDocuments', 'kycAuditLogs'])->find($id);

        if (! $customer) {
            return $this->error('Customer not found', 404);
        }

        return $this->success($customer, 'KYC review record retrieved');
    }
}
