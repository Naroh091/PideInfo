<?php

namespace App\Observability;

/**
 * String constants for the OpenTelemetry attribute keys used across the codebase.
 * Mixes OTel `gen_ai.*` semantic conventions with Langfuse-specific `langfuse.*`
 * extensions that drive the Langfuse UI mapping (trace name, user, session, I/O).
 */
final class AttributeKeys
{
    public const GEN_AI_SYSTEM = 'gen_ai.system';
    public const GEN_AI_OPERATION = 'gen_ai.operation.name';
    public const GEN_AI_REQUEST_MODEL = 'gen_ai.request.model';
    public const GEN_AI_REQUEST_TEMPERATURE = 'gen_ai.request.temperature';
    public const GEN_AI_REQUEST_MAX_TOKENS = 'gen_ai.request.max_tokens';
    public const GEN_AI_REQUEST_INPUT_COUNT = 'gen_ai.request.input_count';
    public const GEN_AI_REQUEST_EMBEDDING_DIMENSION = 'gen_ai.request.embedding_dimension';
    public const GEN_AI_REQUEST_ATTEMPT = 'gen_ai.request.attempt';
    public const GEN_AI_RESPONSE_MODEL = 'gen_ai.response.model';
    public const GEN_AI_RESPONSE_FINISH_REASON = 'gen_ai.response.finish_reason';
    public const GEN_AI_USAGE_INPUT_TOKENS = 'gen_ai.usage.input_tokens';
    public const GEN_AI_USAGE_OUTPUT_TOKENS = 'gen_ai.usage.output_tokens';

    public const LANGFUSE_TRACE_NAME = 'langfuse.trace.name';
    public const LANGFUSE_USER_ID = 'langfuse.user.id';
    public const LANGFUSE_SESSION_ID = 'langfuse.session.id';
    public const LANGFUSE_TAGS = 'langfuse.trace.tags';
    public const LANGFUSE_OBSERVATION_TYPE = 'langfuse.observation.type';
    public const LANGFUSE_OBSERVATION_LEVEL = 'langfuse.observation.level';
    public const LANGFUSE_OBSERVATION_INPUT = 'langfuse.observation.input';
    public const LANGFUSE_OBSERVATION_OUTPUT = 'langfuse.observation.output';
    public const LANGFUSE_OBSERVATION_LABEL = 'langfuse.observation.metadata.label';
}
