<?php
declare(strict_types=1);

use App\Support\ApiPolicyMatrix;
use PHPUnit\Framework\TestCase;

final class PermissionCriticalEndpointContractTest extends TestCase
{
    public function testCriticalEndpointsKeepExpectedPermissionContract(): void
    {
        $matrix = ApiPolicyMatrix::all();

        $expected = [
            'api/save-and-next.php' => 'guarantee_save',
            'api/update-guarantee.php' => 'guarantee_save',
            'api/extend.php' => 'guarantee_extend',
            'api/reduce.php' => 'guarantee_reduce',
            'api/release.php' => 'guarantee_release',
            'api/undo-requests.php' => 'manage_data',
            'api/settings.php' => 'manage_users',
            'api/users/list.php' => 'manage_users',
            'api/roles/create.php' => 'manage_roles',
        ];

        foreach ($expected as $endpoint => $permission) {
            $this->assertArrayHasKey($endpoint, $matrix, "Missing endpoint in ApiPolicyMatrix: {$endpoint}");
            $actual = (string)($matrix[$endpoint]['permission'] ?? '');
            $this->assertSame($permission, $actual, "Permission drift for endpoint {$endpoint}");
        }
    }
}
