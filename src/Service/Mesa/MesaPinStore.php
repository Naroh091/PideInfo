<?php

declare(strict_types=1);

namespace App\Service\Mesa;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * «La mesa»: las resoluciones que el instructor va fijando mientras trabaja,
 * con una nota opcional por resolución. Vive en la sesión — la mesa es de la
 * persona, no de la base de datos, y se vacía sola al caducar la sesión.
 */
final class MesaPinStore
{
    private const SESSION_KEY = 'mesa_resoluciones_pins';
    private const MAX_PINS = 20;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return array<string, array{note: string}> id (RFC 4122) => datos, en orden de fijado
     */
    public function all(): array
    {
        try {
            $pins = $this->requestStack->getSession()->get(self::SESSION_KEY, []);
        } catch (\LogicException) {
            return [];
        }

        return is_array($pins) ? $pins : [];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function pin(string $id): void
    {
        $pins = $this->all();
        if (isset($pins[$id]) || count($pins) >= self::MAX_PINS) {
            return;
        }

        $pins[$id] = ['note' => ''];
        $this->save($pins);
    }

    public function unpin(string $id): void
    {
        $pins = $this->all();
        unset($pins[$id]);
        $this->save($pins);
    }

    public function setNote(string $id, string $note): void
    {
        $pins = $this->all();
        if (!isset($pins[$id])) {
            return;
        }

        $pins[$id]['note'] = mb_substr(trim($note), 0, 500);
        $this->save($pins);
    }

    public function clear(): void
    {
        $this->save([]);
    }

    /** @param array<string, array{note: string}> $pins */
    private function save(array $pins): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $pins);
    }
}
