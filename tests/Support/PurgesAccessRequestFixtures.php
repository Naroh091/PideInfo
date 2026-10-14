<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The suite runs against the live corpus (TEST_DB_SUFFIX= in .env.test.local),
 * so every test seeding AccessRequest/User/PublicBody/ApplicableLaw
 * must delete them on teardown or the dev DB accumulates fixtures each run.
 *
 * Usage: register ids with trackFixture*() on creation; tearDown purges
 * them with direct SQL (dependents first) before shutting the kernel down.
 */
trait PurgesAccessRequestFixtures
{
    /** @var list<string> */
    private array $fixtureRequestIds = [];
    /** @var list<string> */
    private array $fixtureUserIds = [];
    /** @var list<string> */
    private array $fixtureBodyIds = [];
    /** @var list<string> */
    private array $fixtureLawIds = [];
    /** @var list<string> */
    private array $fixtureOrgIds = [];

    private function trackFixtureOrg(string $id): void
    {
        $this->fixtureOrgIds[] = $id;
    }

    private function trackFixtureRequest(string $id): void
    {
        $this->fixtureRequestIds[] = $id;
    }

    private function trackFixtureUser(string $id): void
    {
        $this->fixtureUserIds[] = $id;
    }

    private function trackFixtureBody(string $id): void
    {
        $this->fixtureBodyIds[] = $id;
    }

    private function trackFixtureLaw(string $id): void
    {
        $this->fixtureLawIds[] = $id;
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRequestIds || $this->fixtureUserIds || $this->fixtureBodyIds || $this->fixtureLawIds || $this->fixtureOrgIds) {
            $conn = static::getContainer()->get('doctrine')->getConnection();

            if ($this->fixtureRequestIds) {
                $dependents = [
                    'access_request_list_item', 'status_history', 'deadline_history',
                    'custom_deadline', 'agent_task', 'access_request_complaint',
                    'document', 'reminder', 'user_notification',
                ];
                foreach ($dependents as $table) {
                    $conn->executeStatement(
                        "DELETE FROM $table WHERE access_request_id IN (:ids)",
                        ['ids' => $this->fixtureRequestIds],
                        ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                    );
                }
                $conn->executeStatement(
                    'DELETE FROM access_request WHERE id IN (:ids)',
                    ['ids' => $this->fixtureRequestIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
            if ($this->fixtureUserIds) {
                $conn->executeStatement(
                    'DELETE FROM user_notification WHERE user_id IN (:ids)',
                    ['ids' => $this->fixtureUserIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
                $conn->executeStatement(
                    'DELETE FROM "user" WHERE id IN (:ids)',
                    ['ids' => $this->fixtureUserIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
            if ($this->fixtureBodyIds) {
                $conn->executeStatement(
                    'DELETE FROM public_body WHERE id IN (:ids)',
                    ['ids' => $this->fixtureBodyIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
            if ($this->fixtureLawIds) {
                $conn->executeStatement(
                    'DELETE FROM applicable_law WHERE id IN (:ids)',
                    ['ids' => $this->fixtureLawIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
            if ($this->fixtureOrgIds) {
                $conn->executeStatement(
                    'DELETE FROM organization WHERE id IN (:ids)',
                    ['ids' => $this->fixtureOrgIds],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }

            $this->fixtureRequestIds = $this->fixtureUserIds = $this->fixtureBodyIds = $this->fixtureLawIds = $this->fixtureOrgIds = [];
        }

        parent::tearDown();
    }
}
