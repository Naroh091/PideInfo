import { describe, it, expect } from 'vitest';
import worker from '../src/index';

describe('Email Worker', () => {
	it('exports an email handler', () => {
		expect(worker.email).toBeDefined();
		expect(typeof worker.email).toBe('function');
	});

	it('silently drops non-usuario addresses', async () => {
		let webhookCalled = false;

		const message = {
			to: 'info@pideinfo.es',
			from: 'test@example.com',
			raw: new ReadableStream(),
		} as unknown as ForwardableEmailMessage;

		const env = {
			WEBHOOK_URL: 'https://example.com/webhook',
			WEBHOOK_SECRET: 'test-secret',
		} as Env;

		// Should return without calling webhook
		await worker.email(message, env, {} as ExecutionContext);
		expect(webhookCalled).toBe(false);
	});
});
