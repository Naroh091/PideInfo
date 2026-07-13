<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La suite corre contra el corpus vivo (TEST_DB_SUFFIX= en .env.test.local),
 * así que cada test que siembra AccessRequest/User/PublicBody/ApplicableLaw
 * debe borrarlos al terminar o la BD de dev acumula fixtures en cada run.
 *
 * Uso: registrar los ids con trackFixture*() al crearlos; el tearDown los
 * purga con SQL directo (los dependientes primero) antes de apagar el kernel.
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
        if ($this->fixtureRequestIds || $this->fixtureUserIds || $this->fixtureBodyIds || $this->fixtureLawIds) {
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

            $this->fixtureRequestIds = $this->fixtureUserIds = $this->fixtureBodyIds = $this->fixtureLawIds = [];
        }

        parent::tearDown();
    }
}
