import PostalMime from 'postal-mime';

interface EmailPayload {
	to: string;
	from: string;
	subject: string;
	date: string;
	textBody: string;
	htmlBody: string;
	attachments: Array<{
		filename: string;
		contentType: string;
		content: string; // base64
	}>;
}

function arrayBufferToBase64(buffer: ArrayBuffer): string {
	const bytes = new Uint8Array(buffer);
	let binary = '';
	for (let i = 0; i < bytes.length; i++) {
		binary += String.fromCharCode(bytes[i]);
	}
	return btoa(binary);
}

export default {
	async email(message: ForwardableEmailMessage, env: Env): Promise<void> {
		const toAddress = message.to;

		// Only process usuario-* addresses, silently drop everything else
		if (!toAddress.startsWith('usuario-')) {
			return;
		}

		try {
			const rawEmail = await new Response(message.raw).arrayBuffer();
			const parser = new PostalMime();
			const parsed = await parser.parse(rawEmail);

			const attachments = parsed.attachments.map((att) => {
				const buffer = att.content instanceof ArrayBuffer
					? att.content
					: att.content instanceof Uint8Array
						? att.content.buffer as ArrayBuffer
						: new TextEncoder().encode(att.content as string).buffer as ArrayBuffer;
				return {
					filename: att.filename || 'attachment',
					contentType: att.mimeType,
					content: arrayBufferToBase64(buffer),
				};
			});

			const payload: EmailPayload = {
				to: toAddress,
				from: message.from,
				subject: parsed.subject || '(sin asunto)',
				date: parsed.date || new Date().toISOString(),
				textBody: parsed.text || '',
				htmlBody: parsed.html || '',
				attachments,
			};

			const response = await fetch(env.WEBHOOK_URL, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-Webhook-Secret': env.WEBHOOK_SECRET,
				},
				body: JSON.stringify(payload),
			});

			if (!response.ok) {
				const body = await response.text();
				console.error(`Webhook returned ${response.status}: ${body}`);
			}
		} catch (error) {
			console.error('Error processing inbound email:', error);
		}
	},
} satisfies ExportedHandler<Env>;
