<?php

namespace Tests\Unit;

use App\Services\AccessControlService;
use PDO;
use PHPUnit\Framework\TestCase;

class AccessControlServiceTest extends TestCase
{
    private function createPdoMock(array $statementMap): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function ($sql) use ($statementMap) {
            foreach ($statementMap as $needle => $stmt) {
                if (str_contains($sql, $needle)) {
                    return $stmt;
                }
            }
            return $this->createMock(\PDOStatement::class);
        });
        return $pdo;
    }

    public function testCanPublishWhenUserHasPlanWithLimit(): void
    {
        $stmtPlan = $this->createMock(\PDOStatement::class);
        $stmtPlan->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Frete Pro',
            'limit_monthly' => 10,
            'active' => 1,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 3]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtPlan,
            'FROM freights' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(3, $result['used']);
        $this->assertEquals(7, $result['remaining']);
        $this->assertEquals('Frete Pro', $result['plan_name']);
    }

    public function testCanPublishBlocksWhenLimitExceeded(): void
    {
        $stmtPlan = $this->createMock(\PDOStatement::class);
        $stmtPlan->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Frete Starter',
            'limit_monthly' => 5,
            'active' => 1,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 5]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtPlan,
            'FROM freights' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals('Frete Starter', $result['plan_name']);
    }

    public function testCanPublishFallsBackToPricingRulesWhenNoPlan(): void
    {
        $stmtNoPlan = $this->createMock(\PDOStatement::class);
        $stmtNoPlan->method('fetch')->willReturn(false);

        $stmtFreeLimit = $this->createMock(\PDOStatement::class);
        $stmtFreeLimit->method('fetch')->willReturn([
            'free_limit' => 3,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 1]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtNoPlan,
            'pricing_rules' => $stmtFreeLimit,
            'FROM freights' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(3, $result['limit']);
        $this->assertEquals(1, $result['used']);
        $this->assertEquals(2, $result['remaining']);
        $this->assertEquals('Plano Gratuito', $result['plan_name']);
    }

    public function testCanPublishBlocksWhenUsedEqualsLimit(): void
    {
        $stmtPlan = $this->createMock(\PDOStatement::class);
        $stmtPlan->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Frete Pro',
            'limit_monthly' => 5,
            'active' => 1,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 5]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtPlan,
            'FROM freights' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals(5, $result['used']);
    }

    public function testCanPublishWithZeroUsage(): void
    {
        $stmtPlan = $this->createMock(\PDOStatement::class);
        $stmtPlan->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Frete Pro',
            'limit_monthly' => 10,
            'active' => 1,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 0]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtPlan,
            'FROM freights' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(10, $result['remaining']);
        $this->assertEquals(0, $result['used']);
    }

    public function testCanPublishForMarketplaceModule(): void
    {
        $stmtPlan = $this->createMock(\PDOStatement::class);
        $stmtPlan->method('fetch')->willReturn([
            'id' => 1,
            'name' => 'Marketplace Pro',
            'limit_monthly' => 15,
            'active' => 1,
        ]);

        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 3]);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtPlan,
            'FROM listings' => $stmtCount,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'marketplace');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(15, $result['limit']);
        $this->assertEquals(3, $result['used']);
        $this->assertEquals(12, $result['remaining']);
        $this->assertEquals('Marketplace Pro', $result['plan_name']);
    }

    public function testCanPublishRequiresPlanWhenNoPlanAndNoPricingRule(): void
    {
        $stmtNoPlan = $this->createMock(\PDOStatement::class);
        $stmtNoPlan->method('fetch')->willReturn(false);

        $stmtNoPricing = $this->createMock(\PDOStatement::class);
        $stmtNoPricing->method('fetch')->willReturn(false);

        $pdo = $this->createPdoMock([
            'user_modules' => $stmtNoPlan,
            'pricing_rules' => $stmtNoPricing,
        ]);

        $service = new AccessControlService($pdo);
        $result = $service->canPublish(1, 'freights');

        $this->assertArrayHasKey('requires_plan', $result);
        $this->assertTrue($result['requires_plan']);
    }
}
