<?php
// Set default endpoint for sales data if not set
$salesDataEndpoint = isset($salesDataEndpoint) ? $salesDataEndpoint : 'dashboard_sales_data.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Render sales chart if element exists
const salesChartEl = document.getElementById('salesChart');
if (salesChartEl) {
    fetch('<?php echo htmlspecialchars($salesDataEndpoint, ENT_QUOTES, 'UTF-8'); ?>')
        .then((res) => {
            if (!res.ok) {
                throw new Error('Failed to load sales data');
            }
            return res.json();
        })
        .then((data) => {
            const labels = Array.isArray(data.labels) ? data.labels : [];
            const salesValues = Array.isArray(data.values)
                ? data.values.map((value) => Number(value) || 0)
                : [];
            const maxSales = salesValues.length ? Math.max(...salesValues) : 0;
            const allZero = salesValues.every((v) => v === 0);

            // Helper for nice y-axis steps
            function niceStep(maxValue) {
                if (maxValue <= 0) return 100;
                const rough = maxValue / 5;
                const magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
                const normalized = rough / magnitude;
                if (normalized <= 1) return magnitude;
                if (normalized <= 2) return 2 * magnitude;
                if (normalized <= 5) return 5 * magnitude;
                return 10 * magnitude;
            }

            const yStepSize = niceStep(maxSales || 100);
            const ySuggestedMax = allZero
                ? yStepSize * 5
                : Math.ceil((maxSales * 1.15) / yStepSize) * yStepSize;

            new Chart(salesChartEl, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue',
                        data: salesValues,
                        backgroundColor: [
                            '#60a5fa', '#60a5fa', '#60a5fa', '#60a5fa', '#60a5fa', '#60a5fa', '#60a5fa'
                        ],
                        borderColor: 'transparent',
                        borderWidth: 0,
                        borderRadius: 4,
                        maxBarThickness: 32,
                        barPercentage: 0.7,
                        categoryPercentage: 0.75
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 400,
                        easing: 'easeOutQuad'
                    },
                    layout: {
                        padding: {
                            top: 0,
                            right: 0,
                            left: 0,
                            bottom: 0
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            displayColors: false,
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            padding: 10,
                            titleFont: {
                                weight: '600',
                                size: 12
                            },
                            bodyFont: {
                                size: 12,
                                weight: '500'
                            },
                            borderColor: '#d1d5db',
                            borderWidth: 0,
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    const amount = Number(context.parsed.y || 0);
                                    return 'Rs ' + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 0,
                                autoSkip: false,
                                color: '#6b7280',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            suggestedMax: ySuggestedMax,
                            ticks: {
                                stepSize: yStepSize,
                                color: '#9ca3af',
                                callback: function(value) {
                                    return Number(value).toLocaleString('en-IN');
                                }
                            },
                            grid: {
                                color: '#f3f4f6',
                                drawBorder: false
                            }
                        }
                    }
                }
            });
        })
        .catch((err) => {
            console.error(err);
        });
}

</script>

<script>
(function () {
    var logoutLinks = document.querySelectorAll('a[href$="logout.php"]');
    if (!logoutLinks || logoutLinks.length === 0) return;

    for (var i = 0; i < logoutLinks.length; i++) {
        logoutLinks[i].addEventListener('click', function (e) {
            var ok = confirm('Do you want to logout?');
            if (!ok) {
                e.preventDefault();
            }
        });
    }
})();
</script>

<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'employee') { ?>
<script>
(function () {
    // Skip auto logout when the page is leaving because of normal navigation.
    var skipAutoLogout = false;

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a');
        if (!link) return;

        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download')) return;

        skipAutoLogout = true;
    }, true);

    document.addEventListener('submit', function () {
        skipAutoLogout = true;
    }, true);

    window.addEventListener('keydown', function (event) {
        if (event.key === 'F5' || ((event.ctrlKey || event.metaKey) && (event.key === 'r' || event.key === 'R'))) {
            skipAutoLogout = true;
        }
    });

    window.addEventListener('pagehide', function () {
        // Try to close the employee session when the tab/browser is closed.
        if (skipAutoLogout || !navigator.sendBeacon) return;
        navigator.sendBeacon('../auth/auto_logout.php');
    });
})();
</script>
<?php } ?>

</div>
</body>
</html>


