<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function auditLogApiUser(string $role, string $suffix): array
{
    $user = User::factory()->create([
        'role' => $role,
        'username' => $role.'-audit-'.$suffix,
    ]);

    return [$user, $user->createToken('audit-log-test')->plainTextToken];
}

function createAuditLog(User $user, string $action, string $createdAt, int $entityId = 1): AuditLog
{
    $log = AuditLog::query()->create([
        'user_id' => $user->id,
        'action' => $action,
        'entity_type' => 'Product',
        'entity_id' => $entityId,
        'payload' => ['source' => 'test'],
    ]);
    $log->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    return $log;
}

it('filters audit logs by action and inclusive WIB date range', function () {
    [$owner, $token] = auditLogApiUser('owner', 'filters');
    createAuditLog($owner, 'product.created', '2026-10-21 17:00:00'); // 22 Oct 00:00 WIB
    createAuditLog($owner, 'product.price_changed', '2026-10-22 16:59:59', 2); // 22 Oct 23:59:59 WIB
    createAuditLog($owner, 'product.created', '2026-10-23 17:00:00', 3); // 24 Oct 00:00 WIB

    $this->withToken($token)
        ->getJson('/api/v1/audit-logs?action=product.created&from=2026-10-22&to=2026-10-22')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'product.created')
        ->assertJsonPath('data.0.entity_id', 1)
        ->assertJsonPath('data.0.payload.source', 'test')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('validates audit log filters', function () {
    [, $token] = auditLogApiUser('owner', 'validation');

    $this->withToken($token)
        ->getJson('/api/v1/audit-logs?from=22-10-2026&to=2026-10-21&per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'to', 'per_page']);
});

it('denies audit log access to cashiers', function () {
    [, $token] = auditLogApiUser('kasir', 'denied');

    $this->withToken($token)->getJson('/api/v1/audit-logs')->assertForbidden();
});
