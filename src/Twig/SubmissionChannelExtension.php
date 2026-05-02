<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\PublicBody;
use App\Service\Submission\ChannelResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SubmissionChannelExtension extends AbstractExtension
{
    public function __construct(
        private readonly ChannelResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'submission_channel_badge',
                [$this, 'renderBadge'],
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'submission_channel_label',
                fn (PublicBody $body): string => $this->resolver->badgeLabel($body),
            ),
        ];
    }

    public function renderBadge(PublicBody $body): string
    {
        $label = $this->resolver->badgeLabel($body);
        $taskType = $this->resolver->resolveTaskType($body);
        $cssClass = $taskType === \App\Entity\AgentTask::TYPE_SUBMIT_REQUEST_PORTAL
            ? 'channel-badge channel-badge-portal'
            : 'channel-badge channel-badge-reg';

        return sprintf(
            '<span class="%s">%s</span>',
            htmlspecialchars($cssClass, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES)
        );
    }
}
