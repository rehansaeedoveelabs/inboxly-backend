<?php

namespace App\Models;

use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use Database\Factories\ShopifyComplianceRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyComplianceRequest extends Model
{
    /** @use HasFactory<ShopifyComplianceRequestFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'workspace_id',
        'shop_domain',
        'topic',
        'webhook_id',
        'shopify_customer_id',
        'shopify_data_request_id',
        'payload_sha256',
        'status',
        'processing_checkpoint',
        'legal_checklist',
        'attempt_count',
        'error_code',
        'error_message',
        'inventory_disk',
        'inventory_path',
        'correlation_disk',
        'correlation_path',
        'inventory_summary',
        'received_at',
        'processing_started_at',
        'processing_lease_expires_at',
        'processing_lease_token',
        'completed_at',
        'failed_at',
        'awaiting_policy_at',
    ];

    protected function casts(): array
    {
        return [
            'topic' => ShopifyComplianceTopic::class,
            'status' => ShopifyComplianceStatus::class,
            'processing_checkpoint' => 'array',
            'legal_checklist' => 'array',
            'inventory_summary' => 'array',
            'attempt_count' => 'integer',
            'received_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'awaiting_policy_at' => 'datetime',
        ];
    }

    /**
     * True when status is processing and the lease has expired (or is missing past the configured window).
     */
    public function hasExpiredProcessingLease(?int $leaseSeconds = null): bool
    {
        if ($this->status !== ShopifyComplianceStatus::Processing) {
            return false;
        }

        if ($this->processing_lease_expires_at !== null) {
            return $this->processing_lease_expires_at->lte(now());
        }

        $seconds = $leaseSeconds ?? max(1, (int) config('services.shopify.compliance_processing_lease_seconds', 300));

        return $this->processing_started_at !== null
            && $this->processing_started_at->lte(now()->subSeconds($seconds));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShopifyComplianceRequestEvent::class, 'compliance_request_id');
    }

    public function hasInventoryArtifact(): bool
    {
        return filled($this->inventory_path) && filled($this->inventory_disk);
    }
}
