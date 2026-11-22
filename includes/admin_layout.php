<?php

/** Shared page labels and descriptions for the administrator control room. */
function kq_admin_pages(): array
{
    return [
        'admin_dashboard.php' => [
            'title' => 'Admin dashboard',
            'subtitle' => 'A clear overview of listings and client activity.',
        ],
        'admin_reports.php' => [
            'title' => 'Reports & analytics',
            'subtitle' => 'Review revenue, inventory, viewing requests, and inquiry activity.',
        ],
        'admin_sales.php' => [
            'title' => 'Sales & transactions',
            'subtitle' => 'Monitor the pipeline, verified revenue, collections, and every tracked client case.',
        ],
        'admin_clients.php' => [
            'title' => 'Clients',
            'subtitle' => 'Create and maintain client accounts, contact details, and account status.',
        ],
        'admin_payments.php' => [
            'title' => 'Payments',
            'subtitle' => 'Record client payments, verify references, and reconcile collections against tracked transactions.',
        ],
        'transaction_tracking.php' => [
            'title' => 'Transaction tracking',
            'subtitle' => 'Record each verified milestone so clients can follow their request through completion.',
        ],
    ];
}
