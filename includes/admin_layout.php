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
        'admin_agents.php' => [
            'title' => 'Sales Agents',
            'subtitle' => 'Create staff accounts, update contact details, reset passwords, and manage account access.',
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

/**
 * Standard navigation drawer items for the Administrator Control Room.
 * Rendered in exact order across every admin view:
 * 1. Admin dashboard
 * 2. Reports & analytics
 * 3. Sales & transactions
 * 4. Clients
 * 5. Sales Agents
 * 6. Payments
 * 7. Transaction tracking
 * 8. Client transactions
 * 9. Property listings
 * 10. Audit logs
 */
function kq_admin_sidebar_items(string $currentPage = ''): array
{
    return [
        [
            'id' => 'nav-admin-dashboard',
            'title' => 'Admin dashboard',
            'href' => 'admin_dashboard.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_dashboard.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-admin-reports',
            'title' => 'Reports & analytics',
            'href' => 'admin_reports.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_reports.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-admin-sales',
            'title' => 'Sales & transactions',
            'href' => 'admin_sales.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_sales.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-admin-clients',
            'title' => 'Clients',
            'href' => 'admin_clients.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_clients.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-admin-agents',
            'title' => 'Sales Agents',
            'href' => 'admin_agents.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_agents.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-admin-payments',
            'title' => 'Payments',
            'href' => 'admin_payments.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'admin_payments.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-transaction-tracking',
            'title' => 'Transaction tracking',
            'href' => 'transaction_tracking.php',
            'is_page' => true,
            'is_active' => ($currentPage === 'transaction_tracking.php'),
            'type' => 'link',
        ],
        [
            'id' => 'nav-client-transactions',
            'title' => 'Client transactions',
            'href' => ($currentPage === 'admin_dashboard.php' ? '#client-transactions' : 'admin_dashboard.php#client-transactions'),
            'is_page' => false,
            'is_active' => false,
            'type' => 'anchor',
        ],
        [
            'id' => 'nav-property-listings',
            'title' => 'Property listings',
            'href' => ($currentPage === 'admin_dashboard.php' ? '#property-list' : 'admin_dashboard.php#property-list'),
            'is_page' => false,
            'is_active' => false,
            'type' => 'anchor',
        ],
        [
            'id' => 'nav-audit-logs',
            'title' => 'Audit logs',
            'href' => '#auditLogsModal',
            'is_page' => false,
            'is_active' => false,
            'type' => 'modal',
            'modal_target' => '#auditLogsModal',
        ],
    ];
}
