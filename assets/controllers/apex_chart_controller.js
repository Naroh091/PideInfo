import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

export default class extends Controller {
    static values = { config: Object };

    connect() {
        this.chart = new ApexCharts(this.element, this.configValue);
        this.chart.render();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
