<?php

namespace App\DTO;

final readonly class ChatMessage
{
    public function __construct(
        public string $role,
        public string $content,
        public string $type = 'message',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toGeminiFormat(): array
    {
        return [
            'role' => $this->role === 'user' ? 'user' : 'model',
            'parts' => [
                ['text' => $this->content],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'type' => $this->type,
        ];
    }

    /**
     * @param array<string, string> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'] ?? 'user',
            content: $data['content'] ?? '',
            type: $data['type'] ?? 'message',
        );
    }
}
