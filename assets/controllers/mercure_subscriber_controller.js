import { Controller } from '@hotwired/stimulus';

/**
 * Mercure Subscriber Controller
 *
 * Subscribes to Mercure topics and triggers LiveComponent refreshes
 * when updates are received.
 *
 * Usage:
 * <div data-controller="mercure-subscriber"
 *      data-mercure-subscriber-topic-value="dashboard/user/123"
 *      data-mercure-subscriber-hub-value="http://localhost:3000/.well-known/mercure">
 */
export default class extends Controller {
    static values = {
        topic: String,
        hub: String,
    };

    connect() {
        if (!this.topicValue || !this.hubValue) {
            console.warn('Mercure subscriber: missing topic or hub URL');
            return;
        }

        this.subscribe();
    }

    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }

    subscribe() {
        const url = new URL(this.hubValue);
        url.searchParams.append('topic', this.topicValue);

        this.eventSource = new EventSource(url, { withCredentials: false });

        this.eventSource.onmessage = (event) => {
            this.handleUpdate(event);
        };

        this.eventSource.onerror = (error) => {
            console.warn('Mercure connection error, will retry...', error);
        };

        console.log('Mercure: subscribed to', this.topicValue);
    }

    handleUpdate(event) {
        try {
            const data = JSON.parse(event.data);
            console.log('Mercure update received:', data);

            // Dispatch a custom event that LiveComponents can listen to
            this.dispatch('update', { detail: data });

            // Find all LiveComponents in this element and trigger refresh
            this.refreshLiveComponents();

        } catch (e) {
            console.error('Error handling Mercure update:', e);
        }
    }

    refreshLiveComponents() {
        // Find all LiveComponent elements within this controller's scope
        const liveComponents = this.element.querySelectorAll('[data-controller*="live"]');

        liveComponents.forEach(component => {
            // Trigger the LiveComponent to re-render by dispatching the refresh action
            const liveController = this.application.getControllerForElementAndIdentifier(component, 'live');
            if (liveController && typeof liveController.$render === 'function') {
                liveController.$render();
            }
        });

        // Also dispatch a global event for any component that wants to listen
        window.dispatchEvent(new CustomEvent('mercure:dashboard-update', {
            bubbles: true,
            detail: { timestamp: new Date().toISOString() }
        }));
    }
}
