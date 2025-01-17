import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';

export default class extends Controller {
    static values = {
        data: Object,
        type: String,
    };

    connect() {
        const ctx = this.element.querySelector('canvas').getContext('2d');
        new Chart(ctx, {
            type: this.typeValue,
            data: this.dataValue,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true, // Ensure the y-axis starts at 0
                        ticks: {
                            callback: function (value) {
                                const hours = Math.floor(value / 3600);
                                const minutes = Math.floor((value % 3600) / 60);
                                const seconds = value % 60;
                                return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds
                                    .toString()
                                    .padStart(2, '0')}`;
                            },
                        },
                    },
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const value = context.raw;
                                const hours = Math.floor(value / 3600);
                                const minutes = Math.floor((value % 3600) / 60);
                                const seconds = value % 60;
                                return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds
                                    .toString()
                                    .padStart(2, '0')}`;
                            },
                        },
                    },
                },
            },
        });
    }
}
