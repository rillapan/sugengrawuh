<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSimulationException;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DTO\HardwareCommandResult;
use Tests\TestCase;

class HardwareDriverAbstractionTest extends TestCase
{
    /** @test */
    public function hardware_configuration_file_has_safe_defaults()
    {
        $config = config('hardware');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('driver', $config);
        $this->assertArrayHasKey('simulation_enabled', $config);
        $this->assertArrayHasKey('allowed_environments', $config);
        $this->assertArrayHasKey('allowed_databases', $config);

        // Safe defaults verification
        $this->assertContains('local', $config['allowed_environments']);
        $this->assertContains('testing', $config['allowed_environments']);
        $this->assertContains('kasir_ps_test', $config['allowed_databases']);
    }

    /** @test */
    public function hardware_command_result_dto_contains_all_required_fields()
    {
        $result = new HardwareCommandResult(
            10,
            'MOCK_SAMSUNG_TV',
            true,
            'POWER_ON',
            true,
            'SUCCESS',
            'Perangkat dinyalakan (Simulasi)',
            ['power' => 'ON', 'mute' => false],
            '2026-11-05T12:00:00+07:00',
            ['mock' => true]
        );

        $array = $result->toArray();

        $this->assertEquals(10, $array['device_id']);
        $this->assertEquals('MOCK_SAMSUNG_TV', $array['driver_type']);
        $this->assertTrue($array['simulation_mode']);
        $this->assertEquals('POWER_ON', $array['command']);
        $this->assertTrue($array['success']);
        $this->assertEquals('SUCCESS', $array['result_code']);
        $this->assertEquals('Perangkat dinyalakan (Simulasi)', $array['message']);
        $this->assertEquals(['power' => 'ON', 'mute' => false], $array['simulated_state']);
        $this->assertEquals('2026-11-05T12:00:00+07:00', $array['executed_at']);
        $this->assertEquals(['mock' => true], $array['raw_response']);

        // JsonSerializable verification
        $json = json_encode($result);
        $this->assertStringContainsString('"device_id":10', $json);
        $this->assertStringContainsString('"result_code":"SUCCESS"', $json);
    }

    /** @test */
    public function hardware_command_result_factory_methods_produce_expected_result_codes()
    {
        $success = HardwareCommandResult::createSuccess(1, 'MOCK_TV', 'POWER_ON', 'OK', ['power' => 'ON']);
        $this->assertTrue($success->success);
        $this->assertEquals('SUCCESS', $success->resultCode);

        $offline = HardwareCommandResult::createOffline(1, 'MOCK_TV', 'POWER_ON');
        $this->assertFalse($offline->success);
        $this->assertEquals('OFFLINE', $offline->resultCode);

        $timeout = HardwareCommandResult::createTimeout(1, 'MOCK_TV', 'POWER_ON', 3000);
        $this->assertFalse($timeout->success);
        $this->assertEquals('TIMEOUT', $timeout->resultCode);

        $rejected = HardwareCommandResult::createRejected(1, 'MOCK_TV', 'POWER_ON');
        $this->assertFalse($rejected->success);
        $this->assertEquals('COMMAND_REJECTED', $rejected->resultCode);

        $error = HardwareCommandResult::createError(1, 'MOCK_TV', 'UNKNOWN', 'Invalid', 'INVALID_COMMAND');
        $this->assertFalse($error->success);
        $this->assertEquals('INVALID_COMMAND', $error->resultCode);
    }

    /** @test */
    public function hardware_simulation_exception_provides_standardized_http_status_codes()
    {
        $exDisabled = HardwareSimulationException::simulationDisabled();
        $this->assertEquals(403, $exDisabled->getStatusCode());

        $exProd = HardwareSimulationException::productionDatabaseGuard('billing-sugeng-rawuh');
        $this->assertEquals(403, $exProd->getStatusCode());
        $this->assertStringContainsString('billing-sugeng-rawuh', $exProd->getMessage());

        $exEnv = HardwareSimulationException::environmentGuard('production');
        $this->assertEquals(403, $exEnv->getStatusCode());

        $exCommand = HardwareSimulationException::unsupportedCommand('EXPLODE', 'SMART_TV');
        $this->assertEquals(422, $exCommand->getStatusCode());

        $exInactive = HardwareSimulationException::deviceInactive('TV 01');
        $this->assertEquals(409, $exInactive->getStatusCode());

        $exScenario = HardwareSimulationException::invalidScenario('FOOBAR');
        $this->assertEquals(422, $exScenario->getStatusCode());

        $exNotFound = HardwareSimulationException::deviceNotFound(999);
        $this->assertEquals(404, $exNotFound->getStatusCode());
    }

    /** @test */
    public function hardware_driver_interface_defines_required_lifecycle_methods()
    {
        $reflection = new \ReflectionClass(HardwareDriverInterface::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertTrue($reflection->hasMethod('probe'));
        $this->assertTrue($reflection->hasMethod('pair'));
        $this->assertTrue($reflection->hasMethod('sendCommand'));
        $this->assertTrue($reflection->hasMethod('getStatus'));
        $this->assertTrue($reflection->hasMethod('getSupportedCommands'));
        $this->assertTrue($reflection->hasMethod('getDriverType'));
    }
}
