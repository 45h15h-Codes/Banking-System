<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\KycDocument;
use App\Models\KycAuditLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class KycController extends Controller
{
    use ApiResponse;

    /**
     * Upload a KYC document.
     */
    public function uploadDocument(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:pan,aadhaar,passport,utility_bill,driving_license',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // Support up to 5MB
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $userId = $request->attributes->get('user_id');
        $customer = Customer::where('user_id', $userId)->first();

        if (!$customer) {
            return $this->error('Customer profile not found', 404);
        }

        try {
            $file = $request->file('file');
            // Store using local disk (could map to S3 via env configured disk in the future)
            $path = $file->storeAs(
                "kyc_documents/{$customer->id}",
                uniqid() . '.' . $file->extension(),
                'public'
            );

            $document = KycDocument::create([
                'customer_id' => $customer->id,
                'document_type' => $request->document_type,
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
        } catch (\Exception $e) {
            return $this->error('Failed to upload document', 500, $e->getMessage());
        }
    }

    /**
     * Submit KYC for review by moving status from pending to under_review.
     */
    public function submitKyc(Request $request)
    {
        $userId = $request->attributes->get('user_id');
        $customer = Customer::where('user_id', $userId)->first();

        if (!$customer) {
            return $this->error('Customer profile not found', 404);
        }

        if ($customer->kyc_status !== 'pending') {
            return $this->error("Cannot submit KYC. Current status is {$customer->kyc_status}", 400);
        }

        DB::beginTransaction();
        try {
            // Log the change
            KycAuditLog::create([
                'customer_id' => $customer->id,
                'action' => 'STATUS_CHANGE',
                'old_value' => 'pending',
                'new_value' => 'under_review',
                'performed_by' => $userId, // The user themselves triggers this submit
            ]);

            // Update status
            $customer->update(['kyc_status' => 'under_review']);

            DB::commit();
            return $this->success($customer, 'KYC submitted successfully for review');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to submit KYC', 500, $e->getMessage());
        }
    }

    /**
     * Admin/Officer approval of KYC statuses. Simulated workflow.
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        // Ideally checking for admin/officer role here!
        $adminId = $request->attributes->get('user_id');

        $customer = Customer::find($id);

        if (!$customer) {
            return $this->error('Customer not found', 404);
        }

        if ($customer->kyc_status === 'approved' && $request->status === 'approved') {
            return $this->error('KYC is already approved', 400);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $customer->kyc_status;
            
            KycAuditLog::create([
                'customer_id' => $customer->id,
                'action' => 'STATUS_CHANGE',
                'old_value' => $oldStatus,
                'new_value' => $request->status,
                'performed_by' => $adminId,
                'remarks' => $request->remarks ?? 'Status updated by officer',
            ]);

            $customer->update(['kyc_status' => $request->status]);

            DB::commit();
            return $this->success($customer, "KYC status updated to {$request->status}");
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update KYC status', 500, $e->getMessage());
        }
    }
}
